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
            case 'add_product':
                // Solo admin puede agregar productos
                if (!hasPermission('admin')) {
                    throw new Exception('Sin permisos para agregar productos');
                }
                
                $codigo = sanitize($_POST['codigo_producto']);
                $nombre = sanitize($_POST['nombre']);
                $descripcion = sanitize($_POST['descripcion']);
                $categoria_id = intval($_POST['categoria_id']);
                $tipo = $_POST['tipo'];
                $marca = sanitize($_POST['marca']);
                $modelo_compatible = sanitize($_POST['modelo_compatible']);
                $precio_venta = floatval($_POST['precio_venta']);
                $precio_compra = !empty($_POST['precio_compra']) ? floatval($_POST['precio_compra']) : null;
                $minimo_stock = intval($_POST['minimo_stock']);
                
                if (empty($nombre) || empty($tipo) || $precio_venta <= 0) {
                    throw new Exception('Nombre, tipo y precio son obligatorios');
                }
                
                // Verificar código único si se proporciona
                if (!empty($codigo)) {
                    $check_stmt = $db->prepare("SELECT id FROM productos WHERE codigo_producto = ?");
                    $check_stmt->execute([$codigo]);
                    if ($check_stmt->fetch()) {
                        throw new Exception('El código de producto ya existe');
                    }
                }
                
                $stmt = $db->prepare("
                    INSERT INTO productos (codigo_producto, nombre, descripcion, categoria_id, tipo, marca, 
                                         modelo_compatible, precio_venta, precio_compra, minimo_stock) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $result = $stmt->execute([
                    $codigo ?: null, $nombre, $descripcion, $categoria_id ?: null, $tipo,
                    $marca, $modelo_compatible, $precio_venta, $precio_compra, $minimo_stock
                ]);
                
                if ($result) {
                    logActivity($user['id'], 'add_product', "Producto agregado: $nombre");
                    echo json_encode(['success' => true, 'message' => 'Producto agregado correctamente']);
                } else {
                    throw new Exception('Error al agregar producto');
                }
                break;
                
            case 'update_product':
                if (!hasPermission('admin')) {
                    throw new Exception('Sin permisos para modificar productos');
                }
                
                $product_id = intval($_POST['product_id']);
                $codigo = sanitize($_POST['codigo_producto']);
                $nombre = sanitize($_POST['nombre']);
                $descripcion = sanitize($_POST['descripcion']);
                $categoria_id = intval($_POST['categoria_id']);
                $tipo = $_POST['tipo'];
                $marca = sanitize($_POST['marca']);
                $modelo_compatible = sanitize($_POST['modelo_compatible']);
                $precio_venta = floatval($_POST['precio_venta']);
                $precio_compra = !empty($_POST['precio_compra']) ? floatval($_POST['precio_compra']) : null;
                $minimo_stock = intval($_POST['minimo_stock']);
                $activo = isset($_POST['activo']) ? 1 : 0;
                
                if (empty($nombre) || empty($tipo) || $precio_venta <= 0) {
                    throw new Exception('Nombre, tipo y precio son obligatorios');
                }
                
                // Verificar código único si se proporciona (excluyendo el actual)
                if (!empty($codigo)) {
                    $check_stmt = $db->prepare("SELECT id FROM productos WHERE codigo_producto = ? AND id != ?");
                    $check_stmt->execute([$codigo, $product_id]);
                    if ($check_stmt->fetch()) {
                        throw new Exception('El código de producto ya existe');
                    }
                }
                
                $stmt = $db->prepare("
                    UPDATE productos SET codigo_producto = ?, nombre = ?, descripcion = ?, categoria_id = ?, 
                           tipo = ?, marca = ?, modelo_compatible = ?, precio_venta = ?, precio_compra = ?, 
                           minimo_stock = ?, activo = ? 
                    WHERE id = ?
                ");
                
                $result = $stmt->execute([
                    $codigo ?: null, $nombre, $descripcion, $categoria_id ?: null, $tipo,
                    $marca, $modelo_compatible, $precio_venta, $precio_compra, $minimo_stock, 
                    $activo, $product_id
                ]);
                
                if ($result) {
                    logActivity($user['id'], 'update_product', "Producto actualizado ID: $product_id");
                    echo json_encode(['success' => true, 'message' => 'Producto actualizado correctamente']);
                } else {
                    throw new Exception('Error al actualizar producto');
                }
                break;
                
            case 'delete_product':
                if (!hasPermission('admin')) {
                    throw new Exception('Sin permisos para eliminar productos');
                }
                
                $product_id = intval($_POST['product_id']);
                
                // Verificar si tiene stock en alguna tienda
                $check_stock = $db->prepare("SELECT SUM(cantidad_actual) as total FROM stock_productos WHERE producto_id = ?");
                $check_stock->execute([$product_id]);
                $stock_data = $check_stock->fetch();
                
                if ($stock_data['total'] > 0) {
                    throw new Exception('No se puede eliminar: el producto tiene stock en tiendas');
                }
                
                // Verificar si tiene ventas
                $check_sales = $db->prepare("SELECT COUNT(*) as count FROM ventas_productos WHERE producto_id = ?");
                $check_sales->execute([$product_id]);
                $sales_data = $check_sales->fetch();
                
                if ($sales_data['count'] > 0) {
                    // Desactivar en lugar de eliminar
                    $stmt = $db->prepare("UPDATE productos SET activo = 0 WHERE id = ?");
                    $result = $stmt->execute([$product_id]);
                    $message = 'Producto desactivado (tenía ventas registradas)';
                } else {
                    // Eliminar completamente
                    $stmt = $db->prepare("DELETE FROM productos WHERE id = ?");
                    $result = $stmt->execute([$product_id]);
                    $message = 'Producto eliminado correctamente';
                }
                
                if ($result) {
                    logActivity($user['id'], 'delete_product', "Producto eliminado/desactivado ID: $product_id");
                    echo json_encode(['success' => true, 'message' => $message]);
                } else {
                    throw new Exception('Error al eliminar producto');
                }
                break;
                
            case 'adjust_stock':
                // Admin puede ajustar cualquier tienda, vendedor solo la suya
                $producto_id = intval($_POST['producto_id']);
                $tienda_id = intval($_POST['tienda_id']);
                $nueva_cantidad = intval($_POST['nueva_cantidad']);
                $motivo = sanitize($_POST['motivo']);
                
                if (!hasPermission('admin') && $tienda_id != $user['tienda_id']) {
                    throw new Exception('Sin permisos para ajustar stock de esta tienda');
                }
                
                if ($nueva_cantidad < 0) {
                    throw new Exception('La cantidad no puede ser negativa');
                }
                
                // Llamar al procedimiento almacenado
                $stmt = $db->prepare("CALL AjustarInventario(?, ?, ?, ?, ?)");
                $result = $stmt->execute([$producto_id, $tienda_id, $nueva_cantidad, $user['id'], $motivo]);
                
                if ($result) {
                    logActivity($user['id'], 'adjust_stock', "Ajuste de stock: Producto $producto_id, Tienda $tienda_id, Nueva cantidad: $nueva_cantidad");
                    echo json_encode(['success' => true, 'message' => 'Stock ajustado correctamente']);
                } else {
                    throw new Exception('Error al ajustar stock');
                }
                break;
                
            case 'add_stock':
                // Agregar stock (entrada de mercancía)
                $producto_id = intval($_POST['producto_id']);
                $tienda_id = intval($_POST['tienda_id']);
                $cantidad = intval($_POST['cantidad']);
                $precio_unitario = floatval($_POST['precio_unitario']);
                $motivo = sanitize($_POST['motivo']);
                
                if (!hasPermission('admin') && $tienda_id != $user['tienda_id']) {
                    throw new Exception('Sin permisos para agregar stock a esta tienda');
                }
                
                if ($cantidad <= 0) {
                    throw new Exception('La cantidad debe ser mayor a cero');
                }
                
                $stmt = $db->prepare("
                    INSERT INTO movimientos_stock (producto_id, tienda_id, tipo_movimiento, cantidad, 
                                                 precio_unitario, motivo, referencia_tipo, usuario_id) 
                    VALUES (?, ?, 'entrada', ?, ?, ?, 'compra', ?)
                ");
                
                $result = $stmt->execute([$producto_id, $tienda_id, $cantidad, $precio_unitario, $motivo, $user['id']]);
                
                if ($result) {
                    logActivity($user['id'], 'add_stock', "Entrada de stock: Producto $producto_id, Cantidad: $cantidad");
                    echo json_encode(['success' => true, 'message' => 'Stock agregado correctamente']);
                } else {
                    throw new Exception('Error al agregar stock');
                }
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
        
    } catch(Exception $e) {
        logError("Error en gestión de productos: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Obtener filtros
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$tipo_filter = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$categoria_filter = isset($_GET['categoria']) ? intval($_GET['categoria']) : 0;
$tienda_filter = isset($_GET['tienda']) && hasPermission('admin') ? intval($_GET['tienda']) : null;
$stock_filter = isset($_GET['stock']) ? $_GET['stock'] : '';

// Construir query
$where_conditions = ['p.activo = 1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.nombre LIKE ? OR p.codigo_producto LIKE ? OR p.marca LIKE ? OR p.modelo_compatible LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if (!empty($tipo_filter)) {
    $where_conditions[] = "p.tipo = ?";
    $params[] = $tipo_filter;
}

if ($categoria_filter > 0) {
    $where_conditions[] = "p.categoria_id = ?";
    $params[] = $categoria_filter;
}

// Filtro de tienda para admin
if (hasPermission('admin')) {
    if ($tienda_filter) {
        $where_conditions[] = "t.id = ?";
        $params[] = $tienda_filter;
    }
} else {
    // Vendedor: solo su tienda
    $where_conditions[] = "t.id = ?";
    $params[] = $user['tienda_id'];
}

// Filtro de stock
if (!empty($stock_filter)) {
    if ($stock_filter === 'bajo') {
        $where_conditions[] = "COALESCE(s.cantidad_actual, 0) <= p.minimo_stock";
    } elseif ($stock_filter === 'sin_stock') {
        $where_conditions[] = "COALESCE(s.cantidad_actual, 0) = 0";
    }
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

try {
    // Obtener productos con stock
    $query = "
        SELECT p.*, c.nombre as categoria_nombre, t.id as tienda_id, t.nombre as tienda_nombre,
               COALESCE(s.cantidad_actual, 0) as stock_actual,
               COALESCE(s.cantidad_reservada, 0) as stock_reservado,
               s.ubicacion,
               CASE 
                   WHEN COALESCE(s.cantidad_actual, 0) <= p.minimo_stock THEN 'BAJO'
                   WHEN COALESCE(s.cantidad_actual, 0) <= (p.minimo_stock * 2) THEN 'MEDIO'
                   ELSE 'NORMAL'
               END as estado_stock
        FROM productos p
        CROSS JOIN tiendas t
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        LEFT JOIN stock_productos s ON p.id = s.producto_id AND t.id = s.tienda_id
        $where_clause
        ORDER BY p.nombre, t.nombre
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
    // Obtener categorías
    $categorias_stmt = $db->query("SELECT * FROM categorias_productos WHERE activa = 1 ORDER BY tipo, nombre");
    $categorias = $categorias_stmt->fetchAll();
    
    // Obtener tiendas para admin
    $tiendas = [];
    if (hasPermission('admin')) {
        $tiendas_stmt = $db->query("SELECT id, nombre FROM tiendas WHERE activa = 1 ORDER BY nombre");
        $tiendas = $tiendas_stmt->fetchAll();
    }
    
} catch(Exception $e) {
    logError("Error al obtener productos: " . $e->getMessage());
    $products = [];
    $categorias = [];
    $tiendas = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos y Accesorios - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        .modal { display: none; }
        .modal.show { display: flex; }
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) {
            .sidebar-hidden { transform: translateX(-100%); }
        }
        .stock-bajo { background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); border-left: 4px solid #ef4444; }
        .stock-medio { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-left: 4px solid #f59e0b; }
        .stock-normal { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-left: 4px solid #22c55e; }
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
                    
                    <a href="products.php" class="flex items-center px-4 py-2 text-gray-700 bg-purple-50 border-r-4 border-purple-500 rounded-l">
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

                    <a href="product_sales.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                        </svg>
                        Ventas Productos
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
                        <h2 class="text-3xl font-bold text-gray-900">Gestión de Productos</h2>
                        <p class="text-gray-600">Accesorios y repuestos para celulares</p>
                    </div>
                    
                    <div class="flex gap-3 mt-4 md:mt-0">
                        <?php if (hasPermission('admin')): ?>
                            <button onclick="openAddProductModal()" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Nuevo Producto
                            </button>
                        <?php endif; ?>
                        
                        <button onclick="openAddStockModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Agregar Stock
                        </button>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
                    <form method="GET" class="flex flex-wrap gap-4">
                        <div class="flex-1 min-w-64">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Buscar por nombre, código, marca o modelo..." 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <select name="tipo" class="px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Todos los tipos</option>
                                <option value="accesorio" <?php echo $tipo_filter === 'accesorio' ? 'selected' : ''; ?>>📱 Accesorios</option>
                                <option value="repuesto" <?php echo $tipo_filter === 'repuesto' ? 'selected' : ''; ?>>🔧 Repuestos</option>
                            </select>
                        </div>
                        <div>
                            <select name="categoria" class="px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Todas las categorías</option>
                                <?php foreach($categorias as $categoria): ?>
                                    <option value="<?php echo $categoria['id']; ?>" <?php echo $categoria_filter == $categoria['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($categoria['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="stock" class="px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">Todos los stocks</option>
                                <option value="bajo" <?php echo $stock_filter === 'bajo' ? 'selected' : ''; ?>>🔴 Stock bajo</option>
                                <option value="sin_stock" <?php echo $stock_filter === 'sin_stock' ? 'selected' : ''; ?>>⚫ Sin stock</option>
                            </select>
                        </div>
                        <?php if (hasPermission('admin')): ?>
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

                <!-- Lista de Productos -->
                <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                    <?php if (empty($products)): ?>
                        <div class="col-span-full text-center py-12">
                            <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                            <p class="text-xl font-medium text-gray-500">No se encontraron productos</p>
                            <p class="text-gray-400 mt-2">Ajusta los filtros o agrega nuevos productos</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($products as $product): ?>
                            <div class="bg-white rounded-lg shadow-sm overflow-hidden stock-<?php echo strtolower($product['estado_stock']); ?>">
                                <div class="p-6">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 mb-2">
                                                <span class="text-2xl">
                                                    <?php echo $product['tipo'] === 'accesorio' ? '📱' : '🔧'; ?>
                                                </span>
                                                <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($product['nombre']); ?></h3>
                                            </div>
                                            
                                            <?php if ($product['codigo_producto']): ?>
                                                <p class="text-xs text-gray-500 font-mono bg-gray-100 inline-block px-2 py-1 rounded">
                                                    <?php echo htmlspecialchars($product['codigo_producto']); ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <?php if ($product['marca']): ?>
                                                <p class="text-sm text-gray-600 mt-1">
                                                    <strong>Marca:</strong> <?php echo htmlspecialchars($product['marca']); ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <?php if ($product['modelo_compatible']): ?>
                                                <p class="text-sm text-gray-600">
                                                    <strong>Compatible:</strong> <?php echo htmlspecialchars($product['modelo_compatible']); ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <?php if ($product['categoria_nombre']): ?>
                                                <span class="inline-block px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full mt-2">
                                                    <?php echo htmlspecialchars($product['categoria_nombre']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="text-right ml-4">
                                            <p class="text-lg font-bold text-green-600">
                                                $<?php echo number_format($product['precio_venta'], 2); ?>
                                            </p>
                                            <?php if ($product['precio_compra'] && hasPermission('admin')): ?>
                                                <p class="text-xs text-gray-500">
                                                    Costo: $<?php echo number_format($product['precio_compra'], 2); ?>
                                                </p>
                                                <p class="text-xs text-green-600">
                                                    Margen: $<?php echo number_format($product['precio_venta'] - $product['precio_compra'], 2); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Stock Info -->
                                    <div class="border-t pt-4">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-sm font-medium text-gray-700">
                                                <?php if (hasPermission('admin')): ?>
                                                    <?php echo htmlspecialchars($product['tienda_nombre']); ?>
                                                <?php else: ?>
                                                    Stock Actual
                                                <?php endif; ?>
                                            </span>
                                            <div class="flex items-center gap-2">
                                                <span class="text-lg font-bold <?php 
                                                    echo $product['estado_stock'] === 'BAJO' ? 'text-red-600' : 
                                                        ($product['estado_stock'] === 'MEDIO' ? 'text-yellow-600' : 'text-green-600'); 
                                                ?>">
                                                    <?php echo $product['stock_actual']; ?>
                                                </span>
                                                <span class="text-xs text-gray-500">unidades</span>
                                            </div>
                                        </div>
                                        
                                        <?php if ($product['stock_reservado'] > 0): ?>
                                            <p class="text-xs text-blue-600 mb-2">
                                                📦 <?php echo $product['stock_reservado']; ?> reservadas
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if ($product['ubicacion']): ?>
                                            <p class="text-xs text-gray-500 mb-2">
                                                📍 <?php echo htmlspecialchars($product['ubicacion']); ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <!-- Alerta de stock -->
                                        <?php if ($product['estado_stock'] === 'BAJO'): ?>
                                            <div class="flex items-center text-red-600 text-xs mb-2">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 15.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                                </svg>
                                                Stock bajo (mín: <?php echo $product['minimo_stock']; ?>)
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Acciones -->
                                        <div class="flex gap-2 mt-4">
                                            <button onclick="openStockModal(<?php echo $product['id']; ?>, <?php echo $product['tienda_id']; ?>, '<?php echo htmlspecialchars($product['nombre']); ?>', <?php echo $product['stock_actual']; ?>)" 
                                                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-xs px-3 py-2 rounded transition-colors">
                                                📊 Ajustar Stock
                                            </button>
                                            
                                            <?php if (hasPermission('admin')): ?>
                                                <button onclick="openEditProductModal(<?php echo htmlspecialchars(json_encode($product)); ?>)" 
                                                        class="bg-yellow-600 hover:bg-yellow-700 text-white text-xs px-3 py-2 rounded transition-colors" title="Editar">
                                                    ✏️
                                                </button>
                                                
                                                <button onclick="deleteProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['nombre']); ?>')" 
                                                        class="bg-red-600 hover:bg-red-700 text-white text-xs px-3 py-2 rounded transition-colors" title="Eliminar">
                                                    🗑️
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Estadísticas resumidas -->
                <?php if (!empty($products)): ?>
                    <div class="mt-8 grid grid-cols-1 md:grid-cols-4 gap-4">
                        <?php
                        $total_productos = count(array_unique(array_column($products, 'id')));
                        $total_stock = array_sum(array_column($products, 'stock_actual'));
                        $productos_bajo_stock = count(array_filter($products, function($p) { return $p['estado_stock'] === 'BAJO'; }));
                        $valor_total = array_sum(array_map(function($p) { return $p['precio_venta'] * $p['stock_actual']; }, $products));
                        ?>
                        
                        <div class="bg-white rounded-lg shadow p-4 text-center">
                            <p class="text-2xl font-bold text-blue-600"><?php echo $total_productos; ?></p>
                            <p class="text-sm text-gray-600">Productos Únicos</p>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow p-4 text-center">
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($total_stock); ?></p>
                            <p class="text-sm text-gray-600">Unidades en Stock</p>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow p-4 text-center">
                            <p class="text-2xl font-bold text-red-600"><?php echo $productos_bajo_stock; ?></p>
                            <p class="text-sm text-gray-600">Con Stock Bajo</p>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow p-4 text-center">
                            <p class="text-2xl font-bold text-purple-600">$<?php echo number_format($valor_total, 0); ?></p>
                            <p class="text-sm text-gray-600">Valor Total</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Overlay para móvil -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 md:hidden hidden"></div>

    <!-- Modal Agregar/Editar Producto (solo admin) -->
    <?php if (hasPermission('admin')): ?>
    <div id="productModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-2xl mx-4 max-h-screen overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 id="productModalTitle" class="text-lg font-semibold">Agregar Producto</h3>
                <button onclick="closeProductModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form id="productForm" class="space-y-4">
                <input type="hidden" id="productId" name="product_id">
                <input type="hidden" id="formAction" name="action" value="add_product">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del Producto *</label>
                        <input type="text" id="nombre" name="nombre" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Código SKU</label>
                        <input type="text" id="codigo_producto" name="codigo_producto" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
                        <select id="tipo" name="tipo" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">Seleccionar tipo...</option>
                            <option value="accesorio">📱 Accesorio</option>
                            <option value="repuesto">🔧 Repuesto</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Categoría</label>
                        <select id="categoria_id" name="categoria_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                            <option value="">Seleccionar categoría...</option>
                            <?php foreach($categorias as $categoria): ?>
                                <option value="<?php echo $categoria['id']; ?>" data-tipo="<?php echo $categoria['tipo']; ?>">
                                    <?php echo htmlspecialchars($categoria['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Marca</label>
                        <input type="text" id="marca" name="marca" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Modelo Compatible</label>
                        <input type="text" id="modelo_compatible" name="modelo_compatible" 
                               placeholder="Ej: iPhone 15, Galaxy S24" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Precio de Venta *</label>
                        <input type="number" id="precio_venta" name="precio_venta" step="0.01" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Precio de Compra</label>
                        <input type="number" id="precio_compra" name="precio_compra" step="0.01" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Stock Mínimo</label>
                        <input type="number" id="minimo_stock" name="minimo_stock" value="5" min="0" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    
                    <div id="statusField" class="hidden">
                        <label class="flex items-center mt-6">
                            <input type="checkbox" id="activo" name="activo" class="mr-2">
                            <span class="text-sm font-medium text-gray-700">Producto activo</span>
                        </label>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                    <textarea id="descripcion" name="descripcion" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"></textarea>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeProductModal()" 
                            class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg">
                        Cancelar
                    </button>
                    <button type="button" onclick="saveProduct()" 
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal Ajustar Stock -->
    <div id="stockModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Ajustar Stock</h3>
                <button onclick="closeStockModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form id="stockForm" class="space-y-4">
                <input type="hidden" id="stockProductoId">
                <input type="hidden" id="stockTiendaId">
                
                <div class="bg-blue-50 p-3 rounded-lg mb-4">
                    <p class="font-medium text-blue-900" id="stockProductName"></p>
                    <p class="text-sm text-blue-700">Stock actual: <span id="stockCurrent"></span> unidades</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nueva Cantidad *</label>
                    <input type="number" id="nueva_cantidad" min="0" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Motivo del Ajuste *</label>
                    <select id="motivo_ajuste" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Seleccionar motivo...</option>
                        <option value="Inventario físico">📋 Inventario físico</option>
                        <option value="Producto dañado">💔 Producto dañado</option>
                        <option value="Producto perdido">❓ Producto perdido</option>
                        <option value="Error de registro">✏️ Error de registro</option>
                        <option value="Devolución">↩️ Devolución</option>
                        <option value="Otro">🔄 Otro</option>
                    </select>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeStockModal()" 
                            class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg">
                        Cancelar
                    </button>
                    <button type="button" onclick="adjustStock()" 
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                        Ajustar Stock
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Agregar Stock -->
    <div id="addStockModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Agregar Stock - Entrada de Mercancía</h3>
                <button onclick="closeAddStockModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form id="addStockForm" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Producto *</label>
                    <select id="add_producto_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="">Seleccionar producto...</option>
                        <?php
                        // Obtener productos únicos para el selector
                        $unique_products = [];
                        foreach($products as $product) {
                            if (!isset($unique_products[$product['id']])) {
                                $unique_products[$product['id']] = $product;
                            }
                        }
                        foreach($unique_products as $product):
                        ?>
                            <option value="<?php echo $product['id']; ?>">
                                <?php echo htmlspecialchars($product['nombre']); ?> 
                                <?php if ($product['codigo_producto']): ?>
                                    (<?php echo htmlspecialchars($product['codigo_producto']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if (hasPermission('admin')): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tienda *</label>
                    <select id="add_tienda_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="">Seleccionar tienda...</option>
                        <?php foreach($tiendas as $tienda): ?>
                            <option value="<?php echo $tienda['id']; ?>"><?php echo htmlspecialchars($tienda['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" id="add_tienda_id" value="<?php echo $user['tienda_id']; ?>">
                <?php endif; ?>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad *</label>
                        <input type="number" id="add_cantidad" min="1" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Precio Unitario</label>
                        <input type="number" id="add_precio_unitario" step="0.01" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Motivo</label>
                    <select id="add_motivo" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="Compra a proveedor">🛒 Compra a proveedor</option>
                        <option value="Transferencia desde otra tienda">🚚 Transferencia desde otra tienda</option>
                        <option value="Devolución de cliente">↩️ Devolución de cliente</option>
                        <option value="Ajuste de inventario">📋 Ajuste de inventario</option>
                        <option value="Producto encontrado">🔍 Producto encontrado</option>
                    </select>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeAddStockModal()" 
                            class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg">
                        Cancelar
                    </button>
                    <button type="button" onclick="addStock()" 
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg">
                        Agregar Stock
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

        <?php if (hasPermission('admin')): ?>
        let isEditMode = false;

        function openAddProductModal() {
            isEditMode = false;
            document.getElementById('productModalTitle').textContent = 'Agregar Producto';
            document.getElementById('formAction').value = 'add_product';
            document.getElementById('productForm').reset();
            document.getElementById('productId').value = '';
            document.getElementById('statusField').classList.add('hidden');
            document.getElementById('productModal').classList.add('show');
        }

        function openEditProductModal(product) {
            isEditMode = true;
            document.getElementById('productModalTitle').textContent = 'Editar Producto';
            document.getElementById('formAction').value = 'update_product';
            document.getElementById('productId').value = product.id;
            
            // Llenar formulario
            document.getElementById('nombre').value = product.nombre || '';
            document.getElementById('codigo_producto').value = product.codigo_producto || '';
            document.getElementById('tipo').value = product.tipo || '';
            document.getElementById('categoria_id').value = product.categoria_id || '';
            document.getElementById('marca').value = product.marca || '';
            document.getElementById('modelo_compatible').value = product.modelo_compatible || '';
            document.getElementById('precio_venta').value = product.precio_venta || '';
            document.getElementById('precio_compra').value = product.precio_compra || '';
            document.getElementById('minimo_stock').value = product.minimo_stock || '';
            document.getElementById('descripcion').value = product.descripcion || '';
            document.getElementById('activo').checked = product.activo == 1;
            
            document.getElementById('statusField').classList.remove('hidden');
            filterCategoriesByType();
            document.getElementById('productModal').classList.add('show');
        }

        function closeProductModal() {
            document.getElementById('productModal').classList.remove('show');
        }

        function saveProduct() {
            const formData = new FormData();
            
            formData.append('action', document.getElementById('formAction').value);
            
            if (isEditMode) {
                formData.append('product_id', document.getElementById('productId').value);
            }
            
            formData.append('nombre', document.getElementById('nombre').value);
            formData.append('codigo_producto', document.getElementById('codigo_producto').value);
            formData.append('tipo', document.getElementById('tipo').value);
            formData.append('categoria_id', document.getElementById('categoria_id').value);
            formData.append('marca', document.getElementById('marca').value);
            formData.append('modelo_compatible', document.getElementById('modelo_compatible').value);
            formData.append('precio_venta', document.getElementById('precio_venta').value);
            formData.append('precio_compra', document.getElementById('precio_compra').value);
            formData.append('minimo_stock', document.getElementById('minimo_stock').value);
            formData.append('descripcion', document.getElementById('descripcion').value);
            
            if (isEditMode && document.getElementById('activo').checked) {
                formData.append('activo', '1');
            }
            
            const button = event.target;
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Guardando...';
            
            fetch('products.php', {
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

        // Filtrar categorías por tipo
        function filterCategoriesByType() {
            const tipoSelect = document.getElementById('tipo');
            const categoriaSelect = document.getElementById('categoria_id');
            const selectedTipo = tipoSelect.value;
            
            // Resetear selector de categorías
            const options = categoriaSelect.querySelectorAll('option');
            options.forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else {
                    const optionTipo = option.getAttribute('data-tipo');
                    option.style.display = (selectedTipo === '' || optionTipo === selectedTipo) ? 'block' : 'none';
                }
            });
            
            // Si la categoría actual no coincide con el tipo, resetear
            const currentCategory = categoriaSelect.querySelector('option:checked');
            if (currentCategory && currentCategory.getAttribute('data-tipo') && 
                currentCategory.getAttribute('data-tipo') !== selectedTipo) {
                categoriaSelect.value = '';
            }
        }

        // Event listener para filtrar categorías
        document.getElementById('tipo').addEventListener('change', filterCategoriesByType);

        function deleteProduct(id, name) {
            if (!confirm(`¿Estás seguro de que quieres eliminar "${name}"?\n\nEsta acción no se puede deshacer.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_product');
            formData.append('product_id', id);
            
            fetch('products.php', {
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
        <?php endif; ?>

        // Modal de ajuste de stock
        function openStockModal(productId, tiendaId, productName, currentStock) {
            document.getElementById('stockProductoId').value = productId;
            document.getElementById('stockTiendaId').value = tiendaId;
            document.getElementById('stockProductName').textContent = productName;
            document.getElementById('stockCurrent').textContent = currentStock;
            document.getElementById('nueva_cantidad').value = currentStock;
            document.getElementById('motivo_ajuste').value = '';
            document.getElementById('stockModal').classList.add('show');
        }

        function closeStockModal() {
            document.getElementById('stockModal').classList.remove('show');
        }

        function adjustStock() {
            const formData = new FormData();
            formData.append('action', 'adjust_stock');
            formData.append('producto_id', document.getElementById('stockProductoId').value);
            formData.append('tienda_id', document.getElementById('stockTiendaId').value);
            formData.append('nueva_cantidad', document.getElementById('nueva_cantidad').value);
            formData.append('motivo', document.getElementById('motivo_ajuste').value);
            
            if (!document.getElementById('motivo_ajuste').value) {
                showNotification('⚠️ Por favor selecciona un motivo para el ajuste', 'warning');
                return;
            }
            
            const button = event.target;
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Ajustando...';
            
            fetch('products.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('✅ ' + data.message, 'success');
                    closeStockModal();
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

        // Modal de agregar stock
        function openAddStockModal() {
            document.getElementById('addStockForm').reset();
            <?php if (!hasPermission('admin')): ?>
            document.getElementById('add_tienda_id').value = <?php echo $user['tienda_id']; ?>;
            <?php endif; ?>
            document.getElementById('addStockModal').classList.add('show');
        }

        function closeAddStockModal() {
            document.getElementById('addStockModal').classList.remove('show');
        }

        function addStock() {
            const formData = new FormData();
            formData.append('action', 'add_stock');
            formData.append('producto_id', document.getElementById('add_producto_id').value);
            formData.append('tienda_id', document.getElementById('add_tienda_id').value);
            formData.append('cantidad', document.getElementById('add_cantidad').value);
            formData.append('precio_unitario', document.getElementById('add_precio_unitario').value);
            formData.append('motivo', document.getElementById('add_motivo').value);
            
            if (!document.getElementById('add_producto_id').value) {
                showNotification('⚠️ Por favor selecciona un producto', 'warning');
                return;
            }
            
            if (!document.getElementById('add_tienda_id').value) {
                showNotification('⚠️ Por favor selecciona una tienda', 'warning');
                return;
            }
            
            if (!document.getElementById('add_cantidad').value || document.getElementById('add_cantidad').value <= 0) {
                showNotification('⚠️ Por favor ingresa una cantidad válida', 'warning');
                return;
            }
            
            const button = event.target;
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Agregando...';
            
            fetch('products.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('✅ ' + data.message, 'success');
                    closeAddStockModal();
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

        // Sistema de notificaciones
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm transition-all duration-300 ${
                type === 'success' ? 'bg-green-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                type === 'warning' ? 'bg-yellow-500 text-white' :
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

        // Auto-completar precio unitario al seleccionar producto
        document.getElementById('add_producto_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                // Aquí podrías hacer una llamada AJAX para obtener el precio del producto
                // Por simplicidad, dejamos que el usuario lo ingrese manualmente
            }
        });

        // Cerrar modales con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeProductModal();
                closeStockModal();
                closeAddStockModal();
            }
        });

        // Validaciones en tiempo real
        document.getElementById('precio_venta').addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });

        document.getElementById('precio_compra').addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });

        document.getElementById('minimo_stock').addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });

        document.getElementById('nueva_cantidad').addEventListener('input', function() {
            if (this.value < 0) this.value = 0;
        });

        document.getElementById('add_cantidad').addEventListener('input', function() {
            if (this.value < 1) this.value = 1;
        });

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (hasPermission('admin')): ?>
            filterCategoriesByType();
            <?php endif; ?>
            
            // Mostrar alerta si hay productos con stock bajo
            <?php
            $productos_bajo_stock = array_filter($products, function($p) { return $p['estado_stock'] === 'BAJO'; });
            if (!empty($productos_bajo_stock)):
            ?>
            setTimeout(() => {
                showNotification('⚠️ Tienes <?php echo count($productos_bajo_stock); ?> productos con stock bajo', 'warning');
            }, 1000);
            <?php endif; ?>
        });
    </script>
</body>
</html>