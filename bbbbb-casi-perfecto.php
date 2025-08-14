<?php
/*
Plugin Name: AGG Importer
Description: Importa productos desde CSV y muestra catálogo con shortcode.
Version: 8.3
Author: rimarc + Copilot
*/

if (!defined('ABSPATH')) exit;

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
	// Asegurar soporte de miniaturas para este CPT
	add_theme_support('post-thumbnails', ['agg_item']);
}
add_action('init', 'agg_register_cpt');

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
			<b>Formato esperado del CSV:</b><br>
			<code>ID,titulo,contenido,plataforma,combo,precio,stock,activo,imagen_url,descripcion_larga</code>
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
		'precio'    => ['precio','price'],
		'stock'     => ['stock','qty','quantity'],
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

		// Metas genéricas (menos el título y 'id')
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
 * 8) Shortcode: lista de items con imagen destacada o fallback a imagen_url
 * Uso: [agg_importer_list count="10"]
 */
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
	

  .agg-catalogo-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin: 1em 0;
  }
  .agg-catalogo-card {
    background: #fafafa;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 16px;
    width: 320px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.07);
  }
  .agg-catalogo-card img {
    width: 100%;
    height: 180px;
    object-fit: contain;
    display: block;
    margin-bottom: 8px;
    border-radius: 6px;
  }
  .agg-catalogo-card h3 {
    margin: 0 0 8px 0;
    font-size: 1.15em;
  }
  .agg-catalogo-card .agg-meta {
    font-size: 0.98em;
    color: #444;
    margin-bottom: 6px;
  }
  .agg-catalogo-card .agg-precio {
    font-weight: bold;
    color: #2b8c2b;
  }







	.agg-carousel { position: relative; }
.agg-carousel-track { position: relative; overflow: hidden; }
.agg-slide { display: none; }
.agg-slide.is-active { display: block; }
.agg-prev, .agg-next {
  position: absolute; top: 50%; transform: translateY(-50%);
  background: rgba(0,0,0,.5); color:#fff; border:0; width:32px; height:32px;
  border-radius: 16px; cursor: pointer;
}
.agg-prev { left: 6px; }
.agg-next { right: 6px; }
.agg-dots { display:flex; gap:6px; justify-content:center; margin-top:6px; }
.agg-dot { width:8px; height:8px; border-radius:4px; background:#bbb; border:0; cursor:pointer; }
.agg-dot.is-active { background:#333; }
		</style>



		<div class="agg-catalogo-grid">
		<?php
		while ($query->have_posts()) {
			$query->the_post();
			$meta = get_post_meta(get_the_ID());
			$external_img = !empty($meta['imagen_url'][0]) ? esc_url($meta['imagen_url'][0]) : '';
			$thumb_html = get_the_post_thumbnail(get_the_ID(), 'medium', ['loading'=>'lazy','decoding'=>'async']);
			?>
			<div class="agg-catalogo-card">
<?php
// Carrusel por tarjeta
$post_id = get_the_ID();
$attachments = get_attached_media('', $post_id);
$media_items = [];
foreach ($attachments as $att) {
    $mime = get_post_mime_type($att->ID);
    if (strpos($mime, 'image/') === 0) {
        $media_items[] = ['type' => 'image', 'html' => wp_get_attachment_image($att->ID, 'medium', false, ['loading'=>'lazy','decoding'=>'async'])];
    } elseif (strpos($mime, 'video/') === 0) {
        $src = wp_get_attachment_url($att->ID);
        if ($src) $media_items[] = ['type' => 'video', 'html' => wp_video_shortcode(['src'=>$src, 'preload'=>'metadata'])];
    }
}
if (empty($media_items)) {
    $raw = '';
    if (!empty($meta['imagen'][0]))       { $raw = $meta['imagen'][0]; }
    elseif (!empty($meta['imagen_url'][0])) { $raw = $meta['imagen_url'][0]; }
    $urls = array_filter(array_map('trim', preg_split('/\||,\s*(?=https?:)/', (string)$raw)));
    foreach ($urls as $u) {
        if (!filter_var($u, FILTER_VALIDATE_URL)) continue;
        $lower = strtolower($u);
        if (preg_match('/\.(mp4|webm|ogg)(\?.*)?$/', $lower)) {
            $media_items[] = ['type' => 'video', 'html' => wp_video_shortcode(['src'=>$u, 'preload'=>'metadata'])];
        } else {
            $media_items[] = ['type' => 'image', 'html' => '<img src="'.esc_url($u).'" loading="lazy" decoding="async" alt="">'];
        }
    }
}
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
<script>(function(){const c=document.currentScript.previousElementSibling; if(!c) return; const slides=[...c.querySelectorAll('.agg-slide')]; const prev=c.querySelector('.agg-prev'); const next=c.querySelector('.agg-next'); const dots=[...c.querySelectorAll('.agg-dot')]; let idx=slides.findIndex(s=>s.classList.contains('is-active')); if(idx<0) idx=0; function goTo(n){idx=(n+slides.length)%slides.length; slides.forEach((s,i)=>s.classList.toggle('is-active',i===idx)); dots.forEach((d,i)=>d.classList.toggle('is-active',i===idx));} prev&&prev.addEventListener('click',()=>goTo(idx-1)); next&&next.addEventListener('click',()=>goTo(idx+1)); dots.forEach(d=>d.addEventListener('click',()=>goTo(parseInt(d.dataset.index,10))));})();</script>
<?php endif; ?>
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