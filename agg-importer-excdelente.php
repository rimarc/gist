<?php
/*
Plugin Name: AGG Importer Mejorado
Description: Importa, exporta y gestiona datos CSV en custom post types con mapeo, logs, limpieza, AJAX y seguridad avanzada.
Version: 2.0
Author: rimarc
*/

if (!defined('ABSPATH')) exit;

// ================== ADMIN MENU ==================
add_action('admin_menu', 'agg_importer_menu');
function agg_importer_menu() {
    add_menu_page(
        'AGG Importer',
        'AGG Importer',
        'manage_options',
        'agg-importer',
        'agg_importer_admin_page',
        'dashicons-upload',
        80
    );
}

// ================== PAGE ADMIN ==================
function agg_importer_admin_page() {
    ?>
    <div class="wrap">
        <h1>Importador avanzado de CSV para entradas personalizadas</h1>
        <?php agg_importer_show_notices(); ?>
        <form method="post" enctype="multipart/form-data" id="agg-importer-form">
            <?php wp_nonce_field('agg_importer_upload_csv', 'agg_importer_nonce'); ?>
            <input type="file" name="agg_importer_csv" accept=".csv,text/csv" required>
            <input type="submit" name="agg_importer_submit" value="Importar CSV">
        </form>
        <?php
        // AJAX previsualización
        echo '<div id="agg-importer-preview"></div>';
        if (isset($_POST['agg_importer_submit']) &&
            isset($_FILES['agg_importer_csv']) &&
            isset($_POST['agg_importer_nonce']) &&
            wp_verify_nonce($_POST['agg_importer_nonce'], 'agg_importer_upload_csv')) {
            agg_importer_handle_upload();
        }
        ?>
    </div>
    <?php agg_importer_help_contextual(); ?>
    <?php
}

// ================== NOTICES SYSTEM ==================
function agg_importer_show_notices() {
    if (!empty($_GET['agg_notice'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($_GET['agg_notice']) . '</p></div>';
    }
    if (!empty($_GET['agg_error'])) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($_GET['agg_error']) . '</p></div>';
    }
}

// ================== HANDLE UPLOAD ==================
function agg_importer_handle_upload() {
    if (!current_user_can('manage_options')) {
        agg_importer_redirect_error('No tienes permisos para esto.');
        return;
    }
    if (!isset($_FILES['agg_importer_csv']) || $_FILES['agg_importer_csv']['error'] !== UPLOAD_ERR_OK) {
        agg_importer_redirect_error('Error al subir el archivo.');
        return;
    }
    $file = $_FILES['agg_importer_csv'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (strtolower($ext) !== 'csv' || !in_array($mime, ['text/plain', 'text/csv', 'application/vnd.ms-excel'])) {
        agg_importer_redirect_error('Solo se permiten archivos CSV válidos.');
        return;
    }
    $upload_dir = wp_upload_dir();
    $agg_dir = $upload_dir['basedir'] . '/agg-importer';
    $dest_path = $agg_dir . '/' . basename($file['name']);
    if (!is_dir($agg_dir)) mkdir($agg_dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        agg_importer_redirect_error('No se pudo mover el archivo.');
        return;
    }
    // Previsualización antes de procesar
    agg_importer_preview_csv($dest_path);
    // Procesar CSV
    agg_importer_process_csv($dest_path);
}

function agg_importer_redirect_error($msg) {
    wp_redirect(admin_url('admin.php?page=agg-importer&agg_error=' . urlencode($msg)));
    exit;
}
function agg_importer_redirect_notice($msg) {
    wp_redirect(admin_url('admin.php?page=agg-importer&agg_notice=' . urlencode($msg)));
    exit;
}

// ================== PREVIEW CSV ==================
function agg_importer_preview_csv($filepath) {
    $handle = fopen($filepath, 'r');
    if (!$handle) return;
    echo '<h3>Previsualización de las primeras 5 filas del CSV:</h3><table class="widefat">';
    $row = 0;
    while (($data = fgetcsv($handle, 1000, ',')) !== FALSE && $row < 6) {
        echo '<tr>';
        foreach ($data as $cell) echo '<td>' . esc_html($cell) . '</td>';
        echo '</tr>';
        $row++;
    }
    echo '</table>';
    fclose($handle);
}

// ================== PROCESS CSV ==================
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
    while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
        $row++;
        if ($row == 1) { $headers = array_map('sanitize_key', $data); continue; }
        $post_data = [];
        foreach ($headers as $i => $header) $post_data[$header] = isset($data[$i]) ? sanitize_text_field($data[$i]) : '';
        $post_id = isset($post_data['ID']) ? intval($post_data['ID']) : 0;
        $post_arr = [
            'post_title'   => isset($post_data['titulo']) ? sanitize_text_field($post_data['titulo']) : 'Sin título',
            'post_content' => isset($post_data['contenido']) ? sanitize_textarea_field($post_data['contenido']) : '',
            'post_type'    => 'agg_item',
            'post_status'  => 'publish'
        ];
        if ($post_id) {
            $post_arr['ID'] = $post_id;
            $result = wp_update_post($post_arr, true);
            if (is_wp_error($result)) { $errors++; $log[] = "Error actualizando ID {$post_id}"; }
            else { $updated++; $log[] = "Actualizado ID {$post_id}"; }
        } else {
            $result = wp_insert_post($post_arr, true);
            if (is_wp_error($result)) { $errors++; $log[] = "Error insertando fila $row"; }
            else { $imported++; $post_id = $result; $log[] = "Importado nuevo ID {$post_id}"; }
        }
        foreach ($post_data as $key => $value) {
            if (in_array($key, ['ID','titulo','contenido'])) continue;
            update_post_meta($post_id, sanitize_key($key), sanitize_text_field($value));
        }
    }
    fclose($handle);
    agg_importer_log($log);
    agg_importer_redirect_notice("Importados: $imported | Actualizados: $updated | Errores: $errors");
}

// ================== LOGS ==================
function agg_importer_log($log) {
    $upload_dir = wp_upload_dir();
    $file = $upload_dir['basedir'] . '/agg-importer/import-log-' . date('Ymd-His') . '.txt';
    file_put_contents($file, implode("\n", $log));
}

// ================== REGISTER CUSTOM POST TYPE ==================
add_action('init', 'agg_importer_register_post_type');
function agg_importer_register_post_type() {
    register_post_type('agg_item', [
        'labels' => [
            'name' => 'AGG Items',
            'singular_name' => 'AGG Item'
        ],
        'public' => true,
        'has_archive' => true,
        'show_in_menu' => true,
        'supports' => ['title', 'editor', 'custom-fields'],
        'menu_icon' => 'dashicons-database',
        'show_in_rest' => true,
    ]);
}

// ================== SHORTCODE ==================
add_shortcode('agg_importer_list', 'agg_importer_list_shortcode');
function agg_importer_list_shortcode($atts) {
    $atts = shortcode_atts(['count' => 10], $atts, 'agg_importer_list');
    $args = ['post_type' => 'agg_item', 'posts_per_page' => intval($atts['count'])];
    $query = new WP_Query($args);
    ob_start();
    if ($query->have_posts()) {
        echo '<ul class="agg-importer-list">';
        while ($query->have_posts()) {
            $query->the_post();
            echo '<li><strong>' . esc_html(get_the_title()) . '</strong>: ' . esc_html(get_the_content()) . '</li>';
        }
        echo '</ul>';
        wp_reset_postdata();
    } else echo '<p>No hay elementos importados.</p>';
    return ob_get_clean();
}

// ================== EXPORT CSV ==================
add_action('admin_post_agg_importer_export', 'agg_importer_export_csv');
function agg_importer_export_csv() {
    if (!current_user_can('manage_options')) wp_die('No tienes permisos para exportar.');
    if (!isset($_GET['agg_importer_nonce']) || !wp_verify_nonce($_GET['agg_importer_nonce'], 'agg_importer_export_csv')) wp_die('Acceso no autorizado.');
    $args = ['post_type' => 'agg_item', 'posts_per_page' => -1];
    $query = new WP_Query($args);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="agg_items_export.csv"');
    header('Pragma: no-cache'); header('Expires: 0');
    $output = fopen('php://output', 'w');
    // Recopilar todas las meta keys únicas
    $meta_keys = [];
    foreach ($query->posts as $post) {
        $keys = get_post_custom_keys($post->ID);
        if ($keys) foreach ($keys as $key)
            if (!in_array($key, $meta_keys) && !in_array($key, ['_edit_lock','_edit_last'])) $meta_keys[] = $key;
    }
    $headers = array_merge(['ID', 'titulo', 'contenido'], $meta_keys);
    fputcsv($output, $headers);
    foreach ($query->posts as $post) {
        $data = [$post->ID, sanitize_text_field($post->post_title), sanitize_textarea_field($post->post_content)];
        foreach ($meta_keys as $key) $data[] = sanitize_text_field(get_post_meta($post->ID, $key, true));
        fputcsv($output, $data);
    }
    fclose($output); exit;
}

// ================== EXPORT BUTTON ==================
add_action('admin_footer', 'agg_importer_export_button');
function agg_importer_export_button() {
    global $pagenow;
    if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'agg-importer') {
        $nonce = wp_create_nonce('agg_importer_export_csv');
        echo '<a class="button" href="' . admin_url('admin-post.php?action=agg_importer_export&agg_importer_nonce=' . $nonce) . '">Exportar CSV</a>';
    }
}

// ================== CLEANUP FILES ==================
add_action('admin_post_agg_importer_cleanup', 'agg_importer_cleanup_files');
function agg_importer_cleanup_files() {
    if (!current_user_can('manage_options')) wp_die('No tienes permisos para limpiar archivos.');
    if (!isset($_GET['agg_importer_nonce']) || !wp_verify_nonce($_GET['agg_importer_nonce'], 'agg_importer_cleanup_csv')) wp_die('Acceso no autorizado.');
    $upload_dir = wp_upload_dir();
    $agg_dir = $upload_dir['basedir'] . '/agg-importer';
    $deleted = 0;
    if (is_dir($agg_dir)) {
        $files = glob($agg_dir . '/*.csv');
        foreach ($files as $file) if (@unlink($file)) $deleted++;
        $msg = $deleted > 0 ? 'Archivos CSV eliminados correctamente.' : 'No se encontraron archivos para eliminar.';
    } else $msg = 'No hay archivos para limpiar.';
    agg_importer_redirect_notice($msg);
}

// ================== CLEANUP BUTTON ==================
add_action('admin_footer', 'agg_importer_cleanup_button');
function agg_importer_cleanup_button() {
    global $pagenow;
    if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'agg-importer') {
        $nonce = wp_create_nonce('agg_importer_cleanup_csv');
        echo '<a class="button" href="' . admin_url('admin-post.php?action=agg_importer_cleanup&agg_importer_nonce=' . $nonce) . '">Limpiar archivos CSV</a>';
    }
}

// ================== ADMIN NOTICES POST CLEANUP ==================
add_action('admin_notices', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'agg-importer' && isset($_GET['agg_importer_cleanup_notice'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($_GET['agg_importer_cleanup_notice']) . '</p></div>';
    }
});

// ================== CUSTOM COLUMNS ==================
add_filter('manage_agg_item_posts_columns', 'agg_importer_custom_columns');
function agg_importer_custom_columns($columns) {
    $columns['agg_importer_meta'] = 'Meta Campos';
    return $columns;
}
add_action('manage_agg_item_posts_custom_column', 'agg_importer_custom_column_content', 10, 2);
function agg_importer_custom_column_content($column, $post_id) {
    if ($column === 'agg_importer_meta') {
        $meta = get_post_meta($post_id);
        $shown = 0;
        foreach ($meta as $key => $value) {
            if (in_array($key, ['_edit_lock','_edit_last'])) continue;
            echo '<strong>' . esc_html($key) . '</strong>: ' . esc_html(is_array($value) ? implode(',', $value) : $value) . '<br>';
            $shown++; if ($shown > 10) { echo '<em>Mostrando sólo los primeros 10 meta campos...</em>'; break; }
        }
    }
}

// ================== ADMIN FILTERS (SEARCH META) ==================
add_action('restrict_manage_posts', 'agg_importer_admin_filter');
function agg_importer_admin_filter() {
    global $typenow;
    if ($typenow == 'agg_item') {
        ?>
        <input type="text" name="agg_importer_meta_key" placeholder="Meta campo clave" value="<?php echo isset($_GET['agg_importer_meta_key']) ? esc_attr($_GET['agg_importer_meta_key']) : ''; ?>">
        <input type="text" name="agg_importer_meta_value" placeholder="Meta campo valor" value="<?php echo isset($_GET['agg_importer_meta_value']) ? esc_attr($_GET['agg_importer_meta_value']) : ''; ?>">
        <?php
    }
}
add_filter('parse_query', 'agg_importer_admin_filter_query');
function agg_importer_admin_filter_query($query) {
    global $pagenow;
    $meta_key = isset($_GET['agg_importer_meta_key']) ? sanitize_text_field($_GET['agg_importer_meta_key']) : '';
    $meta_value = isset($_GET['agg_importer_meta_value']) ? sanitize_text_field($_GET['agg_importer_meta_value']) : '';
    if ($pagenow == 'edit.php' && $query->get('post_type') == 'agg_item' && ($meta_key || $meta_value)) {
        if ($meta_key && $meta_value) {
            $meta_query = [['key' => $meta_key, 'value' => $meta_value, 'compare' => 'LIKE']];
        } elseif ($meta_key) {
            $meta_query = [['key' => $meta_key, 'compare' => 'EXISTS']];
        } elseif ($meta_value) {
            $meta_query = [['value' => $meta_value, 'compare' => 'LIKE']];
        }
        $query->set('meta_query', $meta_query);
    }
}

// ================== ADMIN NOTICE: CPT MISSING ==================
function agg_importer_check_post_type() {
    global $pagenow;
    if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'agg-importer' && !post_type_exists('agg_item')) {
        echo '<div class="notice notice-error"><p>El tipo de entrada personalizada agg_item no está registrado. Revisa la configuración.</p></div>';
    }
}
add_action('admin_notices', 'agg_importer_check_post_type');

// ================== AYUDA CONTEXTUAL ==================
function agg_importer_help_contextual() {
    ?>
    <style>
        .agg-importer-help { margin-top:20px; padding:10px; background:#f1f1f1; border-left:4px solid #0073aa; }
    </style>
    <div class="agg-importer-help">
        <h2>¿Cómo usar el importador avanzado?</h2>
        <ol>
            <li>Prepara tu CSV con los encabezados ("ID", "titulo", "contenido", campos meta personalizados...)</li>
            <li>Sube tu archivo y revisa la previsualización antes de importar.</li>
            <li>Verifica los items importados en el listado.</li>
            <li>Exporta datos a CSV o limpia archivos subidos según lo necesites.</li>
            <li>Consulta el archivo de logs en <code>wp-content/uploads/agg-importer/</code> para auditoría.</li>
        </ol>
        <p>Los campos meta se guardan automáticamente si están presentes en el CSV. Puedes buscar y filtrar por meta campos en el listado admin.</p>
    </div>
    <?php
}

// ================== AJAX PREVIEW (Opcional: si usas JS para previsualización) ==================
// Puedes implementar una función AJAX para previsualizar el CSV antes de importar si lo deseas.