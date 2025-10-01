<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

setSecurityHeaders();
startSecureSession();
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Definir rango de fechas
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-01');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$tienda_filtro = isset($_GET['tienda_filtro']) && hasPermission('admin') ? intval($_GET['tienda_filtro']) : null;
$vendedor_filtro = isset($_GET['vendedor_filtro']) && hasPermission('admin') ? intval($_GET['vendedor_filtro']) : null;

try {
    // Reportes según el rol
    if (hasPermission('admin')) {
        // Reporte detallado para admin
        $base_conditions = "WHERE DATE(v.fecha_venta) BETWEEN ? AND ?";
        $params = [$fecha_inicio, $fecha_fin];
        
        // Agregar filtros adicionales
        if ($tienda_filtro) {
            $base_conditions .= " AND v.tienda_id = ?";
            $params[] = $tienda_filtro;
        }
        
        if ($vendedor_filtro) {
            $base_conditions .= " AND v.vendedor_id = ?";
            $params[] = $vendedor_filtro;
        }
        
        $ventas_query = "
            SELECT 
                v.id as venta_id,
                DATE(v.fecha_venta) as fecha,
                TIME(v.fecha_venta) as hora,
                c.modelo,
                c.marca,
                c.capacidad,
                c.color,
                c.imei1,
                v.precio_venta,
                v.metodo_pago,
                v.cliente_nombre,
                v.cliente_telefono,
                v.notas as notas_venta,
                t.nombre as tienda_nombre,
                u.nombre as vendedor_nombre,
                u.username as vendedor_usuario,
                c.precio_compra,
                CASE 
                    WHEN c.precio_compra IS NOT NULL THEN v.precio_venta - c.precio_compra 
                    ELSE NULL 
                END as ganancia_venta
            FROM ventas v
            LEFT JOIN celulares c ON v.celular_id = c.id
            LEFT JOIN tiendas t ON v.tienda_id = t.id
            LEFT JOIN usuarios u ON v.vendedor_id = u.id
            $base_conditions
            ORDER BY v.fecha_venta DESC, v.id DESC
        ";
        $ventas_stmt = $db->prepare($ventas_query);
        $ventas_stmt->execute($params);
        $ventas_detalladas = $ventas_stmt->fetchAll();
        
        // Estadísticas globales
        $stats_query = "
            SELECT 
                COUNT(DISTINCT v.id) as total_ventas,
                COALESCE(SUM(v.precio_venta), 0) as total_ingresos,
                COALESCE(AVG(v.precio_venta), 0) as promedio_venta,
                COUNT(DISTINCT v.tienda_id) as tiendas_activas,
                COUNT(DISTINCT v.vendedor_id) as vendedores_activos,
                COUNT(DISTINCT DATE(v.fecha_venta)) as dias_con_ventas,
                COALESCE(SUM(CASE WHEN c.precio_compra IS NOT NULL THEN v.precio_venta - c.precio_compra ELSE 0 END), 0) as ganancia_total
            FROM ventas v
            LEFT JOIN celulares c ON v.celular_id = c.id
            $base_conditions
        ";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->execute($params);
        $stats = $stats_stmt->fetch();
        
        // Top vendedores
        $vendedores_query = "
            SELECT 
                u.nombre as vendedor_nombre,
                u.username,
                t.nombre as tienda_nombre,
                COUNT(v.id) as total_ventas,
                SUM(v.precio_venta) as ingresos_vendedor,
                AVG(v.precio_venta) as promedio_vendedor,
                COALESCE(SUM(CASE WHEN c.precio_compra IS NOT NULL THEN v.precio_venta - c.precio_compra ELSE 0 END), 0) as ganancia_vendedor
            FROM ventas v
            JOIN usuarios u ON v.vendedor_id = u.id
            LEFT JOIN tiendas t ON v.tienda_id = t.id
            LEFT JOIN celulares c ON v.celular_id = c.id
            $base_conditions
            GROUP BY u.id, u.nombre, u.username, t.nombre
            ORDER BY total_ventas DESC, ingresos_vendedor DESC
            LIMIT 10
        ";
        $vendedores_stmt = $db->prepare($vendedores_query);
        $vendedores_stmt->execute($params);
        $top_vendedores = $vendedores_stmt->fetchAll();
        
        // Estadísticas por tienda
        $tiendas_query = "
            SELECT 
                t.nombre as tienda_nombre,
                COUNT(v.id) as total_ventas,
                SUM(v.precio_venta) as ingresos_tienda,
                AVG(v.precio_venta) as promedio_tienda,
                COUNT(DISTINCT v.vendedor_id) as vendedores_activos,
                COALESCE(SUM(CASE WHEN c.precio_compra IS NOT NULL THEN v.precio_venta - c.precio_compra ELSE 0 END), 0) as ganancia_tienda
            FROM ventas v
            JOIN tiendas t ON v.tienda_id = t.id
            LEFT JOIN celulares c ON v.celular_id = c.id
            $base_conditions
            GROUP BY t.id, t.nombre
            ORDER BY total_ventas DESC
        ";
        $tiendas_stmt = $db->prepare($tiendas_query);
        $tiendas_stmt->execute($params);
        $stats_tiendas = $tiendas_stmt->fetchAll();
        
    } else {
        // Reporte específico para vendedor
        $base_conditions = "WHERE v.tienda_id = ? AND DATE(v.fecha_venta) BETWEEN ? AND ?";
        $params = [$user['tienda_id'], $fecha_inicio, $fecha_fin];
        
        $ventas_query = "
            SELECT 
                v.id as venta_id,
                DATE(v.fecha_venta) as fecha,
                TIME(v.fecha_venta) as hora,
                c.modelo,
                c.marca,
                c.capacidad,
                c.color,
                c.imei1,
                v.precio_venta,
                v.metodo_pago,
                v.cliente_nombre,
                v.cliente_telefono,
                v.notas as notas_venta,
                u.nombre as vendedor_nombre
            FROM ventas v
            LEFT JOIN celulares c ON v.celular_id = c.id
            LEFT JOIN usuarios u ON v.vendedor_id = u.id
            $base_conditions
            ORDER BY v.fecha_venta DESC, v.id DESC
        ";
        $ventas_stmt = $db->prepare($ventas_query);
        $ventas_stmt->execute($params);
        $ventas_detalladas = $ventas_stmt->fetchAll();
        
        // Estadísticas del vendedor/tienda
        $stats_query = "
            SELECT 
                COUNT(v.id) as total_ventas,
                COALESCE(SUM(v.precio_venta), 0) as total_ingresos,
                COALESCE(AVG(v.precio_venta), 0) as promedio_venta,
                COUNT(DISTINCT DATE(v.fecha_venta)) as dias_con_ventas,
                COUNT(DISTINCT v.vendedor_id) as vendedores_activos
            FROM ventas v
            $base_conditions
        ";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->execute($params);
        $stats = $stats_stmt->fetch();
        
        $top_vendedores = [];
        $stats_tiendas = [];
    }
    
    // Top productos vendidos
    $productos_query = "
        SELECT 
            c.modelo,
            c.marca,
            COUNT(v.id) as cantidad_vendida,
            SUM(v.precio_venta) as ingresos_producto,
            AVG(v.precio_venta) as precio_promedio
        FROM ventas v
        JOIN celulares c ON v.celular_id = c.id
        $base_conditions
        GROUP BY c.modelo, c.marca
        ORDER BY cantidad_vendida DESC, ingresos_producto DESC
        LIMIT 10
    ";
    $productos_stmt = $db->prepare($productos_query);
    $productos_stmt->execute($params);
    $top_productos = $productos_stmt->fetchAll();
    
    // Obtener listas para filtros (solo admin)
    $tiendas_filtro = [];
    $vendedores_filtro = [];
    
    if (hasPermission('admin')) {
        $tiendas_stmt = $db->query("SELECT id, nombre FROM tiendas WHERE activa = 1 ORDER BY nombre");
        $tiendas_filtro = $tiendas_stmt->fetchAll();
        
        $vendedores_stmt = $db->query("
            SELECT u.id, u.nombre, u.username, t.nombre as tienda_nombre 
            FROM usuarios u 
            LEFT JOIN tiendas t ON u.tienda_id = t.id 
            WHERE u.rol = 'vendedor' AND u.activo = 1 
            ORDER BY u.nombre
        ");
        $vendedores_filtro = $vendedores_stmt->fetchAll();
    }
    
    // Inventario actual
    $inventario_query = hasPermission('admin') ? 
        "SELECT estado, COUNT(*) as cantidad FROM celulares WHERE estado IS NOT NULL GROUP BY estado" :
        "SELECT estado, COUNT(*) as cantidad FROM celulares WHERE tienda_id = ? AND estado IS NOT NULL GROUP BY estado";
    
    $inventario_stmt = $db->prepare($inventario_query);
    if (hasPermission('admin')) {
        $inventario_stmt->execute();
    } else {
        $inventario_stmt->execute([$user['tienda_id']]);
    }
    $inventario_stats = $inventario_stmt->fetchAll();
    
    // Asegurar que las estadísticas no sean null
    if (!$stats) {
        $stats = [
            'total_ventas' => 0,
            'total_ingresos' => 0,
            'promedio_venta' => 0,
            'dias_con_ventas' => 0,
            'tiendas_activas' => 0,
            'vendedores_activos' => 0,
            'ganancia_total' => 0
        ];
    }
    
} catch(Exception $e) {
    logError("Error en reportes: " . $e->getMessage());
    $ventas_detalladas = [];
    $stats = [
        'total_ventas' => 0,
        'total_ingresos' => 0,
        'promedio_venta' => 0,
        'dias_con_ventas' => 0,
        'tiendas_activas' => 0,
        'vendedores_activos' => 0,
        'ganancia_total' => 0
    ];
    $top_productos = [];
    $top_vendedores = [];
    $stats_tiendas = [];
    $inventario_stats = [];
}

// Incluir el navbar/sidebar unificado
require_once '../includes/navbar_unified.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes Detallados - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .print-full { width: 100% !important; margin: 0 !important; }
            body { font-size: 12px; }
        }
        .highlight-row:hover { background-color: #f0f9ff !important; }
        .profit-positive { color: #059669; }
        .profit-negative { color: #dc2626; }
        .profit-neutral { color: #6b7280; }
    </style>
</head>
<body class="bg-gray-100">
    
    <div class="no-print">
        <?php renderNavbar('reports'); ?>
    </div>
    
    <!-- Contenido principal -->
    <main class="page-content print-full">
        <div class="p-6">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 no-print">
                <div>
                    <h2 class="text-3xl font-bold text-gray-900">Reportes Detallados</h2>
                    <p class="text-gray-600">Análisis completo de ventas con vendedor y tienda</p>
                </div>
                <div class="flex space-x-2 mt-4 md:mt-0">
                    <button onclick="exportToCSV()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Exportar CSV
                    </button>
                    <button onclick="window.print()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                        Imprimir
                    </button>
                </div>
            </div>

            <!-- Filtros Avanzados -->
            <div class="bg-white rounded-lg shadow-sm p-4 mb-6 no-print">
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Inicio</label>
                            <input type="date" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio); ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Fin</label>
                            <input type="date" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin); ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <?php if (hasPermission('admin')): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tienda</label>
                            <select name="tienda_filtro" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Todas las tiendas</option>
                                <?php foreach($tiendas_filtro as $tienda): ?>
                                    <option value="<?php echo $tienda['id']; ?>" <?php echo $tienda_filtro == $tienda['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tienda['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Vendedor</label>
                            <select name="vendedor_filtro" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Todos los vendedores</option>
                                <?php foreach($vendedores_filtro as $vendedor): ?>
                                    <option value="<?php echo $vendedor['id']; ?>" <?php echo $vendedor_filtro == $vendedor['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vendedor['nombre']); ?>
                                        <?php if ($vendedor['tienda_nombre']): ?>
                                            (<?php echo htmlspecialchars($vendedor['tienda_nombre']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex gap-3">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                            Filtrar Reportes
                        </button>
                        <button type="button" onclick="resetFilters()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                            Limpiar Filtros
                        </button>
                    </div>
                </form>
            </div>

            <!-- Estadísticas Generales -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-center">
                        <div class="bg-blue-100 rounded-lg p-2 mx-auto w-12 h-12 flex items-center justify-center mb-2">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                        </div>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_ventas']); ?></p>
                        <p class="text-sm text-gray-600">Total Ventas</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-center">
                        <div class="bg-green-100 rounded-lg p-2 mx-auto w-12 h-12 flex items-center justify-center mb-2">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                            </svg>
                        </div>
                        <p class="text-2xl font-bold text-gray-900">$<?php echo number_format($stats['total_ingresos'], 2); ?></p>
                        <p class="text-sm text-gray-600">Ingresos Totales</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-center">
                        <div class="bg-purple-100 rounded-lg p-2 mx-auto w-12 h-12 flex items-center justify-center mb-2">
                            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <p class="text-2xl font-bold text-gray-900">$<?php echo number_format($stats['promedio_venta'], 2); ?></p>
                        <p class="text-sm text-gray-600">Promedio Venta</p>
                    </div>
                </div>

                <?php if (hasPermission('admin') && isset($stats['ganancia_total'])): ?>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-center">
                        <div class="bg-yellow-100 rounded-lg p-2 mx-auto w-12 h-12 flex items-center justify-center mb-2">
                            <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                            </svg>
                        </div>
                        <p class="text-2xl font-bold text-green-600">$<?php echo number_format($stats['ganancia_total'], 2); ?></p>
                        <p class="text-sm text-gray-600">Ganancia Total</p>
                    </div>
                </div>
                <?php endif; ?>

                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-center">
                        <div class="bg-indigo-100 rounded-lg p-2 mx-auto w-12 h-12 flex items-center justify-center mb-2">
                            <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['dias_con_ventas']; ?></p>
                        <p class="text-sm text-gray-600">Días con Ventas</p>
                    </div>
                </div>

                <?php if (hasPermission('admin')): ?>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="text-center">
                        <div class="bg-red-100 rounded-lg p-2 mx-auto w-12 h-12 flex items-center justify-center mb-2">
                            <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                            </svg>
                        </div>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['vendedores_activos']; ?></p>
                        <p class="text-sm text-gray-600">Vendedores Activos</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tabla de Ventas Detalladas -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-900">Ventas Detalladas</h3>
                        <div class="text-sm text-gray-500">
                            Total de registros: <?php echo count($ventas_detalladas); ?>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full" id="ventas-table">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha/Hora</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dispositivo</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cliente</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendedor</th>
                                <?php if (hasPermission('admin')): ?>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tienda</th>
                                <?php endif; ?>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Precio</th>
                                <?php if (hasPermission('admin')): ?>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ganancia</th>
                                <?php endif; ?>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pago</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($ventas_detalladas)): ?>
                                <tr>
                                    <td colspan="<?php echo hasPermission('admin') ? '9' : '7'; ?>" class="px-4 py-8 text-center">
                                        <div class="flex flex-col items-center">
                                            <svg class="w-12 h-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                            </svg>
                                            <p class="text-gray-500 text-lg font-medium">No hay ventas en el período seleccionado</p>
                                            <p class="text-gray-400 text-sm mt-1">Intenta ajustar el rango de fechas o filtros</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($ventas_detalladas as $venta): ?>
                                    <tr class="highlight-row">
                                        <td class="px-4 py-4 text-sm font-mono text-blue-600">#<?php echo $venta['venta_id']; ?></td>
                                        <td class="px-4 py-4 text-sm text-gray-900">
                                            <div>
                                                <p class="font-medium"><?php echo date('d/m/Y', strtotime($venta['fecha'])); ?></p>
                                                <p class="text-xs text-gray-500"><?php echo date('H:i', strtotime($venta['hora'])); ?></p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-gray-900">
                                            <div>
                                                <p class="font-medium"><?php echo htmlspecialchars($venta['modelo']); ?></p>
                                                <p class="text-xs text-gray-500">
                                                    <?php echo htmlspecialchars($venta['marca']); ?> - <?php echo htmlspecialchars($venta['capacidad']); ?>
                                                    <?php if ($venta['color']): ?>- <?php echo htmlspecialchars($venta['color']); ?><?php endif; ?>
                                                </p>
                                                <?php if ($venta['imei1']): ?>
                                                    <p class="text-xs text-gray-400 font-mono">IMEI: <?php echo htmlspecialchars($venta['imei1']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-gray-900">
                                            <div>
                                                <p class="font-medium"><?php echo htmlspecialchars($venta['cliente_nombre']); ?></p>
                                                <?php if ($venta['cliente_telefono']): ?>
                                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($venta['cliente_telefono']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-gray-900">
                                            <div>
                                                <p class="font-medium"><?php echo htmlspecialchars($venta['vendedor_nombre']); ?></p>
                                                <?php if (hasPermission('admin') && isset($venta['vendedor_usuario'])): ?>
                                                    <p class="text-xs text-gray-500">@<?php echo htmlspecialchars($venta['vendedor_usuario']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <?php if (hasPermission('admin')): ?>
                                        <td class="px-4 py-4 text-sm">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <?php echo htmlspecialchars($venta['tienda_nombre']); ?>
                                            </span>
                                        </td>
                                        <?php endif; ?>
                                        <td class="px-4 py-4 text-sm font-semibold text-green-600">
                                            $<?php echo number_format($venta['precio_venta'], 2); ?>
                                        </td>
                                        <?php if (hasPermission('admin')): ?>
                                        <td class="px-4 py-4 text-sm">
                                            <?php if (isset($venta['ganancia_venta']) && $venta['ganancia_venta'] !== null): ?>
                                                <span class="<?php echo $venta['ganancia_venta'] > 0 ? 'profit-positive' : ($venta['ganancia_venta'] < 0 ? 'profit-negative' : 'profit-neutral'); ?> font-medium">
                                                    $<?php echo number_format($venta['ganancia_venta'], 2); ?>
                                                </span>
                                                <?php 
                                                $margin = $venta['precio_venta'] > 0 ? ($venta['ganancia_venta'] / $venta['precio_venta']) * 100 : 0;
                                                ?>
                                                <p class="text-xs text-gray-500"><?php echo number_format($margin, 1); ?>% margen</p>
                                            <?php else: ?>
                                                <span class="text-gray-400">N/D</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="px-4 py-4 text-sm">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php 
                                                echo $venta['metodo_pago'] === 'efectivo' ? 'bg-green-100 text-green-800' : 
                                                    ($venta['metodo_pago'] === 'tarjeta' ? 'bg-blue-100 text-blue-800' : 
                                                    ($venta['metodo_pago'] === 'transferencia' ? 'bg-purple-100 text-purple-800' : 'bg-yellow-100 text-yellow-800')); 
                                            ?>">
                                                <?php echo ucfirst($venta['metodo_pago']); ?>
                                            </span>
                                            <?php if (isset($venta['notas_venta']) && $venta['notas_venta']): ?>
                                                <p class="text-xs text-gray-500 mt-1" title="<?php echo htmlspecialchars($venta['notas_venta']); ?>">
                                                    <?php echo strlen($venta['notas_venta']) > 30 ? substr(htmlspecialchars($venta['notas_venta']), 0, 30) . '...' : htmlspecialchars($venta['notas_venta']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Resumen de productos más vendidos -->
            <?php if (!empty($top_productos)): ?>
            <div class="mt-8 bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Productos Más Vendidos</h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach($top_productos as $index => $producto): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                <div class="flex items-center">
                                    <span class="flex items-center justify-center w-8 h-8 bg-blue-600 text-white rounded-full text-sm font-bold mr-3">
                                        <?php echo $index + 1; ?>
                                    </span>
                                    <div>
                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars($producto['modelo']); ?></p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($producto['marca']); ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-blue-600"><?php echo $producto['cantidad_vendida']; ?> vendidos</p>
                                    <p class="text-sm text-green-600">$<?php echo number_format($producto['ingresos_producto'], 2); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Footer del reporte (solo para imprimir) -->
            <div class="hidden print:block mt-8 pt-4 border-t text-center text-sm text-gray-500">
                <p>Reporte generado el <?php echo date('d/m/Y H:i:s'); ?></p>
                <p>Usuario: <?php echo htmlspecialchars($user['nombre']); ?></p>
                <?php if (hasPermission('admin')): ?>
                    <p>Tipo de usuario: Administrador</p>
                <?php else: ?>
                    <p>Tienda: <?php echo htmlspecialchars($user['tienda_nombre']); ?></p>
                <?php endif; ?>
                <p><?php echo SYSTEM_NAME; ?> v<?php echo SYSTEM_VERSION; ?></p>
            </div>
        </div>
    </main>

    <script>
        // Función para reiniciar filtros
        function resetFilters() {
            const hoy = new Date();
            const primerDiaMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
            
            document.querySelector('input[name="fecha_inicio"]').value = primerDiaMes.toISOString().split('T')[0];
            document.querySelector('input[name="fecha_fin"]').value = hoy.toISOString().split('T')[0];
            
            <?php if (hasPermission('admin')): ?>
            const tiendaSelect = document.querySelector('select[name="tienda_filtro"]');
            const vendedorSelect = document.querySelector('select[name="vendedor_filtro"]');
            if (tiendaSelect) tiendaSelect.value = '';
            if (vendedorSelect) vendedorSelect.value = '';
            <?php endif; ?>
            
            window.location.href = 'reports.php';
        }

        // Función para exportar a CSV
        function exportToCSV() {
            const table = document.getElementById('ventas-table');
            const rows = table.querySelectorAll('tr');
            let csv = [];
            
            for (let i = 0; i < rows.length; i++) {
                const row = [];
                const cols = rows[i].querySelectorAll('td, th');
                
                for (let j = 0; j < cols.length; j++) {
                    let cellData = cols[j].innerText.replace(/"/g, '""');
                    if (cellData.search(/("|,|\n)/g) >= 0) {
                        cellData = '"' + cellData + '"';
                    }
                    row.push(cellData);
                }
                csv.push(row.join(','));
            }
            
            const csvString = csv.join('\n');
            const blob = new Blob(['\ufeff' + csvString], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', 'reporte_ventas_detalladas_' + new Date().toISOString().slice(0,10) + '.csv');
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // Validar fechas
        document.addEventListener('DOMContentLoaded', function() {
            const fechaInicio = document.querySelector('input[name="fecha_inicio"]');
            const fechaFin = document.querySelector('input[name="fecha_fin"]');
            
            if (fechaInicio && fechaFin) {
                fechaInicio.addEventListener('change', function() {
                    if (this.value > fechaFin.value) {
                        fechaFin.value = this.value;
                    }
                });
                
                fechaFin.addEventListener('change', function() {
                    if (this.value < fechaInicio.value) {
                        fechaInicio.value = this.value;
                    }
                });
            }
        });
    </script>
</body>
</html>