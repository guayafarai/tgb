<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

setSecurityHeaders();
startSecureSession();
requireLogin();

// Verificar permisos de acceso
requirePageAccess('sales.php');

$user = getCurrentUser();
$db = getDB();

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'register_sale':
            try {
                $db->beginTransaction();
                
                $celular_id = intval($_POST['celular_id']);
                
                // Verificar que el dispositivo esté disponible
                $check_stmt = $db->prepare("
                    SELECT c.*, t.nombre as tienda_nombre 
                    FROM celulares c 
                    LEFT JOIN tiendas t ON c.tienda_id = t.id 
                    WHERE c.id = ? AND c.estado = 'disponible'
                ");
                $check_stmt->execute([$celular_id]);
                $device = $check_stmt->fetch();
                
                if (!$device) {
                    throw new Exception('Dispositivo no disponible para venta');
                }
                
                // Verificar permisos según el rol
                if (!canAccessDevice($device['tienda_id'], 'sell')) {
                    throw new Exception('No tienes permisos para vender este dispositivo');
                }
                
                // Registrar venta
                $sale_stmt = $db->prepare("
                    INSERT INTO ventas (celular_id, tienda_id, vendedor_id, cliente_nombre, 
                                      cliente_telefono, cliente_email, precio_venta, metodo_pago, notas) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $result = $sale_stmt->execute([
                    $celular_id,
                    $device['tienda_id'],
                    $user['id'],
                    sanitize($_POST['cliente_nombre']),
                    sanitize($_POST['cliente_telefono']),
                    sanitize($_POST['cliente_email']),
                    floatval($_POST['precio_venta']),
                    $_POST['metodo_pago'],
                    sanitize($_POST['notas'])
                ]);
                
                if (!$result) {
                    throw new Exception('Error al registrar la venta');
                }
                
                // Actualizar estado del dispositivo
                $update_stmt = $db->prepare("UPDATE celulares SET estado = 'vendido' WHERE id = ?");
                $update_result = $update_stmt->execute([$celular_id]);
                
                if (!$update_result) {
                    throw new Exception('Error al actualizar el estado del dispositivo');
                }
                
                $db->commit();
                
                logActivity($user['id'], 'register_sale', 
                    "Venta registrada - Dispositivo ID: $celular_id - Cliente: " . $_POST['cliente_nombre'] . 
                    " - Precio: $" . $_POST['precio_venta']);
                
                echo json_encode(['success' => true, 'message' => 'Venta registrada correctamente']);
                
            } catch(Exception $e) {
                $db->rollback();
                logError("Error al registrar venta: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
    }
}

// Obtener dispositivos disponibles para venta (solo de su tienda)
$available_devices = [];
try {
    if (hasPermission('view_all_sales')) {
        // Admin puede ver todas las tiendas
        $devices_query = "
            SELECT c.*, t.nombre as tienda_nombre 
            FROM celulares c 
            LEFT JOIN tiendas t ON c.tienda_id = t.id 
            WHERE c.estado = 'disponible' 
            ORDER BY c.fecha_registro DESC
        ";
        $devices_stmt = $db->query($devices_query);
    } else {
        // Vendedor solo ve su tienda
        $devices_query = "
            SELECT c.*, t.nombre as tienda_nombre 
            FROM celulares c 
            LEFT JOIN tiendas t ON c.tienda_id = t.id 
            WHERE c.estado = 'disponible' AND c.tienda_id = ? 
            ORDER BY c.fecha_registro DESC
        ";
        $devices_stmt = $db->prepare($devices_query);
        $devices_stmt->execute([$user['tienda_id']]);
    }
    $available_devices = $devices_stmt->fetchAll();
} catch(Exception $e) {
    logError("Error al obtener dispositivos disponibles: " . $e->getMessage());
}

// Obtener ventas (según permisos)
$recent_sales = [];
try {
    if (hasPermission('view_all_sales')) {
        // Admin ve todas las ventas
        $sales_query = "
            SELECT v.*, c.modelo, c.marca, c.capacidad, c.imei1, 
                   t.nombre as tienda_nombre, u.nombre as vendedor_nombre
            FROM ventas v
            LEFT JOIN celulares c ON v.celular_id = c.id
            LEFT JOIN tiendas t ON v.tienda_id = t.id  
            LEFT JOIN usuarios u ON v.vendedor_id = u.id
            ORDER BY v.fecha_venta DESC
            LIMIT 20
        ";
        $sales_stmt = $db->query($sales_query);
    } else {
        // Vendedor solo ve ventas de su tienda
        $sales_query = "
            SELECT v.*, c.modelo, c.marca, c.capacidad, c.imei1, 
                   t.nombre as tienda_nombre, u.nombre as vendedor_nombre
            FROM ventas v
            LEFT JOIN celulares c ON v.celular_id = c.id
            LEFT JOIN tiendas t ON v.tienda_id = t.id  
            LEFT JOIN usuarios u ON v.vendedor_id = u.id
            WHERE v.tienda_id = ?
            ORDER BY v.fecha_venta DESC
            LIMIT 20
        ";
        $sales_stmt = $db->prepare($sales_query);
        $sales_stmt->execute([$user['tienda_id']]);
    }
    $recent_sales = $sales_stmt->fetchAll();
} catch(Exception $e) {
    logError("Error al obtener ventas recientes: " . $e->getMessage());
}

// Estadísticas del día para motivar al vendedor
$today_stats = ['ventas' => 0, 'ingresos' => 0];
try {
    $today = date('Y-m-d');
    if (hasPermission('view_all_sales')) {
        $stats_query = "SELECT COUNT(*) as ventas, COALESCE(SUM(precio_venta), 0) as ingresos 
                       FROM ventas WHERE DATE(fecha_venta) = ?";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->execute([$today]);
    } else {
        $stats_query = "SELECT COUNT(*) as ventas, COALESCE(SUM(precio_venta), 0) as ingresos 
                       FROM ventas WHERE DATE(fecha_venta) = ? AND tienda_id = ?";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->execute([$today, $user['tienda_id']]);
    }
    $today_stats = $stats_stmt->fetch();
} catch(Exception $e) {
    // Mantener valores por defecto
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        .modal { display: none; }
        .modal.show { display: flex; }
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) {
            .sidebar-hidden { transform: translateX(-100%); }
        }
        .device-card { 
            transition: all 0.2s ease; 
            cursor: pointer;
        }
        .device-card:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
        }
        .device-selected { 
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-color: #22c55e; 
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1); 
        }
        .stats-card {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }
        .quick-sale-hint {
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
                        <span class="ml-2 px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">
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
                    
                    <a href="inventory.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                        Inventario
                        <?php if ($user['rol'] === 'vendedor'): ?>
                            <span class="ml-auto text-xs text-gray-500">(Consulta)</span>
                        <?php endif; ?>
                    </a>
                    
                    <a href="sales.php" class="flex items-center px-4 py-2 text-gray-700 bg-green-50 border-r-4 border-green-500 rounded-l">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                        Ventas
                    </a>
                    
                    <a href="reports.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        Reportes
                        <?php if ($user['rol'] === 'vendedor'): ?>
                            <span class="ml-auto text-xs text-gray-500">(Su tienda)</span>
                        <?php endif; ?>
                    </a>
                    
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
                <!-- Header con estadísticas del día -->
                <div class="mb-6">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
                        <div>
                            <h2 class="text-3xl font-bold text-gray-900">Centro de Ventas</h2>
                            <p class="text-gray-600">
                                <?php if ($user['rol'] === 'admin'): ?>
                                    Gestión global de ventas y dispositivos
                                <?php else: ?>
                                    Registra ventas de dispositivos de <?php echo htmlspecialchars($user['tienda_nombre']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <!-- Estadísticas del día -->
                        <div class="stats-card text-white p-4 rounded-lg mt-4 md:mt-0">
                            <div class="text-center">
                                <p class="text-sm opacity-90">Hoy</p>
                                <p class="text-2xl font-bold"><?php echo $today_stats['ventas']; ?> ventas</p>
                                <p class="text-sm opacity-90">$<?php echo number_format($today_stats['ingresos'], 2); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Consejo rápido -->
                    <div class="quick-sale-hint p-4 rounded-lg">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-amber-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div>
                                <p class="font-medium text-amber-800">Proceso de venta rápido:</p>
                                <p class="text-sm text-amber-700">1. Selecciona un dispositivo disponible → 2. Completa los datos del cliente → 3. Confirma la venta</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Dispositivos Disponibles -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-900">
                                Dispositivos Disponibles
                                <?php if ($user['rol'] === 'vendedor'): ?>
                                    <span class="text-sm font-normal text-gray-500">- <?php echo htmlspecialchars($user['tienda_nombre']); ?></span>
                                <?php endif; ?>
                            </h3>
                            <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                <?php echo count($available_devices); ?> disponibles
                            </span>
                        </div>
                        <div class="p-6 max-h-96 overflow-y-auto">
                            <?php if (empty($available_devices)): ?>
                                <div class="text-center py-8">
                                    <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                    </svg>
                                    <p class="text-gray-500 font-medium">No hay dispositivos disponibles</p>
                                    <p class="text-sm text-gray-400 mt-1">
                                        <?php if ($user['rol'] === 'admin'): ?>
                                            Ve a <a href="inventory.php" class="text-blue-600 underline">Inventario</a> para agregar dispositivos
                                        <?php else: ?>
                                            Contacta al administrador para agregar dispositivos a tu tienda
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach($available_devices as $device): ?>
                                        <div class="device-card border rounded-lg p-4 hover:border-green-300 transition-all duration-200" 
                                             onclick="selectDeviceForSale(<?php echo htmlspecialchars(json_encode($device)); ?>)"
                                             data-device-id="<?php echo $device['id']; ?>">
                                            <div class="flex justify-between items-start">
                                                <div class="flex-1">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars($device['modelo']); ?></p>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                            Disponible
                                                        </span>
                                                    </div>
                                                    <p class="text-sm text-gray-600 mb-1">
                                                        <?php echo htmlspecialchars($device['marca']); ?> - <?php echo htmlspecialchars($device['capacidad']); ?>
                                                        <?php if ($device['color']): ?> - <?php echo htmlspecialchars($device['color']); ?><?php endif; ?>
                                                    </p>
                                                    <p class="text-xs text-gray-500"><?php echo ucfirst($device['condicion']); ?></p>
                                                    <?php if (hasPermission('view_all_sales')): ?>
                                                        <p class="text-xs text-blue-600 mt-1"><?php echo htmlspecialchars($device['tienda_nombre']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-right ml-4">
                                                    <p class="font-bold text-lg text-green-600">$<?php echo number_format($device['precio'], 2); ?></p>
                                                    <?php if ($device['precio_compra'] && hasPermission('view_all_sales')): ?>
                                                        <p class="text-xs text-gray-500">Ganancia: $<?php echo number_format($device['precio'] - $device['precio_compra'], 2); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Ventas Recientes -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-900">Ventas Recientes</h3>
                            <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                Últimas <?php echo count($recent_sales); ?> ventas
                            </span>
                        </div>
                        <div class="p-6 max-h-96 overflow-y-auto">
                            <?php if (empty($recent_sales)): ?>
                                <div class="text-center py-8">
                                    <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                                    </svg>
                                    <p class="text-gray-500 font-medium">No hay ventas registradas</p>
                                    <p class="text-sm text-gray-400 mt-1">¡Registra tu primera venta!</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach($recent_sales as $sale): ?>
                                        <div class="border-l-4 border-green-400 bg-green-50 p-4 rounded-r-lg">
                                            <div class="flex justify-between items-start">
                                                <div class="flex-1">
                                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($sale['modelo']); ?></p>
                                                    <p class="text-sm text-gray-600 mb-1">
                                                        Cliente: <?php echo htmlspecialchars($sale['cliente_nombre']); ?>
                                                    </p>
                                                    <div class="flex items-center gap-2 text-xs text-gray-500">
                                                        <span><?php echo date('d/m/Y H:i', strtotime($sale['fecha_venta'])); ?></span>
                                                        <?php if (hasPermission('view_all_sales')): ?>
                                                            <span>•</span>
                                                            <span><?php echo htmlspecialchars($sale['tienda_nombre']); ?></span>
                                                        <?php endif; ?>
                                                        <span>•</span>
                                                        <span><?php echo htmlspecialchars($sale['vendedor_nombre']); ?></span>
                                                    </div>
                                                </div>
                                                <div class="text-right ml-4">
                                                    <p class="font-bold text-lg text-green-600">$<?php echo number_format($sale['precio_venta'], 2); ?></p>
                                                    <p class="text-xs text-gray-500"><?php echo ucfirst($sale['metodo_pago']); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Overlay para móvil -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 md:hidden hidden"></div>

    <!-- Modal Registrar Venta -->
    <div id="saleModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-lg mx-4 max-h-screen overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-semibold text-gray-900">Registrar Venta</h3>
                <button onclick="closeSaleModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form id="saleForm" class="space-y-4">
                <input type="hidden" id="selectedDeviceId">
                
                <!-- Info del dispositivo -->
                <div id="deviceInfo" class="bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 p-4 rounded-lg hidden">
                    <div class="flex items-center gap-3">
                        <div class="bg-green-100 p-2 rounded-lg">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="font-semibold text-gray-900" id="deviceModel"></p>
                            <p class="text-sm text-gray-600" id="deviceDetails"></p>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-green-600" id="devicePrice"></p>
                        </div>
                    </div>
                </div>
                
                <div class="border-t pt-4">
                    <h4 class="font-medium text-gray-900 mb-3">Información del Cliente</h4>
                    
                    <!-- Datos del cliente -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del Cliente *</label>
                            <input type="text" id="cliente_nombre" required 
                                   placeholder="Nombre completo del cliente"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                                <input type="tel" id="cliente_telefono" 
                                       placeholder="Número de contacto"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input type="email" id="cliente_email" 
                                       placeholder="correo@ejemplo.com"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="border-t pt-4">
                    <h4 class="font-medium text-gray-900 mb-3">Detalles de la Venta</h4>
                    
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Precio de Venta *</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-2 text-gray-500">$</span>
                                    <input type="number" id="precio_venta" step="0.01" required 
                                           placeholder="0.00"
                                           class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Método de Pago</label>
                                <select id="metodo_pago" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                                    <option value="efectivo">💵 Efectivo</option>
                                    <option value="tarjeta">💳 Tarjeta</option>
                                    <option value="transferencia">🏦 Transferencia</option>
                                    <option value="credito">📝 Crédito</option>
                                </select>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notas <span class="text-gray-400">(opcional)</span></label>
                            <textarea id="sale_notas" rows="3" 
                                      placeholder="Observaciones adicionales sobre la venta, garantía, accesorios incluidos, etc..."
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 pt-4 border-t">
                    <button type="button" onclick="closeSaleModal()" 
                            class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        Cancelar
                    </button>
                    <button type="button" onclick="registerSale()" 
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors flex items-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Confirmar Venta
                    </button>
                </div>
            </form>
        </div>
    </div>

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

        let selectedDevice = null;

        function openSaleModal() {
            if (!selectedDevice) {
                showNotification('⚠️ Primero selecciona un dispositivo de la lista haciendo clic sobre él', 'warning');
                return;
            }
            document.getElementById('saleModal').classList.add('show');
            document.getElementById('cliente_nombre').focus();
        }

        function closeSaleModal() {
            document.getElementById('saleModal').classList.remove('show');
            clearSaleForm();
            clearDeviceSelection();
        }

        function selectDeviceForSale(device) {
            selectedDevice = device;
            
            // Limpiar selección previa
            document.querySelectorAll('.device-card').forEach(el => {
                el.classList.remove('device-selected');
            });
            
            // Marcar dispositivo seleccionado
            const selectedCard = document.querySelector(`[data-device-id="${device.id}"]`);
            if (selectedCard) {
                selectedCard.classList.add('device-selected');
            }
            
            // Mostrar info del dispositivo en el modal
            document.getElementById('selectedDeviceId').value = device.id;
            document.getElementById('deviceModel').textContent = device.modelo;
            document.getElementById('deviceDetails').textContent = `${device.marca} - ${device.capacidad}${device.color ? ' - ' + device.color : ''}`;
            document.getElementById('devicePrice').textContent = `${parseFloat(device.precio).toLocaleString('es-ES', {minimumFractionDigits: 2})}`;
            document.getElementById('deviceInfo').classList.remove('hidden');
            
            // Pre-llenar precio de venta
            document.getElementById('precio_venta').value = device.precio;
            
            // Auto-abrir modal
            openSaleModal();
        }

        function clearDeviceSelection() {
            selectedDevice = null;
            document.querySelectorAll('.device-card').forEach(el => {
                el.classList.remove('device-selected');
            });
            document.getElementById('deviceInfo').classList.add('hidden');
        }

        function clearSaleForm() {
            document.getElementById('cliente_nombre').value = '';
            document.getElementById('cliente_telefono').value = '';
            document.getElementById('cliente_email').value = '';
            document.getElementById('precio_venta').value = '';
            document.getElementById('metodo_pago').value = 'efectivo';
            document.getElementById('sale_notas').value = '';
        }

        function registerSale() {
            if (!selectedDevice) {
                showNotification('❌ No se ha seleccionado un dispositivo', 'error');
                return;
            }
            
            const cliente_nombre = document.getElementById('cliente_nombre').value.trim();
            const precio_venta = document.getElementById('precio_venta').value;
            
            if (!cliente_nombre) {
                showNotification('⚠️ Por favor ingresa el nombre del cliente', 'warning');
                document.getElementById('cliente_nombre').focus();
                return;
            }
            
            if (!precio_venta || precio_venta <= 0) {
                showNotification('⚠️ Por favor ingresa un precio de venta válido', 'warning');
                document.getElementById('precio_venta').focus();
                return;
            }
            
            // Confirmar venta
            const confirmMessage = `¿Confirmar venta?\n\nDispositivo: ${selectedDevice.modelo}\nCliente: ${cliente_nombre}\nPrecio: ${precio_venta}`;
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'register_sale');
            formData.append('celular_id', selectedDevice.id);
            formData.append('cliente_nombre', cliente_nombre);
            formData.append('cliente_telefono', document.getElementById('cliente_telefono').value);
            formData.append('cliente_email', document.getElementById('cliente_email').value);
            formData.append('precio_venta', precio_venta);
            formData.append('metodo_pago', document.getElementById('metodo_pago').value);
            formData.append('notas', document.getElementById('sale_notas').value);
            
            const button = event.target;
            const originalText = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<svg class="w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>Procesando...';
            
            fetch('sales.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('🎉 ' + data.message, 'success');
                    
                    // Limpiar formulario y cerrar modal
                    clearSaleForm();
                    closeSaleModal();
                    
                    // Recargar página después de un momento para ver la nueva venta
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification('❌ ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('❌ Error en la conexión. Por favor intenta nuevamente.', 'error');
            })
            .finally(() => {
                button.disabled = false;
                button.innerHTML = originalText;
            });
        }

        // Sistema de notificaciones mejorado
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            const bgColors = {
                'success': 'bg-green-500',
                'error': 'bg-red-500', 
                'warning': 'bg-yellow-500',
                'info': 'bg-blue-500'
            };
            
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm transition-all duration-300 transform translate-x-full ${bgColors[type]} text-white`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <div class="flex-1">${message}</div>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white/80 hover:text-white">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Animar entrada
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                notification.classList.add('translate-x-full');
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.remove();
                    }
                }, 300);
            }, 5000);
        }

        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSaleModal();
            }
        });

        // Validación en tiempo real del precio
        document.getElementById('precio_venta').addEventListener('input', function() {
            const value = parseFloat(this.value);
            if (value < 0) {
                this.value = 0;
            }
        });

        // Auto-completar email basado en el nombre (sugerencia)
        document.getElementById('cliente_nombre').addEventListener('blur', function() {
            const nombre = this.value.trim();
            const emailField = document.getElementById('cliente_email');
            
            if (nombre && !emailField.value) {
                // Sugerir email basado en el nombre (solo como placeholder)
                const sugerencia = nombre.toLowerCase().replace(/\s+/g, '.') + '@ejemplo.com';
                emailField.placeholder = `Ej: ${sugerencia}`;
            }
        });

        // Mostrar mensaje de bienvenida para nuevos vendedores
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($user['rol'] === 'vendedor' && count($recent_sales) === 0): ?>
                setTimeout(() => {
                    showNotification('👋 ¡Bienvenido al sistema de ventas! Selecciona un dispositivo para registrar tu primera venta.', 'info');
                }, 1000);
            <?php endif; ?>
        });
    </script>
</body>
</html>