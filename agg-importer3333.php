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

// 5. Importación robusta desde CSV (detecta delimitador y encabezado de título)
function agg_importer_process_csv($filepath) {
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        agg_importer_redirect_error('No se pudo abrir el archivo.');
        return;
    }
    $row = 0;
    $imported = 0; $updated = 0; $errors = 0;
    $log = [];
    $headers = [];
    $encoding_checked = false;

    // Detectar delimitador automáticamente
    $first_line = fgets($handle);
    $delimiter = ',';
    if (substr_count($first_line, ';') > substr_count($first_line, ',')) {
        $delimiter = ';';
    }
    rewind($handle);

    // Leer encabezados
    if (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        $headers = array_map(function($h){
            return strtolower(trim($h));
        }, $data);
        $titulo_index = false;
        $titulo_names = ['titulo','título','title','nombre','producto','nombre_producto'];
        foreach ($headers as $i => $h) {
            if (in_array($h, $titulo_names)) {
                $titulo_index = $i;
                break;
            }
        }
        if ($titulo_index === false) {
            fclose($handle);
            agg_importer_redirect_error('No se encontró la columna de título en el CSV. Encabezados detectados: ' . implode(', ', $headers));
            return;
        }
    } else {
        fclose($handle);
        agg_importer_redirect_error('No se pudo leer el encabezado del CSV.');
        return;
    }

    // Procesar filas
    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
        $row++;
        $post_data = [];
        foreach ($headers as $i => $header) $post_data[$header] = isset($data[$i]) ? trim($data[$i]) : '';
        // Validación: título
        $post_title = !empty($data[$titulo_index]) ? $data[$titulo_index] : 'Sin título';
        // Mapear correctamente
        $post_id = !empty($post_data['id']) ? intval($post_data['id']) : 0;
        $post_content = isset($post_data['contenido']) ? $post_data['contenido'] : '';
        $post_arr = [
            'post_type'    => 'agg_item',
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field($post_title),
            'post_content' => sanitize_textarea_field($post_content)
        ];
        if ($post_id && get_post($post_id)) {
            $post_arr['ID'] = $post_id;
            $result = wp_update_post($post_arr, true);
            if (is_wp_error($result)) { $errors++; $log[] = "Error actualizando ID {$post_id}"; }
            else { $updated++; $log[] = "Actualizado ID {$post_id}"; }
        } else {
            $result = wp_insert_post($post_arr, true);
            if (is_wp_error($result)) { $errors++; $log[] = "Error insertando fila $row"; }
            else { $imported++; $post_id = $result; $log[] = "Importado nuevo ID {$post_id}"; }
        }
        // Guardar meta campos
        $meta_fields = array_diff(array_keys($post_data), ['id','titulo','título','title','nombre','producto','nombre_producto','contenido']);
        foreach ($meta_fields as $key) {
            update_post_meta($post_id, sanitize_key($key), sanitize_text_field($post_data[$key]));
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