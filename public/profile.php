<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

setSecurityHeaders();
startSecureSession();
requireLogin();

$user = getCurrentUser();
$db = getDB();
$message = '';
$error = '';

// Procesar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        try {
            $nombre = sanitize($_POST['nombre']);
            $email = sanitize($_POST['email']);
            
            if (empty($nombre)) {
                throw new Exception('El nombre es obligatorio');
            }
            
            if ($email && !validateEmail($email)) {
                throw new Exception('El formato del email no es válido');
            }
            
            $stmt = $db->prepare("UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?");
            $result = $stmt->execute([$nombre, $email, $user['id']]);
            
            if ($result) {
                $_SESSION['nombre'] = $nombre;
                $_SESSION['email'] = $email;
                logActivity($user['id'], 'update_profile', 'Perfil actualizado');
                $message = 'Perfil actualizado correctamente';
            } else {
                $error = 'Error al actualizar el perfil';
            }
        } catch(Exception $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['change_password'])) {
        try {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                throw new Exception('Todos los campos de contraseña son obligatorios');
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception('Las contraseñas nuevas no coinciden');
            }
            
            if (strlen($new_password) < 6) {
                throw new Exception('La nueva contraseña debe tener al menos 6 caracteres');
            }
            
            // Verificar contraseña actual
            $stmt = $db->prepare("SELECT password FROM usuarios WHERE id = ?");
            $stmt->execute([$user['id']]);
            $userData = $stmt->fetch();
            
            if (!verifyPassword($current_password, $userData['password'])) {
                throw new Exception('La contraseña actual es incorrecta');
            }
            
            // Actualizar contraseña
            $hashedPassword = hashPassword($new_password);
            $stmt = $db->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
            $result = $stmt->execute([$hashedPassword, $user['id']]);
            
            if ($result) {
                logActivity($user['id'], 'change_password', 'Contraseña cambiada');
                $message = 'Contraseña cambiada correctamente';
            } else {
                $error = 'Error al cambiar la contraseña';
            }
        } catch(Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Obtener datos actualizados del usuario
try {
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$user['id']]);
    $userData = $stmt->fetch();
} catch(Exception $e) {
    $userData = $user;
}

// Incluir el navbar/sidebar unificado
require_once '../includes/navbar_unified.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    
    <?php renderNavbar('profile'); ?>
    
    <!-- Contenido principal -->
    <main class="page-content">
        <div class="p-6">
            <!-- Header -->
            <div class="mb-6">
                <h2 class="text-3xl font-bold text-gray-900">Mi Perfil</h2>
                <p class="text-gray-600">Gestiona tu información personal</p>
            </div>

            <?php if ($message): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Información del Usuario -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Información Personal</h3>
                    
                    <form method="POST">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Usuario</label>
                                <input type="text" value="<?php echo htmlspecialchars($userData['username']); ?>" 
                                       disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                                <p class="text-xs text-gray-500 mt-1">El nombre de usuario no se puede cambiar</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo *</label>
                                <input type="text" name="nombre" required 
                                       value="<?php echo htmlspecialchars($userData['nombre']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input type="email" name="email" 
                                       value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rol</label>
                                <input type="text" value="<?php echo ucfirst($userData['rol']); ?>" 
                                       disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                            </div>
                            
                            <?php if ($user['tienda_nombre']): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tienda</label>
                                <input type="text" value="<?php echo htmlspecialchars($user['tienda_nombre']); ?>" 
                                       disabled class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mt-6">
                            <button type="submit" name="update_profile" 
                                    class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-lg transition-colors">
                                Actualizar Información
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Cambiar Contraseña -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Cambiar Contraseña</h3>
                    
                    <form method="POST">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña Actual *</label>
                                <input type="password" name="current_password" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nueva Contraseña *</label>
                                <input type="password" name="new_password" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar Nueva Contraseña *</label>
                                <input type="password" name="confirm_password" required 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <button type="submit" name="change_password" 
                                    class="w-full bg-yellow-600 hover:bg-yellow-700 text-white py-2 px-4 rounded-lg transition-colors">
                                Cambiar Contraseña
                            </button>
                        </div>
                    </form>
                    
                    <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <h4 class="text-sm font-medium text-yellow-800">Recomendaciones de Seguridad</h4>
                        <ul class="text-sm text-yellow-700 mt-1 space-y-1">
                            <li>• Usa una contraseña única y segura</li>
                            <li>• Incluye mayúsculas, minúsculas y números</li>
                            <li>• Cambia tu contraseña regularmente</li>
                            <li>• No compartas tus credenciales</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

</body>
</html>