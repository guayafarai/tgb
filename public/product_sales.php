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