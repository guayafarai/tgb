<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

setSecurityHeaders();
startSecureSession();
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Procesar venta de productos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'register_product_sale':
                $producto_id = intval($_POST['producto_id']);
                $tienda_id = intval($_POST['tienda_id']);
                $cantidad = intval($_POST['cantidad']);
                $precio_unitario = floatval($_POST['precio_unitario']);
                $descuento = floatval($_POST['descuento']) ?: 0;
                $cliente_nombre = sanitize($_POST['cliente_nombre']);
                $cliente_telefono = sanitize($_POST['cliente_telefono']);
                $cliente_email = sanitize($_POST['cliente_email']);
                $metodo_pago = $_POST['metodo_pago'];
                $notas = sanitize($_POST['notas']);
                
                // Verificar permisos de tienda
                if (!hasPermission('admin') && $tienda_id != $user['tienda_id']) {
                    throw new Exception('Sin permisos para vender en esta tienda');
                }
                
                if ($cantidad <= 0) {
                    throw new Exception('La cantidad debe ser mayor a cero');
                }
                
                if ($precio_unitario <= 0) {
                    throw new Exception('El precio debe ser mayor a cero');
                }
                
                // Verificar stock disponible
                $stock_stmt = $db->prepare("
                    SELECT cantidad_actual FROM stock_productos 
                    WHERE producto_id = ? AND tienda_id = ?
                ");
                $stock_stmt->execute([$producto_id, $tienda_id]);
                $stock_data = $stock_stmt->fetch();
                
                if (!$stock_data || $stock_data['cantidad_actual'] < $cantidad) {
                    throw new Exception('Stock insuficiente para realizar la venta');
                }
                
                $precio_total = ($precio_unitario * $cantidad) - $descuento;
                
                if ($precio_total < 0) {
                    throw new Exception('El descuento no puede ser mayor al total');
                }
                
                $db->beginTransaction();
                
                // Registrar venta
                $venta_stmt = $db->prepare("
                    INSERT INTO ventas_productos (producto_id, tienda_id, vendedor_id, cantidad, 
                                                precio_unitario, precio_total, descuento, cliente_nombre, 
                                                cliente_telefono, cliente_email, metodo_pago, notas) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $result = $venta_stmt->execute([
                    $producto_id, $tienda_id, $user['id'], $cantidad,
                    $precio_unitario, $precio_total, $descuento, $cliente_nombre,
                    $cliente_telefono, $cliente_email, $metodo_pago, $notas
                ]);
                
                if (!$result) {
                    throw new Exception('Error al registrar la venta');
                }
                
                $db->commit();
                
                logActivity($user['id'], 'product_sale', 
                    "Venta de producto - ID: $producto_id, Cantidad: $cantidad, Total: $precio_total");
                
                echo json_encode(['success' => true, 'message' => 'Venta registrada correctamente']);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
        
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        logError("Error en venta de productos: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Obtener productos disponibles para venta
$productos_disponibles = [];
try {
    if (hasPermission('admin')) {
        $productos_query = "
            SELECT p.*, c.nombre as categoria_nombre, s.cantidad_actual, s.tienda_id, t.nombre as tienda_nombre
            FROM productos p
            LEFT JOIN categorias_productos c ON p.categoria_id = c.id
            LEFT JOIN stock_productos s ON p.id = s.producto_id
            LEFT JOIN tiendas t ON s.tienda_id = t.id
            WHERE p.activo = 1 AND s.cantidad_actual > 0
            ORDER BY p.nombre, t.nombre
        ";
        $productos_stmt = $db->query($productos_query);
    } else {
        $productos_query = "
            SELECT p.*, c.nombre as categoria_nombre, s.cantidad_actual, s.tienda_id, t.nombre as tienda_nombre
            FROM productos p
            LEFT JOIN categorias_productos c ON p.categoria_id = c.id
            LEFT JOIN stock_productos s ON p.id = s.producto_id
            LEFT JOIN tiendas t ON s.tienda_id = t.id
            WHERE p.activo = 1 AND s.cantidad_actual > 0 AND s.tienda_id = ?
            ORDER BY p.nombre
        ";
        $productos_stmt = $db->prepare($productos_query);
        $productos_stmt->execute([$user['tienda_id']]);
    }
    $productos_disponibles = $productos_stmt->fetchAll();
    
    // Obtener ventas recientes
    if (hasPermission('admin')) {
        $ventas_query = "
            SELECT vp.*, p.nombre as producto_nombre, p.codigo_producto, t.nombre as tienda_nombre, 
                   u.nombre as vendedor_nombre
            FROM ventas_productos vp
            LEFT JOIN productos p ON vp.producto_id = p.id
            LEFT JOIN tiendas t ON vp.tienda_id = t.id
            LEFT JOIN usuarios u ON vp.vendedor_id = u.id
            ORDER BY vp.fecha_venta DESC
            LIMIT 20
        ";
        $ventas_stmt = $db->query($ventas_query);
    } else {
        $ventas_query = "
            SELECT vp.*, p.nombre as producto_nombre, p.codigo_producto, t.nombre as tienda_nombre, 
                   u.nombre as vendedor_nombre
            FROM ventas_productos vp
            LEFT JOIN productos p ON vp.producto_id = p.id
            LEFT JOIN tiendas t ON vp.tienda_id = t.id
            LEFT JOIN usuarios u ON vp.vendedor_id = u.id
            WHERE vp.tienda_id = ?
            ORDER BY vp.fecha_venta DESC
            LIMIT 20
        ";
        $ventas_stmt = $db->prepare($ventas_query);
        $ventas_stmt->execute([$user['tienda_id']]);
    }
    $ventas_recientes = $ventas_stmt->fetchAll();
    
    // Estadísticas del día
    $hoy = date('Y-m-d');
    if (hasPermission('admin')) {
        $stats_query = "
            SELECT COUNT(*) as ventas_hoy, COALESCE(SUM(precio_total), 0) as ingresos_hoy,
                   SUM(cantidad) as unidades_vendidas_hoy
            FROM ventas_productos WHERE DATE(fecha_venta) = ?
        ";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->execute([$hoy]);
    } else {
        $stats_query = "
            SELECT COUNT(*) as ventas_hoy, COALESCE(SUM(precio_total), 0) as ingresos_hoy,
                   SUM(cantidad) as unidades_vendidas_hoy
            FROM ventas_productos WHERE DATE(fecha_venta) = ? AND tienda_id = ?
        ";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->execute([$hoy, $user['tienda_id']]);
    }
    $estadisticas_hoy = $stats_stmt->fetch();
    
} catch(Exception $e) {
    logError("Error al obtener datos de ventas: " . $e->getMessage());
    $productos_disponibles = [];
    $ventas_recientes = [];
    $estadisticas_hoy = ['ventas_hoy' => 0, 'ingresos_hoy' => 0, 'unidades_vendidas_hoy' => 0];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas de Productos - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        .modal { display: none; }
        .modal.show { display: flex; }
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) {
            .sidebar-hidden { transform: translateX(-100%); }
        }
        .product-card { 
            transition: all 0.2s ease; 
            cursor: pointer;
        }
        .product-card:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
        }
        .product-selected { 
            background: linear-gradient(135deg, #fdf4ff 0%, #f3e8ff 100%);
            border-color: #a855f7; 
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.1); 
        }
        .stats-card {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
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
                        Celulares
                    </a>
                    
                    <a href="products.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        Productos
                    </a>
                    
                    <a href="sales.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                        Ventas Celulares
                    </a>

                    <a href="product_sales.php" class="flex items-center px-4 py-2 text-gray-700 bg-purple-50 border-r-4 border-purple-500 rounded-l">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                        </svg>
                        Ventas Productos
                    </a>
                    
                    <a href="reports.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        Reportes
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
                            <h2 class="text-3xl font-bold text-gray-900">Ventas de Productos</h2>
                            <p class="text-gray-600">Accesorios y repuestos para celulares</p>
                        </div>
                        
                        <!-- Estadísticas del día -->
                        <div class="stats-card text-white p-4 rounded-lg mt-4 md:mt-0">
                            <div class="text-center">
                                <p class="text-sm opacity-90">Ventas de Hoy</p>
                                <p class="text-2xl font-bold"><?php echo $estadisticas_hoy['ventas_hoy']; ?> ventas</p>
                                <p class="text-sm opacity-90">$<?php echo number_format($estadisticas_hoy['ingresos_hoy'], 2); ?></p>
                                <p class="text-xs opacity-75"><?php echo $estadisticas_hoy['unidades_vendidas_hoy']; ?> unidades</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Consejo rápido -->
                    <div class="bg-gradient-to-r from-purple-50 to-indigo-50 border border-purple-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-purple-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div>
                                <p class="font-medium text-purple-800">Venta de productos:</p>
                                <p class="text-sm text-purple-700">1. Selecciona un producto disponible → 2. Ajusta cantidad y precio → 3. Completa datos del cliente → 4. Confirma la venta</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Productos Disponibles -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-900">
                                Productos Disponibles
                                <?php if ($user['rol'] === 'vendedor'): ?>
                                    <span class="text-sm font-normal text-gray-500">- <?php echo htmlspecialchars($user['tienda_nombre']); ?></span>
                                <?php endif; ?>
                            </h3>
                            <span class="bg-purple-100 text-purple-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                <?php echo count($productos_disponibles); ?> disponibles
                            </span>
                        </div>
                        <div class="p-6 max-h-96 overflow-y-auto">
                            <?php if (empty($productos_disponibles)): ?>
                                <div class="text-center py-8">
                                    <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                    <p class="text-gray-500 font-medium">No hay productos con stock</p>
                                    <p class="text-sm text-gray-400 mt-1">
                                        <?php if ($user['rol'] === 'admin'): ?>
                                            Ve a <a href="products.php" class="text-purple-600 underline">Productos</a> para agregar stock
                                        <?php else: ?>
                                            Contacta al administrador para reponer stock
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach($productos_disponibles as $producto): ?>
                                        <div class="product-card border rounded-lg p-4 hover:border-purple-300 transition-all duration-200" 
                                             onclick="selectProductForSale(<?php echo htmlspecialchars(json_encode($producto)); ?>)"
                                             data-product-id="<?php echo $producto['id']; ?>-<?php echo $producto['tienda_id']; ?>">
                                            <div class="flex justify-between items-start">
                                                <div class="flex-1">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <span class="text-lg">
                                                            <?php echo $producto['tipo'] === 'accesorio' ? '📱' : '🔧'; ?>
                                                        </span>
                                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars($producto['nombre']); ?></p>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                            Stock: <?php echo $producto['cantidad_actual']; ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <?php if ($producto['codigo_producto']): ?>
                                                        <p class="text-xs text-gray-500 font-mono bg-gray-100 inline-block px-2 py-1 rounded mb-1">
                                                            <?php echo htmlspecialchars($producto['codigo_producto']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    
                                                    <div class="flex flex-wrap gap-2 text-xs text-gray-600">
                                                        <?php if ($producto['marca']): ?>
                                                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded">
                                                                <?php echo htmlspecialchars($producto['marca']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($producto['categoria_nombre']): ?>
                                                            <span class="bg-gray-100 text-gray-800 px-2 py-1 rounded">
                                                                <?php echo htmlspecialchars($producto['categoria_nombre']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <?php if ($producto['modelo_compatible']): ?>
                                                        <p class="text-xs text-gray-500 mt-1">
                                                            Compatible: <?php echo htmlspecialchars($producto['modelo_compatible']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (hasPermission('admin')): ?>
                                                        <p class="text-xs text-blue-600 mt-1"><?php echo htmlspecialchars($producto['tienda_nombre']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="text-right ml-4">
                                                    <p class="font-bold text-lg text-purple-600">$<?php echo number_format($producto['precio_venta'], 2); ?></p>
                                                    <p class="text-xs text-gray-500">por unidad</p>
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
                                Últimas <?php echo count($ventas_recientes); ?> ventas
                            </span>
                        </div>
                        <div class="p-6 max-h-96 overflow-y-auto">
                            <?php if (empty($ventas_recientes)): ?>
                                <div class="text-center py-8">
                                    <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                    </svg>
                                    <p class="text-gray-500 font-medium">No hay ventas registradas</p>
                                    <p class="text-sm text-gray-400 mt-1">¡Registra tu primera venta de productos!</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach($ventas_recientes as $venta): ?>
                                        <div class="border-l-4 border-purple-400 bg-purple-50 p-4 rounded-r-lg">
                                            <div class="flex justify-between items-start">
                                                <div class="flex-1">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars($venta['producto_nombre']); ?></p>
                                                        <span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded">
                                                            <?php echo $venta['cantidad']; ?> ud.
                                                        </span>
                                                    </div>
                                                    
                                                    <p class="text-sm text-gray-600 mb-1">
                                                        Cliente: <?php echo htmlspecialchars($venta['cliente_nombre']); ?>
                                                    </p>
                                                    
                                                    <div class="flex items-center gap-2 text-xs text-gray-500">
                                                        <span><?php echo date('d/m/Y H:i', strtotime($venta['fecha_venta'])); ?></span>
                                                        <?php if (hasPermission('admin')): ?>
                                                            <span>•</span>
                                                            <span><?php echo htmlspecialchars($venta['tienda_nombre']); ?></span>
                                                        <?php endif; ?>
                                                        <span>•</span>
                                                        <span><?php echo htmlspecialchars($venta['vendedor_nombre']); ?></span>
                                                        <span>•</span>
                                                        <span><?php echo ucfirst($venta['metodo_pago']); ?></span>
                                                    </div>
                                                    
                                                    <?php if ($venta['descuento'] > 0): ?>
                                                        <p class="text-xs text-orange-600 mt-1">
                                                            Descuento aplicado: $<?php echo number_format($venta['descuento'], 2); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="text-right ml-4">
                                                    <p class="font-bold text-lg text-purple-600">$<?php echo number_format($venta['precio_total'], 2); ?></p>
                                                    <p class="text-xs text-gray-500">
                                                        $<?php echo number_format($venta['precio_unitario'], 2); ?> c/u
                                                    </p>
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

    <!-- Modal Registrar Venta de Producto -->
    <div id="productSaleModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-lg mx-4 max-h-screen overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-semibold text-gray-900">Registrar Venta de Producto</h3>
                <button onclick="closeProductSaleModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form id="productSaleForm" class="space-y-4">
                <input type="hidden" id="selectedProductId">
                <input type="hidden" id="selectedTiendaId">
                
                <!-- Info del producto -->
                <div id="productInfo" class="bg-gradient-to-r from-purple-50 to-indigo-50 border border-purple-200 p-4 rounded-lg hidden">
                    <div class="flex items-center gap-3">
                        <div class="bg-purple-100 p-2 rounded-lg">
                            <span id="productIcon" class="text-lg">📱</span>
                        </div>
                        <div class="flex-1">
                            <p class="font-semibold text-gray-900" id="productName"></p>
                            <p class="text-sm text-gray-600" id="productDetails"></p>
                            <p class="text-xs text-purple-600" id="productStock"></p>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-purple-600" id="productPrice"></p>
                        </div>
                    </div>
                </div>
                
                <div class="border-t pt-4">
                    <h4 class="font-medium text-gray-900 mb-3">Detalles de la Venta</h4>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad *</label>
                            <input type="number" id="cantidad" min="1" required 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Precio Unitario *</label>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-gray-500">$</span>
                                <input type="number" id="precio_unitario" step="0.01" required 
                                       class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Descuento</label>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-gray-500">$</span>
                                <input type="number" id="descuento" step="0.01" min="0" value="0"
                                       class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Método de Pago</label>
                            <select id="metodo_pago" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="tarjeta">💳 Tarjeta</option>
                                <option value="transferencia">🏦 Transferencia</option>
                                <option value="credito">📝 Crédito</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Total calculado -->
                    <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                        <div class="flex justify-between items-center">
                            <span class="font-medium text-green-800">Total a Pagar:</span>
                            <span id="totalCalculado" class="text-xl font-bold text-green-700">$0.00</span>
                        </div>
                    </div>
                </div>
                
                <div class="border-t pt-4">
                    <h4 class="font-medium text-gray-900 mb-3">Información del Cliente</h4>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del Cliente *</label>
                            <input type="text" id="cliente_nombre" required 
                                   placeholder="Nombre completo del cliente"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                                <input type="tel" id="cliente_telefono" 
                                       placeholder="Número de contacto"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input type="email" id="cliente_email" 
                                       placeholder="correo@ejemplo.com"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notas <span class="text-gray-400">(opcional)</span></label>
                            <textarea id="notas" rows="2" 
                                      placeholder="Observaciones adicionales sobre la venta..."
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end gap-3 pt-4 border-t">
                    <button type="button" onclick="closeProductSaleModal()" 
                            class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        Cancelar
                    </button>
                    <button type="button" onclick="registerProductSale()" 
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition-colors flex items-center">
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

        let selectedProduct = null;

        function selectProductForSale(product) {
            selectedProduct = product;
            
            // Limpiar selección previa
            document.querySelectorAll('.product-card').forEach(el => {
                el.classList.remove('product-selected');
            });
            
            // Marcar producto seleccionado
            const selectedCard = document.querySelector(`[data-product-id="${product.id}-${product.tienda_id}"]`);
            if (selectedCard) {
                selectedCard.classList.add('product-selected');
            }
            
            // Mostrar info del producto en el modal
            document.getElementById('selectedProductId').value = product.id;
            document.getElementById('selectedTiendaId').value = product.tienda_id;
            document.getElementById('productIcon').textContent = product.tipo === 'accesorio' ? '📱' : '🔧';
            document.getElementById('productName').textContent = product.nombre;
            
            let details = '';
            if (product.marca) details += product.marca;
            if (product.codigo_producto) details += (details ? ' - ' : '') + product.codigo_producto;
            if (product.categoria_nombre) details += (details ? ' - ' : '') + product.categoria_nombre;
            document.getElementById('productDetails').textContent = details;
            
            document.getElementById('productStock').textContent = `Stock disponible: ${product.cantidad_actual} unidades`;
            document.getElementById('productPrice').textContent = `${parseFloat(product.precio_venta).toFixed(2)}`;
            document.getElementById('productInfo').classList.remove('hidden');
            
            // Pre-llenar valores
            document.getElementById('cantidad').value = 1;
            document.getElementById('cantidad').max = product.cantidad_actual;
            document.getElementById('precio_unitario').value = product.precio_venta;
            
            // Auto-abrir modal
            openProductSaleModal();
        }

        function openProductSaleModal() {
            if (!selectedProduct) {
                showNotification('⚠️ Primero selecciona un producto de la lista haciendo clic sobre él', 'warning');
                return;
            }
            document.getElementById('productSaleModal').classList.add('show');
            document.getElementById('cantidad').focus();
            calculateTotal();
        }

        function closeProductSaleModal() {
            document.getElementById('productSaleModal').classList.remove('show');
            clearSaleForm();
            clearProductSelection();
        }

        function clearProductSelection() {
            selectedProduct = null;
            document.querySelectorAll('.product-card').forEach(el => {
                el.classList.remove('product-selected');
            });
            document.getElementById('productInfo').classList.add('hidden');
        }

        function clearSaleForm() {
            document.getElementById('cantidad').value = '';
            document.getElementById('precio_unitario').value = '';
            document.getElementById('descuento').value = '0';
            document.getElementById('cliente_nombre').value = '';
            document.getElementById('cliente_telefono').value = '';
            document.getElementById('cliente_email').value = '';
            document.getElementById('metodo_pago').value = 'efectivo';
            document.getElementById('notas').value = '';
            document.getElementById('totalCalculado').textContent = '$0.00';
        }

        function calculateTotal() {
            const cantidad = parseFloat(document.getElementById('cantidad').value) || 0;
            const precio = parseFloat(document.getElementById('precio_unitario').value) || 0;
            const descuento = parseFloat(document.getElementById('descuento').value) || 0;
            
            const subtotal = cantidad * precio;
            const total = Math.max(0, subtotal - descuento);
            
            document.getElementById('totalCalculado').textContent = `${total.toFixed(2)}`;
            
            // Validar descuento
            if (descuento > subtotal) {
                document.getElementById('descuento').style.borderColor = '#ef4444';
                showNotification('⚠️ El descuento no puede ser mayor al subtotal', 'warning');
            } else {
                document.getElementById('descuento').style.borderColor = '#d1d5db';
            }
        }

        function registerProductSale() {
            if (!selectedProduct) {
                showNotification('❌ No se ha seleccionado un producto', 'error');
                return;
            }
            
            const cantidad = parseInt(document.getElementById('cantidad').value);
            const precio_unitario = parseFloat(document.getElementById('precio_unitario').value);
            const descuento = parseFloat(document.getElementById('descuento').value) || 0;
            const cliente_nombre = document.getElementById('cliente_nombre').value.trim();
            
            // Validaciones
            if (!cantidad || cantidad <= 0) {
                showNotification('⚠️ Por favor ingresa una cantidad válida', 'warning');
                document.getElementById('cantidad').focus();
                return;
            }
            
            if (cantidad > selectedProduct.cantidad_actual) {
                showNotification(`⚠️ Stock insuficiente. Disponible: ${selectedProduct.cantidad_actual}`, 'warning');
                document.getElementById('cantidad').focus();
                return;
            }
            
            if (!precio_unitario || precio_unitario <= 0) {
                showNotification('⚠️ Por favor ingresa un precio válido', 'warning');
                document.getElementById('precio_unitario').focus();
                return;
            }
            
            if (!cliente_nombre) {
                showNotification('⚠️ Por favor ingresa el nombre del cliente', 'warning');
                document.getElementById('cliente_nombre').focus();
                return;
            }
            
            const subtotal = cantidad * precio_unitario;
            if (descuento > subtotal) {
                showNotification('⚠️ El descuento no puede ser mayor al subtotal', 'warning');
                document.getElementById('descuento').focus();
                return;
            }
            
            const total = subtotal - descuento;
            
            // Confirmar venta
            const confirmMessage = `¿Confirmar venta?\n\nProducto: ${selectedProduct.nombre}\nCantidad: ${cantidad}\nPrecio unitario: ${precio_unitario.toFixed(2)}\nTotal: ${total.toFixed(2)}\nCliente: ${cliente_nombre}`;
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'register_product_sale');
            formData.append('producto_id', selectedProduct.id);
            formData.append('tienda_id', selectedProduct.tienda_id);
            formData.append('cantidad', cantidad);
            formData.append('precio_unitario', precio_unitario);
            formData.append('descuento', descuento);
            formData.append('cliente_nombre', cliente_nombre);
            formData.append('cliente_telefono', document.getElementById('cliente_telefono').value);
            formData.append('cliente_email', document.getElementById('cliente_email').value);
            formData.append('metodo_pago', document.getElementById('metodo_pago').value);
            formData.append('notas', document.getElementById('notas').value);
            
            const button = event.target;
            const originalText = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<svg class="w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>Procesando...';
            
            fetch('product_sales.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('🎉 ' + data.message, 'success');
                    
                    // Limpiar formulario y cerrar modal
                    clearSaleForm();
                    closeProductSaleModal();
                    
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

        // Event listeners para cálculo automático
        document.getElementById('cantidad').addEventListener('input', calculateTotal);
        document.getElementById('precio_unitario').addEventListener('input', calculateTotal);
        document.getElementById('descuento').addEventListener('input', calculateTotal);

        // Validaciones en tiempo real
        document.getElementById('cantidad').addEventListener('input', function() {
            const max = selectedProduct ? selectedProduct.cantidad_actual : 999;
            if (this.value > max) {
                this.value = max;
                showNotification(`⚠️ Stock máximo disponible: ${max}`, 'warning');
            }
            if (this.value < 1) this.value = 1;
        });

        document.getElementById('precio_unitario').addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });

        document.getElementById('descuento').addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });

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
            
            // Auto-remove después de 5 segundos
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
                closeProductSaleModal();
            }
        });

        // Auto-completar email basado en el nombre
        document.getElementById('cliente_nombre').addEventListener('blur', function() {
            const nombre = this.value.trim();
            const emailField = document.getElementById('cliente_email');
            
            if (nombre && !emailField.value) {
                const sugerencia = nombre.toLowerCase().replace(/\s+/g, '.') + '@ejemplo.com';
                emailField.placeholder = `Ej: ${sugerencia}`;
            }
        });

        // Búsqueda rápida de productos
        let searchTimeout;
        function setupQuickSearch() {
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.placeholder = 'Buscar producto...';
            searchInput.className = 'w-full px-3 py-2 mb-4 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500';
            
            const productContainer = document.querySelector('.space-y-3');
            if (productContainer) {
                productContainer.parentNode.insertBefore(searchInput, productContainer);
                
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        const searchTerm = this.value.toLowerCase();
                        const productCards = document.querySelectorAll('.product-card');
                        
                        productCards.forEach(card => {
                            const text = card.textContent.toLowerCase();
                            const shouldShow = searchTerm === '' || text.includes(searchTerm);
                            card.style.display = shouldShow ? 'block' : 'none';
                        });
                    }, 300);
                });
            }
        }

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            setupQuickSearch();
            
            // Mostrar mensaje de bienvenida para nuevos vendedores
            <?php if ($user['rol'] === 'vendedor' && count($ventas_recientes) === 0): ?>
                setTimeout(() => {
                    showNotification('👋 ¡Bienvenido a las ventas de productos! Selecciona un producto para registrar tu primera venta.', 'info');
                }, 1000);
            <?php endif; ?>
            
            // Alerta si hay pocos productos disponibles
            <?php if (count($productos_disponibles) < 5 && count($productos_disponibles) > 0): ?>
                setTimeout(() => {
                    showNotification('⚠️ Pocos productos disponibles. Considera reponer stock.', 'warning');
                }, 2000);
            <?php endif; ?>
        });

        // Funciones de utilidad para el teclado
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + Enter para confirmar venta rápidamente
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                if (document.getElementById('productSaleModal').classList.contains('show')) {
                    registerProductSale();
                }
            }
            
            // F2 para abrir modal de venta si hay producto seleccionado
            if (e.key === 'F2' && selectedProduct) {
                openProductSaleModal();
            }
        });

        // Animaciones de carga
        function showLoadingState(element) {
            element.style.opacity = '0.6';
            element.style.pointerEvents = 'none';
        }

        function hideLoadingState(element) {
            element.style.opacity = '1';
            element.style.pointerEvents = 'auto';
        }

        // Funciones de accesibilidad
        function announceToScreenReader(message) {
            const announcement = document.createElement('div');
            announcement.setAttribute('aria-live', 'polite');
            announcement.setAttribute('aria-atomic', 'true');
            announcement.className = 'sr-only';
            announcement.textContent = message;
            document.body.appendChild(announcement);
            
            setTimeout(() => {
                document.body.removeChild(announcement);
            }, 1000);
        }

        // Atajos de teclado informativos
        window.addEventListener('load', function() {
            console.log('🔤 Atajos de teclado disponibles:');
            console.log('   F2: Abrir modal de venta (producto seleccionado)');
            console.log('   Ctrl/Cmd + Enter: Confirmar venta');
            console.log('   Escape: Cerrar modal');
        });
    </script>
</body>
</html>
            