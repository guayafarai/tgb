<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

setSecurityHeaders();
startSecureSession();
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'search_products':
                $search = isset($_POST['search']) ? sanitize($_POST['search']) : '';
                
                // Construir query de búsqueda
                $where_conditions = ['p.activo = 1', 's.cantidad_actual > 0'];
                $params = [];
                
                if (!empty($search)) {
                    $where_conditions[] = "(p.nombre LIKE ? OR p.codigo_producto LIKE ? OR p.marca LIKE ? OR p.modelo_compatible LIKE ? OR c.nombre LIKE ?)";
                    $search_param = "%$search%";
                    $params = array_fill(0, 5, $search_param);
                }
                
                // Filtro por tienda según rol
                if (!hasPermission('admin')) {
                    $where_conditions[] = "s.tienda_id = ?";
                    $params[] = $user['tienda_id'];
                }
                
                $where_clause = "WHERE " . implode(" AND ", $where_conditions);
                
                $query = "
                    SELECT p.*, c.nombre as categoria_nombre, s.cantidad_actual, s.tienda_id, t.nombre as tienda_nombre
                    FROM productos p
                    LEFT JOIN categorias_productos c ON p.categoria_id = c.id
                    LEFT JOIN stock_productos s ON p.id = s.producto_id
                    LEFT JOIN tiendas t ON s.tienda_id = t.id
                    $where_clause
                    ORDER BY p.nombre
                    LIMIT 50
                ";
                
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $products = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'products' => $products]);
                break;
                
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

// Obtener productos disponibles para venta - inicial
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
            LIMIT 20
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
            LIMIT 20
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

// Incluir el navbar/sidebar unificado
require_once '../includes/navbar_unified.php';
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
        .search-box {
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
            border-bottom: 2px solid #e5e7eb;
        }
        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #8b5cf6;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-gray-100">
    
    <?php renderNavbar('product_sales'); ?>
    
    <!-- Contenido principal -->
    <main class="page-content">
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
                            <p class="text-sm text-purple-700">1. Busca el producto por nombre, código o categoría → 2. Selecciona el producto → 3. Ajusta cantidad y precio → 4. Completa datos del cliente → 5. Confirma la venta</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Productos Disponibles CON BUSCADOR -->
                <div class="bg-white rounded-lg shadow">
                    <!-- Buscador -->
                    <div class="search-box p-4">
                        <div class="flex items-center gap-3">
                            <div class="flex-1 relative">
                                <input type="text" id="productSearch" placeholder="Buscar por nombre, código, marca o categoría..." 
                                       class="w-full px-4 py-2 pl-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                <svg class="w-5 h-5 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            <button onclick="searchProducts()" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition-colors">
                                Buscar
                            </button>
                            <button onclick="clearProductSearch()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                                Limpiar
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-2" id="searchProductInfo">
                            <?php if ($user['rol'] === 'vendedor'): ?>
                                Buscando solo en <?php echo htmlspecialchars($user['tienda_nombre']); ?>
                            <?php else: ?>
                                Mostrando los últimos 20 productos disponibles
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Productos Disponibles
                            <?php if ($user['rol'] === 'vendedor'): ?>
                                <span class="text-sm font-normal text-gray-500">- <?php echo htmlspecialchars($user['tienda_nombre']); ?></span>
                            <?php endif; ?>
                        </h3>
                        <span class="bg-purple-100 text-purple-800 text-xs font-medium px-2.5 py-0.5 rounded-full" id="productCount">
                            <?php echo count($productos_disponibles); ?> disponibles
                        </span>
                    </div>
                    
                    <div class="p-6 max-h-96 overflow-y-auto" id="productsContainer">
                        <!-- Loading spinner -->
                        <div id="loadingProductSpinner" class="hidden flex justify-center items-center py-8">
                            <div class="loading-spinner"></div>
                        </div>
                        
                        <!-- Contenedor de productos -->
                        <div id="productsList" class="space-y-3">
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
                                <?php foreach($productos_disponibles as $producto): ?>
                                    <div class="product-card border rounded-lg p-4 hover:border-purple-300 transition-all duration-200" 
                                         onclick="selectProductForSale(<?php echo htmlspecialchars(json_encode($producto)); ?>)"
                                         data-product-id="<?php echo $producto['id']; ?>-<?php echo $producto['tienda_id']; ?>">
                                        <div class="flex justify-between items-start">
                                            <div class="flex-1">
                                                <div class="flex items-center gap-2 mb-1">
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
                            <?php endif; ?>
                        </div>
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
    </main>

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
                <input type="hidden" id="maxStock">
                
                <!-- Info del producto -->
                <div id="productInfo" class="bg-gradient-to-r from-purple-50 to-indigo-50 border border-purple-200 p-4 rounded-lg hidden">
                    <div class="flex items-center gap-3">
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
                            <p class="text-xs text-gray-500 mt-1" id="cantidadInfo"></p>
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
                                <option value="efectivo">Efectivo</option>
                                <option value="tarjeta">Tarjeta</option>
                                <option value="transferencia">Transferencia</option>
                                <option value="credito">Crédito</option>
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
        let selectedProduct = null;
        let productSearchTimeout = null;

        // Búsqueda de productos
        function searchProducts() {
            const searchTerm = document.getElementById('productSearch').value.trim();
            
            document.getElementById('loadingProductSpinner').classList.remove('hidden');
            document.getElementById('productsList').style.opacity = '0.5';
            
            const formData = new FormData();
            formData.append('action', 'search_products');
            formData.append('search', searchTerm);
            
            fetch('product_sales.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderProducts(data.products);
                    document.getElementById('productCount').textContent = data.products.length + ' encontrados';
                    
                    if (searchTerm) {
                        document.getElementById('searchProductInfo').textContent = 
                            `Mostrando ${data.products.length} resultados para "${searchTerm}"`;
                    } else {
                        document.getElementById('searchProductInfo').textContent = 
                            '<?php echo $user['rol'] === 'vendedor' ? "Mostrando productos de " . htmlspecialchars($user['tienda_nombre']) : "Mostrando todos los productos disponibles"; ?>';
                    }
                } else {
                    showNotification('Error al buscar productos: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error en la búsqueda', 'error');
            })
            .finally(() => {
                document.getElementById('loadingProductSpinner').classList.add('hidden');
                document.getElementById('productsList').style.opacity = '1';
            });
        }

        // Renderizar lista de productos
        function renderProducts(products) {
            const container = document.getElementById('productsList');
            
            if (products.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-8">
                        <svg class="w-12 h-12 text-gray-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        <p class="text-gray-500 font-medium">No se encontraron productos</p>
                        <p class="text-sm text-gray-400 mt-1">Intenta con otros términos de búsqueda</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            const showTienda = <?php echo hasPermission('admin') ? 'true' : 'false'; ?>;
            
            products.forEach(product => {
                html += `
                    <div class="product-card border rounded-lg p-4 hover:border-purple-300 transition-all duration-200" 
                         onclick='selectProductForSale(${JSON.stringify(product)})'
                         data-product-id="${product.id}-${product.tienda_id}">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <p class="font-medium text-gray-900">${escapeHtml(product.nombre)}</p>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        Stock: ${product.cantidad_actual}
                                    </span>
                                </div>
                                ${product.codigo_producto ? `
                                    <p class="text-xs text-gray-500 font-mono bg-gray-100 inline-block px-2 py-1 rounded mb-1">
                                        ${escapeHtml(product.codigo_producto)}
                                    </p>
                                ` : ''}
                                <div class="flex flex-wrap gap-2 text-xs text-gray-600">
                                    ${product.marca ? `<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded">${escapeHtml(product.marca)}</span>` : ''}
                                    ${product.categoria_nombre ? `<span class="bg-gray-100 text-gray-800 px-2 py-1 rounded">${escapeHtml(product.categoria_nombre)}</span>` : ''}
                                </div>
                                ${product.modelo_compatible ? `<p class="text-xs text-gray-500 mt-1">Compatible: ${escapeHtml(product.modelo_compatible)}</p>` : ''}
                                ${showTienda ? `<p class="text-xs text-blue-600 mt-1">${escapeHtml(product.tienda_nombre)}</p>` : ''}
                            </div>
                            <div class="text-right ml-4">
                                <p class="font-bold text-lg text-purple-600">${formatPrice(product.precio_venta)}</p>
                                <p class="text-xs text-gray-500">por unidad</p>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Limpiar búsqueda
        function clearProductSearch() {
            document.getElementById('productSearch').value = '';
            searchProducts();
        }

        // Búsqueda en tiempo real (con debounce)
        document.getElementById('productSearch').addEventListener('input', function() {
            clearTimeout(productSearchTimeout);
            productSearchTimeout = setTimeout(() => {
                searchProducts();
            }, 500);
        });

        // Enter para buscar
        document.getElementById('productSearch').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchProducts();
            }
        });

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
                selectedCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            
            // Llenar información en el modal
            document.getElementById('selectedProductId').value = product.id;
            document.getElementById('selectedTiendaId').value = product.tienda_id;
            document.getElementById('maxStock').value = product.cantidad_actual;
            
            document.getElementById('productName').textContent = product.nombre;
            document.getElementById('productDetails').textContent = 
                (product.marca || '') + (product.categoria_nombre ? ' - ' + product.categoria_nombre : '');
            document.getElementById('productStock').textContent = `Stock disponible: ${product.cantidad_actual} unidades`;
            document.getElementById('productPrice').textContent = `${parseFloat(product.precio_venta).toFixed(2)} c/u`;
            document.getElementById('productInfo').classList.remove('hidden');
            
            // Pre-llenar valores
            document.getElementById('cantidad').value = 1;
            document.getElementById('cantidad').max = product.cantidad_actual;
            document.getElementById('precio_unitario').value = product.precio_venta;
            document.getElementById('descuento').value = 0;
            document.getElementById('cantidadInfo').textContent = `Máximo disponible: ${product.cantidad_actual}`;
            
            // Calcular total inicial
            calculateTotal();
            
            // Abrir modal
            document.getElementById('productSaleModal').classList.add('show');
            setTimeout(() => document.getElementById('cantidad').focus(), 100);
        }

        function closeProductSaleModal() {
            document.getElementById('productSaleModal').classList.remove('show');
            clearProductSaleForm();
            clearProductSelection();
        }

        function clearProductSelection() {
            selectedProduct = null;
            document.querySelectorAll('.product-card').forEach(el => {
                el.classList.remove('product-selected');
            });
            document.getElementById('productInfo').classList.add('hidden');
        }

        function clearProductSaleForm() {
            document.getElementById('cliente_nombre').value = '';
            document.getElementById('cliente_telefono').value = '';
            document.getElementById('cliente_email').value = '';
            document.getElementById('cantidad').value = 1;
            document.getElementById('precio_unitario').value = '';
            document.getElementById('descuento').value = 0;
            document.getElementById('metodo_pago').value = 'efectivo';
            document.getElementById('notas').value = '';
            document.getElementById('totalCalculado').textContent = '$0.00';
        }

        // Calcular total automáticamente
        function calculateTotal() {
            const cantidad = parseFloat(document.getElementById('cantidad').value) || 0;
            const precioUnitario = parseFloat(document.getElementById('precio_unitario').value) || 0;
            const descuento = parseFloat(document.getElementById('descuento').value) || 0;
            
            const subtotal = cantidad * precioUnitario;
            const total = subtotal - descuento;
            
            document.getElementById('totalCalculado').textContent = `${total.toFixed(2)}`;
            
            // Validar descuento
            if (descuento > subtotal) {
                document.getElementById('descuento').classList.add('border-red-500');
                document.getElementById('totalCalculado').classList.add('text-red-600');
                document.getElementById('totalCalculado').classList.remove('text-green-700');
            } else {
                document.getElementById('descuento').classList.remove('border-red-500');
                document.getElementById('totalCalculado').classList.remove('text-red-600');
                document.getElementById('totalCalculado').classList.add('text-green-700');
            }
        }

        // Event listeners para calcular total en tiempo real
        document.getElementById('cantidad').addEventListener('input', function() {
            const maxStock = parseInt(document.getElementById('maxStock').value);
            if (parseInt(this.value) > maxStock) {
                this.value = maxStock;
                showNotification(`Stock máximo disponible: ${maxStock}`, 'warning');
            }
            calculateTotal();
        });

        document.getElementById('precio_unitario').addEventListener('input', calculateTotal);
        document.getElementById('descuento').addEventListener('input', calculateTotal);

        function registerProductSale() {
            if (!selectedProduct) {
                showNotification('No se ha seleccionado un producto', 'error');
                return;
            }
            
            const cliente_nombre = document.getElementById('cliente_nombre').value.trim();
            const cantidad = parseInt(document.getElementById('cantidad').value);
            const precio_unitario = parseFloat(document.getElementById('precio_unitario').value);
            const descuento = parseFloat(document.getElementById('descuento').value) || 0;
            const maxStock = parseInt(document.getElementById('maxStock').value);
            
            // Validaciones
            if (!cliente_nombre) {
                showNotification('Por favor ingresa el nombre del cliente', 'warning');
                document.getElementById('cliente_nombre').focus();
                return;
            }
            
            if (!cantidad || cantidad <= 0) {
                showNotification('Por favor ingresa una cantidad válida', 'warning');
                document.getElementById('cantidad').focus();
                return;
            }
            
            if (cantidad > maxStock) {
                showNotification(`Stock insuficiente. Máximo disponible: ${maxStock}`, 'error');
                document.getElementById('cantidad').focus();
                return;
            }
            
            if (!precio_unitario || precio_unitario <= 0) {
                showNotification('Por favor ingresa un precio válido', 'warning');
                document.getElementById('precio_unitario').focus();
                return;
            }
            
            const total = (cantidad * precio_unitario) - descuento;
            if (total < 0) {
                showNotification('El descuento no puede ser mayor al total', 'error');
                document.getElementById('descuento').focus();
                return;
            }
            
            // Confirmar venta
            const confirmMessage = `¿Confirmar venta?\n\nProducto: ${selectedProduct.nombre}\nCantidad: ${cantidad} unidades\nCliente: ${cliente_nombre}\nTotal: ${total.toFixed(2)}`;
            
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
            const originalHTML = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<svg class="w-4 h-4 mr-2 animate-spin inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>Procesando...';
            
            fetch('product_sales.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('✅ ' + data.message, 'success');
                    clearProductSaleForm();
                    closeProductSaleModal();
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
                button.innerHTML = originalHTML;
            });
        }

        // Funciones de utilidad
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatPrice(price) {
            return parseFloat(price).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        // Sistema de notificaciones
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            const bgColors = {
                'success': 'bg-green-500',
                'error': 'bg-red-500', 
                'warning': 'bg-yellow-500',
                'info': 'bg-blue-500'
            };
            
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm transition-all duration-300 ${bgColors[type]} text-white`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 4000);
        }

        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeProductSaleModal();
            }
        });

        // Validaciones en tiempo real
        document.getElementById('cantidad').addEventListener('input', function() {
            if (this.value < 1) this.value = 1;
        });

        document.getElementById('precio_unitario').addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });

        document.getElementById('descuento').addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
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
    </script>
</body>
</html>