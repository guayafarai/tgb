<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/barcode_generator.php';

setSecurityHeaders();
startSecureSession();
requireLogin();

$user = getCurrentUser();
$db = getDB();
$barcodeGen = new BarcodeGenerator();

// Procesar búsqueda por código de barras
$search_result = null;
$codigo_buscado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'search_barcode':
                $codigo_barras = sanitize($_POST['codigo_barras']);
                $result = $barcodeGen->buscarPorCodigoBarras($codigo_barras);
                echo json_encode($result);
                break;
                
            case 'generate_barcode':
                $tipo = $_POST['tipo'];
                $item_id = intval($_POST['item_id']);
                
                if ($tipo === 'celular') {
                    // Obtener tienda del celular
                    $stmt = $db->prepare("SELECT tienda_id FROM celulares WHERE id = ?");
                    $stmt->execute([$item_id]);
                    $celular = $stmt->fetch();
                    
                    if (!$celular) {
                        throw new Exception('Celular no encontrado');
                    }
                    
                    $codigo_barras = $barcodeGen->generateCelularBarcode($celular['tienda_id']);
                    
                    // Actualizar celular con código de barras
                    $update_stmt = $db->prepare("UPDATE celulares SET codigo_barras = ? WHERE id = ?");
                    $update_stmt->execute([$codigo_barras, $item_id]);
                    
                } elseif ($tipo === 'producto') {
                    // Obtener categoría del producto
                    $stmt = $db->prepare("SELECT categoria_id FROM productos WHERE id = ?");
                    $stmt->execute([$item_id]);
                    $producto = $stmt->fetch();
                    
                    if (!$producto) {
                        throw new Exception('Producto no encontrado');
                    }
                    
                    $codigo_barras = $barcodeGen->generateProductoBarcode($producto['categoria_id'] ?? 0);
                    
                    // Actualizar producto con código de barras
                    $update_stmt = $db->prepare("UPDATE productos SET codigo_barras = ? WHERE id = ?");
                    $update_stmt->execute([$codigo_barras, $item_id]);
                }
                
                logActivity($user['id'], 'generate_barcode', "Código de barras generado: $codigo_barras para $tipo ID: $item_id");
                
                echo json_encode([
                    'success' => true,
                    'codigo_barras' => $codigo_barras,
                    'message' => 'Código de barras generado correctamente'
                ]);
                break;
                
            case 'print_label':
                $codigo_barras = sanitize($_POST['codigo_barras']);
                $titulo = sanitize($_POST['titulo']);
                $precio = isset($_POST['precio']) ? floatval($_POST['precio']) : null;
                
                $html = $barcodeGen->generarEtiqueta($codigo_barras, $titulo, $precio);
                
                echo json_encode([
                    'success' => true,
                    'html' => $html
                ]);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
    } catch(Exception $e) {
        logError("Error en sistema de códigos de barras: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['codigo'])) {
    $codigo_buscado = sanitize($_GET['codigo']);
    $search_result = $barcodeGen->buscarPorCodigoBarras($codigo_buscado);
}

// Incluir el navbar/sidebar unificado
require_once '../includes/navbar_unified.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Búsqueda por Código de Barras - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        .modal { display: none; }
        .modal.show { display: flex; }
        .scanner-frame {
            border: 3px solid #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
            50% { box-shadow: 0 0 0 6px rgba(59, 130, 246, 0.4); }
        }
        .barcode-input {
            font-family: 'Courier New', monospace;
            font-size: 1.25rem;
            letter-spacing: 0.1em;
        }
    </style>
</head>
<body class="bg-gray-100">
    
    <?php renderNavbar('barcode_search'); ?>
    
    <!-- Contenido principal -->
    <main class="page-content">
        <div class="p-6">
            <!-- Header -->
            <div class="mb-6">
                <h2 class="text-3xl font-bold text-gray-900">Búsqueda por Código de Barras</h2>
                <p class="text-gray-600">Escanea o ingresa el código de barras para buscar productos y celulares</p>
            </div>

            <!-- Búsqueda por código de barras -->
            <div class="bg-white rounded-lg shadow-lg p-8 mb-8">
                <div class="max-w-2xl mx-auto">
                    <div class="text-center mb-6">
                        <div class="inline-block p-4 bg-blue-50 rounded-full mb-4">
                            <svg class="w-16 h-16 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 mb-2">Escanear Código de Barras</h3>
                        <p class="text-gray-600">Usa un lector de código de barras o ingresa manualmente</p>
                    </div>
                    
                    <form id="barcodeSearchForm" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Código de Barras</label>
                            <input type="text" 
                                   id="barcodeInput" 
                                   name="codigo_barras"
                                   placeholder="Escanea o ingresa el código de barras..."
                                   maxlength="13"
                                   pattern="\d{13}"
                                   class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg barcode-input focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-center"
                                   autofocus>
                            <p class="text-xs text-gray-500 mt-1 text-center">Código EAN-13 (13 dígitos)</p>
                        </div>
                        
                        <div class="flex gap-3">
                            <button type="submit" 
                                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition-colors flex items-center justify-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                Buscar
                            </button>
                            <button type="button" 
                                    onclick="clearSearch()"
                                    class="px-6 py-3 bg-gray-500 hover:bg-gray-600 text-white rounded-lg font-medium transition-colors">
                                Limpiar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Resultados de búsqueda -->
            <div id="searchResults" class="hidden">
                <!-- Los resultados se cargarán aquí dinámicamente -->
            </div>

            <!-- Historial de búsquedas recientes -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Búsquedas Recientes</h3>
                <div id="recentSearches" class="space-y-2">
                    <p class="text-gray-500 text-sm text-center py-4">No hay búsquedas recientes</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal de visualización de código de barras -->
    <div id="barcodeModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Código de Barras</h3>
                <button onclick="closeBarcodeModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <div id="barcodeDisplay" class="text-center">
                <!-- El código de barras se mostrará aquí -->
            </div>
            
            <div class="mt-4 flex gap-3">
                <button onclick="printBarcode()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                    Imprimir Etiqueta
                </button>
                <button onclick="closeBarcodeModal()" class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-700 rounded-lg transition-colors">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentBarcode = null;
        let currentItem = null;
        let recentSearches = JSON.parse(localStorage.getItem('recentBarcodeSearches') || '[]');

        // Actualizar historial de búsquedas
        function updateRecentSearches() {
            const container = document.getElementById('recentSearches');
            
            if (recentSearches.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-sm text-center py-4">No hay búsquedas recientes</p>';
                return;
            }
            
            container.innerHTML = recentSearches.slice(0, 5).map(search => `
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors cursor-pointer"
                     onclick="searchByCode('${search.codigo}')">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-sm font-medium">${search.codigo}</span>
                        <span class="text-xs text-gray-500">${search.tipo}</span>
                        <span class="text-xs text-gray-400">${search.fecha}</span>
                    </div>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
            `).join('');
        }

        // Agregar búsqueda al historial
        function addToRecentSearches(codigo, tipo) {
            const search = {
                codigo: codigo,
                tipo: tipo,
                fecha: new Date().toLocaleString('es-PE')
            };
            
            // Eliminar duplicados
            recentSearches = recentSearches.filter(s => s.codigo !== codigo);
            
            // Agregar al inicio
            recentSearches.unshift(search);
            
            // Mantener solo las últimas 10
            recentSearches = recentSearches.slice(0, 10);
            
            // Guardar en localStorage
            localStorage.setItem('recentBarcodeSearches', JSON.stringify(recentSearches));
            
            updateRecentSearches();
        }

        // Buscar por código
        function searchByCode(codigo) {
            document.getElementById('barcodeInput').value = codigo;
            document.getElementById('barcodeSearchForm').dispatchEvent(new Event('submit'));
        }

        // Enviar búsqueda
        document.getElementById('barcodeSearchForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const codigo = document.getElementById('barcodeInput').value.trim();
            
            if (!codigo) {
                showNotification('Por favor ingresa un código de barras', 'warning');
                return;
            }
            
            if (!/^\d{13}$/.test(codigo)) {
                showNotification('El código de barras debe tener 13 dígitos', 'error');
                return;
            }
            
            searchBarcode(codigo);
        });

        function searchBarcode(codigo) {
            const formData = new FormData();
            formData.append('action', 'search_barcode');
            formData.append('codigo_barras', codigo);
            
            fetch('barcode_search.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displaySearchResult(data);
                    addToRecentSearches(codigo, data.tipo);
                } else {
                    showNotification(data.message, 'error');
                    document.getElementById('searchResults').innerHTML = `
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
                            <svg class="w-12 h-12 text-yellow-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 15.5c-.77.833.192 2.5 1.732 2.5z"></path>
                            </svg>
                            <p class="text-lg font-medium text-yellow-800">Código de barras no encontrado</p>
                            <p class="text-sm text-yellow-600 mt-1">No existe ningún producto o celular con este código</p>
                        </div>
                    `;
                    document.getElementById('searchResults').classList.remove('hidden');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error en la búsqueda', 'error');
            });
        }

        function displaySearchResult(result) {
            const container = document.getElementById('searchResults');
            let html = '';
            
            if (result.tipo === 'celular') {
                const item = result.data;
                currentItem = item;
                currentBarcode = item.codigo_barras;
                
                html = `
                    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                        <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white p-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-2xl font-bold">Celular Encontrado</h3>
                                    <p class="text-blue-100 mt-1">Código: ${item.codigo_barras}</p>
                                </div>
                                <div class="bg-white bg-opacity-20 p-4 rounded-lg">
                                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        
                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-3">Información del Dispositivo</h4>
                                    <dl class="space-y-2">
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Modelo:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.modelo}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Marca:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.marca || 'N/A'}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Capacidad:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.capacidad}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Color:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.color || 'N/A'}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Condición:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.condicion.charAt(0).toUpperCase() + item.condicion.slice(1)}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">IMEI 1:</dt>
                                            <dd class="text-sm font-medium text-gray-900 font-mono">${item.imei1}</dd>
                                        </div>
                                        ${item.imei2 ? `
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">IMEI 2:</dt>
                                            <dd class="text-sm font-medium text-gray-900 font-mono">${item.imei2}</dd>
                                        </div>
                                        ` : ''}
                                    </dl>
                                </div>
                                
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-3">Estado y Ubicación</h4>
                                    <dl class="space-y-2">
                                        <div class="flex justify-between items-center">
                                            <dt class="text-sm text-gray-600">Estado:</dt>
                                            <dd>
                                                <span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full ${
                                                    item.estado === 'disponible' ? 'bg-green-100 text-green-800' :
                                                    item.estado === 'vendido' ? 'bg-red-100 text-red-800' :
                                                    item.estado === 'reservado' ? 'bg-yellow-100 text-yellow-800' :
                                                    'bg-blue-100 text-blue-800'
                                                }">
                                                    ${item.estado.charAt(0).toUpperCase() + item.estado.slice(1)}
                                                </span>
                                            </dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Tienda:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.tienda_nombre}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Precio:</dt>
                                            <dd class="text-lg font-bold text-green-600">${parseFloat(item.precio).toFixed(2)}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Fecha Registro:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${new Date(item.fecha_registro).toLocaleDateString('es-PE')}</dd>
                                        </div>
                                    </dl>
                                    
                                    ${item.notas ? `
                                    <div class="mt-4 p-3 bg-gray-50 rounded-lg">
                                        <p class="text-xs text-gray-600 font-medium mb-1">Notas:</p>
                                        <p class="text-sm text-gray-700">${item.notas}</p>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                            
                            <div class="mt-6 flex gap-3">
                                <button onclick="showBarcodeModal('${item.codigo_barras}')" 
                                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors flex items-center justify-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
                                    </svg>
                                    Ver Código de Barras
                                </button>
                                <a href="inventory.php" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors flex items-center justify-center">
                                    Ir al Inventario
                                </a>
                                ${item.estado === 'disponible' ? `
                                <a href="sales.php" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors flex items-center justify-center">
                                    Vender
                                </a>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            } else if (result.tipo === 'producto') {
                const item = result.data;
                currentItem = item;
                currentBarcode = item.codigo_barras;
                
                html = `
                    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                        <div class="bg-gradient-to-r from-purple-500 to-purple-600 text-white p-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-2xl font-bold">Producto Encontrado</h3>
                                    <p class="text-purple-100 mt-1">Código: ${item.codigo_barras}</p>
                                </div>
                                <div class="bg-white bg-opacity-20 p-4 rounded-lg">
                                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        
                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-3">Información del Producto</h4>
                                    <dl class="space-y-2">
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Nombre:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.nombre}</dd>
                                        </div>
                                        ${item.codigo_producto ? `
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">SKU:</dt>
                                            <dd class="text-sm font-medium text-gray-900 font-mono">${item.codigo_producto}</dd>
                                        </div>
                                        ` : ''}
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Tipo:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.tipo.charAt(0).toUpperCase() + item.tipo.slice(1)}</dd>
                                        </div>
                                        ${item.categoria_nombre ? `
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Categoría:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.categoria_nombre}</dd>
                                        </div>
                                        ` : ''}
                                        ${item.marca ? `
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Marca:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.marca}</dd>
                                        </div>
                                        ` : ''}
                                        ${item.modelo_compatible ? `
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Compatible con:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.modelo_compatible}</dd>
                                        </div>
                                        ` : ''}
                                    </dl>
                                    
                                    ${item.descripcion ? `
                                    <div class="mt-4 p-3 bg-gray-50 rounded-lg">
                                        <p class="text-xs text-gray-600 font-medium mb-1">Descripción:</p>
                                        <p class="text-sm text-gray-700">${item.descripcion}</p>
                                    </div>
                                    ` : ''}
                                </div>
                                
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-3">Precios y Stock</h4>
                                    <dl class="space-y-2">
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Precio Venta:</dt>
                                            <dd class="text-lg font-bold text-green-600">${parseFloat(item.precio_venta).toFixed(2)}</dd>
                                        </div>
                                        ${item.precio_compra ? `
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Precio Compra:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${parseFloat(item.precio_compra).toFixed(2)}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Margen:</dt>
                                            <dd class="text-sm font-medium text-green-600">${(parseFloat(item.precio_venta) - parseFloat(item.precio_compra)).toFixed(2)}</dd>
                                        </div>
                                        ` : ''}
                                        <div class="flex justify-between items-center">
                                            <dt class="text-sm text-gray-600">Stock Total:</dt>
                                            <dd>
                                                <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full ${
                                                    item.stock_total > item.minimo_stock ? 'bg-green-100 text-green-800' :
                                                    item.stock_total > 0 ? 'bg-yellow-100 text-yellow-800' :
                                                    'bg-red-100 text-red-800'
                                                }">
                                                    ${item.stock_total || 0} unidades
                                                </span>
                                            </dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Stock Mínimo:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.minimo_stock} unidades</dd>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <dt class="text-sm text-gray-600">Estado:</dt>
                                            <dd>
                                                <span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full ${
                                                    item.activo ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                                                }">
                                                    ${item.activo ? 'Activo' : 'Inactivo'}
                                                </span>
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                            
                            <div class="mt-6 flex gap-3">
                                <button onclick="showBarcodeModal('${item.codigo_barras}')" 
                                        class="flex-1 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition-colors flex items-center justify-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
                                    </svg>
                                    Ver Código de Barras
                                </button>
                                <a href="products.php" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors flex items-center justify-center">
                                    Ir a Productos
                                </a>
                                ${item.stock_total > 0 ? `
                                <a href="product_sales.php" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors flex items-center justify-center">
                                    Vender
                                </a>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            }
            
            container.innerHTML = html;
            container.classList.remove('hidden');
            
            // Scroll suave a resultados
            container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function showBarcodeModal(codigo) {
            currentBarcode = codigo;
            
            // Generar SVG del código de barras (simplificado)
            const display = document.getElementById('barcodeDisplay');
            display.innerHTML = `
                <div class="p-4 bg-gray-50 rounded-lg">
                    <div class="mb-3">
                        <svg width="300" height="100" xmlns="http://www.w3.org/2000/svg">
                            <rect width="100%" height="100%" fill="white"/>
                            <text x="150" y="85" text-anchor="middle" font-family="monospace" font-size="16" fill="black">${codigo}</text>
                            <!-- Barras simplificadas -->
                            ${generateSimpleBarcode(codigo)}
                        </svg>
                    </div>
                    <p class="text-sm text-gray-600 text-center font-mono font-bold text-lg">${codigo}</p>
                </div>
            `;
            
            document.getElementById('barcodeModal').classList.add('show');
        }

        function generateSimpleBarcode(codigo) {
            let bars = '';
            let x = 10;
            const barWidth = 3;
            
            for (let i = 0; i < codigo.length; i++) {
                const digit = parseInt(codigo[i]);
                const height = 60 - (digit * 3);
                
                if (i % 2 === 0) {
                    bars += `<rect x="${x}" y="15" width="${barWidth}" height="${height}" fill="black"/>`;
                }
                x += barWidth + 2;
            }
            
            return bars;
        }

        function closeBarcodeModal() {
            document.getElementById('barcodeModal').classList.remove('show');
        }

        function printBarcode() {
            if (!currentBarcode || !currentItem) {
                showNotification('No hay código de barras para imprimir', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'print_label');
            formData.append('codigo_barras', currentBarcode);
            
            let titulo = '';
            let precio = null;
            
            if (currentItem.modelo) {
                // Es un celular
                titulo = `${currentItem.modelo} ${currentItem.capacidad}`;
                precio = currentItem.precio;
            } else {
                // Es un producto
                titulo = currentItem.nombre;
                precio = currentItem.precio_venta;
            }
            
            formData.append('titulo', titulo);
            formData.append('precio', precio);
            
            fetch('barcode_search.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const printWindow = window.open('', '_blank');
                    printWindow.document.write(data.html);
                    printWindow.document.close();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error al imprimir etiqueta', 'error');
            });
        }

        function clearSearch() {
            document.getElementById('barcodeInput').value = '';
            document.getElementById('searchResults').classList.add('hidden');
            document.getElementById('barcodeInput').focus();
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm transition-all duration-300 ${
                type === 'success' ? 'bg-green-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                type === 'warning' ? 'bg-yellow-500 text-white' :
                'bg-blue-500 text-white'
            }`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 4000);
        }

        // Auto-focus en el input
        document.getElementById('barcodeInput').focus();

        // Detectar lectura de escáner (entrada rápida)
        let scannerBuffer = '';
        let scannerTimeout;

        document.addEventListener('keypress', function(e) {
            // Si el input tiene foco, dejar que funcione normalmente
            if (document.activeElement === document.getElementById('barcodeInput')) {
                return;
            }
            
            // Acumular caracteres
            scannerBuffer += e.key;
            
            // Resetear timeout
            clearTimeout(scannerTimeout);
            
            // Si se detecta Enter, procesar el código
            if (e.key === 'Enter' && scannerBuffer.length >= 13) {
                const codigo = scannerBuffer.replace('Enter', '').trim();
                document.getElementById('barcodeInput').value = codigo;
                searchBarcode(codigo);
                scannerBuffer = '';
            } else {
                // Limpiar buffer después de 100ms (lectura manual)
                scannerTimeout = setTimeout(() => {
                    scannerBuffer = '';
                }, 100);
            }
        });

        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeBarcodeModal();
            }
        });

        // Inicializar historial
        updateRecentSearches();
    </script>
</body>
</html>