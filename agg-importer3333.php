<?php
/*
Plugin Name: AGG Importer
Description: Importa productos desde CSV y muestra catálogo con shortcode.
Version: 8.2
Author: rimarc + Copilot
*/

if (!defined('ABSPATH')) exit;

// 1. Registrar el Custom Post Type
function agg_register_cpt() {
    register_post_type('agg_item', [
        'label' => 'AGG Items',
        'public' => true,
        'show_in_menu' => true,
        'supports' => ['title', 'editor'],
        'menu_icon' => 'dashicons-database',
    ]);
}
add_action('init', 'agg_register_cpt');

// 2. Menú admin
function agg_add_admin_menu() {
    add_menu_page('AGG Importer', 'AGG Importer', 'manage_options', 'agg-importer', 'agg_importer_admin_page', 'dashicons-upload', 80);
}
add_action('admin_menu', 'agg_add_admin_menu');

// 3. Página admin: formulario subida
function agg_importer_admin_page() {
    ?>
    <div class="wrap">
        <h1>AGG Importer</h1>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('agg_import_csv','agg_import_csv_nonce'); ?>
            <input type="file" name="agg_csv" accept=".csv" required>
            <input type="submit" name="agg_import_submit" class="button button-primary" value="Importar CSV">
        </form>
        <?php
        if (!empty($_GET['agg_notice'])) {
            echo '<div class="notice notice-success"><p>' . esc_html($_GET['agg_notice']) . '</p></div>';
        }
        if (!empty($_GET['agg_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html($_GET['agg_error']) . '</p></div>';
        }
        ?>
        <div style="margin-top:2em; font-size:0.9em;">
            <b>Formato esperado del CSV:</b><br>
            <code>ID,titulo,contenido,plataforma,combo,precio,stock,activo,imagen_url,descripcion_larga</code>
        </div>
    </div>
    <?php
}

// 4. Proceso de importación SOLO dentro del hook correcto
add_action('admin_init', function() {
    if (isset($_POST['agg_import_submit']) && current_user_can('manage_options')) {
        if (!isset($_POST['agg_import_csv_nonce']) || !wp_verify_nonce($_POST['agg_import_csv_nonce'], 'agg_import_csv')) {
            agg_importer_redirect_error('Solicitud inválida (nonce).');
            return;
        }
        if (!empty($_FILES['agg_csv']['tmp_name'])) {
            $filepath = $_FILES['agg_csv']['tmp_name'];
            agg_importer_process_csv($filepath);
        } else {
            agg_importer_redirect_error('No se seleccionó archivo.');
        }
    }
});

// Helper: tomar la primera URL de imagen válida desde un campo (soporta '|' múltiple)
function agg_pick_first_image_url($value) {
    if (!is_string($value) || $value === '') return '';
    $candidates = array_filter(array_map('trim', preg_split('/\||,\s*(?=https?:)/', $value)));
    foreach ($candidates as $url) {
        $url = esc_url_raw($url);
        if (wp_http_validate_url($url)) {
            return $url;
        }
    }
    return '';
}

function agg_importer_process_csv($filepath) {
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        agg_importer_redirect_error('No se pudo abrir el archivo.');
        return;
    }

    // Detectar delimitador automáticamente
    $first_line = fgets($handle);
    $delimiter = ',';
    if (substr_count($first_line, ';') > substr_count($first_line, ',')) {
        $delimiter = ';';
    }
    rewind($handle);

    $row = 0;
    $imported = 0; $updated = 0; $errors = 0;
    $log = [];

    // Leer encabezados normalizados
    $headers = [];
    if (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $headers = array_map(function($h){ return strtolower(trim($h)); }, $data);
    } else {
        fclose($handle);
        agg_importer_redirect_error('No se pudo leer el encabezado del CSV.');
        return;
    }

    // Mapeo flexible de alias -> clave canónica
    $aliasMap = [
        'titulo'   => ['titulo','título','title','nombre','producto','nombre_producto'],
        'contenido'=> ['contenido','descripcion','descripción','longdescription','long_description','descripcion_larga','descripción_larga','shortdescription','short_description'],
        'precio'   => ['precio','price'],
        'stock'    => ['stock','qty','quantity'],
        'imagen_url'=> ['imagen_url','image','imagen','image_url','photo','photos','fotos','pictures']
    ];
    $index = [];
    foreach ($aliasMap as $canon => $aliases) {
        foreach ($aliases as $a) {
            $pos = array_search($a, $headers, true);
            if ($pos !== false) { $index[$canon] = $pos; break; }
        }
    }
    if (!isset($index['titulo'])) {
        fclose($handle);
        agg_importer_redirect_error('No se encontró columna de título. Encabezados: ' . implode(', ', $headers));
        return;
    }

    // Preparar utilidades para sideload
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    add_filter('http_request_timeout', function($t){ return max(20, (int)$t); }, 9999);
    add_filter('http_headers_useragent', function($ua){ return trim($ua.' AGG-Importer'); }, 9999);

    // Procesar filas
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row++;
        // Construir array asociativo original
        $post_data = [];
        foreach ($headers as $i => $header) {
            $post_data[$header] = isset($data[$i]) ? trim($data[$i]) : '';
        }

        // Campos principales
        $post_title = $post_data[$headers[$index['titulo']]] ?? '';
        if ($post_title === '') { $post_title = 'Sin título'; }
        $post_id    = !empty($post_data['id']) ? intval($post_data['id']) : 0;

        // Contenido preferente: contenido, o descripciones si existen
        $content_val = '';
        if (isset($index['contenido'])) {
            $content_val = $post_data[$headers[$index['contenido']]] ?? '';
        } else {
            $cand = [];
            foreach (['descripcion','descripción','longdescription','long_description','shortdescription','short_description'] as $k) {
                if (isset($post_data[$k]) && $post_data[$k] !== '') { $cand[] = $post_data[$k]; }
            }
            $content_val = implode("\n\n", $cand);
        }

        $post_arr = [
            'post_type'    => 'agg_item',
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field($post_title),
            'post_content' => sanitize_textarea_field($content_val)
        ];

        if ($post_id && get_post($post_id)) {
            $post_arr['ID'] = $post_id;
            $result = wp_update_post($post_arr, true);
            if (is_wp_error($result)) { $errors++; $log[] = "Error actualizando ID {$post_id}"; continue; }
            else { $post_id = $result; $updated++; $log[] = "Actualizado ID {$post_id}"; }
        } else {
            $result = wp_insert_post($post_arr, true);
            if (is_wp_error($result)) { $errors++; $log[] = "Error insertando fila {$row}"; continue; }
            else { $post_id = $result; $imported++; $log[] = "Importado nuevo ID {$post_id}"; }
        }

        // Guardar metas genéricas (todas las columnas, menos las ya usadas)
        $skip_keys = array_merge($aliasMap['titulo'], ['id']);
        foreach ($post_data as $key => $val) {
            if (in_array($key, $skip_keys, true)) continue;
            update_post_meta($post_id, sanitize_key($key), sanitize_text_field($val));
        }
        // Metas canónicas
        if (isset($index['precio'])) {
            update_post_meta($post_id, 'precio', sanitize_text_field($post_data[$headers[$index['precio']]] ?? ''));
        }
        if (isset($index['stock'])) {
            update_post_meta($post_id, 'stock', sanitize_text_field($post_data[$headers[$index['stock']]] ?? ''));
        }

        // Imágenes: aceptar imagen_url o photos y hacer sideload del primer URL
        $raw_img = '';
        if (isset($index['imagen_url'])) {
            $raw_img = $post_data[$headers[$index['imagen_url']]] ?? '';
        }
        if ($raw_img === '' && isset($post_data['photos'])) {
            $raw_img = $post_data['photos'];
        }
        $first_url = agg_pick_first_image_url($raw_img);
        if ($first_url !== '') {
            update_post_meta($post_id, 'imagen_url', esc_url_raw($first_url));
            $att_id = media_sideload_image($first_url, $post_id, null, 'id');
            if (is_wp_error($att_id)) {
                error_log('[AGG Importer] Error imagen: '.$first_url.' -> '.$att_id->get_error_message());
            } else {
                // Generar metadatos y setear thumbnail
                $file_path = get_attached_file($att_id);
                if ($file_path && file_exists($file_path)) {
                    $metadata = wp_generate_attachment_metadata($att_id, $file_path);
                    if (!is_wp_error($metadata) && !empty($metadata)) {
                        wp_update_attachment_metadata($att_id, $metadata);
                    }
                }
                set_post_thumbnail($post_id, (int)$att_id);
            }
        }
    }

    fclose($handle);
    agg_importer_log($log);
    agg_importer_redirect_notice("Importados: $imported | Actualizados: $updated | Errores: $errors");
}

// 6. Notificaciones admin
function agg_importer_redirect_notice($msg) {
    wp_redirect(admin_url('admin.php?page=agg-importer&agg_notice=' . urlencode($msg)));
    exit;
}
function agg_importer_redirect_error($msg) {
    wp_redirect(admin_url('admin.php?page=agg-importer&agg_error=' . urlencode($msg)));
    exit;
}

// 7. Guardar log (simple, en uploads)
function agg_importer_log($lines) {
    $upload_dir = wp_upload_dir();
    $filename = 'import-log-' . date('Ymd-His') . '.txt';
    $filepath = trailingslashit($upload_dir['basedir']) . $filename;
    file_put_contents($filepath, implode("\n", $lines));
}

// 8. Shortcode para mostrar productos (catálogo visual moderno)
add_shortcode('agg_importer_list', function($atts){
    $atts = shortcode_atts(['count' => 10], $atts, 'agg_importer_list');
    $args = [
        'post_type' => 'agg_item',
        'posts_per_page' => intval($atts['count'])
    ];
    $query = new WP_Query($args);
    ob_start();
    if ($query->have_posts()) {
        ?>
        <style>
        .agg-catalogo-grid { display: flex; flex-wrap: wrap; gap:20px; margin:1em 0;}
        .agg-catalogo-card {
            background: #fafafa; border:1px solid #ddd; border-radius:8px;
            padding:16px; width:320px; box-shadow:0 2px 6px rgba(0,0,0,0.07);
        }
        .agg-catalogo-card img { max-width:100%; max-height:150px; border-radius:6px; margin-bottom:8px; }
        .agg-catalogo-card h3 { margin:0 0 8px 0; font-size:1.15em; }
        .agg-catalogo-card .agg-meta { font-size:0.98em; color:#444; margin-bottom:6px;}
        .agg-catalogo-card .agg-precio { font-weight:bold; color:#2b8c2b; }
        </style>
        <div class="agg-catalogo-grid">
        <?php
        while ($query->have_posts()) {
            $query->the_post();
            $meta = get_post_meta(get_the_ID());
            $img = !empty($meta['imagen_url'][0]) ? esc_url($meta['imagen_url'][0]) : '';
            ?>
            <div class="agg-catalogo-card">
                <?php if ($img) { ?><img src="<?php echo $img; ?>"><?php } ?>
                <h3><?php echo esc_html(get_the_title()); ?></h3>
                <div class="agg-meta"><?php echo esc_html(get_the_content()); ?></div>
                <?php
                $meta_keys = ['plataforma','combo','stock','activo','descripcion_larga'];
                foreach ($meta_keys as $key) {
                    if (!empty($meta[$key][0])) {
                        echo '<div class="agg-meta"><b>' . esc_html(ucfirst($key)) . ':</b> ' . esc_html($meta[$key][0]) . '</div>';
                    }
                }
                if (!empty($meta['precio'][0])) {
                    echo '<div class="agg-precio">Precio: $' . esc_html($meta['precio'][0]) . '</div>';
                }
                ?>
            </div>
            <?php
        }
        echo '</div>';
        wp_reset_postdata();
    } else {
        echo '<p>No hay elementos importados.</p>';
    }
    return ob_get_clean();
});

?>