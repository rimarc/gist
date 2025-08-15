linea 54

/* Metabox: enlaces externos */
add_action('add_meta_boxes', function(){
  add_meta_box('agg_links', 'Enlaces de plataformas', function($post){
    wp_nonce_field('agg_links_nonce','agg_links_nonce_field');
    $ml  = esc_url(get_post_meta($post->ID, 'link_ml', true));
    $olx = esc_url(get_post_meta($post->ID, 'link_olx', true));
    $sh  = esc_url(get_post_meta($post->ID, 'link_shopee', true));
    echo '<p><label>Mercado Livre: <input type="url" name="link_ml" value="'.$ml.'" style="width:100%"></label></p>';
    echo '<p><label>OLX: <input type="url" name="link_olx" value="'.$olx.'" style="width:100%"></label></p>';
    echo '<p><label>Shopee: <input type="url" name="link_shopee" value="'.$sh.'" style="width:100%"></label></p>';
    echo '<p><label>Nombre Plataforma Personalizada: <input type="text" name="custom_platform_name" value="'.$custom_platform_name.'" placeholder="Ej: Amazon, eBay, etc." style="width:100%"></label></p>';
    echo '<p><label>Enlace Plataforma Personalizada: <input type="url" name="link_custom_platform" value="'.$custom_platform.'" placeholder="https://..." style="width:100%"></label></p>';
    echo '<hr style="margin:15px 0; border:0; border-top:1px solid #ddd;">';
    echo '<p><label>Enlace WhatsApp Directo: <input type="url" name="whatsapp_direct_link" value="'.esc_url(get_post_meta($post->ID, 'whatsapp_direct_link', true)).'" placeholder="https://wa.me/5511988263393?text=..." style="width:100%"></label></p>';
        
    
  }, 'agg_item', 'side', 'default');
});


linea 74

add_action('save_post_agg_item', function($post_id){
  if (!isset($_POST['agg_links_nonce_field']) || !wp_verify_nonce($_POST['agg_links_nonce_field'], 'agg_links_nonce')) return;
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (!current_user_can('edit_post', $post_id)) return;
  foreach (['link_ml','link_olx','link_shopee'] as $k) {
    if (isset($_POST[$k])) update_post_meta($post_id, $k, esc_url_raw($_POST[$k]));
  }
});




linea 190 

   // Alias -> índice
    $aliasMap = [
        'titulo'    => ['titulo','título','title','nombre','producto','nombre_producto'],
        'contenido' => ['contenido','descripcion','descripción','longdescription','long_description','descripcion_larga','descripción_larga','shortdescription','short_description'],
        'precio'    => ['precio','price','preço'],
        'stock'     => ['stock','qty','quantity'],
        'imagen_url'=> ['imagen_url','image','imagen','imagem','image_url','photo','photos','fotos','pictures'],
        // en aliasMap:
        'link_ml'      => ['link_ml','mercado_livre_link','ml_link'],
        'link_olx'     => ['link_olx','olx_link'],
        'link_shopee'  => ['link_shopee','shopee_link'],    
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



    linea 667

     <?php
                $ml  = get_post_meta(get_the_ID(), 'link_ml', true);
                $olx = get_post_meta(get_the_ID(), 'link_olx', true);
                $sh  = get_post_meta(get_the_ID(), 'link_shopee', true);
                $wa  = get_post_meta(get_the_ID(), 'whatsapp_number', true);  // ← CAMBIO AQUÍ
                $wa_msg = get_post_meta(get_the_ID(), 'whatsapp_message', true);  // ← AÑADIR ESTA LÍNEA
                
                if ($ml || $olx || $sh || $wa) {
                  echo '<div class="agg-links">';
                  if ($ml)  echo '<a class="agg-btn agg-btn--ml" href="'.esc_url($ml).'" target="_blank" rel="nofollow noopener">Ver en Mercado Livre</a>';
                  if ($olx) echo '<a class="agg-btn agg-btn--olx" href="'.esc_url($olx).'" target="_blank" rel="nofollow noopener">Ver en OLX</a>';
                  if ($sh)  echo '<a class="agg-btn agg-btn--sh" href="'.esc_url($sh).'" target="_blank" rel="nofollow noopener">Ver en Shopee</a>';
                  
                  if ($wa) {  // ← AÑADIR LLAVE DE APERTURA
                    $wa_number = preg_replace('/[^0-9+]/', '', $wa);
                    $default_msg = 'Olá! Gostaria de saber mais sobre: ' . get_the_title();
                    $message = !empty($wa_msg) ? $wa_msg : $default_msg;
                    $wa_url = 'https://wa.me/' . $wa_number . '?text=' . urlencode($message);
                    echo '<a class="agg-btn agg-btn--wa" href="'.esc_url($wa_url).'" target="_blank" rel="nofollow noopener">💬 WhatsApp</a>';
                  }  // ← AÑADIR LLAVE DE CIERRE
                                  echo '</div>';
                }
                ?>
                           
                </div>