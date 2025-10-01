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
    
    switch ($_POST['action']) {
        case 'create_store':
            try {
                $nombre = sanitize($_POST['nombre']);
                $direccion = sanitize($_POST['direccion']);
                $telefono = sanitize($_POST['telefono']);
                $email = sanitize($_POST['email']);
                
                if (empty($nombre)) {
                    throw new Exception('El nombre de la tienda es obligatorio');
                }
                
                if ($email && !validateEmail($email)) {
                    throw new Exception('El formato del email no es válido');
                }
                
                $stmt = $db->prepare("INSERT INTO tiendas (nombre, direccion, telefono, email) VALUES (?, ?, ?, ?)");
                $result = $stmt->execute([$nombre, $direccion, $telefono, $email]);
                
                if ($result) {
                    logActivity($user['id'], 'create_store', "Tienda creada: $nombre");
                    echo json_encode(['success' => true, 'message' => 'Tienda creada correctamente']);
                } else {
                    throw new Exception('Error al crear la tienda');
                }
            } catch(Exception $e) {
                logError("Error al crear tienda: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'update_store':
            try {
                $store_id = intval($_POST['store_id']);
                $nombre = sanitize($_POST['nombre']);
                $direccion = sanitize($_POST['direccion']);
                $telefono = sanitize($_POST['telefono']);
                $email = sanitize($_POST['email']);
                $activa = isset($_POST['activa']) ? 1 : 0;
                
                if (empty($nombre)) {
                    throw new Exception('El nombre de la tienda es obligatorio');
                }
                
                if ($email && !validateEmail($email)) {
                    throw new Exception('El formato del email no es válido');
                }
                
                $stmt = $db->prepare("UPDATE tiendas SET nombre = ?, direccion = ?, telefono = ?, email = ?, activa = ? WHERE id = ?");
                $result = $stmt->execute([$nombre, $direccion, $telefono, $email, $activa, $store_id]);
                
                if ($result) {
                    logActivity($user['id'], 'update_store', "Tienda actualizada ID: $store_id");
                    echo json_encode(['success' => true, 'message' => 'Tienda actualizada correctamente']);
                } else {
                    throw new Exception('Error al actualizar la tienda');
                }
            } catch(Exception $e) {
                logError("Error al actualizar tienda: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'delete_store':
            try {
                $store_id = intval($_POST['store_id']);
                
                // Verificar si tiene usuarios asignados
                $check_users = $db->prepare("SELECT COUNT(*) as count FROM usuarios WHERE tienda_id = ?");
                $check_users->execute([$store_id]);
                $user_count = $check_users->fetch()['count'];
                
                // Verificar si tiene dispositivos
                $check_devices = $db->prepare("SELECT COUNT(*) as count FROM celulares WHERE tienda_id = ?");
                $check_devices->execute([$store_id]);
                $device_count = $check_devices->fetch()['count'];
                
                if ($user_count > 0) {
                    throw new Exception("No se puede eliminar la tienda porque tiene $user_count usuarios asignados");
                }
                
                if ($device_count > 0) {
                    throw new Exception("No se puede eliminar la tienda porque tiene $device_count dispositivos registrados");
                }
                
                $stmt = $db->prepare("DELETE FROM tiendas WHERE id = ?");
                $result = $stmt->execute([$store_id]);
                
                if ($result) {
                    logActivity($user['id'], 'delete_store', "Tienda eliminada ID: $store_id");
                    echo json_encode(['success' => true, 'message' => 'Tienda eliminada correctamente']);
                } else {
                    throw new Exception('Error al eliminar la tienda');
                }
            } catch(Exception $e) {
                logError("Error al eliminar tienda: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
    }
}

// Obtener tiendas con estadísticas
try {
    $stores_query = "
        SELECT 
            t.*,
            COUNT(DISTINCT u.id) as total_usuarios,
            COUNT(DISTINCT c.id) as total_dispositivos,
            COUNT(DISTINCT CASE WHEN c.estado = 'disponible' THEN c.id END) as dispositivos_disponibles,
            COALESCE(SUM(CASE WHEN c.estado = 'disponible' THEN c.precio ELSE 0 END), 0) as valor_inventario,
            COUNT(DISTINCT v.id) as total_ventas,
            COALESCE(SUM(v.precio_venta), 0) as ingresos_ventas
        FROM tiendas t
        LEFT JOIN usuarios u ON t.id = u.tienda_id AND u.activo = 1
        LEFT JOIN celulares c ON t.id = c.tienda_id
        LEFT JOIN ventas v ON t.id = v.tienda_id
        GROUP BY t.id, t.nombre, t.direccion, t.telefono, t.email, t.activa, t.fecha_creacion
        ORDER BY t.nombre
    ";
    $stores_stmt = $db->query($stores_query);
    $stores = $stores_stmt->fetchAll();
    
} catch(Exception $e) {
    logError("Error al obtener tiendas: " . $e->getMessage());
    $stores = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tiendas - <?php echo SYSTEM_NAME; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        .modal { display: none; }
        .modal.show { display: flex; }
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
                        <?php echo htmlspecialchars($user['nombre']); ?> - Administrador
                    </span>
                    <a href="logout.php" class="text-sm text-red-600 hover:text-red-800">Cerrar Sesión</a>
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
                        <a href="users.php" class="flex items-center px-4 py-2 mt-2 text-gray-700 hover:bg-gray-100 rounded">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                            </svg>
                            Usuarios
                        </a>
                        <a href="stores.php" class="flex items-center px-4 py-2 text-gray-700 bg-blue-50 border-r-4 border-blue-500 rounded-l">
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
                        <h2 class="text-3xl font-bold text-gray-900">Gestión de Tiendas</h2>
                        <p class="text-gray-600">Administrar sucursales y ubicaciones</p>
                    </div>
                    <button onclick="openCreateModal()" class="mt-4 md:mt-0 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Crear Tienda
                    </button>
                </div>

                <!-- Grid de Tiendas -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach($stores as $store): ?>
                        <div class="bg-white rounded-lg shadow-md overflow-hidden">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($store['nombre']); ?></h3>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php 
                                        echo $store['activa'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; 
                                    ?>">
                                        <?php echo $store['activa'] ? 'Activa' : 'Inactiva'; ?>
                                    </span>
                                </div>
                                
                                <?php if ($store['direccion']): ?>
                                    <div class="flex items-start mb-3">
                                        <svg class="w-4 h-4 text-gray-400 mt-1 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($store['direccion']); ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($store['telefono']): ?>
                                    <div class="flex items-center mb-3">
                                        <svg class="w-4 h-4 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                        </svg>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($store['telefono']); ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($store['email']): ?>
                                    <div class="flex items-center mb-4">
                                        <svg class="w-4 h-4 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 7.89a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                        </svg>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($store['email']); ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Estadísticas -->
                                <div class="grid grid-cols-2 gap-4 mb-4 pt-4 border-t border-gray-200">
                                    <div class="text-center">
                                        <p class="text-lg font-semibold text-blue-600"><?php echo $store['total_usuarios']; ?></p>
                                        <p class="text-xs text-gray-600">Usuarios</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-lg font-semibold text-green-600"><?php echo $store['total_dispositivos']; ?></p>
                                        <p class="text-xs text-gray-600">Dispositivos</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-lg font-semibold text-purple-600">$<?php echo number_format($store['valor_inventario'], 0); ?></p>
                                        <p class="text-xs text-gray-600">Inventario</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-lg font-semibold text-yellow-600"><?php echo $store['total_ventas']; ?></p>
                                        <p class="text-xs text-gray-600">Ventas</p>
                                    </div>
                                </div>
                                
                                <!-- Acciones -->
                                <div class="flex justify-end gap-2">
                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($store)); ?>)" 
                                            class="text-blue-600 hover:text-blue-900 p-2 rounded" title="Editar">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </button>
                                    <?php if ($store['total_usuarios'] == 0 && $store['total_dispositivos'] == 0): ?>
                                        <button onclick="deleteStore(<?php echo $store['id']; ?>, '<?php echo htmlspecialchars($store['nombre']); ?>')" 
                                                class="text-red-600 hover:text-red-900 p-2 rounded" title="Eliminar">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Crear/Editar Tienda -->
    <div id="storeModal" class="modal fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 id="storeModalTitle" class="text-lg font-semibold">Crear Tienda</h3>
                <button onclick="closeStoreModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <div id="storeForm" class="space-y-4">
                <input type="hidden" id="storeId">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de la Tienda *</label>
                    <input type="text" id="nombre" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Dirección</label>
                    <textarea id="direccion" rows="2" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                    <input type="tel" id="telefono" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" id="email" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div id="statusField" class="hidden">
                    <label class="flex items-center">
                        <input type="checkbox" id="activa" class="mr-2">
                        <span class="text-sm font-medium text-gray-700">Tienda activa</span>
                    </label>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button onclick="closeStoreModal()" 
                            class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg">
                        Cancelar
                    </button>
                    <button onclick="saveStore()" 
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                        Guardar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Overlay para móvil -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 md:hidden hidden"></div>

    <script>
        // Toggle sidebar
        document.getElementById('sidebar-toggle').addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('sidebar-hidden');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        });

        document.getElementById('sidebar-overlay').addEventListener('click', () => {
            document.getElementById('sidebar').classList.add('sidebar-hidden');
            document.getElementById('sidebar-overlay').classList.add('hidden');
        });

        let isEditMode = false;

        function openCreateModal() {
            isEditMode = false;
            document.getElementById('storeModalTitle').textContent = 'Crear Tienda';
            document.getElementById('storeId').value = '';
            document.getElementById('nombre').value = '';
            document.getElementById('direccion').value = '';
            document.getElementById('telefono').value = '';
            document.getElementById('email').value = '';
            document.getElementById('activa').checked = true;
            
            document.getElementById('statusField').classList.add('hidden');
            document.getElementById('storeModal').classList.add('show');
        }

        function openEditModal(storeData) {
            isEditMode = true;
            document.getElementById('storeModalTitle').textContent = 'Editar Tienda';
            document.getElementById('storeId').value = storeData.id;
            document.getElementById('nombre').value = storeData.nombre;
            document.getElementById('direccion').value = storeData.direccion || '';
            document.getElementById('telefono').value = storeData.telefono || '';
            document.getElementById('email').value = storeData.email || '';
            document.getElementById('activa').checked = storeData.activa == 1;
            
            document.getElementById('statusField').classList.remove('hidden');
            document.getElementById('storeModal').classList.add('show');
        }

        function closeStoreModal() {
            document.getElementById('storeModal').classList.remove('show');
        }

        function saveStore() {
            const formData = new FormData();
            formData.append('action', isEditMode ? 'update_store' : 'create_store');
            
            if (isEditMode) {
                formData.append('store_id', document.getElementById('storeId').value);
            }
            
            formData.append('nombre', document.getElementById('nombre').value);
            formData.append('direccion', document.getElementById('direccion').value);
            formData.append('telefono', document.getElementById('telefono').value);
            formData.append('email', document.getElementById('email').value);
            
            if (isEditMode && document.getElementById('activa').checked) {
                formData.append('activa', '1');
            }
            
            const button = event.target;
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Guardando...';
            
            fetch('stores.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error en la conexión');
            })
            .finally(() => {
                button.disabled = false;
                button.textContent = originalText;
            });
        }

        function deleteStore(storeId, storeName) {
            if (!confirm(`¿Estás seguro de que quieres eliminar la tienda "${storeName}"?\n\nEsta acción no se puede deshacer.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_store');
            formData.append('store_id', storeId);
            
            fetch('stores.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error en la conexión');
            });
        }

        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStoreModal();
            }
        });
    </script>
</body>
</html>
                