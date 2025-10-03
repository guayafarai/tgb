<?php
/**
 * INDEX PÚBLICO - CATÁLOGO DE PRODUCTOS CON CONFIGURACIÓN DINÁMICA
 * Sistema de Inventario de Celulares
 * Muestra productos y celulares disponibles al público con personalización
 */

require_once __DIR__ . '/../config/database.php';

// Configuración de headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$db = getDB();

// ==========================================
// CARGAR CONFIGURACIÓN DEL CATÁLOGO
// ==========================================
try {
    $config_stmt = $db->query("SELECT clave, valor FROM configuracion_catalogo");
    $config_rows = $config_stmt->fetchAll();
    
    $config = [];
    foreach ($config_rows as $row) {
        $config[$row['clave']] = $row['valor'];
    }
    
    // Función helper para obtener configuración
    function getConfig($key, $default = '') {
        global $config;
        return isset($config[$key]) ? $config[$key] : $default;
    }
    
} catch(Exception $e) {
    error_log("Error al cargar configuración del catálogo: " . $e->getMessage());
    $config = [];
    function getConfig($key, $default = '') {
        return $default;
    }
}

// Verificar si el catálogo está activo
if (getConfig('catalogo_activo', '1') != '1') {
    echo '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Catálogo No Disponible</title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    </head>
    <body class="bg-gray-100 flex items-center justify-center min-h-screen">
        <div class="text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Catálogo Temporalmente No Disponible</h1>
            <p class="text-gray-600">Estamos trabajando en mejoras. Vuelve pronto.</p>
        </div>
    </body>
    </html>';
    exit;
}

// Obtener filtros
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$tipo_filter = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$precio_max = isset($_GET['precio_max']) ? floatval($_GET['precio_max']) : 0;

// Items por página desde configuración
$items_por_pagina = intval(getConfig('catalogo_items_por_pagina', '20'));
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina - 1) * $items_por_pagina;

try {
    $productos = [];
    $celulares = [];
    
    // ============== PRODUCTOS DISPONIBLES ==============
    if (getConfig('catalogo_mostrar_productos', '1') == '1') {
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
            LIMIT $items_por_pagina OFFSET $offset
        ";
        
        $productos_stmt = $db->prepare($productos_query);
        $productos_stmt->execute($productos_params);
        $productos = $productos_stmt->fetchAll();
    }
    
    // ============== CELULARES DISPONIBLES ==============
    if (getConfig('catalogo_mostrar_celulares', '1') == '1') {
        $celulares_where = ["c.estado = 'disponible'"];
        $celulares_params = [];
        
        if (!empty($search)) {
            $celulares_where[] = "(c.modelo LIKE ? OR c.marca LIKE ? OR c.capacidad LIKE ?)";
            $search_param = "%$search%";
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
            LIMIT $items_por_pagina OFFSET $offset
        ";
        
        $celulares_stmt = $db->prepare($celulares_query);
        $celulares_stmt->execute($celulares_params);
        $celulares = $celulares_stmt->fetchAll();
    }
    
    // ============== ESTADÍSTICAS GENERALES ==============
    $stats = [
        'total_productos' => count($productos),
        'total_celulares' => count($celulares),
        'total_items' => count($productos) + count($celulares)
    ];
    
} catch(Exception $e) {
    error_log("Error en index público: " . $e->getMessage());
    $productos = [];
    $celulares = [];
    $stats = ['total_productos' => 0, 'total_celulares' => 0, 'total_items' => 0];
}

// Configuración de colores
$color_principal = getConfig('catalogo_color_principal', '#667eea');
$color_secundario = getConfig('catalogo_color_secundario', '#764ba2');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars(getConfig('catalogo_meta_description', 'Catálogo de celulares y accesorios')); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars(getConfig('catalogo_meta_keywords', 'celulares, accesorios, smartphones')); ?>">
    <title><?php echo htmlspecialchars(getConfig('catalogo_titulo', 'Catálogo de Productos')); ?> - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        :root {
            --color-principal: <?php echo $color_principal; ?>;
            --color-secundario: <?php echo $color_secundario; ?>;
        }
        .gradient-header {
            background: linear-gradient(135deg, var(--color-principal) 0%, var(--color-secundario) 100%);
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
            background: linear-gradient(135deg, var(--color-principal) 0%, var(--color-secundario) 100%);
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
            background: linear-gradient(135deg, var(--color-principal) 0%, var(--color-secundario) 100%);
            position: relative;
            overflow: hidden;
        }
        .floating-badge {
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
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
                <h1 class="text-5xl font-bold mb-4"><?php echo htmlspecialchars(getConfig('catalogo_titulo', SYSTEM_NAME)); ?></h1>
                <p class="text-xl mb-8 text-purple-100"><?php echo htmlspecialchars(getConfig('catalogo_descripcion', 'Celulares y Accesorios de Calidad')); ?></p>
                
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
        </div>
    </div>

    <!-- Contenido Principal -->
    <main class="container mx-auto px-4 py-8">
        
        <!-- PRODUCTOS -->
        <?php if (!empty($productos)): ?>
        <section class="mb-12">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-3xl font-bold text-gray-900">
                    <span class="badge-product text-white px-4 py-2 rounded-lg">Productos Disponibles</span>
                </h2>
                <span class="text-gray-600"><?php echo count($productos); ?> productos</span>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($productos as $producto): ?>
                <div class="product-card bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="p-6">
                        <!-- Badge de tipo -->
                        <div class="flex items-center justify-between mb-4">
                            <span class="inline-block px-3 py-1 text-xs font-semibold text-white badge-product rounded-full">
                                <?php echo strtoupper($producto['tipo']); ?>
                            </span>
                            <span class="text-xs text-gray-500"><?php echo htmlspecialchars($producto['codigo_producto']); ?></span>
                        </div>
                        
                        <!-- Nombre del producto -->
                        <h3 class="text-lg font-bold text-gray-900 mb-2">
                            <?php echo htmlspecialchars($producto['nombre']); ?>
                        </h3>
                        
                        <!-- Marca y modelo -->
                        <div class="space-y-1 mb-4">
                            <?php if ($producto['marca']): ?>
                            <p class="text-sm text-gray-600">
                                <span class="font-semibold">Marca:</span> <?php echo htmlspecialchars($producto['marca']); ?>
                            </p>
                            <?php endif; ?>
                            
                            <?php if ($producto['modelo_compatible']): ?>
                            <p class="text-sm text-gray-600">
                                <span class="font-semibold">Compatible:</span> <?php echo htmlspecialchars($producto['modelo_compatible']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Descripción -->
                        <?php if ($producto['descripcion']): ?>
                        <p class="text-sm text-gray-600 mb-4 line-clamp-2">
                            <?php echo htmlspecialchars($producto['descripcion']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <!-- Stock y tiendas -->
                        <div class="border-t pt-4 mb-4">
                            <div class="flex items-center justify-between text-sm mb-2">
                                <span class="text-gray-600">Stock disponible:</span>
                                <span class="font-bold text-green-600"><?php echo $producto['stock_total']; ?> unidades</span>
                            </div>
                            <?php if ($producto['tiendas_disponibles']): ?>
                            <p class="text-xs text-gray-500">
                                En: <?php echo htmlspecialchars($producto['tiendas_disponibles']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Precio -->
                        <div class="flex items-center justify-between">
                            <span class="text-3xl font-bold text-purple-600">
                                $<?php echo number_format($producto['precio_venta'], 2); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        
        <!-- CELULARES -->
        <?php if (!empty($celulares)): ?>
        <section class="mb-12">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-3xl font-bold text-gray-900">
                    <span class="badge-phone text-white px-4 py-2 rounded-lg">Celulares Disponibles</span>
                </h2>
                <span class="text-gray-600"><?php echo count($celulares); ?> celulares</span>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($celulares as $celular): ?>
                <div class="product-card bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="p-6">
                        <!-- Badge de condición -->
                        <div class="flex items-center justify-between mb-4">
                            <span class="inline-block px-3 py-1 text-xs font-semibold text-white badge-phone rounded-full">
                                <?php echo strtoupper($celular['condicion']); ?>
                            </span>
                        </div>
                        
                        <!-- Marca y modelo -->
                        <h3 class="text-xl font-bold text-gray-900 mb-2">
                            <?php echo htmlspecialchars($celular['marca']); ?>
                        </h3>
                        <p class="text-lg text-gray-700 mb-4">
                            <?php echo htmlspecialchars($celular['modelo']); ?>
                        </p>
                        
                        <!-- Especificaciones -->
                        <div class="space-y-2 mb-4">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">Capacidad:</span>
                                <span class="font-semibold"><?php echo htmlspecialchars($celular['capacidad']); ?></span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">Color:</span>
                                <span class="font-semibold"><?php echo htmlspecialchars($celular['color']); ?></span>
                            </div>
                        </div>
                        
                        <!-- Tienda -->
                        <?php if ($celular['tienda_nombre']): ?>
                        <div class="border-t pt-4 mb-4">
                            <p class="text-xs text-gray-500 mb-1">
                                <span class="font-semibold">Tienda:</span> <?php echo htmlspecialchars($celular['tienda_nombre']); ?>
                            </p>
                            <?php if ($celular['tienda_telefono']): ?>
                            <p class="text-xs text-gray-500">
                                <span class="font-semibold">Tel:</span> <?php echo htmlspecialchars($celular['tienda_telefono']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Precio -->
                        <div class="flex items-center justify-between">
                            <span class="text-3xl font-bold text-pink-600">
                                $<?php echo number_format($celular['precio'], 2); ?>
                            </span>
                        </div>
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
        <?php if (getConfig('catalogo_whatsapp') || getConfig('catalogo_telefono') || getConfig('catalogo_email')): ?>
        <section class="mt-16 mb-8">
            <div class="contact-card rounded-xl p-8 shadow-lg">
                <div class="text-center mb-6">
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">¿Tienes alguna pregunta?</h3>
                    <p class="text-gray-600">Estamos aquí para ayudarte</p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <?php if (getConfig('catalogo_whatsapp')): ?>
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-green-500 rounded-full mb-3">
                            <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                            </svg>
                        </div>
                        <h4 class="font-bold text-gray-900 mb-1">WhatsApp</h4>
                        <p class="text-gray-600 text-sm"><?php echo htmlspecialchars(getConfig('catalogo_whatsapp')); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (getConfig('catalogo_telefono')): ?>
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-500 rounded-full mb-3">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                            </svg>
                        </div>
                        <h4 class="font-bold text-gray-900 mb-1">Llámanos</h4>
                        <p class="text-gray-600 text-sm"><?php echo htmlspecialchars(getConfig('catalogo_telefono')); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (getConfig('catalogo_email')): ?>
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-purple-500 rounded-full mb-3">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <h4 class="font-bold text-gray-900 mb-1">Email</h4>
                        <p class="text-gray-600 text-sm"><?php echo htmlspecialchars(getConfig('catalogo_email')); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="gradient-header text-white py-8 no-print">
        <div class="container mx-auto px-4">
            <div class="text-center">
                <p class="text-purple-100 text-sm">
                    © <?php echo date('Y'); ?> <?php echo htmlspecialchars(getConfig('catalogo_titulo', SYSTEM_NAME)); ?>. Todos los derechos reservados.
                </p>
                <p class="text-purple-200 text-xs mt-2">
                    Sistema v<?php echo SYSTEM_VERSION; ?> | Catálogo actualizado en tiempo real
                </p>
            </div>
        </div>
    </footer>

    <!-- Botón flotante de WhatsApp -->
    <?php if (getConfig('catalogo_whatsapp')): 
    $whatsapp = getConfig('catalogo_whatsapp');
    $mensaje = getConfig('catalogo_mensaje_whatsapp', 'Hola, me interesa información sobre sus productos');
    $whatsapp_url = "https://wa.me/{$whatsapp}?text=" . urlencode($mensaje);
    ?>
    <a href="<?php echo $whatsapp_url; ?>" 
       target="_blank"
       class="no-print fixed bottom-6 right-6 bg-green-500 hover:bg-green-600 text-white rounded-full p-4 shadow-2xl transition-all hover:scale-110 z-50"
       title="Chatea con nosotros por WhatsApp">
        <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
        </svg>
    </a>
    <?php endif; ?>

    <!-- Scripts -->
    <script>
        // Animación de entrada para las cards
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.product-card');
            
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 50);
            });
        });

        // Scroll suave
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

        // Botón volver arriba
        const scrollBtn = document.createElement('button');
        scrollBtn.innerHTML = '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg>';
        scrollBtn.className = 'no-print hidden fixed bottom-24 right-6 bg-purple-600 hover:bg-purple-700 text-white rounded-full p-3 shadow-lg transition-all hover:scale-110 z-40';
        scrollBtn.title = 'Volver arriba';
        scrollBtn.onclick = () => window.scrollTo({top: 0, behavior: 'smooth'});
        document.body.appendChild(scrollBtn);

        window.addEventListener('scroll', () => {
            if (window.pageYOffset > 300) {
                scrollBtn.classList.remove('hidden');
            } else {
                scrollBtn.classList.add('hidden');
            }
        });

        // Log de estadísticas
        console.log('📱 Catálogo cargado:');
        console.log('- Productos: <?php echo count($productos); ?>');
        console.log('- Celulares: <?php echo count($celulares); ?>');
        console.log('- Total items: <?php echo $stats["total_items"]; ?>');
    </script>

</body>
</html>
                