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
        <div class="agg-importer-carousel swiper">
            <div class="swiper-wrapper">
                <?php while ($query->have_posts()) : $query->the_post(); ?>
                    <div class="swiper-slide agg-item">
                        <a href="<?php the_permalink(); ?>">
                            <?php
                            // Imagen destacada
                            if (has_post_thumbnail()) {
                                the_post_thumbnail('medium');
                            } else {
                                // Busca adjuntos
                                $attachments = get_attached_media('image', get_the_ID());
                                if (!empty($attachments)) {
                                    $first_attachment = array_shift($attachments);
                                    echo wp_get_attachment_image($first_attachment->ID, 'medium');
                                } else {
                                    // Fallback a imagen_url meta
                                    $img_url = get_post_meta(get_the_ID(), 'imagen_url', true);
                                    if ($img_url) {
                                        echo '<img src="' . esc_url($img_url) . '" alt="' . esc_attr(get_the_title()) . '">';
                                    } else {
                                        echo '<img src="' . esc_url(plugin_dir_url(__FILE__) . 'assets/no-image.png') . '" alt="Sin imagen">';
                                    }
                                }
                            }
                            ?>
                            <h3><?php the_title(); ?></h3>
                        </a>
                    </div>
                <?php endwhile; ?>
            </div>
            <!-- Botones del carrusel -->
            <div class="swiper-button-next"></div>
            <div class="swiper-button-prev"></div>
            <div class="swiper-pagination"></div>
        </div>
        <?php
    }
    wp_reset_postdata();
    return ob_get_clean();
});
