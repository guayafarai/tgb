<?php
/**
 * INDEX PÚBLICO - CATÁLOGO DE PRODUCTOS
 * Sistema de Inventario de Celulares
 * Muestra productos y celulares disponibles al público
 */

require_once __DIR__ . '/../config/database.php';

// Configuración de headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$db = getDB();

// Obtener filtros
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$tipo_filter = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$precio_max = isset($_GET['precio_max']) ? floatval($_GET['precio_max']) : 0;

try {
    // ============== PRODUCTOS DISPONIBLES ==============
    $productos_where = ["p.activo = 1", "s.cantidad_actual > 0"];
    $productos_params = [];
    
    if (!empty($search)) {
        $productos_where[] = "(p.nombre LIKE ? OR p.marca LIKE ? OR p.modelo_compatible LIKE ?)";
        $search_param = "%$search%";
        $productos_params = array_merge($productos_params, [$search_param, $search_param, $search_param]);
    }
    
    if (!empty($tipo_filter)) {
        $productos_where[] = "p.tipo = ?";
        $productos_params[] = $tipo_filter;
    }
    
    if ($precio_max > 0) {
        $productos_where[] = "p.precio_venta <= ?";
        $productos_params[] = $precio_max;
    }
    
    $productos_where_clause = "WHERE " . implode(" AND ", $productos_where);
    
    $productos_query = "
        SELECT p.id, p.nombre, p.codigo_producto, p.tipo, p.marca, p.modelo_compatible,
               p.precio_venta, p.descripcion, c.nombre as categoria_nombre,
               SUM(s.cantidad_actual) as stock_total,
               GROUP_CONCAT(DISTINCT t.nombre SEPARATOR ', ') as tiendas_disponibles
        FROM productos p
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id
        LEFT JOIN stock_productos s ON p.id = s.producto_id
        LEFT JOIN tiendas t ON s.tienda_id = t.id
        $productos_where_clause
        GROUP BY p.id
        ORDER BY p.nombre
    ";
    
    $productos_stmt = $db->prepare($productos_query);
    $productos_stmt->execute($productos_params);
    $productos = $productos_stmt->fetchAll();
    
    // ============== CELULARES DISPONIBLES ==============
    $celulares_where = ["c.estado = 'disponible'"];
    $celulares_params = [];
    
    if (!empty($search)) {
        $celulares_where[] = "(c.modelo LIKE ? OR c.marca LIKE ? OR c.capacidad LIKE ?)";
        $celulares_params = array_merge($celulares_params, [$search_param, $search_param, $search_param]);
    }
    
    if ($precio_max > 0) {
        $celulares_where[] = "c.precio <= ?";
        $celulares_params[] = $precio_max;
    }
    
    $celulares_where_clause = "WHERE " . implode(" AND ", $celulares_where);
    
    $celulares_query = "
        SELECT c.id, c.modelo, c.marca, c.capacidad, c.color, c.condicion, c.precio,
               t.nombre as tienda_nombre, t.direccion as tienda_direccion, t.telefono as tienda_telefono
        FROM celulares c
        LEFT JOIN tiendas t ON c.tienda_id = t.id
        $celulares_where_clause
        ORDER BY c.modelo, c.capacidad
    ";
    
    $celulares_stmt = $db->prepare($celulares_query);
    $celulares_stmt->execute($celulares_params);
    $celulares = $celulares_stmt->fetchAll();
    
    // ============== ESTADÍSTICAS GENERALES ==============
    $stats = [
        'total_productos' => count($productos),
        'total_celulares' => count($celulares),
        'total_items' => count($productos) + count($celulares)
    ];
    
    // ============== CATEGORÍAS PARA FILTRO ==============
    $categorias_stmt = $db->query("
        SELECT DISTINCT tipo FROM productos WHERE activo = 1 ORDER BY tipo
    ");
    $categorias = $categorias_stmt->fetchAll();
    
} catch(Exception $e) {
    error_log("Error en index público: " . $e->getMessage());
    $productos = [];
    $celulares = [];
    $stats = ['total_productos' => 0, 'total_celulares' => 0, 'total_items' => 0];
    $categorias = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Catálogo de celulares y accesorios disponibles - <?php echo SYSTEM_NAME; ?>">
    <title>Catálogo de Productos - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        .gradient-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .product-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        .badge-product {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .badge-phone {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .sticky-header {
            position: sticky;
            top: 0;
            z-index: 50;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow: hidden;
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg"><defs><pattern id="grid" width="100" height="100" patternUnits="userSpaceOnUse"><path d="M 100 0 L 0 0 0 100" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="1"/></pattern></defs><rect width="100%" height="100%" fill="url(%23grid)"/></svg>');
            opacity: 0.3;
        }
        .floating-badge {
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        .price-tag {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        .search-highlight {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
        }
        .filter-chip {
            transition: all 0.2s ease;
        }
        .filter-chip:hover {
            transform: scale(1.05);
        }
        .contact-card {
            background: linear-gradient(135deg, #e0e7ff 0%, #ddd6fe 100%);
        }
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-gray-50">

    <!-- Hero Header -->
    <header class="hero-section text-white py-16 no-print relative">
        <div class="container mx-auto px-4">
            <div class="max-w-4xl mx-auto text-center relative z-10">
                <div class="floating-badge inline-block mb-6">
                    <svg class="w-20 h-20 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <h1 class="text-5xl font-bold mb-4"><?php echo SYSTEM_NAME; ?></h1>
                <p class="text-xl mb-8 text-purple-100">Celulares y Accesorios de Calidad</p>
                
                <!-- Stats rápidas -->
                <div class="grid grid-cols-3 gap-4 max-w-2xl mx-auto">
                    <div class="bg-white bg-opacity-10 backdrop-filter backdrop-blur-lg rounded-lg p-4">
                        <p class="text-3xl font-bold"><?php echo $stats['total_celulares']; ?></p>
                        <p class="text-sm opacity-90">Celulares</p>
                    </div>
                    <div class="bg-white bg-opacity-10 backdrop-filter backdrop-blur-lg rounded-lg p-4">
                        <p class="text-3xl font-bold"><?php echo $stats['total_productos']; ?></p>
                        <p class="text-sm opacity-90">Productos</p>
                    </div>
                    <div class="bg-white bg-opacity-10 backdrop-filter backdrop-blur-lg rounded-lg p-4">
                        <p class="text-3xl font-bold"><?php echo $stats['total_items']; ?></p>
                        <p class="text-sm opacity-90">Total Items</p>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Barra de búsqueda sticky -->
    <div class="sticky-header shadow-md no-print">
        <div class="container mx-auto px-4 py-4">
            <form method="GET" class="flex flex-col md:flex-row gap-3">
                <div class="flex-1 relative">
                    <input type="text" 
                           name="search" 
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Buscar productos o celulares..." 
                           class="w-full px-4 py-3 pl-12 border-2 border-purple-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                    <svg class="w-6 h-6 text-gray-400 absolute left-4 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                
                <select name="tipo" class="px-4 py-3 border-2 border-purple-200 rounded-lg focus:ring-2 focus:ring-purple-500">
                    <option value="">Todos los tipos</option>
                    <option value="accesorio" <?php echo $tipo_filter === 'accesorio' ? 'selected' : ''; ?>>Accesorios</option>
                    <option value="repuesto" <?php echo $tipo_filter === 'repuesto' ? 'selected' : ''; ?>>Repuestos</option>
                </select>
                
                <select name="precio_max" class="px-4 py-3 border-2 border-purple-200 rounded-lg focus:ring-2 focus:ring-purple-500">
                    <option value="0">Todos los precios</option>
                    <option value="50" <?php echo $precio_max == 50 ? 'selected' : ''; ?>>Hasta $50</option>
                    <option value="100" <?php echo $precio_max == 100 ? 'selected' : ''; ?>>Hasta $100</option>
                    <option value="200" <?php echo $precio_max == 200 ? 'selected' : ''; ?>>Hasta $200</option>
                    <option value="500" <?php echo $precio_max == 500 ? 'selected' : ''; ?>>Hasta $500</option>
                </select>
                
                <button type="submit" class="bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white px-6 py-3 rounded-lg font-medium transition-all">
                    Buscar
                </button>
                
                <?php if ($search || $tipo_filter || $precio_max): ?>
                <a href="index.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-3 rounded-lg font-medium transition-all text-center">
                    Limpiar
                </a>
                <?php endif; ?>
            </form>
            
            <!-- Chips de filtros activos -->
            <?php if ($search || $tipo_filter || $precio_max): ?>
            <div class="flex flex-wrap gap-2 mt-3">
                <?php if ($search): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-purple-100 text-purple-800">
                    Búsqueda: "<?php echo htmlspecialchars($search); ?>"
                </span>
                <?php endif; ?>
                <?php if ($tipo_filter): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-blue-100 text-blue-800">
                    Tipo: <?php echo ucfirst($tipo_filter); ?>
                </span>
                <?php endif; ?>
                <?php if ($precio_max): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-green-100 text-green-800">
                    Hasta: $<?php echo $precio_max; ?>
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Contenido Principal -->
    <main class="container mx-auto px-4 py-8">
        
        <!-- Sección: Productos y Accesorios -->
        <?php if (!empty($productos)): ?>
        <section class="mb-12">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-3xl font-bold text-gray-900 flex items-center">
                    <svg class="w-8 h-8 mr-3 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    Productos y Accesorios
                </h2>
                <span class="px-4 py-2 bg-purple-100 text-purple-800 rounded-full font-medium">
                    <?php echo count($productos); ?> disponibles
                </span>
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach($productos as $producto): ?>
                <div class="product-card bg-white rounded-xl shadow-md overflow-hidden">
                    <!-- Badge de tipo -->
                    <div class="badge-product text-white px-4 py-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase">
                                <?php echo htmlspecialchars($producto['tipo']); ?>
                            </span>
                            <?php if ($producto['stock_total'] <= 5): ?>
                            <span class="bg-red-500 px-2 py-1 rounded-full text-xs">
                                ¡Últimas unidades!
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="p-6">
                        <!-- Nombre del producto -->
                        <h3 class="text-lg font-bold text-gray-900 mb-2 min-h-[3rem]">
                            <?php echo htmlspecialchars($producto['nombre']); ?>
                        </h3>
                        
                        <!-- Detalles -->
                        <div class="space-y-2 mb-4">
                            <?php if ($producto['marca']): ?>
                            <p class="text-sm text-gray-600 flex items-center">
                                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                </svg>
                                <?php echo htmlspecialchars($producto['marca']); ?>
                            </p>
                            <?php endif; ?>
                            
                            <?php if ($producto['modelo_compatible']): ?>
                            <p class="text-sm text-gray-600 flex items-center">
                                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Compatible: <?php echo htmlspecialchars($producto['modelo_compatible']); ?>
                            </p>
                            <?php endif; ?>
                            
                            <?php if ($producto['categoria_nombre']): ?>
                            <span class="inline-block px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs">
                                <?php echo htmlspecialchars($producto['categoria_nombre']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Stock -->
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center">
                                <span class="w-2 h-2 rounded-full <?php echo $producto['stock_total'] > 0 ? 'bg-green-500' : 'bg-red-500'; ?> mr-2"></span>
                                <span class="text-sm font-medium text-gray-700">
                                    <?php echo $producto['stock_total']; ?> en stock
                                </span>
                            </div>
                        </div>
                        
                        <!-- Precio -->
                        <div class="price-tag text-white rounded-lg p-3 text-center">
                            <p class="text-sm opacity-90">Precio</p>
                            <p class="text-2xl font-bold">$<?php echo number_format($producto['precio_venta'], 2); ?></p>
                        </div>
                        
                        <!-- Tiendas disponibles -->
                        <?php if ($producto['tiendas_disponibles']): ?>
                        <div class="mt-3 pt-3 border-t border-gray-200">
                            <p class="text-xs text-gray-500 flex items-center">
                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                <?php echo htmlspecialchars($producto['tiendas_disponibles']); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        
        <!-- Sección: Celulares Disponibles -->
        <?php if (!empty($celulares)): ?>
        <section class="mb-12">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-3xl font-bold text-gray-900 flex items-center">
                    <svg class="w-8 h-8 mr-3 text-pink-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                    Celulares Disponibles
                </h2>
                <span class="px-4 py-2 bg-pink-100 text-pink-800 rounded-full font-medium">
                    <?php echo count($celulares); ?> disponibles
                </span>
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach($celulares as $celular): ?>
                <div class="product-card bg-white rounded-xl shadow-md overflow-hidden">
                    <!-- Badge de condición -->
                    <div class="badge-phone text-white px-4 py-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase">
                                <?php echo htmlspecialchars($celular['condicion']); ?>
                            </span>
                            <span class="bg-green-500 px-2 py-1 rounded-full text-xs">
                                Disponible
                            </span>
                        </div>
                    </div>
                    
                    <div class="p-6">
                        <!-- Modelo -->
                        <h3 class="text-lg font-bold text-gray-900 mb-2 min-h-[3rem]">
                            <?php echo htmlspecialchars($celular['modelo']); ?>
                        </h3>
                        
                        <!-- Especificaciones -->
                        <div class="space-y-2 mb-4">
                            <?php if ($celular['marca']): ?>
                            <p class="text-sm text-gray-600 flex items-center">
                                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
                                </svg>
                                <?php echo htmlspecialchars($celular['marca']); ?>
                            </p>
                            <?php endif; ?>
                            
                            <p class="text-sm text-gray-600 flex items-center">
                                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                </svg>
                                <?php echo htmlspecialchars($celular['capacidad']); ?>
                            </p>
                            
                            <?php if ($celular['color']): ?>
                            <p class="text-sm text-gray-600 flex items-center">
                                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                                </svg>
                                Color: <?php echo htmlspecialchars($celular['color']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Precio -->
                        <div class="price-tag text-white rounded-lg p-3 text-center mb-4">
                            <p class="text-sm opacity-90">Precio</p>
                            <p class="text-2xl font-bold">$<?php echo number_format($celular['precio'], 2); ?></p>
                        </div>
                        
                        <!-- Ubicación -->
                        <?php if ($celular['tienda_nombre']): ?>
                        <div class="pt-3 border-t border-gray-200">
                            <p class="text-xs font-medium text-gray-700 mb-1 flex items-center">
                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                                <?php echo htmlspecialchars($celular['tienda_nombre']); ?>
                            </p>
                            <?php if ($celular['tienda_direccion']): ?>
                            <p class="text-xs text-gray-500">
                                <?php echo htmlspecialchars($celular['tienda_direccion']); ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($celular['tienda_telefono']): ?>
                            <p class="text-xs text-gray-500 flex items-center mt-1">
                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                </svg>
                                <?php echo htmlspecialchars($celular['tienda_telefono']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        
        <!-- Sin resultados -->
        <?php if (empty($productos) && empty($celulares)): ?>
        <div class="text-center py-16">
            <svg class="w-24 h-24 mx-auto text-gray-400 mb-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
            <h3 class="text-2xl font-bold text-gray-700 mb-2">No se encontraron resultados</h3>
            <p class="text-gray-500 mb-6">Intenta ajustar los filtros o buscar con otros términos</p>
            <a href="index.php" class="inline-block bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white px-6 py-3 rounded-lg font-medium transition-all">
                Ver todo el catálogo
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Información de contacto -->
        <section class="mt-16 mb-8">
            <div class="contact-card rounded-xl p-8 shadow-lg">
                <div class="text-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">¿Tienes alguna pregunta?</h3>
                    <p class="text-gray-600">Estamos aquí para ayudarte</p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- WhatsApp -->
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-green-500 rounded-full mb-3">
                            <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                            </svg>
                        </div>
                        <h4 class="font-bold text-gray-900 mb-1">WhatsApp</h4>
                        <p class="text-gray-600 text-sm">Contáctanos por WhatsApp</p>
                    </div>
                    
                    <!-- Teléfono -->
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-500 rounded-full mb-3">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                            </svg>
                        </div>
                        <h4 class="font-bold text-gray-900 mb-1">Llámanos</h4>
                        <p class="text-gray-600 text-sm">Atención telefónica</p>
                    </div>
                    
                    <!-- Visítanos -->
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-purple-500 rounded-full mb-3">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                        </div>
                        <h4 class="font-bold text-gray-900 mb-1">Visítanos</h4>
                        <p class="text-gray-600 text-sm">Encuentra nuestras tiendas</p>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="gradient-header text-white py-8 no-print">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-6">
                <!-- Columna 1: Información -->
                <div>
                    <h4 class="text-lg font-bold mb-3">Sobre Nosotros</h4>
                    <p class="text-purple-100 text-sm mb-3">
                        Somos tu mejor opción en celulares y accesorios de calidad. 
                        Contamos con múltiples sucursales para atenderte mejor.
                    </p>
                    <div class="flex items-center text-purple-100 text-sm">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Horario: Lun - Sáb 9:00 AM - 7:00 PM
                    </div>
                </div>
                
                <!-- Columna 2: Enlaces rápidos -->
                <div>
                    <h4 class="text-lg font-bold mb-3">Enlaces Rápidos</h4>
                    <ul class="space-y-2 text-sm text-purple-100">
                        <li>
                            <a href="index.php" class="hover:text-white transition-colors flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                </svg>
                                Inicio
                            </a>
                        </li>
                        <li>
                            <a href="index.php?tipo=accesorio" class="hover:text-white transition-colors flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
                                Accesorios
                            </a>
                        </li>
                        <li>
                            <a href="index.php?tipo=repuesto" class="hover:text-white transition-colors flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                Repuestos
                            </a>
                        </li>
                        <li>
                            <a href="public/login.php" class="hover:text-white transition-colors flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                                </svg>
                                Acceso Sistema
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- Columna 3: Contacto -->
                <div>
                    <h4 class="text-lg font-bold mb-3">Contáctanos</h4>
                    <div class="space-y-3 text-sm text-purple-100">
                        <div class="flex items-start">
                            <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            <div>
                                <p class="font-medium text-white">Ubicación</p>
                                <p>Consulta nuestras tiendas</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                            <div>
                                <p class="font-medium text-white">Email</p>
                                <p>contacto@tutienda.com</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Copyright -->
            <div class="border-t border-purple-400 pt-6 text-center">
                <p class="text-purple-100 text-sm">
                    © <?php echo date('Y'); ?> <?php echo SYSTEM_NAME; ?>. Todos los derechos reservados.
                </p>
                <p class="text-purple-200 text-xs mt-2">
                    Sistema v<?php echo SYSTEM_VERSION; ?> | Catálogo actualizado en tiempo real
                </p>
            </div>
        </div>
    </footer>

    <!-- Botón flotante de WhatsApp -->
    <a href="https://wa.me/51999999999?text=Hola,%20me%20interesa%20información%20sobre%20sus%20productos" 
       target="_blank"
       class="no-print fixed bottom-6 right-6 bg-green-500 hover:bg-green-600 text-white rounded-full p-4 shadow-2xl transition-all hover:scale-110 z-50"
       title="Chatea con nosotros por WhatsApp">
        <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
        </svg>
    </a>

    <!-- Scripts -->
    <script>
        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Animación de entrada para las cards
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.product-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            observer.observe(card);
        });

        // Botón volver arriba (aparece al hacer scroll)
        window.addEventListener('scroll', () => {
            const scrollTop = document.getElementById('scrollTop');
            if (scrollTop) {
                if (window.pageYOffset > 300) {
                    scrollTop.classList.remove('hidden');
                } else {
                    scrollTop.classList.add('hidden');
                }
            }
        });

        // Contador de items
        console.log('📱 Catálogo cargado:');
        console.log('- Productos: <?php echo count($productos); ?>');
        console.log('- Celulares: <?php echo count($celulares); ?>');
        console.log('- Total items: <?php echo $stats["total_items"]; ?>');
    </script>

    <!-- Botón volver arriba -->
    <button id="scrollTop" 
            onclick="window.scrollTo({top: 0, behavior: 'smooth'})"
            class="no-print hidden fixed bottom-24 right-6 bg-purple-600 hover:bg-purple-700 text-white rounded-full p-3 shadow-lg transition-all hover:scale-110 z-40"
            title="Volver arriba">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path>
        </svg>
    </button>

</body>
</html>