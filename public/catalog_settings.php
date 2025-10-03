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
$message = '';
$error = '';

// Procesar guardar configuración
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    try {
        $db->beginTransaction();
        
        foreach ($_POST as $key => $value) {
            if ($key === 'save_config') continue;
            
            // Sanitizar valor
            $sanitized_value = sanitize($value);
            
            // Actualizar configuración
            $stmt = $db->prepare("
                UPDATE configuracion_catalogo 
                SET valor = ?, actualizado_por = ? 
                WHERE clave = ?
            ");
            $stmt->execute([$sanitized_value, $user['id'], $key]);
        }
        
        $db->commit();
        logActivity($user['id'], 'update_catalog_config', 'Configuración del catálogo actualizada');
        $message = 'Configuración guardada correctamente';
        
    } catch(Exception $e) {
        $db->rollback();
        logError("Error al guardar configuración: " . $e->getMessage());
        $error = 'Error al guardar la configuración: ' . $e->getMessage();
    }
}

// Obtener configuración actual
try {
    $config_stmt = $db->query("SELECT * FROM configuracion_catalogo ORDER BY clave");
    $configs = $config_stmt->fetchAll();
    
    // Convertir a array asociativo
    $config_array = [];
    foreach ($configs as $conf) {
        $config_array[$conf['clave']] = [
            'valor' => $conf['valor'],
            'tipo' => $conf['tipo'],
            'descripcion' => $conf['descripcion']
        ];
    }
    
} catch(Exception $e) {
    logError("Error al obtener configuración: " . $e->getMessage());
    $config_array = [];
    $error = 'Error al cargar la configuración';
}

// Función helper para obtener configuración
function getCatalogConfig($clave, $default = '') {
    global $config_array;
    return $config_array[$clave]['valor'] ?? $default;
}

require_once '../includes/navbar_unified.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración del Catálogo - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        .config-section {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-left: 4px solid #3b82f6;
        }
    </style>
</head>
<body class="bg-gray-100">
    
    <?php renderNavbar('stores'); ?>
    
    <main class="page-content">
        <div class="p-6">
            <!-- Header -->
            <div class="mb-6">
                <h2 class="text-3xl font-bold text-gray-900">Configuración del Catálogo Público</h2>
                <p class="text-gray-600">Personaliza la apariencia y contenido del catálogo visible al público</p>
                <div class="mt-2">
                    <a href="../index.php" target="_blank" class="inline-flex items-center text-blue-600 hover:text-blue-800">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                        Ver catálogo público
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <!-- Configuración General -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="config-section p-4 rounded-lg mb-4">
                        <h3 class="text-lg font-semibold text-blue-900 mb-1">⚙️ Configuración General</h3>
                        <p class="text-sm text-blue-700">Ajustes básicos del catálogo público</p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="catalogo_activo" value="1" 
                                       <?php echo ($config_array['catalogo_activo']['valor'] ?? '1') == '1' ? 'checked' : ''; ?>
                                       class="mr-2 rounded">
                                <span class="font-medium">Catálogo Activo</span>
                            </label>
                            <p class="text-xs text-gray-500 ml-6">Activar/desactivar el catálogo público</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Título del Catálogo</label>
                            <input type="text" name="catalogo_titulo" 
                                   value="<?php echo htmlspecialchars($config_array['catalogo_titulo']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                            <input type="text" name="catalogo_descripcion" 
                                   value="<?php echo htmlspecialchars($config_array['catalogo_descripcion']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Items por Página</label>
                            <input type="number" name="catalogo_items_por_pagina" min="5" max="100"
                                   value="<?php echo htmlspecialchars($config_array['catalogo_items_por_pagina']['valor'] ?? '20'); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                    </div>
                </div>

                <!-- Información de Contacto -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="config-section p-4 rounded-lg mb-4">
                        <h3 class="text-lg font-semibold text-blue-900 mb-1">📞 Información de Contacto</h3>
                        <p class="text-sm text-blue-700">Datos de contacto mostrados en el catálogo</p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" name="catalogo_email" 
                                   value="<?php echo htmlspecialchars($config_array['catalogo_email']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                            <input type="text" name="catalogo_telefono" 
                                   value="<?php echo htmlspecialchars($config_array['catalogo_telefono']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">WhatsApp (sin +)</label>
                            <input type="text" name="catalogo_whatsapp" placeholder="51999999999"
                                   value="<?php echo htmlspecialchars($config_array['catalogo_whatsapp']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <p class="text-xs text-gray-500 mt-1">Ejemplo: 51999999999 (código país + número)</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Horario de Atención</label>
                            <input type="text" name="catalogo_horario" 
                                   value="<?php echo htmlspecialchars($config_array['catalogo_horario']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Dirección</label>
                            <input type="text" name="catalogo_direccion" 
                                   value="<?php echo htmlspecialchars($config_array['catalogo_direccion']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Mensaje de WhatsApp</label>
                            <textarea name="catalogo_mensaje_whatsapp" rows="2"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"><?php echo htmlspecialchars($config_array['catalogo_mensaje_whatsapp']['valor'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Apariencia y Colores -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="config-section p-4 rounded-lg mb-4">
                        <h3 class="text-lg font-semibold text-blue-900 mb-1">🎨 Apariencia y Colores</h3>
                        <p class="text-sm text-blue-700">Personaliza los colores del catálogo</p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Color Principal</label>
                            <div class="flex gap-2">
                                <input type="color" name="catalogo_color_principal" 
                                       value="<?php echo htmlspecialchars($config_array['catalogo_color_principal']['valor'] ?? '#667eea'); ?>"
                                       class="h-10 w-20 border border-gray-300 rounded">
                                <input type="text" readonly
                                       value="<?php echo htmlspecialchars($config_array['catalogo_color_principal']['valor'] ?? '#667eea'); ?>"
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Color Secundario</label>
                            <div class="flex gap-2">
                                <input type="color" name="catalogo_color_secundario" 
                                       value="<?php echo htmlspecialchars($config_array['catalogo_color_secundario']['valor'] ?? '#764ba2'); ?>"
                                       class="h-10 w-20 border border-gray-300 rounded">
                                <input type="text" readonly
                                       value="<?php echo htmlspecialchars($config_array['catalogo_color_secundario']['valor'] ?? '#764ba2'); ?>"
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Opciones de Visualización -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="config-section p-4 rounded-lg mb-4">
                        <h3 class="text-lg font-semibold text-blue-900 mb-1">👁️ Opciones de Visualización</h3>
                        <p class="text-sm text-blue-700">Controla qué se muestra en el catálogo</p>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="catalogo_mostrar_celulares" value="1" 
                                       <?php echo ($config_array['catalogo_mostrar_celulares']['valor'] ?? '1') == '1' ? 'checked' : ''; ?>
                                       class="mr-2 rounded">
                                <span class="font-medium">Mostrar Celulares</span>
                            </label>
                        </div>
                        
                        <div>
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="catalogo_mostrar_productos" value="1" 
                                       <?php echo ($config_array['catalogo_mostrar_productos']['valor'] ?? '1') == '1' ? 'checked' : ''; ?>
                                       class="mr-2 rounded">
                                <span class="font-medium">Mostrar Productos/Accesorios</span>
                            </label>
                        </div>
                        
                        <div>
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="catalogo_mostrar_precios" value="1" 
                                       <?php echo ($config_array['catalogo_mostrar_precios']['valor'] ?? '1') == '1' ? 'checked' : ''; ?>
                                       class="mr-2 rounded">
                                <span class="font-medium">Mostrar Precios</span>
                            </label>
                        </div>
                        
                        <div>
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="catalogo_mostrar_stock" value="1" 
                                       <?php echo ($config_array['catalogo_mostrar_stock']['valor'] ?? '1') == '1' ? 'checked' : ''; ?>
                                       class="mr-2 rounded">
                                <span class="font-medium">Mostrar Stock</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Redes Sociales -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="config-section p-4 rounded-lg mb-4">
                        <h3 class="text-lg font-semibold text-blue-900 mb-1">🔗 Redes Sociales</h3>
                        <p class="text-sm text-blue-700">Enlaces a redes sociales (dejar vacío para ocultar)</p>
                    </div>
                    
                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Facebook</label>
                            <input type="url" name="catalogo_facebook" placeholder="https://facebook.com/tuempresa"
                                   value="<?php echo htmlspecialchars($config_array['catalogo_facebook']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Instagram</label>
                            <input type="url" name="catalogo_instagram" placeholder="https://instagram.com/tuempresa"
                                   value="<?php echo htmlspecialchars($config_array['catalogo_instagram']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Twitter</label>
                            <input type="url" name="catalogo_twitter" placeholder="https://twitter.com/tuempresa"
                                   value="<?php echo htmlspecialchars($config_array['catalogo_twitter']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                    </div>
                </div>

                <!-- SEO -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="config-section p-4 rounded-lg mb-4">
                        <h3 class="text-lg font-semibold text-blue-900 mb-1">🔍 SEO (Optimización para Buscadores)</h3>
                        <p class="text-sm text-blue-700">Mejora la visibilidad en motores de búsqueda</p>
                    </div>
                    
                    <div class="space-y-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Meta Description</label>
                            <textarea name="catalogo_meta_description" rows="2" maxlength="160"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg"><?php echo htmlspecialchars($config_array['catalogo_meta_description']['valor'] ?? ''); ?></textarea>
                            <p class="text-xs text-gray-500 mt-1">Máximo 160 caracteres</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Meta Keywords</label>
                            <input type="text" name="catalogo_meta_keywords" 
                                   value="<?php echo htmlspecialchars($config_array['catalogo_meta_keywords']['valor'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <p class="text-xs text-gray-500 mt-1">Separadas por comas</p>
                        </div>
                    </div>
                </div>

                <!-- Botones de Acción -->
                <div class="flex justify-end gap-4 sticky bottom-4 bg-white p-4 rounded-lg shadow-lg">
                    <a href="dashboard.php" class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors">
                        Cancelar
                    </a>
                    <button type="submit" name="save_config" 
                            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Guardar Configuración
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Actualizar campo de texto al cambiar color
        document.querySelectorAll('input[type="color"]').forEach(colorInput => {
            colorInput.addEventListener('input', function() {
                const textInput = this.nextElementSibling;
                if (textInput) {
                    textInput.value = this.value;
                }
            });
        });

        // Confirmación antes de salir si hay cambios
        let formChanged = false;
        document.querySelectorAll('input, textarea, select').forEach(input => {
            input.addEventListener('change', () => { formChanged = true; });
        });

        window.addEventListener('beforeunload', (e) => {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        document.querySelector('form').addEventListener('submit', () => {
            formChanged = false;
        });
    </script>
</body>
</html>
