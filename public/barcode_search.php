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
                    $stmt = $db->prepare("SELECT tienda_id FROM celulares WHERE id = ?");
                    $stmt->execute([$item_id]);
                    $celular = $stmt->fetch();
                    
                    if (!$celular) {
                        throw new Exception('Celular no encontrado');
                    }
                    
                    $codigo_barras = $barcodeGen->generateCelularBarcode($celular['tienda_id']);
                    
                    $update_stmt = $db->prepare("UPDATE celulares SET codigo_barras = ? WHERE id = ?");
                    $update_stmt->execute([$codigo_barras, $item_id]);
                    
                } elseif ($tipo === 'producto') {
                    $stmt = $db->prepare("SELECT categoria_id FROM productos WHERE id = ?");
                    $stmt->execute([$item_id]);
                    $producto = $stmt->fetch();
                    
                    if (!$producto) {
                        throw new Exception('Producto no encontrado');
                    }
                    
                    $codigo_barras = $barcodeGen->generateProductoBarcode($producto['categoria_id'] ?? 0);
                    
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
    
    <main class="page-content">
        <div class="p-6">
            <div class="mb-6">
                <h2 class="text-3xl font-bold text-gray-900">Búsqueda por Código de Barras</h2>
                <p class="text-gray-600">Escanea o ingresa el código de barras para buscar productos y celulares</p>
            </div>

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

            <div id="searchResults" class="hidden"></div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Búsquedas Recientes</h3>
                <div id="recentSearches" class="space-y-2">
                    <p class="text-gray-500 text-sm text-center py-4">No hay búsquedas recientes</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal de Impresión -->
    <div id="printModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Imprimir Etiqueta NES</h3>
                <button onclick="closePrintModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <div class="mb-4 p-4 bg-purple-50 border border-purple-200 rounded-lg">
                <div class="flex items-center gap-3">
                    <div class="bg-purple-100 p-2 rounded-lg">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="font-semibold text-purple-900">NES NT-P58-X</p>
                        <p class="text-sm text-purple-700">Impresora térmica 58mm</p>
                    </div>
                </div>
            </div>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Producto</label>
                    <input type="text" id="print_titulo" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Código</label>
                        <input type="text" id="print_codigo" readonly class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Precio</label>
                        <input type="number" id="print_precio" step="0.01" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad de copias</label>
                    <input type="number" id="print_cantidad" value="1" min="1" max="50" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button onclick="executePrint()" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-lg transition-colors flex items-center justify-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                    </svg>
                    Imprimir
                </button>
                <button onclick="closePrintModal()" class="px-4 py-3 bg-gray-300 hover:bg-gray-400 text-gray-700 rounded-lg transition-colors">
                    Cancelar
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

        function addToRecentSearches(codigo, tipo) {
            const search = {
                codigo: codigo,
                tipo: tipo,
                fecha: new Date().toLocaleString('es-PE')
            };
            
            recentSearches = recentSearches.filter(s => s.codigo !== codigo);
            recentSearches.unshift(search);
            recentSearches = recentSearches.slice(0, 10);
            localStorage.setItem('recentBarcodeSearches', JSON.stringify(recentSearches));
            updateRecentSearches();
        }

        function searchByCode(codigo) {
            document.getElementById('barcodeInput').value = codigo;
            document.getElementById('barcodeSearchForm').dispatchEvent(new Event('submit'));
        }

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
                                    </dl>
                                </div>
                                
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-3">Estado y Ubicación</h4>
                                    <dl class="space-y-2">
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Tienda:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.tienda_nombre}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Precio:</dt>
                                            <dd class="text-lg font-bold text-green-600">${parseFloat(item.precio).toFixed(2)}</dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                            
                            <div class="mt-6 flex gap-3">
                                <button onclick="openPrintModal('${item.codigo_barras}', '${escapeHtml(item.modelo + ' ' + item.capacidad)}', ${item.precio})" 
                                        class="flex-1 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition-colors flex items-center justify-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                    </svg>
                                    Imprimir Etiqueta
                                </button>
                                <a href="inventory.php" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors flex items-center justify-center">
                                    Ir al Inventario
                                </a>
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
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Tipo:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.tipo}</dd>
                                        </div>
                                    </dl>
                                </div>
                                
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-3">Precio y Stock</h4>
                                    <dl class="space-y-2">
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Precio:</dt>
                                            <dd class="text-lg font-bold text-green-600">${parseFloat(item.precio_venta).toFixed(2)}</dd>
                                        </div>
                                        <div class="flex justify-between">
                                            <dt class="text-sm text-gray-600">Stock:</dt>
                                            <dd class="text-sm font-medium text-gray-900">${item.stock_total || 0} unidades</dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                            
                            <div class="mt-6 flex gap-3">
                                <button onclick="openPrintModal('${item.codigo_barras}', '${escapeHtml(item.nombre)}', ${item.precio_venta})" 
                                        class="flex-1 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition-colors flex items-center justify-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                    </svg>
                                    Imprimir Etiqueta
                                </button>
                                <a href="products.php" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors flex items-center justify-center">
                                    Ir a Productos
                                </a>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            container.innerHTML = html;
            container.classList.remove('hidden');
            container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function openPrintModal(codigo, titulo, precio) {
            currentBarcode = codigo;
            
            document.getElementById('print_codigo').value = codigo;
            document.getElementById('print_titulo').value = titulo;
            document.getElementById('print_precio').value = precio;
            document.getElementById('print_cantidad').value = 1;
            
            document.getElementById('printModal').classList.add('show');
        }

        function closePrintModal() {
            document.getElementById('printModal').classList.remove('show');
        }

        function executePrint() {
            const codigo = document.getElementById('print_codigo').value;
            const titulo = document.getElementById('print_titulo').value;
            const precio = parseFloat(document.getElementById('print_precio').value);
            const cantidad = parseInt(document.getElementById('print_cantidad').value);
            
            closePrintModal();
            
            if (cantidad === 1) {
                printLabelNES(codigo, titulo, precio);
            } else {
                const etiquetas = [];
                for (let i = 0; i < cantidad; i++) {
                    etiquetas.push({
                        codigo_barras: codigo,
                        titulo: titulo,
                        precio: precio
                    });
                }
                printMultipleLabelsNES(etiquetas);
            }
            
            showNotification(`Imprimiendo ${cantidad} etiqueta(s)...`, 'success');
        }

        function printLabelNES(codigo_barras, titulo, precio) {
            const formData = new FormData();
            formData.append('print_nes', 'true');
            formData.append('codigo_barras', codigo_barras);
            formData.append('titulo', titulo);
            formData.append('precio', precio);
            
            fetch('print_label.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                const printWindow = window.open('', '_blank', 'width=600,height=800');
                printWindow.document.write(html);
                printWindow.document.close();
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error al imprimir etiqueta', 'error');
            });
        }

        function printMultipleLabelsNES(etiquetas) {
            const formData = new FormData();
            formData.append('print_nes', 'true');
            formData.append('multiple', 'true');
            formData.append('etiquetas_data', JSON.stringify(etiquetas));
            
            fetch('print_label.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                const printWindow = window.open('', '_blank', 'width=600,height=800');
                printWindow.document.write(html);
                printWindow.document.close();
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error al imprimir etiquetas', 'error');
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

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.getElementById('barcodeInput').focus();

        let scannerBuffer = '';
        let scannerTimeout;

        document.addEventListener('keypress', function(e) {
            if (document.activeElement === document.getElementById('barcodeInput')) {
                return;
            }
            
            scannerBuffer += e.key;
            clearTimeout(scannerTimeout);
            
            if (e.key === 'Enter' && scannerBuffer.length >= 13) {
                const codigo = scannerBuffer.replace('Enter', '').trim();
                document.getElementById('barcodeInput').value = codigo;
                searchBarcode(codigo);
                scannerBuffer = '';
            } else {
                scannerTimeout = setTimeout(() => {
                    scannerBuffer = '';
                }, 100);
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePrintModal();
            }
        });

        updateRecentSearches();
    </script>
</body>
</html>