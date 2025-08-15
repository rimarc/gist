<?php
/*
Plugin Name: AGG Importer - Hestia Search Integration
Description: Integra la búsqueda de Hestia con el carrusel de productos AGG.
Version: 8.9
Author: rimarc
*/

if (!defined('ABSPATH')) exit;

/**
 * 1) Interceptar búsqueda de Hestia y redirigir al shortcode
 */
add_action('wp_head', function() {
    // Solo en páginas de búsqueda
    if (!is_search()) return;
    
    // Verificar si hay resultados de agg_item
    $search_query = get_search_query();
    if (empty($search_query)) return;
    
    // Buscar productos AGG que coincidan
    $agg_results = new WP_Query([
        'post_type' => 'agg_item',
        'posts_per_page' => -1,
        's' => $search_query,
        'meta_query' => [
            'relation' => 'OR',
            [ 'key' => 'agg_hide', 'compare' => 'NOT EXISTS' ],
            [ 'key' => 'agg_hide', 'value' => '1', 'compare' => '!=' ],
        ],
    ]);
    
    // Si hay resultados AGG, redirigir a página personalizada
    if ($agg_results->have_posts()) {
        // Crear URL de redirección con parámetros
        $redirect_url = add_query_arg([
            'agg_search' => urlencode($search_query),
            'agg_results' => $agg_results->found_posts
        ], home_url('/agg-search-results/'));
        
        // Redirigir solo si no estamos ya en la página de resultados
        if (!is_page('agg-search-results')) {
            wp_redirect($redirect_url);
            exit;
        }
    }
});

/**
 * 2) Crear página de resultados de búsqueda personalizada
 */
add_action('init', function() {
    // Registrar página virtual para resultados de búsqueda
    add_rewrite_rule(
        '^agg-search-results/?$',
        'index.php?agg_search_results=1',
        'top'
    );
    
    // Agregar query var
    add_filter('query_vars', function($vars) {
        $vars[] = 'agg_search_results';
        $vars[] = 'agg_search';
        $vars[] = 'agg_results';
        return $vars;
    });
    
    // Interceptar la página virtual
    add_action('template_redirect', function() {
        if (get_query_var('agg_search_results')) {
            // Cargar template personalizado
            include(plugin_dir_path(__FILE__) . 'templates/search-results.php');
            exit;
        }
    });
});

/**
 * 3) Template para resultados de búsqueda
 */
add_action('init', function() {
    // Crear directorio de templates si no existe
    $template_dir = plugin_dir_path(__FILE__) . 'templates/';
    if (!file_exists($template_dir)) {
        mkdir($template_dir, 0755, true);
    }
    
    // Crear archivo de template
    $template_file = $template_dir . 'search-results.php';
    if (!file_exists($template_file)) {
        file_put_contents($template_file, '<?php
/**
 * Template para resultados de búsqueda AGG
 */
get_header(); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <div class="agg-search-results">
                <h1>Resultados de búsqueda: "<?php echo esc_html(urldecode($_GET[\'agg_search\'] ?? \'\')); ?>"</h1>
                <p>Se encontraron <?php echo intval($_GET[\'agg_results\'] ?? 0); ?> productos</p>
                
                <?php
                // Mostrar resultados usando el shortcode
                $search_query = urldecode($_GET[\'agg_search\'] ?? \'\');
                if (!empty($search_query)) {
                    // Crear shortcode con parámetros de búsqueda
                    echo do_shortcode(\'[agg_importer_list count="20" search="\' . esc_attr($search_query) . \'"]\');
                } else {
                    echo \'<p>No se especificó término de búsqueda.</p>\';
                }
                ?>
                
                <div class="agg-search-back">
                    <a href="' . home_url() . '" class="btn btn-primary">← Volver al inicio</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.agg-search-results {
    padding: 40px 0;
    text-align: center;
}

.agg-search-results h1 {
    color: #333;
    margin-bottom: 10px;
}

.agg-search-results p {
    color: #666;
    margin-bottom: 30px;
}

.agg-search-back {
    margin-top: 40px;
}

.agg-search-back .btn {
    padding: 12px 24px;
    font-size: 16px;
    border-radius: 6px;
}
</style>

<?php get_footer(); ?>');
    }
});

/**
 * 4) Modificar shortcode para soportar búsqueda
 */
add_filter('agg_importer_shortcode_query', function($args, $atts) {
    // Si se especifica búsqueda, agregar parámetro
    if (!empty($atts['search'])) {
        $args['s'] = sanitize_text_field($atts['search']);
    }
    
    return $args;
}, 10, 2);

/**
 * 5) Modificar el shortcode original para usar el filtro
 */
add_action('init', function() {
    // Remover shortcode original si existe
    remove_shortcode('agg_importer_list');
    
    // Agregar shortcode modificado
    add_shortcode('agg_importer_list', function($atts) {
        $atts = shortcode_atts([
            'count' => 10,
            'search' => '' // Nuevo parámetro de búsqueda
        ], $atts, 'agg_importer_list');

        $args = [
            'post_type'      => 'agg_item',
            'posts_per_page' => intval($atts['count']),
            'meta_query'     => [
                'relation' => 'OR',
                [ 'key' => 'agg_hide', 'compare' => 'NOT EXISTS' ],
                [ 'key' => 'agg_hide', 'value' => '1', 'compare' => '!=' ],
            ],
        ];
        
        // Aplicar filtro para búsqueda
        $args = apply_filters('agg_importer_shortcode_query', $args, $atts);

        $query = new WP_Query($args);
        ob_start();
        
        if ($query->have_posts()) {
            // Mostrar mensaje de búsqueda si aplica
            if (!empty($atts['search'])) {
                echo '<div class="agg-search-notice">';
                echo '<p>Mostrando resultados para: <strong>"' . esc_html($atts['search']) . '"</strong></p>';
                echo '</div>';
            }
            
            // Aquí va todo el HTML del carrusel (mantener el código original)
            ?>
            <style>
            /* Estilos del carrusel (mantener los originales) */
            .agg-links { display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
            .agg-btn { display:inline-block; padding:8px 10px; border-radius:6px; text-decoration:none; font-weight:600; font-size:13px; background:#333; color:#fff; }
            .agg-btn--ml { background:#ffe600; color:#333; }
            .agg-btn--olx { background:#6e00f5; }
            .agg-btn--sh { background:#ee4d2d; }
            .agg-btn--wa { background:#25d366; }
            .agg-btn--custom { background:#007bff; }
            .agg-btn:hover { opacity:.9; }

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

            .agg-catalogo-card img,
            .agg-catalogo-card video {
              width: 100%;
              height: 180px;
              object-fit: contain;
              display: block;
              border-radius: 6px;
            }

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

            .agg-catalogo-card p,
            .agg-catalogo-card figure,
            .agg-catalogo-card br,
            .agg-catalogo-card .wp-video,
            .agg-catalogo-card .mejs-container,
            .agg-catalogo-card .mejs__container { margin: 0 !important; padding: 0 !important; }
            .agg-catalogo-card br { display: none; }
            .agg-catalogo-card p:empty { display: none; }

            @media (max-width: 768px) {
              .agg-catalogo-grid { grid-template-columns: 1fr; gap: 15px; }
              .agg-catalogo-card { padding: 12px; }
              .agg-catalogo-card img, .agg-catalogo-card video { height: 160px; }
              .agg-links { flex-direction: column; gap: 6px; }
              .agg-btn { text-align: center; padding: 10px 12px; font-size: 14px; }
            }
            @media (min-width: 769px) and (max-width: 1024px) {
              .agg-catalogo-grid { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 18px; }
            }
            @media (min-width: 1025px) {
              .agg-catalogo-grid { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; }
              .agg-catalogo-card { padding: 16px; }
            }

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
            
            .agg-search-notice {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 6px;
                padding: 15px;
                margin-bottom: 20px;
                text-align: center;
            }
            .agg-search-notice p {
                margin: 0;
                color: #495057;
            }
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
                
                // Añadir URLs externas (imagen/imagen_url) ADEMÁS de adjuntos
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
                      if ($sh)  echo '<a class="agg-hestia-btn agg-hestia-btn--sh" href="'.esc_url($sh).'" target="_blank" rel="nofollow noopener">Ver en Shopee</a>';
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
        } else {
            if (!empty($atts['search'])) {
                echo '<p>No se encontraron productos para: <strong>"' . esc_html($atts['search']) . '"</strong></p>';
            } else {
                echo '<p>No hay elementos importados.</p>';
            }
        }
        
        wp_reset_postdata();
        return ob_get_clean();
    });
});

/**
 * 6) Flush rewrite rules al activar
 */
register_activation_hook(__FILE__, function() {
    // Flush rewrite rules para que funcione la página virtual
    flush_rewrite_rules();
});

/**
 * 7) Limpiar rewrite rules al desactivar
 */
register_deactivation_hook(__FILE__, function() {
    // Limpiar rewrite rules
    flush_rewrite_rules();
});