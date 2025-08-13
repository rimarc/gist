<?php
/**
 * Plugin Name: AGG Importer (mejorado)
 * Description: Importador flexible para CSV/XLSX compatible con formatos de agg-importer. Mejora: detección delimitador, forzar UTF-8, asegurar columnas, SKUs únicos, resumen final.
 * Version: 1.1.0
 * Author: Tu equipo
 * License: GPLv2 or later
 */

// Seguridad básica
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// --- Mejoras de compatibilidad agregadas ---
// Forzar UTF-8
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

/**
 * Detectar delimitador CSV analizando la primera línea
 * @param string $file_path
 * @return string Delimitador detectado (',' o ';' o "\t")
 */
function agg_detect_delimiter($file_path) {
    $delimiters = ["," => 0, ";" => 0, "\t" => 0];
    $handle = fopen($file_path, 'r');
    if ($handle) {
        $first_line = fgets($handle);
        fclose($handle);
        if ($first_line !== false) {
            foreach ($delimiters as $del => $count) {
                $delimiters[$del] = substr_count($first_line, $del);
            }
            // Elegir el delimitador con mayor cantidad de ocurrencias
            arsort($delimiters);
            reset($delimiters);
            return key($delimiters);
        }
    }
    // Valor por defecto
    return ',';
}

/**
 * Asegura que el array $row tenga todas las columnas requeridas (añade si faltan)
 * @param array $row
 * @return array
 */
function agg_ensure_required_columns_row($row) {
    $required = ["SKU","Title","ShortDescription","LongDescription","Price","Stock","Category_ID","Brand","Weight","Height","Width","Length","Condition","Warranty","Photos","Attributes"];
    foreach ($required as $col) {
        if (!array_key_exists($col, $row)) {
            $row[$col] = '';
        }
    }
    return $row;
}

/**
 * Hace únicos los SKUs dentro de $products (array de arrays con clave 'SKU')
 * Modifica los SKUs duplicados agregando sufijo -2, -3, etc.
 * @param array $products
 * @return array productos con SKUs ajustados
 */
function agg_make_sku_unique($products) {
    $sku_count = [];
    foreach ($products as &$row) {
        $sku = (string)$row['SKU'];
        if ($sku === '') {
            // si SKU vacío, generamos uno temporal
            $sku = 'NO-SKU-' . wp_generate_password(6, false, false);
            $row['SKU'] = $sku;
        }
        if (!isset($sku_count[$sku])) {
            $sku_count[$sku] = 1;
        } else {
            $sku_count[$sku]++;
            $row['SKU'] = $sku . '-' . $sku_count[$sku];
        }
    }
    return $products;
}

/**
 * Lee un CSV y devuelve array de filas asociativas (UTF-8)
 * @param string $file_path
 * @param string $delimiter
 * @return array
 */
function agg_parse_csv_to_array($file_path, $delimiter = ',') {
    $rows = [];
    // Intentar abrir con distintos encodings, forzamos lectura y conversión a UTF-8
    $contents = @file_get_contents($file_path);
    if ($contents === false) {
        return $rows;
    }

    // Detectar encoding simple (fallbacks)
    $enc = mb_detect_encoding($contents, ['UTF-8','ISO-8859-1','Windows-1252'], true);
    if ($enc !== 'UTF-8') {
        $contents = mb_convert_encoding($contents, 'UTF-8', $enc ? $enc : 'ISO-8859-1');
    }

    // Normalizar saltos de línea
    $contents = str_replace("\r\n", "\n", $contents);
    $contents = str_replace("\r", "\n", $contents);

    $lines = explode("\n", $contents);
    // Eliminar líneas vacías al final
    while (!empty($lines) && trim(end($lines)) === '') {
        array_pop($lines);
    }

    if (count($lines) === 0) {
        return $rows;
    }

    // Cabeceras
    $header = str_getcsv(array_shift($lines), $delimiter);
    // Limpiar encabezados (quitar BOM, espacios)
    foreach ($header as &$h) {
        $h = trim($h, " \t\n\r\0\x0B\xEF\xBB\xBF"); // quitar BOM si lo tiene
    }
    unset($h);

    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        // str_getcsv con delimitador detectado
        $data = str_getcsv($line, $delimiter);
        // Si la cantidad de datos no coincide con el header, intentamos con otro delimitador común
        if (count($data) !== count($header)) {
            // Intentar forzar separación por punto y coma si no es el actual
            $data = str_getcsv($line, ';');
        }
        // Mapear
        $assoc = [];
        for ($i = 0; $i < count($header); $i++) {
            $key = isset($header[$i]) ? $header[$i] : 'col_' . $i;
            $assoc[$key] = isset($data[$i]) ? $data[$i] : '';
        }
        // Asegurar columnas requeridas
        $assoc = agg_ensure_required_columns_row($assoc);
        $rows[] = $assoc;
    }

    return $rows;
}

/**
 * Intenta leer XLSX usando PhpSpreadsheet si está disponible.
 * Devuelve array de filas asociativas o [] si no pudo leer.
 * @param string $file_path
 * @return array
 */
function agg_parse_xlsx_to_array($file_path) {
    $rows = [];
    if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        // PhpSpreadsheet no disponible
        return $rows;
    }

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, true); // indexed by column letters
        if (count($data) < 1) return $rows;

        // Primer fila = header
        $first = array_shift($data);
        // Convertir header de letras a índices provisionales
        $header = [];
        foreach ($first as $col => $val) {
            $header[] = trim((string)$val);
        }

        foreach ($data as $row) {
            $assoc = [];
            $i = 0;
            foreach ($row as $col => $val) {
                $key = isset($header[$i]) ? $header[$i] : 'col_' . $i;
                $assoc[$key] = (string)$val;
                $i++;
            }
            // Asegurar columnas requeridas
            $assoc = agg_ensure_required_columns_row($assoc);
            $rows[] = $assoc;
        }
    } catch (Exception $e) {
        return [];
    }

    return $rows;
}

/**
 * Normaliza y limpia valores comunes en una fila de producto
 * @param array $row
 * @return array
 */
function agg_normalize_row($row) {
    // Trim y conversión básica
    foreach ($row as $k => $v) {
        if (is_string($v)) {
            $row[$k] = trim($v);
        }
    }

    // Price: reemplazar coma decimal por punto
    if (isset($row['Price'])) {
        $price = str_replace(',', '.', $row['Price']);
        $price = preg_replace('/[^0-9\.\-]/', '', $price);
        $row['Price'] = $price === '' ? 0 : floatval($price);
    } else {
        $row['Price'] = 0;
    }

    // Stock: entero
    if (isset($row['Stock'])) {
        $stock = preg_replace('/[^0-9\-]/', '', $row['Stock']);
        $row['Stock'] = ($stock === '') ? 0 : intval($stock);
    } else {
        $row['Stock'] = 0;
    }

    // Fotos: si viene con barra vertical o coma, mantener. Normalizar separador a '|'
    if (isset($row['Photos'])) {
        $photos = str_replace(',', '|', $row['Photos']);
        $photos = str_replace(';', '|', $photos);
        // eliminar repeticiones de "|"
        $photos = preg_replace('/\|+/', '|', $photos);
        $photos = trim($photos, "| \t\n\r\0\x0B");
        $row['Photos'] = $photos;
    } else {
        $row['Photos'] = '';
    }

    // Condition default
    if (empty($row['Condition'])) {
        $row['Condition'] = 'new';
    }

    // Warranty default
    if (!isset($row['Warranty'])) {
        $row['Warranty'] = '';
    }

    // Attributes: si no es JSON, intentar envolver como JSON básico
    if (isset($row['Attributes']) && $row['Attributes'] !== '') {
        $attr = trim($row['Attributes']);
        if (strpos($attr, '{') !== 0) {
            // reemplazos simples para crear JSON
            $attr_safe = json_encode(["info" => $attr]);
            $row['Attributes'] = $attr_safe;
        } else {
            // validar JSON
            json_decode($attr);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // intentar forzar como string
                $row['Attributes'] = json_encode(["info" => $attr]);
            }
        }
    } else {
        $row['Attributes'] = json_encode(new stdClass());
    }

    return $row;
}

/**
 * Inserta o actualiza un producto en WordPress/WooCommerce (básico)
 * Para integrarlo totalmente con WooCommerce, necesitarás wc-functions; aquí se realiza una operación general con post_type 'product'.
 * Devuelve 'inserted' o 'updated' o 'error'.
 * @param array $row
 * @return array ['status'=>string, 'product_id'=>int|null, 'message'=>string]
 */
function agg_import_product_row($row) {
    // Revisar existencia por SKU (meta_key _sku)
    $sku = sanitize_text_field($row['SKU']);

    // Buscar post por meta SKU
    $args = [
        'post_type' => 'product',
        'meta_query' => [
            [
                'key' => '_sku',
                'value' => $sku,
                'compare' => '='
            ]
        ],
        'posts_per_page' => 1,
        'fields' => 'ids',
    ];
    $query = new WP_Query($args);
    $existing_id = ($query->have_posts()) ? $query->posts[0] : 0;
    wp_reset_postdata();

    $post_data = [
        'post_title'   => wp_strip_all_tags($row['Title']),
        'post_content' => wp_kses_post($row['LongDescription']),
        'post_excerpt' => wp_strip_all_tags($row['ShortDescription']),
        'post_status'  => 'publish',
        'post_type'    => 'product',
    ];

    if ($existing_id) {
        // Actualizar
        $post_data['ID'] = $existing_id;
        $pid = wp_update_post($post_data, true);
        if (is_wp_error($pid)) {
            return ['status' => 'error', 'product_id' => null, 'message' => $pid->get_error_message()];
        } else {
            $product_id = $pid;
            $status = 'updated';
        }
    } else {
        // Insertar nuevo
        $product_id = wp_insert_post($post_data, true);
        if (is_wp_error($product_id)) {
            return ['status' => 'error', 'product_id' => null, 'message' => $product_id->get_error_message()];
        }
        $status = 'inserted';
    }

    // Guardar metas comunes (compatible con WooCommerce)
    if ($product_id && !is_wp_error($product_id)) {
        update_post_meta($product_id, '_sku', $sku);
        update_post_meta($product_id, '_regular_price', $row['Price']);
        update_post_meta($product_id, '_price', $row['Price']);
        update_post_meta($product_id, '_stock', intval($row['Stock']));
        update_post_meta($product_id, '_stock_status', (intval($row['Stock']) > 0) ? 'instock' : 'outofstock');
        update_post_meta($product_id, 'brand', sanitize_text_field($row['Brand']));
        update_post_meta($product_id, '_weight', sanitize_text_field($row['Weight']));
        update_post_meta($product_id, '_length', sanitize_text_field($row['Length']));
        update_post_meta($product_id, '_width', sanitize_text_field($row['Width']));
        update_post_meta($product_id, '_height', sanitize_text_field($row['Height']));
        update_post_meta($product_id, 'attributes_json', maybe_serialize(json_decode($row['Attributes'], true)));
        // Images: el plugin original probablemente espera URLs en columna Photos separadas por '|'
        if (!empty($row['Photos'])) {
            // Guardar raw en meta (el manejo de attachments debe hacerse aparte o por otro proceso)
            update_post_meta($product_id, 'product_photos_urls', sanitize_text_field($row['Photos']));
        }
    }

    return ['status' => $status, 'product_id' => $product_id, 'message' => 'OK'];
}

/**
 * Punto de entrada para procesar un archivo subido
 * Se espera que exista un formulario en el admin que suba a $_FILES['agg_import_file']
 */
function agg_handle_upload_and_import() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Solo actuamos si existe archivo
    if (!isset($_FILES['agg_import_file']) && !isset($_FILES['agg_file'])) {
        return;
    }
    
    // Compatibilidad con ambos nombres de campo
    $file = isset($_FILES['agg_import_file']) ? $_FILES['agg_import_file'] : $_FILES['agg_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        add_action('admin_notices', function() use ($file) {
            echo "<div class='notice notice-error'><p>Error al subir archivo: " . esc_html($file['error']) . "</p></div>";
        });
        return;
    }

    $tmp_path = $file['tmp_name'];
    $original_name = $file['name'];
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    $products = [];
    $delimiter_info = '';

    if (in_array($ext, ['csv','txt'])) {
        $detected = agg_detect_delimiter($tmp_path);
        // Si detectó tabulador, pasar "\t" a PHP
        $delimiter = $detected;
        if ($delimiter === "\t") $delimiter = "\t";
        $products = agg_parse_csv_to_array($tmp_path, $delimiter);
        
        // Guardar información sobre el delimitador detectado
        $delimiter_name = $detected === ',' ? 'coma' : ($detected === ';' ? 'punto y coma' : 'tabulador');
        $delimiter_info = " Delimitador detectado: $delimiter_name.";
        
        // Mostrar mensaje sobre el delimitador detectado
        add_action('admin_notices', function() use ($delimiter_name) {
            echo "<div class='notice notice-info'><p>Delimitador detectado: " . esc_html($delimiter_name) . "</p></div>";
        });
    } elseif (in_array($ext, ['xlsx','xls','ods'])) {
        // Intentar usar PhpSpreadsheet
        $products = agg_parse_xlsx_to_array($tmp_path);
        if (empty($products)) {
            // fallback: intentar convertir internamente (poco fiable)
            add_action('admin_notices', function() {
                echo "<div class='notice notice-warning'><p>PhpSpreadsheet no disponible o archivo XLSX inválido. Solo CSV reliably supported. Consider instalar phpoffice/phpspreadsheet.</p></div>";
            });
            return;
        }
    } else {
        add_action('admin_notices', function() {
            echo "<div class='notice notice-error'><p>Tipo de archivo no soportado. Use CSV o XLSX.</p></div>";
        });
        return;
    }

    if (empty($products)) {
        add_action('admin_notices', function() {
            echo "<div class='notice notice-error'><p>No se detectaron filas válidas en el archivo.</p></div>";
        });
        return;
    }

    // Normalizar todas las filas
    $normalized = [];
    foreach ($products as $r) {
        $normalized[] = agg_normalize_row($r);
    }

    // Hacer SKUs únicos
    $normalized = agg_make_sku_unique($normalized);

    // Importar fila por fila
    $summary = [
        'inserted' => 0,
        'updated' => 0,
        'errors' => []
    ];

    foreach ($normalized as $row) {
        $res = agg_import_product_row($row);
        if ($res['status'] === 'inserted') {
            $summary['inserted']++;
        } elseif ($res['status'] === 'updated') {
            $summary['updated']++;
        } else {
            $summary['errors'][] = $res['message'];
        }
    }

    // Mostrar resumen en admin
    add_action('admin_notices', function() use ($summary, $delimiter_info) {
        echo "<div class='notice notice-success'><p>Importación finalizada." . esc_html($delimiter_info) . " Insertados: " . intval($summary['inserted']) . ". Actualizados: " . intval($summary['updated']) . ".</p></div>";
        if (!empty($summary['errors'])) {
            echo "<div class='notice notice-error'><p>Algunos errores ocurrieron:</p><ul>";
            foreach ($summary['errors'] as $err) {
                echo "<li>" . esc_html($err) . "</li>";
            }
            echo "</ul></div>";
        }
    });

    // Opcional: registrar log en archivo dentro de wp-content/uploads/agg-importer-log.txt
    $logdir = wp_upload_dir();
    $logfile = trailingslashit($logdir['basedir']) . 'agg-importer-log.txt';
    $log = date('c') . " - Inserted: {$summary['inserted']}, Updated: {$summary['updated']}, Errors: " . count($summary['errors']) . PHP_EOL;
    @file_put_contents($logfile, $log, FILE_APPEND | LOCK_EX);
}

/**
 * Agregar un menu simple en el admin para subir archivos e invocar importación
 */
function agg_add_admin_menu() {
    add_menu_page('AGG Importer', 'AGG Importer', 'manage_options', 'agg-importer', 'agg_importer_admin_page', 'dashicons-upload', 56);
}
add_action('admin_menu', 'agg_add_admin_menu');

/**
 * Renderizamos la página simple de subida
 */
function agg_importer_admin_page() {
    ?>
    <div class="wrap">
        <h1>AGG Importer (mejorado)</h1>
        <p>Sube un archivo CSV (coma o punto y coma) o XLSX para importar productos. El plugin intentará detectar delimitador y normalizar los datos.</p>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('agg_import_nonce', 'agg_import_nonce_field'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="agg_file">Archivo CSV / XLSX</label></th>
                    <td><input type="file" name="agg_file" id="agg_file" accept=".csv,.txt,.xlsx,.xls,.ods" required /></td>
                </tr>
            </table>
            <?php submit_button('Subir e Importar'); ?>
        </form>
    </div>
    <?php

    // Procesar si hay POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['agg_import_nonce_field']) || !wp_verify_nonce($_POST['agg_import_nonce_field'], 'agg_import_nonce')) {
            echo "<div class='notice notice-error'><p>Nonce inválido. Acción cancelada.</p></div>";
            return;
        }
        // Llamamos al manejador
        agg_handle_upload_and_import();
    }
}

/**
 * Nota final:
 * - Para que la importación de imágenes cree attachments y asigne galerías, necesitarás un proceso adicional:
 *   - Descargar las URLs desde la columna Photos (separadas por '|')
 *   - media_sideload_image() y actualizar _thumbnail_id y _product_image_gallery
 * - Este archivo provee la base completa: lectura, normalización, SKUs únicos y creación/actualización básica de productos.
 * - Si usas muchas filas (miles), considera procesos por lotes (batch) y evitar timeouts.
 */

