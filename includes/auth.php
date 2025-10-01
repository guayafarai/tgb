<?php
require_once '../config/database.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    public function login($username, $password) {
        try {
            $stmt = $this->db->prepare("
                SELECT u.*, t.nombre as tienda_nombre 
                FROM usuarios u 
                LEFT JOIN tiendas t ON u.tienda_id = t.id 
                WHERE u.username = ? AND u.activo = 1
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && verifyPassword($password, $user['password'])) {
                // Actualizar último acceso
                $this->updateLastAccess($user['id']);
                
                // Iniciar sesión
                $this->startUserSession($user);
                
                logActivity($user['id'], 'login', 'Inicio de sesión exitoso');
                return ['success' => true, 'user' => $user];
            } else {
                logError("Intento de login fallido para usuario: $username");
                return ['success' => false, 'message' => 'Credenciales incorrectas'];
            }
        } catch(Exception $e) {
            logError("Error en login: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error en el sistema'];
        }
    }
    
    private function updateLastAccess($userId) {
        $stmt = $this->db->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
    }
    
    private function startUserSession($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['tienda_id'] = $user['tienda_id'];
        $_SESSION['tienda_nombre'] = $user['tienda_nombre'];
        $_SESSION['rol'] = $user['rol'];
        $_SESSION['login_time'] = time();
    }
    
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            logActivity($_SESSION['user_id'], 'logout', 'Cierre de sesión');
        }
        
        session_unset();
        session_destroy();
        
        // Regenerar ID de sesión
        session_start();
        session_regenerate_id(true);
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['login_time']);
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit();
        }
        
        // Verificar timeout de sesión (8 horas)
        if (time() - $_SESSION['login_time'] > 28800) {
            $this->logout();
            header('Location: login.php?timeout=1');
            exit();
        }
    }
    
    // SISTEMA DE PERMISOS MEJORADO
    public function hasPermission($permission) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $rol = $_SESSION['rol'];
        
        switch ($permission) {
            // PERMISOS DE ADMINISTRADOR - Solo admin
            case 'admin':
            case 'view_all_stores':
            case 'manage_users':
            case 'manage_stores':
            case 'delete_devices':
            case 'view_all_inventory':
            case 'modify_all_inventory':
            case 'view_all_sales':
            case 'view_global_reports':
                return $rol === 'admin';
            
            // PERMISOS DE VENDEDOR - Solo realizar ventas
            case 'view_sales':
            case 'register_sales':
            case 'view_own_store_devices':
                return in_array($rol, ['admin', 'vendedor']);
            
            // PERMISOS DENEGADOS PARA VENDEDOR
            case 'add_devices':
            case 'edit_devices':
            case 'manage_inventory':
            case 'view_reports':
                return $rol === 'admin'; // Solo admin
            
            default:
                return false;
        }
    }
    
    // Verificar si puede ver/modificar un dispositivo específico
    public function canAccessDevice($device_tienda_id, $action = 'view') {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $rol = $_SESSION['rol'];
        $user_tienda_id = $_SESSION['tienda_id'];
        
        // Admin puede todo
        if ($rol === 'admin') {
            return true;
        }
        
        // Vendedor solo puede ver dispositivos de su tienda para ventas
        if ($rol === 'vendedor') {
            if ($action === 'view' || $action === 'sell') {
                return $device_tienda_id == $user_tienda_id;
            }
            // Vendedor NO puede editar/eliminar
            return false;
        }
        
        return false;
    }
    
    // Verificar si puede acceder a una venta específica
    public function canAccessSale($sale_tienda_id) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $rol = $_SESSION['rol'];
        $user_tienda_id = $_SESSION['tienda_id'];
        
        // Admin puede ver todas las ventas
        if ($rol === 'admin') {
            return true;
        }
        
        // Vendedor solo puede ver ventas de su tienda
        if ($rol === 'vendedor') {
            return $sale_tienda_id == $user_tienda_id;
        }
        
        return false;
    }
    
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'nombre' => $_SESSION['nombre'],
            'email' => $_SESSION['email'],
            'tienda_id' => $_SESSION['tienda_id'],
            'tienda_nombre' => $_SESSION['tienda_nombre'],
            'rol' => $_SESSION['rol']
        ];
    }
    
    public function changePassword($userId, $oldPassword, $newPassword) {
        try {
            // Verificar contraseña actual
            $stmt = $this->db->prepare("SELECT password FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || !verifyPassword($oldPassword, $user['password'])) {
                return ['success' => false, 'message' => 'Contraseña actual incorrecta'];
            }
            
            // Actualizar contraseña
            $hashedPassword = hashPassword($newPassword);
            $stmt = $this->db->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $userId]);
            
            logActivity($userId, 'change_password', 'Cambio de contraseña');
            return ['success' => true, 'message' => 'Contraseña actualizada correctamente'];
        } catch(Exception $e) {
            logError("Error al cambiar contraseña: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error en el sistema'];
        }
    }
    
    public function createUser($data) {
        try {
            // Solo admin puede crear usuarios
            if ($_SESSION['rol'] !== 'admin') {
                return ['success' => false, 'message' => 'Sin permisos para crear usuarios'];
            }
            
            // Verificar que el username no exista
            $stmt = $this->db->prepare("SELECT id FROM usuarios WHERE username = ?");
            $stmt->execute([$data['username']]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'El usuario ya existe'];
            }
            
            // Crear usuario
            $stmt = $this->db->prepare("
                INSERT INTO usuarios (username, password, nombre, email, tienda_id, rol) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $hashedPassword = hashPassword($data['password']);
            $stmt->execute([
                $data['username'],
                $hashedPassword,
                $data['nombre'],
                $data['email'],
                $data['tienda_id'],
                $data['rol']
            ]);
            
            $newUserId = $this->db->lastInsertId();
            logActivity($_SESSION['user_id'], 'create_user', "Usuario creado: " . $data['username']);
            
            return ['success' => true, 'message' => 'Usuario creado correctamente', 'id' => $newUserId];
        } catch(Exception $e) {
            logError("Error al crear usuario: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error en el sistema'];
        }
    }
}

// Instancia global de Auth
$auth = new Auth();

// Funciones de utilidad
function requireLogin() {
    global $auth;
    $auth->requireLogin();
}

function hasPermission($permission) {
    global $auth;
    return $auth->hasPermission($permission);
}

function getCurrentUser() {
    global $auth;
    return $auth->getCurrentUser();
}

function canAccessDevice($device_tienda_id, $action = 'view') {
    global $auth;
    return $auth->canAccessDevice($device_tienda_id, $action);
}

function canAccessSale($sale_tienda_id) {
    global $auth;
    return $auth->canAccessSale($sale_tienda_id);
}

// Función para verificar acceso a páginas específicas
function requirePageAccess($page) {
    $permissions = [
        'inventory.php' => 'view_own_store_devices', // Vendedor puede ver solo su tienda
        'sales.php' => 'view_sales',                 // Vendedor puede hacer ventas
        'reports.php' => 'view_reports',             // Solo Admin
        'users.php' => 'manage_users',               // Solo Admin
        'stores.php' => 'manage_stores'              // Solo Admin
    ];
    
    if (isset($permissions[$page]) && !hasPermission($permissions[$page])) {
        header('Location: dashboard.php?error=access_denied');
        exit();
    }
}
?>