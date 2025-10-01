<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

setSecurityHeaders();
startSecureSession();
requireLogin();

// Solo administradores pueden acceder
if (!hasPermission('admin')) {
    header('Location: dashboard.php');
    exit();
}

$user = getCurrentUser();
$db = getDB();

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'create_user':
                // Validar datos básicos
                $username = trim(sanitize($_POST['username'] ?? ''));
                $nombre = trim(sanitize($_POST['nombre'] ?? ''));
                $email = trim(sanitize($_POST['email'] ?? ''));
                $password = $_POST['password'] ?? '';
                $tienda_id = !empty($_POST['tienda_id']) ? intval($_POST['tienda_id']) : null;
                $rol = $_POST['rol'] ?? 'vendedor';
                
                // Validaciones
                if (empty($username) || empty($nombre) || empty($password)) {
                    throw new Exception('Nombre de usuario, nombre completo y contraseña son obligatorios');
                }
                
                if (strlen($username) < 3) {
                    throw new Exception('El nombre de usuario debe tener al menos 3 caracteres');
                }
                
                if (strlen($password) < 6) {
                    throw new Exception('La contraseña debe tener al menos 6 caracteres');
                }
                
                if (!empty($email) && !validateEmail($email)) {
                    throw new Exception('El formato del email no es válido');
                }
                
                // Solo permitir roles admin y vendedor
                if (!in_array($rol, ['vendedor', 'admin'])) {
                    throw new Exception('Rol no válido. Solo se permiten: Administrador o Vendedor');
                }
                
                // Si es vendedor, debe tener tienda asignada
                if ($rol === 'vendedor' && !$tienda_id) {
                    throw new Exception('Los vendedores deben tener una tienda asignada');
                }
                
                // Verificar que el username no exista
                $check_stmt = $db->prepare("SELECT id FROM usuarios WHERE username = ?");
                $check_stmt->execute([$username]);
                if ($check_stmt->fetch()) {
                    throw new Exception('El nombre de usuario ya existe');
                }
                
                // Si se proporciona email, verificar que no exista
                if (!empty($email)) {
                    $check_email_stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND email != ''");
                    $check_email_stmt->execute([$email]);
                    if ($check_email_stmt->fetch()) {
                        throw new Exception('El email ya está registrado');
                    }
                }
                
                // Crear usuario
                $stmt = $db->prepare("
                    INSERT INTO usuarios (username, password, nombre, email, tienda_id, rol, activo, fecha_creacion) 
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                
                $hashedPassword = hashPassword($password);
                $result = $stmt->execute([
                    $username, 
                    $hashedPassword, 
                    $nombre, 
                    $email ?: null, 
                    $tienda_id, 
                    $rol
                ]);
                
                if ($result) {
                    $newUserId = $db->lastInsertId();
                    logActivity($user['id'], 'create_user', "Usuario creado: $username ($rol)");
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Usuario creado correctamente',
                        'user_id' => $newUserId
                    ]);
                } else {
                    throw new Exception('Error al crear el usuario en la base de datos');
                }
                break;
                
            case 'update_user':
                $user_id = intval($_POST['user_id'] ?? 0);
                $username = trim(sanitize($_POST['username'] ?? ''));
                $nombre = trim(sanitize($_POST['nombre'] ?? ''));
                $email = trim(sanitize($_POST['email'] ?? ''));
                $tienda_id = !empty($_POST['tienda_id']) ? intval($_POST['tienda_id']) : null;
                $rol = $_POST['rol'] ?? 'vendedor';
                $activo = isset($_POST['activo']) ? 1 : 0;
                
                if ($user_id <= 0) {
                    throw new Exception('ID de usuario no válido');
                }
                
                if (empty($username) || empty($nombre)) {
                    throw new Exception('Nombre de usuario y nombre completo son obligatorios');
                }
                
                if (!empty($email) && !validateEmail($email)) {
                    throw new Exception('El formato del email no es válido');
                }
                
                if (!in_array($rol, ['vendedor', 'admin'])) {
                    throw new Exception('Rol no válido');
                }
                
                // Si es vendedor, debe tener tienda asignada
                if ($rol === 'vendedor' && !$tienda_id) {
                    throw new Exception('Los vendedores deben tener una tienda asignada');
                }
                
                // No permitir desactivar al usuario actual
                if ($user_id == $user['id'] && !$activo) {
                    throw new Exception('No puedes desactivar tu propia cuenta');
                }
                
                // No permitir cambiar el rol del usuario actual
                if ($user_id == $user['id'] && $rol !== 'admin') {
                    throw new Exception('No puedes cambiar tu propio rol de administrador');
                }
                
                // Verificar que el username no exista (excepto el actual)
                $check_stmt = $db->prepare("SELECT id FROM usuarios WHERE username = ? AND id != ?");
                $check_stmt->execute([$username, $user_id]);
                if ($check_stmt->fetch()) {
                    throw new Exception('El nombre de usuario ya existe');
                }
                
                // Si se proporciona email, verificar que no exista (excepto el actual)
                if (!empty($email)) {
                    $check_email_stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ? AND email != ''");
                    $check_email_stmt->execute([$email, $user_id]);
                    if ($check_email_stmt->fetch()) {
                        throw new Exception('El email ya está registrado');
                    }
                }
                
                // Actualizar usuario
                $stmt = $db->prepare("
                    UPDATE usuarios SET 
                        username = ?, nombre = ?, email = ?, tienda_id = ?, rol = ?, activo = ?
                    WHERE id = ?
                ");
                
                $result = $stmt->execute([
                    $username, 
                    $nombre, 
                    $email ?: null, 
                    $tienda_id, 
                    $rol, 
                    $activo, 
                    $user_id
                ]);
                
                if ($result) {
                    logActivity($user['id'], 'update_user', "Usuario actualizado ID: $user_id ($rol)");
                    echo json_encode(['success' => true, 'message' => 'Usuario actualizado correctamente']);
                } else {
                    throw new Exception('Error al actualizar el usuario');
                }
                break;
                
            case 'reset_password':
                $user_id = intval($_POST['user_id'] ?? 0);
                $new_password = $_POST['new_password'] ?? '';
                
                if ($user_id <= 0) {
                    throw new Exception('ID de usuario no válido');
                }
                
                if (strlen($new_password) < 6) {
                    throw new Exception('La contraseña debe tener al menos 6 caracteres');
                }
                
                $hashedPassword = hashPassword($new_password);
                $stmt = $db->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
                $result = $stmt->execute([$hashedPassword, $user_id]);
                
                if ($result) {
                    logActivity($user['id'], 'reset_password', "Contraseña restablecida para usuario ID: $user_id");
                    echo json_encode(['success' => true, 'message' => 'Contraseña restablecida correctamente']);
                } else {
                    throw new Exception('Error al restablecer la contraseña');
                }
                break;
                
            case 'delete_user':
                $user_id = intval($_POST['user_id'] ?? 0);
                
                if ($user_id <= 0) {
                    throw new Exception('ID de usuario no válido');
                }
                
                // No permitir eliminar el usuario actual
                if ($user_id == $user['id']) {
                    throw new Exception('No puedes eliminar tu propia cuenta');
                }
                
                // Verificar si el usuario tiene registros asociados
                $check_stmt = $db->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM ventas WHERE vendedor_id = ?) as ventas,
                        (SELECT COUNT(*) FROM celulares WHERE usuario_registro_id = ?) as dispositivos
                ");
                $check_stmt->execute([$user_id, $user_id]);
                $counts = $check_stmt->fetch();
                
                if ($counts['ventas'] > 0 || $counts['dispositivos'] > 0) {
                    // En lugar de eliminar, desactivar el usuario
                    $stmt = $db->prepare("UPDATE usuarios SET activo = 0 WHERE id = ?");
                    $result = $stmt->execute([$user_id]);
                    $message = 'Usuario desactivado correctamente (tenía registros asociados)';
                } else {
                    // Eliminar completamente
                    $stmt = $db->prepare("DELETE FROM usuarios WHERE id = ?");
                    $result = $stmt->execute([$user_id]);
                    $message = 'Usuario eliminado correctamente';
                }
                
                if ($result) {
                    logActivity($user['id'], 'delete_user', "Usuario eliminado/desactivado ID: $user_id");
                    echo json_encode(['success' => true, 'message' => $message]);
                } else {
                    throw new Exception('Error al eliminar el usuario');
                }
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
        
    } catch(Exception $e) {
        logError("Error en gestión de usuarios: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Obtener usuarios y estadísticas
try {
    // Consulta principal para obtener usuarios
    $users_query = "
        SELECT u.id, u.username, u.nombre, u.email, u.rol, u.activo, 
               u.ultimo_acceso, u.fecha_creacion, u.tienda_id,
               t.nombre as tienda_nombre,
               (SELECT COUNT(*) FROM ventas WHERE vendedor_id = u.id) as total_ventas
        FROM usuarios u
        LEFT JOIN tiendas t ON u.tienda_id = t.id
        ORDER BY u.fecha_creacion DESC
    ";
    
    $users_stmt = $db->query($users_query);
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Asegurar que $users es un array
    if (!is_array($users)) {
        $users = [];
    }
    
    // Obtener tiendas activas para el formulario
    $tiendas_query = "SELECT id, nombre FROM tiendas WHERE activa = 1 ORDER BY nombre";
    $tiendas_stmt = $db->query($tiendas_query);
    $tiendas = $tiendas_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Asegurar que $tiendas es un array
    if (!is_array($tiendas)) {
        $tiendas = [];
    }
    
    // Estadísticas rápidas - usar funciones seguras
    $stats = [
        'total_usuarios' => count($users),
        'usuarios_activos' => count(array_filter($users, function($u) { return $u['activo']; })),
        'administradores' => count(array_filter($users, function($u) { return $u['rol'] === 'admin'; })),
        'vendedores' => count(array_filter($users, function($u) { return $u['rol'] === 'vendedor'; })),
        'conectados_hoy' => count(array_filter($users, function($u) { 
            return $u['ultimo_acceso'] && date('Y-m-d', strtotime($u['ultimo_acceso'])) === date('Y-m-d'); 
        }))
    ];
    
} catch(Exception $e) {
    logError("Error al obtener datos de usuarios: " . $e->getMessage());
    $users = [];
    $tiendas = [];
    $stats = ['total_usuarios' => 0, 'usuarios_activos' => 0, 'administradores' => 0, 'vendedores' => 0, 'conectados_hoy' => 0];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        .modal { display: none; }
        .modal.show { display: flex; }
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        @media (max-width: 768px) {
            .sidebar-hidden { transform: translateX(-100%); }
        }
        .loading { opacity: 0.6; pointer-events: none; }
        .role-admin { background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%); }
        .role-vendedor { background: linear-gradient(135deg, #059669 0%, #047857 100%); }
        .notification { 
            transform: translateX(100%); 
            transition: transform 0.3s ease-in-out; 
        }
        .notification.show { transform: translateX(0); }
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
                        <?php echo htmlspecialchars($user['nombre']); ?> - Administrador
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
                    
                    <div class="pt-4 mt-4 border-t border-gray-200">
                        <p class="px-4 text-xs font-medium text-gray-500 uppercase">Administración</p>
                        <a href="users.php" class="flex items-center px-4 py-2 mt-2 text-gray-700 bg-blue-50 border-r-4 border-blue-500 rounded-l">
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
                </nav>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 md:ml-0">
            <div class="p-6">
                <!-- Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                    <div>
                        <h2 class="text-3xl font-bold text-gray-900">Gestión de Usuarios</h2>
                        <p class="text-gray-600">Sistema simplificado: Solo Administradores y Vendedores</p>
                        <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-500">
                            <span>Total: <?php echo $stats['total_usuarios']; ?></span>
                            <span>Activos: <?php echo $stats['usuarios_activos']; ?></span>
                            <span>Admins: <?php echo $stats['administradores']; ?></span>
                            <span>Vendedores: <?php echo $stats['vendedores']; ?></span>
                            <span>Conectados hoy: <?php echo $stats['conectados_hoy']; ?></span>
                        </div>
                    </div>
                    <button onclick="openCreateModal()" class="mt-4 md:mt-0 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors shadow-md">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Crear Usuario
                    </button>
                </div>

                <!-- Sistema simplificado info -->
                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-blue-600 mt-0.5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <div>
                            <p class="font-medium text-blue-800">Sistema de Roles Simplificado</p>
                            <div class="text-sm text-blue-700 mt-1 space-y-1">
                                <p><strong>👑 Administradores:</strong> Control total del sistema - pueden ver y modificar todo</p>
                                <p><strong>👤 Vendedores:</strong> Solo pueden realizar ventas en su tienda asignada - sin permisos de modificación</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
                    <div class="flex flex-col md:flex-row gap-4">
                        <div class="flex-1">
                            <input type="text" id="searchInput" placeholder="Buscar por nombre, usuario o email..." 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <select id="rolFilter" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Todos los roles</option>
                                <option value="admin">👑 Administrador</option>
                                <option value="vendedor">👤 Vendedor</option>
                            </select>
                        </div>
                        <div>
                            <select id="statusFilter" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Todos los estados</option>
                                <option value="1">✅ Activos</option>
                                <option value="0">❌ Inactivos</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Tabla de Usuarios -->
                <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuario</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tienda</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rol</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actividad</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200" id="usersTableBody">
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                            <div class="flex flex-col items-center">
                                                <svg class="w-12 h-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                                                </svg>
                                                <p class="text-lg font-medium">No hay usuarios registrados</p>
                                                <p class="text-sm mt-1">Crea el primer usuario para comenzar</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($users as $usr): ?>
                                        <tr class="hover:bg-gray-50 transition-colors user-row" 
                                            data-username="<?php echo htmlspecialchars(strtolower($usr['username'])); ?>"
                                            data-nombre="<?php echo htmlspecialchars(strtolower($usr['nombre'])); ?>"
                                            data-email="<?php echo htmlspecialchars(strtolower($usr['email'] ?? '')); ?>"
                                            data-rol="<?php echo $usr['rol']; ?>"
                                            data-activo="<?php echo $usr['activo']; ?>">
                                            <td class="px-4 py-4">
                                                <div class="flex items-center">
                                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center mr-3">
                                                        <span class="text-sm font-bold text-white">
                                                            <?php echo strtoupper(substr($usr['nombre'], 0, 2)); ?>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars($usr['nombre']); ?></p>
                                                        <p class="text-sm text-gray-600">@<?php echo htmlspecialchars($usr['username']); ?></p>
                                                        <?php if ($usr['email']): ?>
                                                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($usr['email']); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-900">
                                                <?php if ($usr['rol'] === 'admin'): ?>
                                                    <span class="text-gray-400 italic">Todas las tiendas</span>
                                                <?php elseif ($usr['tienda_nombre']): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                        <?php echo htmlspecialchars($usr['tienda_nombre']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                        Sin asignar
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-4">
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium text-white role-<?php echo $usr['rol']; ?>">
                                                    <?php if ($usr['rol'] === 'admin'): ?>
                                                        👑 Administrador
                                                    <?php else: ?>
                                                        👤 Vendedor
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-4">
                                                <div class="flex items-center">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                                        echo $usr['activo'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; 
                                                    ?>">
                                                        <span class="w-2 h-2 mr-1 rounded-full <?php echo $usr['activo'] ? 'bg-green-400' : 'bg-gray-400'; ?>"></span>
                                                        <?php echo $usr['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-900">
                                                <div>
                                                    <p class="text-sm">
                                                        <?php echo $usr['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($usr['ultimo_acceso'])) : 'Nunca'; ?>
                                                    </p>
                                                    <p class="text-xs text-gray-500">
                                                        <?php echo $usr['total_ventas']; ?> ventas realizadas
                                                    </p>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4">
                                                <div class="flex items-center gap-2">
                                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($usr)); ?>)" 
                                                            class="text-blue-600 hover:text-blue-900 p-1 rounded transition-colors" title="Editar">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                    </button>
                                                    <button onclick="openPasswordModal(<?php echo $usr['id']; ?>, '<?php echo htmlspecialchars($usr['nombre']); ?>')" 
                                                            class="text-yellow-600 hover:text-yellow-900 p-1 rounded transition-colors" title="Cambiar contraseña">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m0 0a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9a2 2 0 012-2m0 0V7a2 2 0 012-2m0 0V5a2 2 0 012-2m0 0h6"></path>
                                                        </svg>
                                                    </button>
                                                    <?php if ($usr['id'] != $user['id']): ?>
                                                        <button onclick="deleteUser(<?php echo $usr['id']; ?>, '<?php echo htmlspecialchars($usr['nombre']); ?>')" 
                                                                class="text-red-600 hover:text-red-900 p-1 rounded transition-colors" title="Eliminar">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                            </svg>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-gray-400 p-1" title="No puedes eliminar tu propia cuenta">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728L18.364 5.636M5.636 18.364l12.728-12.728"></path>
                                                            </svg>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Estadísticas -->
                <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="bg-green-100 rounded-lg p-3">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-600">Usuarios Activos</p>
                                <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['usuarios_activos']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="bg-blue-100 rounded-lg p-3">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-600">Conectados Hoy</p>
                                <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['conectados_hoy']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="bg-red-100 rounded-lg p-3">
                                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-600">Administradores</p>
                                <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['administradores']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Crear/Editar Usuario -->
    <div id="userModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4 max-h-screen overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 id="userModalTitle" class="text-lg font-semibold">Crear Usuario</h3>
                <button onclick="closeUserModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form id="userForm" class="space-y-4" onsubmit="saveUser(event)">
                <input type="hidden" id="userId">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de Usuario *</label>
                    <input type="text" id="username" required maxlength="50"
                           pattern="[a-zA-Z0-9_]+" title="Solo letras, números y guiones bajos"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Solo letras, números y guiones bajos</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo *</label>
                    <input type="text" id="nombre" required maxlength="100"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" id="email" maxlength="100"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div id="passwordField">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña *</label>
                    <input type="password" id="password" minlength="6" maxlength="100"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rol *</label>
                    <select id="rol" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" onchange="handleRoleChange()">
                        <option value="vendedor">👤 Vendedor</option>
                        <option value="admin">👑 Administrador</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <span id="roleDescription">Vendedor: Solo ventas en tienda asignada</span>
                    </p>
                </div>
                
                <div id="tiendaField">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tienda *</label>
                    <select id="tienda_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Seleccionar tienda...</option>
                        <?php if (is_array($tiendas)): ?>
                            <?php foreach($tiendas as $tienda): ?>
                                <option value="<?php echo $tienda['id']; ?>"><?php echo htmlspecialchars($tienda['nombre']); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Los vendedores deben tener una tienda asignada</p>
                </div>
                
                <div id="statusField" class="hidden">
                    <label class="flex items-center">
                        <input type="checkbox" id="activo" class="mr-2 rounded">
                        <span class="text-sm font-medium text-gray-700">Usuario activo</span>
                    </label>
                </div>
                
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="closeUserModal()" 
                            class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" id="saveUserBtn"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Cambiar Contraseña -->
    <div id="passwordModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-sm mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Cambiar Contraseña</h3>
                <button onclick="closePasswordModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form onsubmit="resetPassword(event)" class="space-y-4">
                <input type="hidden" id="passwordUserId">
                <p class="text-sm text-gray-600">Usuario: <span id="passwordUserName" class="font-medium"></span></p>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nueva Contraseña *</label>
                    <input type="password" id="newPassword" required minlength="6" maxlength="100"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres</p>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closePasswordModal()" 
                            class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" id="resetPasswordBtn"
                            class="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg transition-colors">
                        Cambiar Contraseña
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Overlay para móvil -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 md:hidden hidden"></div>

    <script>
        // Variables globales
        let isEditMode = false;
        let formChanged = false;

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

        // Filtros
        document.getElementById('searchInput').addEventListener('input', filterUsers);
        document.getElementById('rolFilter').addEventListener('change', filterUsers);
        document.getElementById('statusFilter').addEventListener('change', filterUsers);

        function filterUsers() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const rolFilter = document.getElementById('rolFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            const rows = document.querySelectorAll('.user-row');

            rows.forEach(row => {
                const username = row.dataset.username;
                const nombre = row.dataset.nombre;
                const email = row.dataset.email;
                const rol = row.dataset.rol;
                const activo = row.dataset.activo;

                const matchesSearch = !searchTerm || 
                    username.includes(searchTerm) || 
                    nombre.includes(searchTerm) || 
                    email.includes(searchTerm);
                const matchesRol = !rolFilter || rol === rolFilter;
                const matchesStatus = !statusFilter || activo === statusFilter;

                if (matchesSearch && matchesRol && matchesStatus) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function handleRoleChange() {
            const rol = document.getElementById('rol').value;
            const tiendaField = document.getElementById('tiendaField');
            const tiendaSelect = document.getElementById('tienda_id');
            const roleDescription = document.getElementById('roleDescription');

            if (rol === 'admin') {
                tiendaField.style.display = 'none';
                tiendaSelect.value = '';
                tiendaSelect.required = false;
                roleDescription.textContent = 'Administrador: Control total del sistema';
            } else {
                tiendaField.style.display = 'block';
                tiendaSelect.required = true;
                roleDescription.textContent = 'Vendedor: Solo ventas en tienda asignada';
            }
        }

        function openCreateModal() {
            isEditMode = false;
            formChanged = false;
            document.getElementById('userModalTitle').textContent = 'Crear Usuario';
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('rol').value = 'vendedor';
            document.getElementById('activo').checked = true;
            
            document.getElementById('passwordField').classList.remove('hidden');
            document.getElementById('statusField').classList.add('hidden');
            document.getElementById('password').required = true;
            
            handleRoleChange();
            document.getElementById('userModal').classList.add('show');
            setTimeout(() => document.getElementById('username').focus(), 100);
        }

        function openEditModal(userData) {
            isEditMode = true;
            formChanged = false;
            document.getElementById('userModalTitle').textContent = 'Editar Usuario';
            document.getElementById('userId').value = userData.id;
            document.getElementById('username').value = userData.username;
            document.getElementById('nombre').value = userData.nombre;
            document.getElementById('email').value = userData.email || '';
            document.getElementById('tienda_id').value = userData.tienda_id || '';
            document.getElementById('rol').value = userData.rol;
            document.getElementById('activo').checked = userData.activo == 1;
            
            document.getElementById('passwordField').classList.add('hidden');
            document.getElementById('statusField').classList.remove('hidden');
            document.getElementById('password').required = false;
            
            handleRoleChange();
            document.getElementById('userModal').classList.add('show');
            setTimeout(() => document.getElementById('username').focus(), 100);
        }

        function closeUserModal() {
            if (formChanged && (document.getElementById('username').value.trim() || 
                              document.getElementById('nombre').value.trim())) {
                if (!confirm('¿Estás seguro de que quieres cerrar? Se perderán los cambios no guardados.')) {
                    return;
                }
            }
            
            document.getElementById('userModal').classList.remove('show');
            formChanged = false;
        }

        function openPasswordModal(userId, userName) {
            document.getElementById('passwordUserId').value = userId;
            document.getElementById('passwordUserName').textContent = userName;
            document.getElementById('newPassword').value = '';
            document.getElementById('passwordModal').classList.add('show');
            setTimeout(() => document.getElementById('newPassword').focus(), 100);
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').classList.remove('show');
        }

        function saveUser(event) {
            event.preventDefault();
            
            const formData = new FormData();
            formData.append('action', isEditMode ? 'update_user' : 'create_user');
            
            if (isEditMode) {
                formData.append('user_id', document.getElementById('userId').value);
            }
            
            formData.append('username', document.getElementById('username').value.trim());
            formData.append('nombre', document.getElementById('nombre').value.trim());
            formData.append('email', document.getElementById('email').value.trim());
            formData.append('rol', document.getElementById('rol').value);
            
            const rol = document.getElementById('rol').value;
            if (rol === 'vendedor') {
                formData.append('tienda_id', document.getElementById('tienda_id').value);
            }
            
            if (!isEditMode) {
                formData.append('password', document.getElementById('password').value);
            } else {
                if (document.getElementById('activo').checked) {
                    formData.append('activo', '1');
                }
            }
            
            const button = document.getElementById('saveUserBtn');
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Guardando...';
            document.getElementById('userForm').classList.add('loading');
            
            fetch('users.php', {
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
                document.getElementById('userForm').classList.remove('loading');
            });
        }

        function resetPassword(event) {
            event.preventDefault();
            
            const userId = document.getElementById('passwordUserId').value;
            const newPassword = document.getElementById('newPassword').value;
            
            if (!newPassword || newPassword.length < 6) {
                showNotification('❌ La contraseña debe tener al menos 6 caracteres', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'reset_password');
            formData.append('user_id', userId);
            formData.append('new_password', newPassword);
            
            const button = document.getElementById('resetPasswordBtn');
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Cambiando...';
            
            fetch('users.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('✅ ' + data.message, 'success');
                    closePasswordModal();
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

        function deleteUser(userId, userName) {
            if (!confirm(`¿Estás seguro de que quieres eliminar al usuario "${userName}"?\n\nEsta acción no se puede deshacer.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_user');
            formData.append('user_id', userId);
            
            fetch('users.php', {
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

        // Sistema de notificaciones
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm ${
                type === 'success' ? 'bg-green-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                type === 'warning' ? 'bg-yellow-500 text-white' :
                'bg-blue-500 text-white'
            }`;
            
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
                notification.classList.add('show');
            }, 100);
            
            // Auto-remove después de 5 segundos
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.remove();
                    }
                }, 300);
            }, 5000);
        }

        // Cerrar modales con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeUserModal();
                closePasswordModal();
            }
        });

        // Validación en tiempo real
        document.getElementById('username').addEventListener('input', function() {
            this.value = this.value.replace(/[^a-zA-Z0-9_]/g, '');
            formChanged = true;
        });

        // Detectar cambios en el formulario
        document.getElementById('userForm').addEventListener('input', function() {
            formChanged = true;
            
            const username = document.getElementById('username').value.trim();
            const nombre = document.getElementById('nombre').value.trim();
            const password = document.getElementById('password').value;
            const rol = document.getElementById('rol').value;
            const tienda = document.getElementById('tienda_id').value;
            
            const saveBtn = document.getElementById('saveUserBtn');
            
            let valid = username.length >= 3 && nombre.length >= 2;
            
            if (!isEditMode) {
                valid = valid && password.length >= 6;
            }
            
            if (rol === 'vendedor') {
                valid = valid && tienda;
            }
            
            saveBtn.disabled = !valid;
            
            if (valid) {
                saveBtn.classList.remove('bg-gray-400');
                saveBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
            } else {
                saveBtn.classList.add('bg-gray-400');
                saveBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
            }
        });

        // Validación del formulario de contraseña
        document.getElementById('newPassword').addEventListener('input', function() {
            const resetBtn = document.getElementById('resetPasswordBtn');
            const valid = this.value.length >= 6;
            
            resetBtn.disabled = !valid;
            
            if (valid) {
                resetBtn.classList.remove('bg-gray-400');
                resetBtn.classList.add('bg-yellow-600', 'hover:bg-yellow-700');
            } else {
                resetBtn.classList.add('bg-gray-400');
                resetBtn.classList.remove('bg-yellow-600', 'hover:bg-yellow-700');
            }
        });

        // Inicializar al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            handleRoleChange();
            
            // Mostrar estadísticas en consola para debugging
            console.log('Sistema de Usuarios - Estadísticas:');
            console.log('Total usuarios: <?php echo $stats['total_usuarios']; ?>');
            console.log('Usuarios activos: <?php echo $stats['usuarios_activos']; ?>');
            console.log('Administradores: <?php echo $stats['administradores']; ?>');
            console.log('Vendedores: <?php echo $stats['vendedores']; ?>');
            
            // Verificar si hay tiendas disponibles
            const tiendaSelect = document.getElementById('tienda_id');
            if (tiendaSelect.options.length <= 1) {
                showNotification('⚠️ No hay tiendas disponibles. Crea tiendas primero para asignar vendedores.', 'warning');
            }
        });

        // Auto-focus en campos específicos
        document.getElementById('userModal').addEventListener('transitionend', function() {
            if (this.classList.contains('show')) {
                setTimeout(() => document.getElementById('username').focus(), 100);
            }
        });

        document.getElementById('passwordModal').addEventListener('transitionend', function() {
            if (this.classList.contains('show')) {
                setTimeout(() => document.getElementById('newPassword').focus(), 100);
            }
        });

        // Prevenir envío accidental del formulario
        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();
            saveUser(e);
        });

        // Mostrar mensaje de bienvenida si es el primer acceso
        <?php if (count($users) === 1): ?>
            setTimeout(() => {
                showNotification('👋 ¡Bienvenido al sistema! Este es tu primer usuario administrador.', 'info');
            }, 1000);
        <?php endif; ?>
    </script>
</body>
</html>
                