<?php
/**
 * SISTEMA DE GENERACIÓN Y GESTIÓN DE CÓDIGOS DE BARRAS
 * Genera códigos de barras únicos para productos y celulares
 * Formato: EAN-13 compatible
 */

class BarcodeGenerator {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Genera un código de barras único para celulares
     * Formato: 200 (celulares) + TIENDA_ID(2) + SECUENCIAL(8)
     */
    public function generateCelularBarcode($tienda_id) {
        $prefix = '200'; // Prefijo para celulares
        $tienda = str_pad($tienda_id, 2, '0', STR_PAD_LEFT);
        
        // Obtener siguiente número secuencial
        $stmt = $this->db->prepare("
            SELECT MAX(CAST(SUBSTRING(codigo_barras, 6, 8) AS UNSIGNED)) as max_seq 
            FROM celulares 
            WHERE codigo_barras LIKE CONCAT(?, ?, '%')
        ");
        $stmt->execute([$prefix, $tienda]);
        $result = $stmt->fetch();
        
        $next_seq = ($result['max_seq'] ?? 0) + 1;
        $secuencial = str_pad($next_seq, 8, '0', STR_PAD_LEFT);
        
        // Código base sin dígito verificador
        $codigo_base = $prefix . $tienda . $secuencial;
        
        // Calcular dígito verificador EAN-13
        $digito_verificador = $this->calcularDigitoVerificador($codigo_base);
        
        return $codigo_base . $digito_verificador;
    }
    
    /**
     * Genera un código de barras único para productos
     * Formato: 300 (productos) + CATEGORIA(2) + SECUENCIAL(8)
     */
    public function generateProductoBarcode($categoria_id = 0) {
        $prefix = '300'; // Prefijo para productos
        $categoria = str_pad($categoria_id, 2, '0', STR_PAD_LEFT);
        
        // Obtener siguiente número secuencial
        $stmt = $this->db->prepare("
            SELECT MAX(CAST(SUBSTRING(codigo_barras, 6, 8) AS UNSIGNED)) as max_seq 
            FROM productos 
            WHERE codigo_barras LIKE CONCAT(?, ?, '%')
        ");
        $stmt->execute([$prefix, $categoria]);
        $result = $stmt->fetch();
        
        $next_seq = ($result['max_seq'] ?? 0) + 1;
        $secuencial = str_pad($next_seq, 8, '0', STR_PAD_LEFT);
        
        // Código base sin dígito verificador
        $codigo_base = $prefix . $categoria . $secuencial;
        
        // Calcular dígito verificador EAN-13
        $digito_verificador = $this->calcularDigitoVerificador($codigo_base);
        
        return $codigo_base . $digito_verificador;
    }
    
    /**
     * Calcula el dígito verificador según algoritmo EAN-13
     */
    private function calcularDigitoVerificador($codigo) {
        $suma = 0;
        $codigo = str_pad($codigo, 12, '0', STR_PAD_LEFT);
        
        for ($i = 0; $i < 12; $i++) {
            $digito = intval($codigo[$i]);
            // Multiplicar por 1 o 3 según la posición
            $suma += ($i % 2 == 0) ? $digito : $digito * 3;
        }
        
        $modulo = $suma % 10;
        return ($modulo == 0) ? 0 : 10 - $modulo;
    }
    
    /**
     * Genera SVG del código de barras
     */
    public function generateBarcodeSVG($codigo, $width = 300, $height = 100) {
        // Patrón de barras para cada dígito (EAN-13 simplificado)
        $patterns = [
            '0' => '0001101', '1' => '0011001', '2' => '0010011', '3' => '0111101', '4' => '0100011',
            '5' => '0110001', '6' => '0101111', '7' => '0111011', '8' => '0110111', '9' => '0001011'
        ];
        
        $bar_width = $width / 95; // Ancho de cada barra
        $x = 5;
        
        $svg = '<svg width="' . $width . '" height="' . $height . '" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<rect width="100%" height="100%" fill="white"/>';
        
        // Barras de inicio
        $svg .= '<rect x="' . $x . '" y="10" width="' . $bar_width . '" height="60" fill="black"/>';
        $x += $bar_width * 2;
        $svg .= '<rect x="' . $x . '" y="10" width="' . $bar_width . '" height="60" fill="black"/>';
        $x += $bar_width * 2;
        
        // Dibujar barras para cada dígito
        for ($i = 0; $i < strlen($codigo); $i++) {
            $pattern = $patterns[$codigo[$i]];
            
            for ($j = 0; $j < strlen($pattern); $j++) {
                if ($pattern[$j] == '1') {
                    $svg .= '<rect x="' . $x . '" y="10" width="' . $bar_width . '" height="60" fill="black"/>';
                }
                $x += $bar_width;
            }
            
            // Separador central
            if ($i == 5) {
                $x += $bar_width;
                $svg .= '<rect x="' . $x . '" y="10" width="' . $bar_width . '" height="60" fill="black"/>';
                $x += $bar_width * 2;
                $svg .= '<rect x="' . $x . '" y="10" width="' . $bar_width . '" height="60" fill="black"/>';
                $x += $bar_width * 2;
            }
        }
        
        // Barras de fin
        $svg .= '<rect x="' . $x . '" y="10" width="' . $bar_width . '" height="60" fill="black"/>';
        $x += $bar_width * 2;
        $svg .= '<rect x="' . $x . '" y="10" width="' . $bar_width . '" height="60" fill="black"/>';
        
        // Texto del código
        $svg .= '<text x="' . ($width / 2) . '" y="' . ($height - 15) . '" text-anchor="middle" font-family="monospace" font-size="14" fill="black">' . $codigo . '</text>';
        
        $svg .= '</svg>';
        
        return $svg;
    }
    
    /**
     * Busca un producto o celular por código de barras
     */
    public function buscarPorCodigoBarras($codigo_barras) {
        $codigo_barras = trim($codigo_barras);
        
        if (empty($codigo_barras)) {
            return ['success' => false, 'message' => 'Código de barras vacío'];
        }
        
        // Determinar tipo por prefijo
        $prefix = substr($codigo_barras, 0, 3);
        
        if ($prefix == '200') {
            // Buscar celular
            $stmt = $this->db->prepare("
                SELECT c.*, t.nombre as tienda_nombre 
                FROM celulares c 
                LEFT JOIN tiendas t ON c.tienda_id = t.id 
                WHERE c.codigo_barras = ?
            ");
            $stmt->execute([$codigo_barras]);
            $result = $stmt->fetch();
            
            if ($result) {
                return [
                    'success' => true,
                    'tipo' => 'celular',
                    'data' => $result
                ];
            }
        } elseif ($prefix == '300') {
            // Buscar producto
            $stmt = $this->db->prepare("
                SELECT p.*, c.nombre as categoria_nombre,
                       (SELECT SUM(cantidad_actual) FROM stock_productos WHERE producto_id = p.id) as stock_total
                FROM productos p 
                LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
                WHERE p.codigo_barras = ?
            ");
            $stmt->execute([$codigo_barras]);
            $result = $stmt->fetch();
            
            if ($result) {
                return [
                    'success' => true,
                    'tipo' => 'producto',
                    'data' => $result
                ];
            }
        }
        
        return ['success' => false, 'message' => 'Código de barras no encontrado'];
    }
    
    /**
     * Valida formato de código de barras
     */
    public function validarCodigoBarras($codigo_barras) {
        // Debe tener 13 dígitos
        if (!preg_match('/^\d{13}$/', $codigo_barras)) {
            return false;
        }
        
        // Verificar dígito verificador
        $codigo_base = substr($codigo_barras, 0, 12);
        $digito_verificador = intval(substr($codigo_barras, 12, 1));
        
        return $this->calcularDigitoVerificador($codigo_base) == $digito_verificador;
    }
    
    /**
     * Genera e imprime etiqueta de código de barras
     */
    public function generarEtiqueta($codigo_barras, $titulo, $precio = null) {
        $barcode_svg = $this->generateBarcodeSVG($codigo_barras, 250, 80);
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { size: 2.5in 1.5in; margin: 0; }
        body { margin: 0; padding: 10px; font-family: Arial, sans-serif; }
        .etiqueta { border: 1px solid #000; padding: 5px; text-align: center; }
        .titulo { font-size: 10px; font-weight: bold; margin-bottom: 5px; height: 20px; overflow: hidden; }
        .barcode { margin: 5px 0; }
        .precio { font-size: 14px; font-weight: bold; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="etiqueta">
        <div class="titulo">' . htmlspecialchars($titulo) . '</div>
        <div class="barcode">' . $barcode_svg . '</div>';
        
        if ($precio !== null) {
            $html .= '<div class="precio">$' . number_format($precio, 2) . '</div>';
        }
        
        $html .= '
    </div>
    <script>window.print();</script>
</body>
</html>';
        
        return $html;
    }
}

/**
 * Función helper para generar código de barras rápido
 */
function generarCodigoBarras($tipo, $id_referencia) {
    $generator = new BarcodeGenerator();
    
    if ($tipo === 'celular') {
        return $generator->generateCelularBarcode($id_referencia);
    } elseif ($tipo === 'producto') {
        return $generator->generateProductoBarcode($id_referencia);
    }
    
    return null;
}

/**
 * Función helper para buscar por código de barras
 */
function buscarPorCodigoBarras($codigo_barras) {
    $generator = new BarcodeGenerator();
    return $generator->buscarPorCodigoBarras($codigo_barras);
}
?>