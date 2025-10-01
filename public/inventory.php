<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

setSecurityHeaders();
startSecureSession();
requireLogin();

// Verificar permisos de acceso a la página
requirePageAccess('inventory.php');

$user = getCurrentUser();
$db = getDB();

// SOLO ADMIN puede realizar acciones de modificación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verificar que solo admin puede modificar inventario
    if ($user['rol'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Sin permisos para modificar inventario']);
        exit;
    }
    
    switch ($_POST['action']) {
        case 'add_device':
            try {
                $stmt = $db->prepare("
                    INSERT INTO celulares (modelo, marca, capacidad, precio, precio_compra, imei1, imei2, color, estado, condicion, tienda_id, usuario_registro_id, notas) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $result = $stmt->execute([
                    sanitize($_POST['modelo']),
                    sanitize($_POST['marca']),
                    sanitize($_POST['capacidad']),
                    floatval($_POST['precio']),
                    !empty($_POST['precio_compra']) ? floatval($_POST['precio_compra']) : null,
                    sanitize($_POST['imei1']),
                    !empty($_POST['imei2']) ? sanitize($_POST['imei2']) : null,
                    sanitize($_POST['color']),
                    $_POST['estado'],
                    $_POST['condicion'],
                    intval($_POST['tienda_id']), // Admin puede elegir tienda
                    $user['id'],
                    sanitize($_POST['notas'])
                ]);
                
                if ($result) {
                    logActivity($user['id'], 'add_device', "Dispositivo agregado: " . $_POST['modelo'] . " - " . $_POST['imei1']);
                    echo json_encode(['success' => true, 'message' => 'Dispositivo agregado correctamente']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error al agregar dispositivo']);
                }
            } catch(Exception $e) {
                logError("Error al agregar dispositivo: " . $e->getMessage());
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    echo json_encode(['success' => false, 'message' => 'El IMEI ya existe en el sistema']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error en el sistema']);
                }
            }
            exit;
            
        case 'update_device':
            try {
                $device_id = intval($_POST['device_id']);
                
                $stmt = $db->prepare("
                    UPDATE celulares SET 
                        modelo = ?, marca = ?, capacidad = ?, precio = ?, precio_compra = ?, 
                        imei1 = ?, imei2 = ?, color = ?, estado = ?, condicion = ?, notas = ?
                    WHERE id = ?
                ");
                
                $result = $stmt->execute([
                    sanitize($_POST['modelo']),
                    sanitize($_POST['marca']),
                    sanitize($_POST['capacidad']),
                    floatval($_POST['precio']),
                    !empty($_POST['precio_compra']) ? floatval($_POST['precio_compra']) : null,
                    sanitize($_POST['imei1']),
                    !empty($_POST['imei2']) ? sanitize($_POST['imei2']) : null,
                    sanitize($_POST['color']),
                    $_POST['estado'],
                    $_POST['condicion'],
                    sanitize($_POST['notas']),
                    $device_id
                ]);
                
                if ($result) {
                    logActivity($user['id'], 'update_device', "Dispositivo actualizado ID: " . $device_id);
                    echo json_encode(['success' => true, 'message' => 'Dispositivo actualizado correctamente']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error al actualizar dispositivo']);
                }
            } catch(Exception $e) {
                logError("Error al actualizar dispositivo: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error en el sistema']);
            }
            exit;
            
        case 'delete_device':
            try {
                $device_id = intval($_POST['device_id']);
                
                $stmt = $db->prepare("DELETE FROM celulares WHERE id = ?");
                $result = $stmt->execute([$device_id]);
                
                if ($result) {
                    logActivity($user['id'], 'delete_device', "Dispositivo eliminado ID: " . $device_id);
                    echo json_encode(['success' => true, 'message' => 'Dispositivo eliminado correctamente']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error al eliminar dispositivo']);
                }
            } catch(Exception $e) {
                logError("Error al eliminar dispositivo: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error en el sistema']);
            }
            exit;
    }
}

// Obtener filtros
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$estado_filter = isset($_GET['estado']) ? $_GET['estado'] : '';
$tienda_filter = isset($_GET['tienda']) && hasPermission('view_all_inventory') ? intval($_GET['tienda']) : null;

// Construir query según el rol
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(modelo LIKE ? OR marca LIKE ? OR imei1 LIKE ? OR imei2 LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($estado_filter)) {
    $where_conditions[] = "estado = ?";
    $params[] = $estado_filter;
}

// CONTROL DE ACCESO POR ROL
if (hasPermission('view_all_inventory')) {
    // Admin: puede ver todas las tiendas
    if ($tienda_filter) {
        $where_conditions[] = "c.tienda_id = ?";
        $params[] = $tienda_filter;
    }
} else {
    // Vendedor: solo su tienda
    $where_conditions[] = "c.tienda_id = ?";
    $params[] = $user['tienda_id'];
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

try {
    // Obtener dispositivos
    $query = "
        SELECT c.*, t.nombre as tienda_nombre, u.nombre as registrado_por,
               CASE WHEN c.precio_compra IS NOT NULL THEN c.precio - c.precio_compra ELSE NULL END as ganancia_estimada
        FROM celulares c
        LEFT JOIN tiendas t ON c.tienda_id = t.id
        LEFT JOIN usuarios u ON c.usuario_registro_id = u.id
        $where_clause 
        ORDER BY c.fecha_registro DESC
    ";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $devices = $stmt->fetchAll();
    
    // Obtener tiendas para admin
    $tiendas = [];
    if (hasPermission('view_all_inventory')) {
        $tiendas_stmt = $db->query("SELECT id, nombre FROM tiendas WHERE activa = 1 ORDER BY nombre");
        $tiendas = $tiendas_stmt->fetchAll();
    }
    
} catch(Exception $e) {
    logError("Error al obtener inventario: " . $e->getMessage());
    $devices = [];
    $tiendas = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        .modal { display: none; }
        .modal.show { display: flex; }
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) {
            .sidebar-hidden { transform: translateX(-100%); }
        }
        .readonly-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 4px solid #f59e0b;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b">
        <div class="px-4 mx-auto">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <button id="sidebar-toggle" class="md:hidden p-2 rounded-md text-gray-600 hover:bg-gray-100">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <h1 class="ml-2 text-xl font-semibold text-gray-800"><?php echo SYSTEM_NAME; ?></h1>
                </div>
                
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600">
                        <?php echo htmlspecialchars($user['nombre']); ?>
                        <?php if ($user['tienda_nombre']): ?>
                            - <?php echo htmlspecialchars($user['tienda_nombre']); ?>
                        <?php endif; ?>
                        <span class="ml-2 px-2 py-1 text-xs rounded-full bg-gray-200 text-gray-700">
                            <?php echo ucfirst($user['rol']); ?>
                        </span>
                    </span>
                    <div class="relative">
                        <button id="user-menu-button" class="flex items-center p-2 rounded-md text-gray-600 hover:bg-gray-100">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </button>
                        <div id="user-menu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Mi Perfil</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Cerrar Sesión</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex">
        <!-- Sidebar -->
        <div id="sidebar" class="sidebar-transition fixed md:relative z-40 w-64 h-screen bg-white shadow-lg md:shadow-none">
            <div class="p-4">
                <nav class="space-y-2">
                    <a href="dashboard.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                        </svg>
                        Dashboard
                    </a>
                    
                    <a href="inventory.php" class="flex items-center px-4 py-2 text-gray-700 bg-blue-50 border-r-4 border-blue-500 rounded-l">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                        Inventario
                        <?php if ($user['rol'] === 'vendedor'): ?>
                            <span class="ml-auto text-xs text-gray-500">(Solo lectura)</span>
                        <?php endif; ?>
                    </a>
                    
                    <a href="sales.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                        Ventas
                    </a>
                    
                    <?php if (hasPermission('view_reports')): ?>
                    <a href="reports.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        Reportes
                    </a>
                    <?php endif; ?>
                    
                    <?php if (hasPermission('admin')): ?>
                        <div class="pt-4 mt-4 border-t border-gray-200">
                            <p class="px-4 text-xs font-medium text-gray-500 uppercase">Administración</p>
                            <a href="users.php" class="flex items-center px-4 py-2 mt-2 text-gray-700 hover:bg-gray-100 rounded">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                                </svg>
                                Usuarios
                            </a>
                            <a href="stores.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                                Tiendas
                            </a>
                        </div>
                    <?php endif; ?>
                </nav>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 md:ml-0">
            <div class="p-6">
                <!-- Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                    <div>
                        <h2 class="text-3xl font-bold text-gray-900">Inventario</h2>
                        <p class="text-gray-600">
                            <?php if ($user['rol'] === 'admin'): ?>
                                Gestión completa de dispositivos móviles
                            <?php else: ?>
                                Consulta de dispositivos disponibles para venta
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <?php if (hasPermission('add_devices')): ?>
                    <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Agregar Dispositivo
                    </button>
                    <?php else: ?>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-2 text-blue-700 text-sm">
                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Solo consulta - Para ventas ir a la sección Ventas
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Alerta para vendedores -->
                <?php if ($user['rol'] === 'vendedor'): ?>
                <div class="readonly-warning p-4 rounded-lg mb-6">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-amber-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 15.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                        <div>
                            <p class="font-medium text-amber-800">Modo Solo Lectura</p>
                            <p class="text-sm text-amber-700">Puedes consultar el inventario de tu tienda, pero no modificarlo. Para realizar ventas, ve a la sección <a href="sales.php" class="underline">Ventas</a>.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filtros -->
                <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
                    <form method="GET" class="flex flex-wrap gap-4">
                        <div class="flex-1 min-w-64">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Buscar por modelo, marca o IMEI..." 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <select name="estado" class="px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Todos los estados</option>
                                <option value="disponible" <?php echo $estado_filter === 'disponible' ? 'selected' : ''; ?>>Disponible</option>
                                <option value="vendido" <?php echo $estado_filter === 'vendido' ? 'selected' : ''; ?>>Vendido</option>
                                <option value="reservado" <?php echo $estado_filter === 'reservado' ? 'selected' : ''; ?>>Reservado</option>
                                <option value="reparacion" <?php echo $estado_filter === 'reparacion' ? 'selected' : ''; ?>>En Reparación</option>
                            </select>
                        </div>
                        <?php if (hasPermission('view_all_inventory')): ?>
                        <div>
                            <select name="tienda" class="px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Todas las tiendas</option>
                                <?php foreach($tiendas as $tienda): ?>
                                    <option value="<?php echo $tienda['id']; ?>" <?php echo $tienda_filter == $tienda['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tienda['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                            Filtrar
                        </button>
                    </form>
                </div>

                <!-- Tabla de Dispositivos -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dispositivo</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Precio</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IMEI</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                                    <?php if (hasPermission('view_all_inventory')): ?>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tienda</th>
                                    <?php endif; ?>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                                    <?php if (hasPermission('edit_devices')): ?>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($devices)): ?>
                                    <tr>
                                        <td colspan="<?php echo hasPermission('view_all_inventory') ? (hasPermission('edit_devices') ? '7' : '6') : (hasPermission('edit_devices') ? '6' : '5'); ?>" class="px-4 py-8 text-center text-gray-500">
                                            <div class="flex flex-col items-center">
                                                <svg class="w-12 h-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                                </svg>
                                                <p class="text-lg font-medium">No se encontraron dispositivos</p>
                                                <?php if ($user['rol'] === 'admin'): ?>
                                                    <p class="text-sm mt-1">¿Quieres <button onclick="openAddModal()" class="text-blue-600 underline">agregar el primero</button>?</p>
                                                <?php else: ?>
                                                    <p class="text-sm mt-1">No hay dispositivos en tu tienda o que coincidan con los filtros</p>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($devices as $device): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-4 py-4">
                                                <div>
                                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($device['modelo']); ?></p>
                                                    <p class="text-sm text-gray-600">
                                                        <?php if ($device['marca']): ?><?php echo htmlspecialchars($device['marca']); ?> - <?php endif; ?>
                                                        <?php echo htmlspecialchars($device['capacidad']); ?>
                                                        <?php if ($device['color']): ?> - <?php echo htmlspecialchars($device['color']); ?><?php endif; ?>
                                                    </p>
                                                    <p class="text-xs text-gray-500"><?php echo ucfirst($device['condicion']); ?></p>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4">
                                                <p class="font-medium text-gray-900">$<?php echo number_format($device['precio'], 2); ?></p>
                                                <?php if ($device['precio_compra'] && hasPermission('view_all_inventory')): ?>
                                                    <p class="text-sm text-gray-600">Compra: $<?php echo number_format($device['precio_compra'], 2); ?></p>
                                                    <p class="text-xs text-green-600">Ganancia: $<?php echo number_format($device['ganancia_estimada'], 2); ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-4">
                                                <p class="font-mono text-sm text-gray-900"><?php echo htmlspecialchars($device['imei1']); ?></p>
                                                <?php if ($device['imei2']): ?>
                                                    <p class="font-mono text-sm text-gray-600"><?php echo htmlspecialchars($device['imei2']); ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-4">
                                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php 
                                                    echo $device['estado'] === 'disponible' ? 'bg-green-100 text-green-800' : 
                                                        ($device['estado'] === 'vendido' ? 'bg-red-100 text-red-800' : 
                                                        ($device['estado'] === 'reservado' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800')); 
                                                ?>">
                                                    <?php echo ucfirst($device['estado']); ?>
                                                </span>
                                            </td>
                                            <?php if (hasPermission('view_all_inventory')): ?>
                                                <td class="px-4 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($device['tienda_nombre']); ?></td>
                                            <?php endif; ?>
                                            <td class="px-4 py-4 text-sm text-gray-900"><?php echo date('d/m/Y', strtotime($device['fecha_registro'])); ?></td>
                                            <?php if (hasPermission('edit_devices')): ?>
                                            <td class="px-4 py-4">
                                                <div class="flex items-center gap-2">
                                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($device)); ?>)" 
                                                            class="text-blue-600 hover:text-blue-900 p-1 rounded transition-colors" title="Editar">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                    </button>
                                                    <button onclick="deleteDevice(<?php echo $device['id']; ?>)" 
                                                            class="text-red-600 hover:text-red-900 p-1 rounded transition-colors" title="Eliminar">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Estadísticas adicionales -->
                <?php if (!empty($devices)): ?>
                <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white rounded-lg shadow p-4">
                        <div class="text-center">
                            <p class="text-2xl font-bold text-green-600">
                                <?php echo count(array_filter($devices, function($d) { return $d['estado'] === 'disponible'; })); ?>
                            </p>
                            <p class="text-sm text-gray-600">Disponibles</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow p-4">
                        <div class="text-center">
                            <p class="text-2xl font-bold text-red-600">
                                <?php echo count(array_filter($devices, function($d) { return $d['estado'] === 'vendido'; })); ?>
                            </p>
                            <p class="text-sm text-gray-600">Vendidos</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow p-4">
                        <div class="text-center">
                            <p class="text-2xl font-bold text-blue-600">
                                $<?php echo number_format(array_sum(array_map(function($d) { return $d['estado'] === 'disponible' ? $d['precio'] : 0; }, $devices)), 0); ?>
                            </p>
                            <p class="text-sm text-gray-600">Valor Inventario</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow p-4">
                        <div class="text-center">
                            <p class="text-2xl font-bold text-purple-600">
                                <?php echo count($devices); ?>
                            </p>
                            <p class="text-sm text-gray-600">Total Dispositivos</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Overlay para móvil -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 md:hidden hidden"></div>

    <!-- Modal Agregar/Editar (solo para admin) -->
    <?php if (hasPermission('add_devices')): ?>
    <div id="deviceModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-2xl mx-4 max-h-screen overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 id="modalTitle" class="text-lg font-semibold">Agregar Dispositivo</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form id="deviceForm" class="space-y-4">
                <input type="hidden" id="deviceId" name="device_id">
                <input type="hidden" id="formAction" name="action" value="add_device">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Modelo *</label>
                        <input type="text" id="modelo" name="modelo" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Marca</label>
                        <input type="text" id="marca" name="marca" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Capacidad *</label>
                        <input type="text" id="capacidad" name="capacidad" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Color</label>
                        <input type="text" id="color" name="color" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Precio *</label>
                        <input type="number" id="precio" name="precio" step="0.01" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Precio Compra</label>
                        <input type="number" id="precio_compra" name="precio_compra" step="0.01" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">IMEI 1 *</label>
                        <input type="text" id="imei1" name="imei1" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">IMEI 2</label>
                        <input type="text" id="imei2" name="imei2" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                        <select id="estado" name="estado" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="disponible">Disponible</option>
                            <option value="vendido">Vendido</option>
                            <option value="reservado">Reservado</option>
                            <option value="reparacion">En Reparación</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Condición</label>
                        <select id="condicion" name="condicion" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="nuevo">Nuevo</option>
                            <option value="usado">Usado</option>
                            <option value="refurbished">Refurbished</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tienda</label>
                        <select id="tienda_id" name="tienda_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <?php foreach($tiendas as $tienda): ?>
                                <option value="<?php echo $tienda['id']; ?>"><?php echo htmlspecialchars($tienda['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notas</label>
                    <textarea id="notas" name="notas" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeModal()" 
                            class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg">
                        Cancelar
                    </button>
                    <button type="button" onclick="saveDevice()" 
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Toggle sidebar
        document.getElementById('sidebar-toggle').addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('sidebar-hidden');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        });

        document.getElementById('sidebar-overlay').addEventListener('click', () => {
            document.getElementById('sidebar').classList.add('sidebar-hidden');
            document.getElementById('sidebar-overlay').classList.add('hidden');
        });

        // User menu toggle
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenu = document.getElementById('user-menu');

        userMenuButton.addEventListener('click', () => {
            userMenu.classList.toggle('hidden');
        });

        document.addEventListener('click', (e) => {
            if (!userMenuButton.contains(e.target) && !userMenu.contains(e.target)) {
                userMenu.classList.add('hidden');
            }
        });

        <?php if (hasPermission('add_devices')): ?>
        let isEditMode = false;

        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').textContent = 'Agregar Dispositivo';
            document.getElementById('formAction').value = 'add_device';
            document.getElementById('deviceForm').reset();
            document.getElementById('deviceId').value = '';
            document.getElementById('deviceModal').classList.add('show');
        }

        function openEditModal(device) {
            isEditMode = true;
            document.getElementById('modalTitle').textContent = 'Editar Dispositivo';
            document.getElementById('formAction').value = 'update_device';
            document.getElementById('deviceId').value = device.id;
            
            // Llenar formulario
            document.getElementById('modelo').value = device.modelo || '';
            document.getElementById('marca').value = device.marca || '';
            document.getElementById('capacidad').value = device.capacidad || '';
            document.getElementById('color').value = device.color || '';
            document.getElementById('precio').value = device.precio || '';
            document.getElementById('precio_compra').value = device.precio_compra || '';
            document.getElementById('imei1').value = device.imei1 || '';
            document.getElementById('imei2').value = device.imei2 || '';
            document.getElementById('estado').value = device.estado || 'disponible';
            document.getElementById('condicion').value = device.condicion || 'nuevo';
            document.getElementById('notas').value = device.notas || '';
            document.getElementById('tienda_id').value = device.tienda_id || '';
            
            document.getElementById('deviceModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('deviceModal').classList.remove('show');
        }

        function saveDevice() {
            const formData = new FormData();
            
            formData.append('action', document.getElementById('formAction').value);
            
            if (isEditMode) {
                formData.append('device_id', document.getElementById('deviceId').value);
            }
            
            formData.append('modelo', document.getElementById('modelo').value);
            formData.append('marca', document.getElementById('marca').value);
            formData.append('capacidad', document.getElementById('capacidad').value);
            formData.append('color', document.getElementById('color').value);
            formData.append('precio', document.getElementById('precio').value);
            formData.append('precio_compra', document.getElementById('precio_compra').value);
            formData.append('imei1', document.getElementById('imei1').value);
            formData.append('imei2', document.getElementById('imei2').value);
            formData.append('estado', document.getElementById('estado').value);
            formData.append('condicion', document.getElementById('condicion').value);
            formData.append('notas', document.getElementById('notas').value);
            formData.append('tienda_id', document.getElementById('tienda_id').value);
            
            const button = event.target;
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Guardando...';
            
            fetch('inventory.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('✅ ' + data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('❌ Error en la conexión', 'error');
            })
            .finally(() => {
                button.disabled = false;
                button.textContent = originalText;
            });
        }

        function deleteDevice(id) {
            if (!confirm('¿Estás seguro de que quieres eliminar este dispositivo?\n\nEsta acción no se puede deshacer.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_device');
            formData.append('device_id', id);
            
            fetch('inventory.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('✅ ' + data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showNotification('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('❌ Error en la conexión', 'error');
            });
        }

        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
        <?php endif; ?>

        // Sistema de notificaciones
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm transition-all duration-300 ${
                type === 'success' ? 'bg-green-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                'bg-blue-500 text-white'
            }`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            // Auto-remove after 4 seconds
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 4000);
        }
    </script>
</body>
</html>