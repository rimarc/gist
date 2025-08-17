<?php
/*
Plugin Name: AGG Importer
Description: Importa productos desde CSV y muestra catálogo con shortcode (con carrusel de imágenes/videos).
Version: 8.7
Author: rimarc
*/

if (!defined('ABSPATH')) exit;

/**
 * 0) Front-end: reproducir videos (MediaElement)
 */
add_action('wp_enqueue_scripts', function(){
    wp_enqueue_style('wp-mediaelement');
    wp_enqueue_script('wp-mediaelement');
});

/**
 * 1) Registrar el Custom Post Type con soporte de miniaturas
 */
function agg_register_cpt() {
    register_post_type('agg_item', [
        'label' => 'AGG Items',
        'public' => true,
        'show_in_menu' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'menu_icon' => 'dashicons-database',
    ]);
    add_theme_support('post-thumbnails', ['agg_item']);
}
add_action('init', 'agg_register_cpt');

/**
 * 1.1) Metabox de visibilidad en listado
 */
add_action('add_meta_boxes', function(){
    add_meta_box('agg_visibility', 'Visibilidad en listado', function($post){
        wp_nonce_field('agg_vis_nonce','agg_vis_nonce_field');
        $hide = get_post_meta($post->ID, 'agg_hide', true) === '1';
        echo '<label><input type="checkbox" name="agg_hide" value="1" '.checked($hide,true,false).'> Ocultar en [agg_importer_list]</label>';
    }, 'agg_item', 'side', 'default');
});

add_action('save_post_agg_item', function($post_id){
    if (!isset($_POST['agg_vis_nonce_field']) || !wp_verify_nonce($_POST['agg_vis_nonce_field'], 'agg_vis_nonce')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    update_post_meta($post_id, 'agg_hide', isset($_POST['agg_hide']) ? '1' : '0');
});

/**
 * 1.2) Metabox: enlaces externos
 */
add_action('add_meta_boxes', function(){
  add_meta_box('agg_links', 'Enlaces de plataformas', function($post){
    wp_nonce_field('agg_links_nonce','agg_links_nonce_field');
    $ml  = esc_url(get_post_meta($post->ID, 'link_ml', true));
    $olx = esc_url(get_post_meta($post->ID, 'link_olx', true));
    $sh  = esc_url(get_post_meta($post->ID, 'link_shopee', true));
    $custom_platform = esc_url(get_post_meta($post->ID, 'link_custom_platform', true));
    $custom_platform_name = esc_attr(get_post_meta($post->ID, 'custom_platform_name', true));
    echo '<p><label>Mercado Livre: <input type="url" name="link_ml" value="'.$ml.'" style="width:100%"></label></p>';
    echo '<p><label>OLX: <input type="url" name="link_olx" value="'.$olx.'" style="width:100%"></label></p>';
    echo '<p><label>Shopee: <input type="url" name="link_shopee" value="'.$sh.'" style="width:100%"></label></p>';
    echo '<p><label>Nombre Plataforma Personalizada: <input type="text" name="custom_platform_name" value="'.$custom_platform_name.'" placeholder="Ej: Amazon, eBay, etc." style="width:100%"></label></p>';
    echo '<p><label>Enlace Plataforma Personalizada: <input type="url" name="link_custom_platform" value="'.$custom_platform.'" placeholder="https://..." style="width:100%"></label></p>';
    echo '<hr style="margin:15px 0; border:0; border-top:1px solid #ddd;">';
    echo '<p><label>Enlace WhatsApp Directo: <input type="url" name="whatsapp_direct_link" value="'.esc_url(get_post_meta($post->ID, 'whatsapp_direct_link', true)).'" placeholder="https://wa.me/5511988263393?text=..." style="width:100%"></label></p>';
  }, 'agg_item', 'side', 'default');
});

add_action('save_post_agg_item', function($post_id){
  if (!isset($_POST['agg_links_nonce_field']) || !wp_verify_nonce($_POST['agg_links_nonce_field'], 'agg_links_nonce')) return;
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (!current_user_can('edit_post', $post_id)) return;
  foreach (['link_ml','link_olx','link_shopee'] as $k) {
    if (isset($_POST[$k])) update_post_meta($post_id, $k, esc_url_raw($_POST[$k]));
  }
  if (isset($_POST['whatsapp_direct_link'])) update_post_meta($post_id, 'whatsapp_direct_link', esc_url_raw($_POST['whatsapp_direct_link']));
  if (isset($_POST['link_custom_platform'])) update_post_meta($post_id, 'link_custom_platform', esc_url_raw($_POST['link_custom_platform']));
  if (isset($_POST['custom_platform_name'])) update_post_meta($post_id, 'custom_platform_name', sanitize_text_field($_POST['custom_platform_name']));
});

/**
 * 1.3) Actualizar imagen_url cuando se cambia la imagen destacada
 */
add_action('set_post_thumbnail', function($post_id, $thumbnail_id, $old_thumbnail_id) {
  if (get_post_type($post_id) === 'agg_item' && $thumbnail_id) {
    $thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'medium');
    if ($thumbnail_url) {
      update_post_meta($post_id, 'imagen_url', esc_url_raw($thumbnail_url));
    }
  }
}, 10, 3);

/**
 * 2) Menú admin
 */
function agg_add_admin_menu() {
    add_menu_page('AGG Importer', 'AGG Importer', 'manage_options', 'agg-importer', 'agg_importer_admin_page', 'dashicons-upload', 80);
}
add_action('admin_menu', 'agg_add_admin_menu');

/**
 * 3) Página admin: formulario subida
 */
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
            <b>Formato esperado del CSV (acepta aliases):</b><br>
            <code>ID,titulo,conteudo,plataforma,combo,preço|precio,stock,ativo,imagem|imagen|imagen_url,descricao_larga</code>
        </div>
    </div>
    <?php
}

/**
 * 4) Proceso de importación (con nonce)
 */
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

/**
 * Helper: primera URL válida (soporta múltiples con | o comas seguidas de http)
 */
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

/**
 * 5) Importación CSV + sideload de imagen
 */
function agg_importer_process_csv($filepath) {
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        agg_importer_redirect_error('No se pudo abrir el archivo.');
        return;
    }

    // Detectar delimitador automáticamente
    $first_line = fgets($handle);
    $delimiter = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';
    rewind($handle);

    $row = 0;
    $imported = 0; $updated = 0; $errors = 0;
    $log = [];

    // Encabezados normalizados
    $headers = [];
    if (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $headers = array_map(function($h){ return strtolower(trim($h)); }, $data);
    } else {
        fclose($handle);
        agg_importer_redirect_error('No se pudo leer el encabezado del CSV.');
        return;
    }

    // Alias -> índice
    $aliasMap = [
        'titulo'    => ['titulo','título','title','nombre','producto','nombre_producto'],
        'contenido' => ['contenido','descripcion','descripción','longdescription','long_description','descripcion_larga','descripción_larga','shortdescription','short_description'],
        'precio'    => ['precio','price','preço'],
        'stock'     => ['stock','qty','quantity'],
        'imagen_url'=> ['imagen_url','image','imagen','imagem','image_url','photo','photos','fotos','pictures'],
        'link_ml'      => ['link_ml','mercado_livre_link','ml_link'],
        'link_olx'     => ['link_olx','olx_link'],
        'link_shopee'  => ['link_shopee','shopee_link']
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

    // Utilidades para sideload
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    add_filter('http_request_timeout', function($t){ return max(20, (int)$t); }, 9999);
    add_filter('http_headers_useragent', function($ua){ return trim($ua.' AGG-Importer'); }, 9999);

    // Filas
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row++;
        $post_data = [];
        foreach ($headers as $i => $header) {
            $post_data[$header] = isset($data[$i]) ? trim($data[$i]) : '';
        }

        $post_title = $post_data[$headers[$index['titulo']]] ?? '';
        if ($post_title === '') $post_title = 'Sin título';
        $post_id    = !empty($post_data['id']) ? intval($post_data['id']) : 0;

        // Contenido preferente
        $content_val = '';
        if (isset($index['contenido'])) {
            $content_val = $post_data[$headers[$index['contenido']]] ?? '';
        } else {
            $cand = [];
            foreach (['descripcion','descripción','longdescription','long_description','shortdescription','short_description'] as $k) {
                if (!empty($post_data[$k])) $cand[] = $post_data[$k];
            }
            $content_val = implode("\n\n", $cand);
        }

        $post_arr = [
            'post_type'    => 'agg_item',
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field($post_title),
            'post_content' => wp_strip_all_tags(sanitize_textarea_field($content_val))
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

        // Metas genéricas (menos el título y 'id')
        $skip_keys = array_merge($aliasMap['titulo'], ['id']);
        foreach ($post_data as $key => $val) {
            if (in_array($key, $skip_keys, true)) continue;
            update_post_meta($post_id, sanitize_key($key), wp_strip_all_tags(sanitize_text_field($val)));
        }
        // Metas canónicas
        if (isset($index['precio'])) {
            update_post_meta($post_id, 'precio', sanitize_text_field($post_data[$headers[$index['precio']]] ?? ''));
        }
        if (isset($index['stock'])) {
            update_post_meta($post_id, 'stock', sanitize_text_field($post_data[$headers[$index['stock']]] ?? ''));
        }

        // Imágenes: imagen_url o photos -> primera URL -> sideload + destacada
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

/**
 * 6) Notificaciones admin
 */
function agg_importer_redirect_notice($msg) {
    wp_redirect(admin_url('admin.php?page=agg-importer&agg_notice=' . urlencode($msg)));
    exit;
}
function agg_importer_redirect_error($msg) {
    wp_redirect(admin_url('admin.php?page=agg-importer&agg_error=' . urlencode($msg)));
    exit;
}

/**
 * 7) Guardar log en uploads
 */
function agg_importer_log($lines) {
    $upload_dir = wp_upload_dir();
    $filename = 'import-log-' . date('Ymd-His') . '.txt';
    $filepath = trailingslashit($upload_dir['basedir']) . $filename;
    file_put_contents($filepath, implode("\n", $lines));
}

/**
 * 8) Shortcode: lista de items con carrusel (imagen destacada/adjuntos o fallback a imagen_url)
 * Uso: [agg_importer_list count="10"]
 */
add_shortcode('agg_importer_list', function($atts){
    $atts = shortcode_atts(['count' => 10], $atts, 'agg_importer_list');

    $args = [
        'post_type'      => 'agg_item',
        'posts_per_page' => intval($atts['count']),
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => 'agg_hide', 'compare' => 'NOT EXISTS' ],
            [ 'key' => 'agg_hide', 'value' => '1', 'compare' => '!=' ],
        ],
    ];

    $query = new WP_Query($args);
    ob_start();
    if ($query->have_posts()) {
        ?>

<style>
.agg-links { display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
.agg-btn { display:inline-block; padding:8px 10px; border-radius:6px; text-decoration:none; font-weight:600; font-size:13px; background:#333; color:#fff; }
.agg-btn--ml { background:#ffe600; color:#333; }
.agg-btn--olx { background:#6e00f5; }
.agg-btn--sh { background:#ee4d2d; }
.agg-btn--wa { background:#25d366; }
.agg-btn--custom { background:#007bff; }
.agg-btn:hover { opacity:.9; }

/* Grid y tarjeta responsivo */
.agg-catalogo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
  margin: 1em 0;
}
.agg-catalogo-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  background: #fafafa;
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 14px;
  min-width: 0;
  box-shadow: 0 2px 6px rgba(0,0,0,0.07);
}
.agg-catalogo-card > * { margin: 0 !important; }

/* Medios en tarjeta */
.agg-catalogo-card img,
.agg-catalogo-card video {
  width: 100%;
  height: 180px;
  object-fit: contain;
  display: block;
  border-radius: 6px;
}

/* Carrusel */
.agg-carousel { position: relative; margin: 0; }
.agg-carousel-track { position: relative; overflow: hidden; padding: 0; min-height: 180px; }
.agg-slide { display: none; }
.agg-slide.is-active { display: block; }
.agg-prev, .agg-next {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  display: flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; padding: 0; line-height: 0;
  border-radius: 18px;
  background: rgba(0,0,0,.5);
  color: #fff; border: 0; z-index: 3;
  box-shadow: 0 1px 3px rgba(0,0,0,.25);
  font-size: 20px;
  cursor: pointer;
}
.agg-prev { left: 6px; }
.agg-next { right: 6px; }
.agg-dots { display:flex; gap:6px; justify-content:center; margin: 0; }
.agg-dot { width:8px; height:8px; border-radius:4px; background:#bbb; border:0; cursor:pointer; }
.agg-dot.is-active { background:#333; }

/* Texto y cortes de palabra */
.agg-catalogo-card h3 { font-size: 1.15em; }
.agg-catalogo-card h3,
.agg-catalogo-card .agg-meta {
  word-break: normal;
  overflow-wrap: break-word;
  hyphens: auto;
}
.agg-catalogo-card .agg-meta {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.agg-catalogo-card .agg-precio {
  font-weight: bold;
  color: #2b8c2b;
}

/* Reset márgenes extra del theme dentro de la tarjeta */
.agg-catalogo-card p,
.agg-catalogo-card figure,
.agg-catalogo-card br,
.agg-catalogo-card .wp-video,
.agg-catalogo-card .mejs-container,
.agg-catalogo-card .mejs__container { margin: 0 !important; padding: 0 !important; }
.agg-catalogo-card br { display: none; }
.agg-catalogo-card p:empty { display: none; }

/* Responsive para móviles */
@media (max-width: 768px) {
  .agg-catalogo-grid { grid-template-columns: 1fr; gap: 15px; }
  .agg-catalogo-card { padding: 12px; }
  .agg-catalogo-card img, .agg-catalogo-card video { height: 160px; }
  .agg-links { flex-direction: column; gap: 6px; }
  .agg-btn { text-align: center; padding: 10px 12px; font-size: 14px; }
}
/* Responsive para tablets */
@media (min-width: 769px) and (max-width: 1024px) {
  .agg-catalogo-grid { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 18px; }
}
/* Responsive para pantallas grandes */
@media (min-width: 1025px) {
  .agg-catalogo-grid { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; }
  .agg-catalogo-card { padding: 16px; }
}

/* Lightbox centrado */
.agg-lightbox {
  position: fixed; inset: 0; z-index: 99999;
  background: rgba(0,0,0,.9);
  display: none; align-items: center; justify-content: center;
}
.agg-lightbox.is-open { display: flex; }
.agg-lightbox__content {
  max-width: 90vw; max-height: 90vh;
  margin: 0 auto;
}
.agg-lightbox__content img,
.agg-lightbox__content video {
  max-width: 90vw; max-height: 90vh;
  object-fit: contain; display: block; margin: 0 auto;
  border-radius: 6px;
}
.agg-lightbox__close, .agg-lightbox__nav {
  position: fixed; top: 14px;
  color: #fff; background: rgba(0,0,0,.5);
  border: 0; cursor: pointer; padding: 8px 12px;
  border-radius: 4px; z-index: 100000;
}
.agg-lightbox__close { right: 14px; }
.agg-lightbox__nav--prev { left: 14px; top: 50%; transform: translateY(-50%); }
.agg-lightbox__nav--next { right: 14px; top: 50%; transform: translateY(-50%); }
</style>

        <div class="agg-catalogo-grid">
        <?php
        while ($query->have_posts()) {
            $query->the_post();
            $meta = get_post_meta(get_the_ID());
            ?>
            <div class="agg-catalogo-card">
            <?php
            // Carrusel por tarjeta: LÓGICA CORREGIDA
            $post_id = get_the_ID();
            $media_items = [];

            // Adjuntos (imágenes y videos)
            $attachments = get_attached_media('', $post_id);
            foreach ($attachments as $att) {
                $mime = get_post_mime_type($att->ID);
                if (strpos($mime, 'image/') === 0) {
                    $media_items[] = ['type'=>'image','html'=>wp_get_attachment_image($att->ID,'medium',false,['loading'=>'lazy','decoding'=>'async'])];
                } elseif (strpos($mime, 'video/') === 0) {
                    $src = wp_get_attachment_url($att->ID);
                    if ($src) $media_items[] = ['type'=>'video','html'=>wp_video_shortcode(['src'=>$src,'preload'=>'metadata'])];
                }
            }
            
            // Añadir URLs externas (imagen/imagen_url) además de adjuntos
            $raw = '';
            // Priorizar imagen destacada de WordPress
            $thumbnail_id = get_post_thumbnail_id();
            if ($thumbnail_id) {
                $thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'medium');
                if ($thumbnail_url) {
                    $raw = $thumbnail_url;
                }
            }
            // Fallback a meta fields si no hay imagen destacada
            if (empty($raw)) {
                if (!empty($meta['imagen'][0])) { $raw = $meta['imagen'][0]; }
                elseif (!empty($meta['imagen_url'][0])) { $raw = $meta['imagen_url'][0]; }
            }
            
            if (!empty($raw)) {
                $urls = array_filter(array_map('trim', preg_split('/\||,\s*(?=https?:)/', (string)$raw)));
                foreach ($urls as $u) {
                    if (!filter_var($u, FILTER_VALIDATE_URL)) continue;
                    $lower = strtolower($u);
                    if (preg_match('/\.(mp4|webm|ogg)(\?.*)?$/', $lower)) {
                        $media_items[] = ['type'=>'video','html'=>wp_video_shortcode(['src'=>$u,'preload'=>'metadata'])];
                    } else {
                        $media_items[] = ['type'=>'image','html'=>'<img src="'.esc_url($u).'" loading="lazy" decoding="async" alt="">'];
                    }
                }
            }

            // Deduplicar por HTML
            $seen = [];
            $media_items = array_values(array_filter($media_items, function($m) use (&$seen){
                $k = md5($m['html']);
                if (isset($seen[$k])) return false;
                $seen[$k] = true;
                return true;
            }));

            if (!empty($media_items)) :
                $carousel_id = 'aggc_' . $post_id;
            ?>
            <div class="agg-carousel" id="<?php echo esc_attr($carousel_id); ?>">
                <div class="agg-carousel-track">
                    <?php foreach ($media_items as $idx => $m) : ?>
                        <div class="agg-slide<?php echo $idx === 0 ? ' is-active' : ''; ?>">
                            <?php echo $m['html']; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($media_items) > 1) : ?>
                    <button class="agg-prev" type="button" aria-label="Anterior">‹</button>
                    <button class="agg-next" type="button" aria-label="Siguiente">›</button>
                    <div class="agg-dots">
                        <?php foreach ($media_items as $i => $_) : ?>
                            <button class="agg-dot<?php echo $i===0?' is-active':''; ?>" data-index="<?php echo $i; ?>" aria-label="Ir a <?php echo $i+1; ?>"></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <script>
            (function(){
                const c = document.currentScript.previousElementSibling;
                if(!c) return;
                const slides = Array.from(c.querySelectorAll('.agg-slide'));
                const prev = c.querySelector('.agg-prev');
                const next = c.querySelector('.agg-next');
                const dots = Array.from(c.querySelectorAll('.agg-dot'));
                let idx = slides.findIndex(s => s.classList.contains('is-active'));
                if (idx < 0) idx = 0;
                function goTo(n){
                    idx = (n + slides.length) % slides.length;
                    slides.forEach((s,i)=> s.classList.toggle('is-active', i===idx));
                    dots.forEach((d,i)=> d.classList.toggle('is-active', i===idx));
                }
                prev && prev.addEventListener('click', ()=> goTo(idx-1));
                next && next.addEventListener('click', ()=> goTo(idx+1));
                dots.forEach(d => d.addEventListener('click', ()=> goTo(parseInt(d.dataset.index,10))));
            })();
            </script>
            <?php endif; ?>

                <h3><?php echo esc_html(get_the_title()); ?></h3>
                <div class="agg-meta"><?php echo esc_html(wp_strip_all_tags(get_the_content())); ?></div>
                
                <?php
                $meta_keys = ['plataforma','combo','stock','activo','descripcion_larga'];
                foreach ($meta_keys as $key) {
                    if (!empty($meta[$key][0])) {
                        echo '<div class="agg-meta"><b>' . esc_html(ucfirst($key)) . ':</b> ' . esc_html($meta[$key][0]) . '</div>';
                    }
                }
                if (!empty($meta['precio'][0])) {
                    echo '<div class="agg-precio">Preço: R$ ' . esc_html($meta['precio'][0]) . '</div>';
                }
                ?>

                <?php
                $ml  = get_post_meta(get_the_ID(), 'link_ml', true);
                $olx = get_post_meta(get_the_ID(), 'link_olx', true);
                $sh  = get_post_meta(get_the_ID(), 'link_shopee', true);
                $whatsapp_direct = get_post_meta(get_the_ID(), 'whatsapp_direct_link', true);
                $custom_platform = get_post_meta(get_the_ID(), 'link_custom_platform', true);
                $custom_platform_name = get_post_meta(get_the_ID(), 'custom_platform_name', true);
                
                if ($ml || $olx || $sh || $whatsapp_direct || $custom_platform) {
                  echo '<div class="agg-links">';
                  if ($ml)  echo '<a class="agg-btn agg-btn--ml" href="'.esc_url($ml).'" target="_blank" rel="nofollow noopener">Ver en Mercado Livre</a>';
                  if ($olx) echo '<a class="agg-btn agg-btn--olx" href="'.esc_url($olx).'" target="_blank" rel="nofollow noopener">Ver en OLX</a>';
                  if ($sh)  echo '<a class="agg-btn agg-btn--sh" href="'.esc_url($sh).'" target="_blank" rel="nofollow noopener">Ver en Shopee</a>';
                  if ($whatsapp_direct) {
                    echo '<a class="agg-btn agg-btn--wa" href="'.esc_url($whatsapp_direct).'" target="_blank" rel="nofollow noopener">💬 WhatsApp</a>';
                  }
                  if ($custom_platform) {
                    $platform_name = !empty($custom_platform_name) ? $custom_platform_name : 'Ver en Plataforma';
                    echo '<a class="agg-btn agg-btn--custom" href="'.esc_url($custom_platform).'" target="_blank" rel="nofollow noopener">'.$platform_name.'</a>';
                  }
                  echo '</div>';
                }
                ?>
                           
                </div>
            <?php
        }
        echo '</div>'; // cierra .agg-catalogo-grid
        ?>

        <!-- Lightbox (una sola vez, fuera del grid) -->
        <div class="agg-lightbox" id="aggLightbox" aria-hidden="true">
          <button class="agg-lightbox__close" type="button" aria-label="Cerrar">✕</button>
          <button class="agg-lightbox__nav agg-lightbox__nav--prev" type="button" aria-label="Anterior">‹</button>
          <div class="agg-lightbox__content" id="aggLightboxContent"></div>
          <button class="agg-lightbox__nav agg-lightbox__nav--next" type="button" aria-label="Siguiente">›</button>
        </div>

        <script>
        // Lightbox global (una sola vez)
        (function(){
          const overlay = document.getElementById('aggLightbox');
          const content = document.getElementById('aggLightboxContent');
          const btnClose = overlay.querySelector('.agg-lightbox__close');
          const btnPrev  = overlay.querySelector('.agg-lightbox__nav--prev');
          const btnNext  = overlay.querySelector('.agg-lightbox__nav--next');

          let items = []; // [{type:'image'|'video', src:''}]
          let idx = 0;

          function buildItemsFromCard(card){
            const slides = card.querySelectorAll('.agg-slide img, .agg-slide video');
            const fallbacks = card.querySelectorAll('img:not(.agg-slide img), video:not(.agg-slide video)');
            items = [];
            (slides.length ? slides : fallbacks).forEach(el => {
              if (el.tagName.toLowerCase()==='img') {
                items.push({type:'image', src: el.currentSrc || el.src});
              } else {
                const src = el.currentSrc || el.src || (el.querySelector('source') && el.querySelector('source').src) || '';
                if (src) items.push({type:'video', src});
              }
            });
            // dedupe
            const seen = new Set();
            items = items.filter(it => (seen.has(it.type+it.src) ? false : seen.add(it.type+it.src)));
          }

          function render(){
            content.innerHTML = '';
            if (!items[idx]) return;
            if (items[idx].type === 'image') {
              const img = new Image();
              img.src = items[idx].src;
              content.appendChild(img);
            } else {
              const v = document.createElement('video');
              v.src = items[idx].src;
              v.controls = true;
              v.preload = 'metadata';
              v.autoplay = true;
              content.appendChild(v);
            }
          }

          function openAt(card, startIdx){
            buildItemsFromCard(card);
            if (!items.length) return;
            idx = Math.max(0, Math.min(startIdx, items.length-1));
            render();
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden','false');
          }
          function close(){
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden','true');
            content.innerHTML = '';
          }
          function prev(){ idx = (idx - 1 + items.length) % items.length; render(); }
          function next(){ idx = (idx + 1) % items.length; render(); }

          btnClose.addEventListener('click', close);
          btnPrev.addEventListener('click', prev);
          btnNext.addEventListener('click', next);
          overlay.addEventListener('click', (e)=> { if (e.target === overlay) close(); });
          document.addEventListener('keydown', (e)=>{
            if (!overlay.classList.contains('is-open')) return;
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowLeft') prev();
            if (e.key === 'ArrowRight') next();
          });

          // Click en imágenes/videos dentro de cada tarjeta
          document.addEventListener('click', (e)=>{
            const media = e.target.closest('.agg-catalogo-card img, .agg-catalogo-card video');
            if (!media) return;
            e.preventDefault();
            const card = media.closest('.agg-catalogo-card');
            // Averiguar índice del elemento clicado dentro de la lista
            const clickedSrc = media.currentSrc || media.src || (media.querySelector && media.querySelector('source') && media.querySelector('source').src) || '';
            openAt(card, 0);
            // Intentar posicionar en el ítem clicado
            const i = items.findIndex(it => it.src === clickedSrc);
            if (i >= 0) { idx = i; render(); }
          });
        })();
        </script>

        <?php
        wp_reset_postdata();
    } else {
        echo '<p>No hay elementos importados.</p>';
    }
    return ob_get_clean();
});