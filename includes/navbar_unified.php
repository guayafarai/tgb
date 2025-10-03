<?php
/**
 * COMPONENTE NAVBAR/SIDEBAR UNIFICADO
 * Sistema de Inventario de Celulares
 * VERSIÓN MODIFICADA CON BÚSQUEDA POR CÓDIGO DE BARRAS
 */

function renderNavbar($active_page = 'dashboard') {
    global $user;
    
    // Definir elementos del menú con permisos
    $menu_items = [
        [
            'id' => 'dashboard',
            'name' => 'Dashboard',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>',
            'url' => 'dashboard.php',
            'permission' => true,
            'badge' => null
        ],
        [
            'id' => 'inventory',
            'name' => 'Inventario Celulares',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>',
            'url' => 'inventory.php',
            'permission' => hasPermission('view_own_store_devices'),
            'badge' => hasPermission('view_own_store_devices') && !hasPermission('add_devices') ? 'Solo lectura' : null
        ],
        [
            'id' => 'products',
            'name' => 'Productos y Accesorios',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>',
            'url' => 'products.php',
            'permission' => true,
            'badge' => null
        ],
        [
            'id' => 'barcode_search',
            'name' => 'Buscar Código',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>',
            'url' => 'barcode_search.php',
            'permission' => true,
            'badge' => null
        ],
        [
            'id' => 'sales',
            'name' => 'Ventas Celulares',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>',
            'url' => 'sales.php',
            'permission' => hasPermission('view_sales'),
            'badge' => null
        ],
        [
            'id' => 'product_sales',
            'name' => 'Ventas Productos',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>',
            'url' => 'product_sales.php',
            'permission' => hasPermission('view_sales'),
            'badge' => null
        ],
        [
            'id' => 'reports',
            'name' => 'Reportes',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>',
            'url' => 'reports.php',
            'permission' => hasPermission('view_reports'),
            'badge' => hasPermission('admin') ? null : 'Su tienda'
        ]
    ];
    
    // Menú de administración
    $admin_items = [
        [
            'id' => 'users',
            'name' => 'Usuarios',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>',
            'url' => 'users.php',
            'permission' => hasPermission('manage_users')
        ],
        [
            'id' => 'stores',
            'name' => 'Tiendas',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>',
            'url' => 'stores.php',
            'permission' => hasPermission('manage_stores')
        ]
    ];
    
    // Obtener estadísticas rápidas para el header
    try {
        $db = getDB();
        $today = date('Y-m-d');
        
        if (hasPermission('admin')) {
            $stats_query = "SELECT COUNT(*) as ventas_hoy, COALESCE(SUM(precio_venta), 0) as ingresos_hoy 
                           FROM ventas WHERE DATE(fecha_venta) = ?";
            $stmt = $db->prepare($stats_query);
            $stmt->execute([$today]);
        } else {
            $stats_query = "SELECT COUNT(*) as ventas_hoy, COALESCE(SUM(precio_venta), 0) as ingresos_hoy 
                           FROM ventas WHERE DATE(fecha_venta) = ? AND tienda_id = ?";
            $stmt = $db->prepare($stats_query);
            $stmt->execute([$today, $user['tienda_id']]);
        }
        $daily_stats = $stmt->fetch();
    } catch(Exception $e) {
        $daily_stats = ['ventas_hoy' => 0, 'ingresos_hoy' => 0];
    }
    
    ?>
    
    <!-- Estilos del componente unificado -->
    <style>
        :root {
            --primary-color: #3b82f6;
            --primary-dark: #2563eb;
            --sidebar-width: 280px;
            --navbar-height: 64px;
            --transition-speed: 0.3s;
        }
        
        /* Navbar superior fijo */
        .unified-navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--navbar-height);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 1000;
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            color: white;
        }
        
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.25rem;
            font-weight: 700;
            color: white;
            text-decoration: none;
        }
        
        .navbar-brand-icon {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .navbar-stats {
            display: none;
            align-items: center;
            gap: 2rem;
            margin-left: auto;
            margin-right: 2rem;
        }
        
        @media (min-width: 1024px) {
            .navbar-stats {
                display: flex;
            }
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
        }
        
        .stat-label {
            font-size: 0.75rem;
            opacity: 0.9;
        }
        
        .navbar-user {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
        }
        
        .user-info {
            text-align: right;
            display: none;
        }
        
        @media (min-width: 768px) {
            .user-info {
                display: block;
            }
        }
        
        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .user-role {
            font-size: 0.75rem;
            opacity: 0.9;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            cursor: pointer;
            transition: background var(--transition-speed);
        }
        
        .user-avatar:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* Sidebar lateral */
        .unified-sidebar {
            position: fixed;
            top: var(--navbar-height);
            left: 0;
            width: var(--sidebar-width);
            height: calc(100vh - var(--navbar-height));
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
            overflow-y: auto;
            transition: transform var(--transition-speed) ease-in-out;
            z-index: 999;
        }
        
        .sidebar-hidden {
            transform: translateX(-100%);
        }
        
        @media (min-width: 768px) {
            .unified-sidebar {
                transform: translateX(0) !important;
            }
        }
        
        .sidebar-section {
            padding: 1.5rem 0;
        }
        
        .sidebar-section-title {
            padding: 0 1.5rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.05em;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .menu-item {
            margin: 0.25rem 0.75rem;
        }
        
        .menu-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            color: #4b5563;
            text-decoration: none;
            transition: all 0.2s;
            position: relative;
        }
        
        .menu-link:hover {
            background: #f3f4f6;
            color: var(--primary-color);
            transform: translateX(2px);
        }
        
        .menu-link.active {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            color: var(--primary-color);
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(59, 130, 246, 0.1);
        }
        
        .menu-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: var(--primary-color);
            border-radius: 0 4px 4px 0;
        }
        
        .menu-icon {
            width: 20px;
            height: 20px;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }
        
        .menu-badge {
            margin-left: auto;
            padding: 0.125rem 0.5rem;
            font-size: 0.65rem;
            font-weight: 600;
            border-radius: 9999px;
            background: #fef3c7;
            color: #92400e;
        }
        
        /* Content area */
        .page-content {
            margin-top: var(--navbar-height);
            margin-left: 0;
            transition: margin-left var(--transition-speed);
            min-height: calc(100vh - var(--navbar-height));
        }
        
        @media (min-width: 768px) {
            .page-content {
                margin-left: var(--sidebar-width);
            }
        }
        
        /* Mobile menu button */
        .mobile-menu-btn {
            display: block;
            padding: 0.5rem;
            border-radius: 0.5rem;
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .mobile-menu-btn:hover {
            background: rgba(255,255,255,0.2);
        }
        
        @media (min-width: 768px) {
            .mobile-menu-btn {
                display: none;
            }
        }
        
        /* Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 998;
            animation: fadeIn 0.3s;
        }
        
        .sidebar-overlay.show {
            display: block;
        }
        
        @media (min-width: 768px) {
            .sidebar-overlay {
                display: none !important;
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* User dropdown */
        .user-dropdown {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            width: 240px;
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s;
        }
        
        .user-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: #374151;
            text-decoration: none;
            transition: background 0.2s;
        }
        
        .dropdown-item:hover {
            background: #f9fafb;
        }
        
        .dropdown-item:first-child {
            border-radius: 0.5rem 0.5rem 0 0;
        }
        
        .dropdown-item:last-child {
            border-radius: 0 0 0.5rem 0.5rem;
        }
        
        .dropdown-divider {
            height: 1px;
            background: #e5e7eb;
            margin: 0.25rem 0;
        }
        
        /* Scrollbar personalizado */
        .unified-sidebar::-webkit-scrollbar {
            width: 6px;
        }
        
        .unified-sidebar::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        .unified-sidebar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        .unified-sidebar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Asegurar que no hay márgenes conflictivos */
        body {
            margin: 0;
            padding: 0;
        }
    </style>
    
    <!-- Navbar Superior -->
    <nav class="unified-navbar">
        <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>
        
        <a href="dashboard.php" class="navbar-brand">
            <div class="navbar-brand-icon">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
            </div>
            <span><?php echo SYSTEM_NAME; ?></span>
        </a>
        
        <div class="navbar-stats">
            <div class="stat-item">
                <div class="stat-value"><?php echo $daily_stats['ventas_hoy']; ?></div>
                <div class="stat-label">Ventas Hoy</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">$<?php echo number_format($daily_stats['ingresos_hoy'], 0); ?></div>
                <div class="stat-label">Ingresos Hoy</div>
            </div>
        </div>
        
        <div class="navbar-user">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user['nombre']); ?></div>
                <div class="user-role">
                    <?php echo ucfirst($user['rol']); ?>
                    <?php if ($user['tienda_nombre']): ?>
                        • <?php echo htmlspecialchars($user['tienda_nombre']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="user-avatar" id="userAvatar">
                <?php echo strtoupper(substr($user['nombre'], 0, 2)); ?>
            </div>
            <div class="user-dropdown" id="userDropdown">
                <a href="profile.php" class="dropdown-item">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    Mi Perfil
                </a>
                <div class="dropdown-divider"></div>
                <a href="logout.php" class="dropdown-item">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                    </svg>
                    Cerrar Sesión
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Sidebar Lateral -->
    <aside class="unified-sidebar sidebar-hidden" id="unifiedSidebar">
        <div class="sidebar-section">
            <ul class="sidebar-menu">
                <?php foreach ($menu_items as $item): ?>
                    <?php if ($item['permission']): ?>
                        <li class="menu-item">
                            <a href="<?php echo $item['url']; ?>" 
                               class="menu-link <?php echo $active_page === $item['id'] ? 'active' : ''; ?>">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <?php echo $item['icon']; ?>
                                </svg>
                                <span><?php echo $item['name']; ?></span>
                                <?php if ($item['badge']): ?>
                                    <span class="menu-badge"><?php echo $item['badge']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <?php if (hasPermission('admin')): ?>
            <div class="sidebar-section" style="border-top: 1px solid #e5e7eb;">
                <div class="sidebar-section-title">Administración</div>
                <ul class="sidebar-menu">
                    <?php foreach ($admin_items as $item): ?>
                        <?php if ($item['permission']): ?>
                            <li class="menu-item">
                                <a href="<?php echo $item['url']; ?>" 
                                   class="menu-link <?php echo $active_page === $item['id'] ? 'active' : ''; ?>">
                                    <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <?php echo $item['icon']; ?>
                                    </svg>
                                    <span><?php echo $item['name']; ?></span>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="sidebar-section" style="border-top: 1px solid #e5e7eb; padding: 1rem 1.5rem; color: #6b7280; font-size: 0.75rem;">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <strong>Sistema v<?php echo SYSTEM_VERSION; ?></strong>
            </div>
            <div style="font-size: 0.65rem; opacity: 0.7;">
                Última sesión: <?php echo $user['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($user['ultimo_acceso'])) : 'Primera vez'; ?>
            </div>
        </div>
    </aside>
    
    <!-- Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- JavaScript del componente -->
    <script>
        (function() {
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.getElementById('unifiedSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const userAvatar = document.getElementById('userAvatar');
            const userDropdown = document.getElementById('userDropdown');
            
            // Toggle sidebar mobile
            function toggleSidebar() {
                sidebar.classList.toggle('sidebar-hidden');
                overlay.classList.toggle('show');
            }
            
            mobileMenuBtn?.addEventListener('click', toggleSidebar);
            overlay?.addEventListener('click', toggleSidebar);
            
            // Toggle user dropdown
            userAvatar?.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdown.classList.toggle('show');
            });
            
            // Cerrar dropdown al hacer click fuera
            document.addEventListener('click', function(e) {
                if (!userAvatar?.contains(e.target) && !userDropdown?.contains(e.target)) {
                    userDropdown?.classList.remove('show');
                }
            });
            
            // Cerrar sidebar al cambiar de tamaño en desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    sidebar?.classList.remove('sidebar-hidden');
                    overlay?.classList.remove('show');
                }
            });
            
            // Cerrar sidebar al hacer click en un link (solo mobile)
            const menuLinks = document.querySelectorAll('.menu-link');
            menuLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        setTimeout(toggleSidebar, 150);
                    }
                });
            });
            
            // Atajos de teclado
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (!sidebar?.classList.contains('sidebar-hidden')) {
                        toggleSidebar();
                    }
                    userDropdown?.classList.remove('show');
                }
                
                if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
                    e.preventDefault();
                    toggleSidebar();
                }
            });
        })();
    </script>
    
    <?php
}
?>