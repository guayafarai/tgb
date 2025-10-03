<?php
/**
 * SISTEMA DE IMPRESIÓN PARA IMPRESORA NES NT-P58-X
 * Archivo: public/print_label.php
 */

require_once '../config/database.php';
require_once '../includes/auth.php';

setSecurityHeaders();
startSecureSession();
requireLogin();

$user = getCurrentUser();
$db = getDB();

/**
 * Clase para impresora NES NT-P58-X (58mm)
 */
class NESPrinter {
    private $printer_width_mm = 58;
    private $printer_width_px = 384;
    
    public function generateLabel($codigo_barras, $titulo, $precio = null, $incluir_fecha = true) {
        $fecha_actual = date('d/m/Y H:i');
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiqueta - ' . htmlspecialchars($codigo_barras) . '</title>
    <style>
        @page {
            size: 58mm 40mm;
            margin: 0;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .no-print {
                display: none !important;
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            width: 58mm;
            height: 40mm;
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            background: white;
            padding: 2mm;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .etiqueta-container {
            width: 100%;
            text-align: center;
        }
        
        .titulo {
            font-size: 9pt;
            font-weight: bold;
            line-height: 1.1;
            margin-bottom: 2mm;
            max-height: 12mm;
            overflow: hidden;
            word-wrap: break-word;
            padding: 0 1mm;
        }
        
        .barcode-container {
            margin: 2mm 0;
            display: flex;
            justify-content: center;
        }
        
        .barcode-svg {
            width: 48mm;
            height: 12mm;
        }
        
        .codigo-texto {
            font-family: "Courier New", Courier, monospace;
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 2mm 0;
        }
        
        .precio {
            font-size: 14pt;
            font-weight: bold;
            margin: 2mm 0;
            border: 2px solid black;
            padding: 1mm 3mm;
            display: inline-block;
            border-radius: 2mm;
        }
        
        .fecha {
            font-size: 6pt;
            color: #666;
            margin-top: 1mm;
        }
        
        @media screen {
            body {
                background: #f0f0f0;
                padding: 20px;
            }
            
            .etiqueta-container {
                background: white;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                padding: 5mm;
                border-radius: 3mm;
            }
            
            .controles {
                margin-bottom: 20px;
                padding: 15px;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                align-items: center;
            }
            
            button {
                padding: 10px 20px;
                font-size: 14px;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-weight: 500;
                transition: all 0.2s;
            }
            
            .btn-print {
                background: #8b5cf6;
                color: white;
            }
            
            .btn-print:hover {
                background: #7c3aed;
            }
            
            .btn-close {
                background: #6b7280;
                color: white;
            }
            
            .btn-close:hover {
                background: #4b5563;
            }
            
            .info-text {
                flex: 1;
                min-width: 200px;
                color: #4b5563;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="controles no-print">
        <button class="btn-print" onclick="window.print()">
            🖨️ Imprimir Etiqueta
        </button>
        <button class="btn-close" onclick="window.close()">
            ❌ Cerrar
        </button>
        <div class="info-text">
            <strong>Impresora:</strong> NES NT-P58-X (58mm)
        </div>
    </div>
    
    <div class="etiqueta-container">
        <div class="titulo">' . htmlspecialchars($titulo) . '</div>
        
        <div class="barcode-container">
            ' . $this->generateBarcodeSVG($codigo_barras, 182, 45) . '
        </div>
        
        <div class="codigo-texto">' . $codigo_barras . '</div>';
        
        if ($precio !== null) {
            $html .= '
        <div class="precio">$' . number_format($precio, 2) . '</div>';
        }
        
        if ($incluir_fecha) {
            $html .= '
        <div class="fecha">' . $fecha_actual . '</div>';
        }
        
        $html .= '
    </div>
    
    <script>
        document.addEventListener("keydown", function(e) {
            if (e.key === "p" || e.key === "P") {
                e.preventDefault();
                window.print();
            }
            if (e.key === "Escape") {
                window.close();
            }
        });
    </script>
</body>
</html>';
        
        return $html;
    }
    
    public function generateMultipleLabels($etiquetas_array) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            size: 58mm auto;
            margin: 0;
        }
        
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
        }
        
        body {
            width: 58mm;
            font-family: Arial, sans-serif;
            background: white;
            margin: 0;
            padding: 0;
        }
        
        .etiqueta {
            width: 58mm;
            min-height: 40mm;
            padding: 2mm;
            text-align: center;
            page-break-inside: avoid;
            border-bottom: 1px dashed #ccc;
        }
        
        .etiqueta:last-child { border-bottom: none; }
        .titulo { font-size: 9pt; font-weight: bold; margin-bottom: 2mm; line-height: 1.1; }
        .barcode-container { margin: 2mm 0; }
        .barcode-svg { width: 48mm; height: 12mm; }
        .codigo-texto { font-family: monospace; font-size: 8pt; font-weight: bold; margin: 2mm 0; }
        .precio { font-size: 14pt; font-weight: bold; margin: 2mm 0; }
        .fecha { font-size: 6pt; color: #666; }
        
        @media screen {
            body { background: #f0f0f0; padding: 20px; }
            .controles {
                position: sticky;
                top: 0;
                background: white;
                padding: 15px;
                margin-bottom: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                z-index: 100;
            }
        }
    </style>
</head>
<body>
    <div class="controles no-print">
        <button onclick="window.print()" style="padding: 10px 20px; background: #8b5cf6; color: white; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px;">
            🖨️ Imprimir Todas (' . count($etiquetas_array) . ' etiquetas)
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #6b7280; color: white; border: none; border-radius: 5px; cursor: pointer;">
            ❌ Cerrar
        </button>
    </div>';
        
        foreach ($etiquetas_array as $etiqueta) {
            $fecha_actual = date('d/m/Y H:i');
            
            $html .= '
    <div class="etiqueta">
        <div class="titulo">' . htmlspecialchars($etiqueta['titulo']) . '</div>
        <div class="barcode-container">
            ' . $this->generateBarcodeSVG($etiqueta['codigo_barras'], 182, 45) . '
        </div>
        <div class="codigo-texto">' . $etiqueta['codigo_barras'] . '</div>';
            
            if (isset($etiqueta['precio'])) {
                $html .= '<div class="precio">$' . number_format($etiqueta['precio'], 2) . '</div>';
            }
            
            $html .= '
        <div class="fecha">' . $fecha_actual . '</div>
    </div>';
        }
        
        $html .= '
</body>
</html>';
        
        return $html;
    }
    
    private function generateBarcodeSVG($codigo, $width = 182, $height = 45) {
        $patterns = [
            '0' => '0001101', '1' => '0011001', '2' => '0010011', '3' => '0111101', '4' => '0100011',
            '5' => '0110001', '6' => '0101111', '7' => '0111011', '8' => '0110111', '9' => '0001011'
        ];
        
        $bar_width = $width / 95;
        $x = 0;
        
        $svg = '<svg class="barcode-svg" width="' . $width . '" height="' . $height . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '">';
        $svg .= '<rect width="100%" height="100%" fill="white"/>';
        
        $svg .= '<rect x="' . $x . '" y="0" width="' . ($bar_width * 1.5) . '" height="' . ($height - 8) . '" fill="black"/>';
        $x += $bar_width * 3;
        $svg .= '<rect x="' . $x . '" y="0" width="' . ($bar_width * 1.5) . '" height="' . ($height - 8) . '" fill="black"/>';
        $x += $bar_width * 3;
        
        for ($i = 0; $i < strlen($codigo); $i++) {
            if (isset($patterns[$codigo[$i]])) {
                $pattern = $patterns[$codigo[$i]];
                
                for ($j = 0; $j < strlen($pattern); $j++) {
                    if ($pattern[$j] == '1') {
                        $svg .= '<rect x="' . $x . '" y="0" width="' . $bar_width . '" height="' . ($height - 8) . '" fill="black"/>';
                    }
                    $x += $bar_width;
                }
            }
            
            if ($i == 5) {
                $x += $bar_width;
                $svg .= '<rect x="' . $x . '" y="0" width="' . ($bar_width * 1.5) . '" height="' . ($height - 8) . '" fill="black"/>';
                $x += $bar_width * 3;
                $svg .= '<rect x="' . $x . '" y="0" width="' . ($bar_width * 1.5) . '" height="' . ($height - 8) . '" fill="black"/>';
                $x += $bar_width * 3;
            }
        }
        
        $svg .= '<rect x="' . $x . '" y="0" width="' . ($bar_width * 1.5) . '" height="' . ($height - 8) . '" fill="black"/>';
        $x += $bar_width * 3;
        $svg .= '<rect x="' . $x . '" y="0" width="' . ($bar_width * 1.5) . '" height="' . ($height - 8) . '" fill="black"/>';
        
        $svg .= '</svg>';
        
        return $svg;
    }
}

// Procesar solicitudes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['print_nes'])) {
    $printer = new NESPrinter();
    
    try {
        if (isset($_POST['multiple']) && $_POST['multiple'] === 'true') {
            // Imprimir múltiples etiquetas
            $etiquetas = json_decode($_POST['etiquetas_data'], true);
            
            if (!$etiquetas || !is_array($etiquetas)) {
                throw new Exception('Datos de etiquetas inválidos');
            }
            
            $html = $printer->generateMultipleLabels($etiquetas);
            logActivity($user['id'], 'print_labels_nes', count($etiquetas) . " etiquetas impresas");
        } else {
            // Imprimir etiqueta individual
            $codigo = sanitize($_POST['codigo_barras']);
            $titulo = sanitize($_POST['titulo']);
            $precio = isset($_POST['precio']) ? floatval($_POST['precio']) : null;
            
            if (empty($codigo) || empty($titulo)) {
                throw new Exception('Código de barras y título son obligatorios');
            }
            
            $html = $printer->generateLabel($codigo, $titulo, $precio);
            logActivity($user['id'], 'print_label_nes', "Etiqueta impresa: $codigo");
        }
        
        echo $html;
        
    } catch(Exception $e) {
        logError("Error imprimiendo en NES: " . $e->getMessage());
        http_response_code(500);
        echo "Error: " . htmlspecialchars($e->getMessage());
    }
    
    exit;
}

// Si no es POST, mostrar página de prueba
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Impresión NES</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-2xl mx-auto">
        <div class="bg-white rounded-lg shadow p-6">
            <h1 class="text-2xl font-bold mb-4">🖨️ Prueba de Impresión NES NT-P58-X</h1>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="print_nes" value="true">
                
                <div>
                    <label class="block font-medium mb-1">Código de Barras:</label>
                    <input type="text" name="codigo_barras" value="2000100000001" required
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
                
                <div>
                    <label class="block font-medium mb-1">Título:</label>
                    <input type="text" name="titulo" value="iPhone 15 Pro 256GB Negro" required
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
                
                <div>
                    <label class="block font-medium mb-1">Precio:</label>
                    <input type="number" name="precio" value="999.99" step="0.01"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
                
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-lg">
                    Imprimir Etiqueta de Prueba
                </button>
            </form>
        </div>
    </div>
</body>
</html>
