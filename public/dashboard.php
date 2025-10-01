<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

setSecurityHeaders();
startSecureSession();
requireLogin();

$user = getCurrentUser();
$db = getDB();

// Obtener estadísticas
try {
    // Estadísticas generales para admin, específicas de tienda para otros roles
    if ($user['rol'] === 'admin') {
        $stats_query = "SELECT * FROM vista_estadisticas_tienda ORDER BY tienda_nombre";
        $stats_stmt = $db->query($stats_query);
        $all_stats = $stats_stmt->fetchAll();
        
        // Estadísticas totales
        $total_dispositivos = array_sum(array_column($all_stats, 'total_dispositivos'));
        $total_disponibles = array_sum(array_column($all_stats, 'disponibles'));
        $total_valor = array_sum(array_column($all_stats, 'valor_inventario'));
        $total_ventas = array_sum(array_column($all_stats, 'total_ventas'));
    } else {
        $stats_query = "SELECT * FROM vista_estadisticas_tienda WHERE tienda_id = ?";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->execute([$user['tienda_id']]);
        $tienda_stats = $stats_stmt->fetch();
        
        $total_dispositivos = $tienda_stats['total_dispositivos'] ?? 0;
        $total_disponibles = $tienda_stats['disponibles'] ?? 0;
        $total_valor = $tienda_stats['valor_inventario'] ?? 0;
        $total_ventas = $tienda_stats['total_ventas'] ?? 0;
    }
    
    // Últimos dispositivos registrados
    if ($user['rol'] === 'admin') {
        $recent_query = "SELECT * FROM vista_inventario_completo ORDER BY fecha_registro DESC LIMIT 10";
        $recent_stmt = $db->query($recent_query);
    } else {
        $recent_query = "SELECT * FROM vista_inventario_completo WHERE tienda_id = ? ORDER BY fecha_registro DESC LIMIT 10";
        $recent_stmt = $db->prepare($recent_query);
        $recent_stmt->execute([$user['tienda_id']]);
    }
    $recent_devices = $recent_stmt->fetchAll();
    
} catch(Exception $e) {
    logError("Error al obtener estadísticas: " . $e->getMessage());
    $total_dispositivos = $total_disponibles = $total_valor = $total_ventas = 0;
    $recent_devices = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) {
            .sidebar-hidden { transform: translateX(-100%); }
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
                    <a href="dashboard.php" class="flex items-center px-4 py-2 text-gray-700 bg-blue-50 border-r-4 border-blue-500 rounded-l">
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
                    </a>
                    
                    <a href="sales.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded">
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
                <!-- Header -->
                <div class="mb-8">
                    <h2 class="text-3xl font-bold text-gray-900">Dashboard</h2>
                    <p class="text-gray-600">Resumen general del sistema</p>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="bg-blue-100 rounded-lg p-3">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-600">Total Dispositivos</p>
                                <p class="text-2xl font-semibold text-gray-900"><?php echo number_format($total_dispositivos); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="bg-green-100 rounded-lg p-3">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-600">Disponibles</p>
                                <p class="text-2xl font-semibold text-gray-900"><?php echo number_format($total_disponibles); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="bg-purple-100 rounded-lg p-3">
                                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-600">Valor Inventario</p>
                                <p class="text-2xl font-semibold text-gray-900">$<?php echo number_format($total_valor, 2); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="bg-yellow-100 rounded-lg p-3">
                                <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-600">Total Ventas</p>
                                <p class="text-2xl font-semibold text-gray-900"><?php echo number_format($total_ventas); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Últimos Dispositivos -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="p-6 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-900">Últimos Dispositivos Registrados</h3>
                        </div>
                        <div class="p-6">
                            <?php if (empty($recent_devices)): ?>
                                <p class="text-gray-500 text-center py-4">No hay dispositivos registrados</p>
                            <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach($recent_devices as $device): ?>
                                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                            <div>
                                                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($device['modelo']); ?></p>
                                                <p class="text-sm text-gray-600">
                                                    <?php echo htmlspecialchars($device['capacidad']); ?> - 
                                                    <span class="<?php 
                                                        echo $device['estado'] === 'disponible' ? 'text-green-600' : 
                                                            ($device['estado'] === 'vendido' ? 'text-red-600' : 'text-yellow-600'); 
                                                    ?>">
                                                        <?php echo ucfirst($device['estado']); ?>
                                                    </span>
                                                </p>
                                                <?php if ($user['rol'] === 'admin'): ?>
                                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($device['tienda_nombre']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-right">
                                                <p class="font-semibold text-gray-900">$<?php echo number_format($device['precio'], 2); ?></p>
                                                <p class="text-xs text-gray-500"><?php echo date('d/m/Y', strtotime($device['fecha_registro'])); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Estadísticas por Tienda (solo para admin) -->
                    <?php if ($user['rol'] === 'admin' && !empty($all_stats)): ?>
                        <div class="bg-white rounded-lg shadow">
                            <div class="p-6 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-900">Estadísticas por Tienda</h3>
                            </div>
                            <div class="p-6">
                                <div class="space-y-4">
                                    <?php foreach($all_stats as $stat): ?>
                                        <div class="border rounded-lg p-4">
                                            <h4 class="font-medium text-gray-900 mb-2"><?php echo htmlspecialchars($stat['tienda_nombre']); ?></h4>
                                            <div class="grid grid-cols-2 gap-4 text-sm">
                                                <div>
                                                    <span class="text-gray-600">Dispositivos:</span>
                                                    <span class="font-medium"><?php echo $stat['total_dispositivos']; ?></span>
                                                </div>
                                                <div>
                                                    <span class="text-gray-600">Disponibles:</span>
                                                    <span class="font-medium text-green-600"><?php echo $stat['disponibles']; ?></span>
                                                </div>
                                                <div>
                                                    <span class="text-gray-600">Valor:</span>
                                                    <span class="font-medium">$<?php echo number_format($stat['valor_inventario'], 2); ?></span>
                                                </div>
                                                <div>
                                                    <span class="text-gray-600">Ventas:</span>
                                                    <span class="font-medium text-blue-600"><?php echo $stat['total_ventas']; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Overlay para móvil -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 md:hidden hidden"></div>

    <script>
        // Toggle sidebar en móvil
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('sidebar-hidden');
            overlay.classList.toggle('hidden');
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.add('sidebar-hidden');
            overlay.classList.add('hidden');
        });

        // User menu toggle
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenu = document.getElementById('user-menu');

        userMenuButton.addEventListener('click', () => {
            userMenu.classList.toggle('hidden');
        });

        // Cerrar menú al hacer click fuera
        document.addEventListener('click', (e) => {
            if (!userMenuButton.contains(e.target) && !userMenu.contains(e.target)) {
                userMenu.classList.add('hidden');
            }
        });
    </script>
</body>
</html>