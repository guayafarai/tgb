<?php
// Configuración de la base de datos
define('DB_HOST', 'localhost'); // Cambiar por tu servidor de BD
define('DB_NAME', 'chamotvs_ventasdb');
define('DB_USER', 'chamotvs_ventasuser'); // Cambiar por tu usuario de BD
define('DB_PASS', 'Guayaba123!!@'); // Cambiar por tu password de BD
define('DB_CHARSET', 'utf8mb4');

// Configuración de seguridad
define('JWT_SECRET', 'tu_clave_secreta_muy_larga_y_segura_aqui_2024'); // Cambiar por una clave única
define('SESSION_NAME', 'phone_inventory_session');

// Configuración del sistema
define('SYSTEM_NAME', 'Sistema de Inventario de Celulares');
define('SYSTEM_VERSION', '1.0.0');
define('TIMEZONE', 'America/Lima');

// Configurar zona horaria
date_default_timezone_set(TIMEZONE);

// Clase de conexión a la base de datos
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch(PDOException $e) {
            error_log("Error de conexión: " . $e->getMessage());
            die("Error de conexión a la base de datos");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Prevenir clonación
    private function __clone() {}
    
    // Prevenir deserialización
    private function __wakeup() {}
}

// Función para obtener la conexión
function getDB() {
    return Database::getInstance()->getConnection();
}

// Función para sanitizar entrada
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Función para validar email
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Función para hash de password
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Función para verificar password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Configuración de headers de seguridad
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Iniciar sesión segura
function startSecureSession() {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    session_name(SESSION_NAME);
    session_start();
}

// Función para log de errores
function logError($message) {
    error_log(date('Y-m-d H:i:s') . " - " . $message . "\n", 3, "logs/error.log");
}

// Función para log de actividad
function logActivity($user_id, $action, $details = '') {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO logs_actividad (usuario_id, accion, detalles, fecha) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$user_id, $action, $details]);
    } catch(Exception $e) {
        logError("Error al registrar actividad: " . $e->getMessage());
    }
}
?>