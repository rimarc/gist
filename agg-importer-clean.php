<?php
/*
Plugin Name: AGG Importer
Description: Importa productos desde CSV y muestra catálogo con shortcode.
Version: 8.7
Author: rimarc
*/

if (!defined('ABSPATH')) exit;

/**
 * 1) Registrar el Custom Post Type
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
 * 2) Metabox de enlaces
 */
add_action('add_meta_boxes', function(){
    add_meta_box('agg_links', 'Enlaces de plataformas', function($post){
        wp_nonce_field('agg_links_nonce','agg_links_nonce_field');
        $ml = esc_url(get_post_meta($post->ID, 'link_ml', true));
        $olx = esc_url(get_post_meta($post->ID, 'link_olx', true));
        $sh = esc_url(get_post_meta($post->ID, 'link_shopee', true));
        $wa = esc_url(get_post_meta($post->ID, 'whatsapp_direct_link', true));
        
        echo '<p><label>Mercado Livre: <input type="url" name="link_ml" value="'.$ml.'" style="width:100%"></label></p>';
        echo '<p><label>OLX: <input type="url" name="link_olx" value="'.$olx.'" style="width:100%"></label></p>';
        echo '<p><label>Shopee: <input type="url" name="link_shopee" value="'.$sh.'" style="width:100%"></label></p>';
        echo '<p><label>WhatsApp: <input type="url" name="whatsapp_direct_link" value="'.$wa.'" style="width:100%"></label></p>';
    }, 'agg_item', 'side', 'default');
});

add_action('save_post_agg_item', function($post_id){
    if (!isset($_POST['agg_links_nonce_field']) || !wp_verify_nonce($_POST['agg_links_nonce_field'], 'agg_links_nonce')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    
    update_post_meta($post_id, 'link_ml', esc_url_raw($_POST['link_ml']));
    update_post_meta($post_id, 'link_olx', esc_url_raw($_POST['link_olx']));
    update_post_meta($post_id, 'link_shopee', esc_url_raw($_POST['link_shopee']));
    update_post_meta($post_id, 'whatsapp_direct_link', esc_url_raw($_POST['whatsapp_direct_link']));
});

/**
 * 3) Menú admin
 */
function agg_add_admin_menu() {
    add_menu_page('AGG Importer', 'AGG Importer', 'manage_options', 'agg-importer', 'agg_importer_admin_page', 'dashicons-upload', 80);
}
add_action('admin_menu', 'agg_add_admin_menu');

function agg_importer_admin_page() {
    ?>
    <div class="wrap">
        <h1>AGG Importer</h1>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('agg_import_csv','agg_import_csv_nonce'); ?>
            <input type="file" name="agg_csv" accept=".csv" required>
            <input type="submit" name="agg_import_submit" class="button button-primary" value="Importar CSV">
        </form>
    </div>
    <?php
}

/**
 * 4) Shortcode básico
 */
add_shortcode('agg_importer_list', function($atts){
    $atts = shortcode_atts(['count' => 10], $atts, 'agg_importer_list');
    
    $args = [
        'post_type' => 'agg_item',
        'posts_per_page' => intval($atts['count']),
        'post_status' => 'publish'
    ];
    
    $query = new WP_Query($args);
    ob_start();
    
    if ($query->have_posts()) {
        echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:20px; margin:1em 0;">';
        
        while ($query->have_posts()) {
            $query->the_post();
            $ml = get_post_meta(get_the_ID(), 'link_ml', true);
            $olx = get_post_meta(get_the_ID(), 'link_olx', true);
            $sh = get_post_meta(get_the_ID(), 'link_shopee', true);
            $wa = get_post_meta(get_the_ID(), 'whatsapp_direct_link', true);
            
            echo '<div style="background:#fafafa; border:1px solid #ddd; border-radius:8px; padding:14px;">';
            
            if (has_post_thumbnail()) {
                echo get_the_post_thumbnail(get_the_ID(), 'medium', ['style' => 'width:100%; height:180px; object-fit:contain; border-radius:6px;']);
            }
            
            echo '<h3>' . esc_html(get_the_title()) . '</h3>';
            echo '<div>' . esc_html(wp_strip_all_tags(get_the_content())) . '</div>';
            
            if ($ml || $olx || $sh || $wa) {
                echo '<div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:10px;">';
                if ($ml) echo '<a href="'.esc_url($ml).'" target="_blank" style="background:#ffe600; color:#333; padding:8px 10px; border-radius:6px; text-decoration:none; font-weight:600; font-size:13px;">Mercado Livre</a>';
                if ($olx) echo '<a href="'.esc_url($olx).'" target="_blank" style="background:#6e00f5; color:#fff; padding:8px 10px; border-radius:6px; text-decoration:none; font-weight:600; font-size:13px;">OLX</a>';
                if ($sh) echo '<a href="'.esc_url($sh).'" target="_blank" style="background:#ee4d2d; color:#fff; padding:8px 10px; border-radius:6px; text-decoration:none; font-weight:600; font-size:13px;">Shopee</a>';
                if ($wa) echo '<a href="'.esc_url($wa).'" target="_blank" style="background:#25d366; color:#fff; padding:8px 10px; border-radius:6px; text-decoration:none; font-weight:600; font-size:13px;">💬 WhatsApp</a>';
                echo '</div>';
            }
            
            echo '</div>';
        }
        
        echo '</div>';
        wp_reset_postdata();
    } else {
        echo '<p>No hay elementos importados.</p>';
    }
    
    return ob_get_clean();
});