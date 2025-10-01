<?php
/**
 * Manejador centralizado de errores para el sistema
 * Incluir después de database.php
 */

// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en producción
ini_set('log_errors', 1);

// Crear directorio de logs si no existe
$log_dir = dirname(__DIR__) . '/logs';
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

ini_set('error_log', $log_dir . '/php_errors.log');

/**
 * Manejador personalizado de errores PHP
 */
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    $error_types = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING', 
        E_PARSE => 'PARSE',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE_ERROR',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE'
    ];
    
    $error_type = $error_types[$errno] ?? 'UNKNOWN';
    
    $log_message = sprintf(
        "[%s] %s: %s in %s on line %d",
        date('Y-m-d H:i:s'),
        $error_type,
        $errstr,
        $errfile,
        $errline
    );
    
    error_log($log_message);
    
    // En desarrollo, mostrar errores detallados
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        echo "<div style='background: #ffebee; border-left: 4px solid #f44336; padding: 10px; margin: 10px 0; font-family: monospace;'>";
        echo "<strong>Error:</strong> $errstr<br>";
        echo "<strong>File:</strong> $errfile<br>";
        echo "<strong>Line:</strong> $errline<br>";
        echo "</div>";
    }
    
    return true;
}

/**
 * Manejador de excepciones no capturadas
 */
function customExceptionHandler($exception) {
    $log_message = sprintf(
        "[%s] EXCEPTION: %s in %s on line %d",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );
    
    error_log($log_message);
    
    // Mostrar página de error genérica en producción
    if (!defined('DEVELOPMENT_MODE') || !DEVELOPMENT_MODE) {
        http_response_code(500);
        echo "<!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Error del Sistema</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
                .error-container { max-width: 600px; margin: 50px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
                .error-icon { font-size: 48px; color: #e74c3c; margin-bottom: 20px; }
                h1 { color: #2c3e50; margin-bottom: 15px; }
                p { color: #7f8c8d; line-height: 1.6; }
                .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; margin: 0 5px; }
            </style>
        </head>
        <body>
            <div class='error-container'>
                <div class='error-icon'>⚠️</div>
                <h1>Error del Sistema</h1>
                <p>Lo sentimos, ha ocurrido un error inesperado.</p>
                <p>Por favor contacta al administrador si el problema persiste.</p>
                <div style='margin-top: 30px;'>
                    <a href='javascript:history.back()' class='btn'>Volver Atrás</a>
                    <a href='/public/login.php' class='btn'>Ir al Login</a>
                </div>
            </div>
        </body>
        </html>";
        exit();
    } else {
        // En desarrollo, mostrar detalles
        echo "<div style='background: #ffebee; border: 2px solid #f44336; padding: 20px; margin: 20px; font-family: monospace;'>";
        echo "<h2 style='color: #d32f2f;'>Excepción No Capturada</h2>";
        echo "<strong>Mensaje:</strong> " . htmlspecialchars($exception->getMessage()) . "<br>";
        echo "<strong>Archivo:</strong> " . htmlspecialchars($exception->getFile()) . "<br>";
        echo "<strong>Línea:</strong> " . $exception->getLine() . "<br>";
        echo "</div>";
    }
    
    exit();
}

/**
 * Función para registrar eventos del sistema
 */
function logSystemEvent($event, $details = '', $level = 'INFO') {
    $log_file = dirname(__DIR__) . '/logs/system.log';
    
    $log_entry = sprintf(
        "[%s] [%s] %s | %s | IP: %s\n",
        date('Y-m-d H:i:s'),
        $level,
        $event,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? 'N/A'
    );
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Registrar manejadores de errores
set_error_handler('customErrorHandler');
set_exception_handler('customExceptionHandler');

// Función de utilidad para debugging (solo en desarrollo)
function debug($data, $label = 'DEBUG') {
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        echo "<div style='background: #e3f2fd; border-left: 4px solid #2196f3; padding: 10px; margin: 10px 0; font-family: monospace;'>";
        echo "<strong>$label:</strong><br>";
        echo "<pre>" . htmlspecialchars(print_r($data, true)) . "</pre>";
        echo "</div>";
    }
}

// Registrar evento de carga del sistema
logSystemEvent('system_load', 'Error handler initialized');
?>