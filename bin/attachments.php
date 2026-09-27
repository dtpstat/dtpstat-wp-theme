<?php
/**
 * Записи медиафайлов: дамп/восстановление в обход WXR-импортёра.
 *
 * Зачем не wp import: импортёр для аттачментов всегда скачивает файл по URL
 * (process_attachment), при этом создаёт копии с суффиксом -1 и затирает
 * _wp_attachment_metadata сгенерированным — ручные кропы crop-thumbnails
 * (cpt_last_cropping_data в sizes) теряются. Здесь: файлы уже разложены
 * uploads'ом целиком, записи создаём сами с ИСХОДНЫМИ id (import_id) и полным
 * meta — тогда ссылки в контенте (wp-image-N, _thumbnail_id) остаются валидными.
 *
 * Использование:
 *   wp eval-file bin/attachments.php dump                    > content/attachments.json
 *   wp eval-file bin/attachments.php restore content/attachments.json
 *
 * @param array $args  [0]=mode (dump|restore), [1]=json-путь для restore
 */

if ( empty( $args[0] ) || ! in_array( $args[0], array( 'dump', 'restore' ), true ) ) {
	WP_CLI::error( 'укажите режим: dump | restore <attachments.json>' );
}

if ( 'dump' === $args[0] ) {
	$out = array();
	foreach ( get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'numberposts'    => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	) ) as $p ) {
		// ВНИМАНИЕ: get_post_meta($id, '', false) возвращает значения СЫРЫМИ
		// (сериализованный PHP строкой) — читаем каждый ключ отдельно, single=true
		// корректно разворачивает сериализацию
		$meta = array();
		foreach ( array_keys( get_post_meta( $p->ID, '', false ) ) as $k ) {
			$meta[ $k ] = get_post_meta( $p->ID, $k, true );
		}
		$out[] = array(
			'ID'   => $p->ID,
			'post' => array(
				'post_author'     => $p->post_author,
				'post_date'       => $p->post_date,
				'post_date_gmt'   => $p->post_date_gmt,
				'post_content'    => $p->post_content,
				'post_title'      => $p->post_title,
				'post_excerpt'    => $p->post_excerpt,
				'post_status'     => $p->post_status,
				'post_name'       => $p->post_name,
				'post_mime_type'  => $p->post_mime_type,
				'menu_order'      => $p->menu_order,
				'comment_status'  => $p->comment_status,
				'ping_status'     => $p->ping_status,
				'guid'            => $p->guid,
			),
			'meta' => $meta,
		);
	}
	echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit;
}

// --- restore
$json_path = $args[1] ?? '';
if ( ! $json_path || ! file_exists( $json_path ) ) {
	WP_CLI::error( "нет файла: $json_path" );
}
$items = json_decode( file_get_contents( $json_path ), true );
if ( ! is_array( $items ) ) {
	WP_CLI::error( 'не разобрать JSON' );
}

$done = 0;
foreach ( $items as $it ) {
	$orig = (int) $it['ID'];
	// id занят (нечистая цель) — исходные id сохранить нельзя, честно пропускаем
	if ( get_post( $orig ) ) {
		WP_CLI::warning( "id $orig занят, пропущен: {$it['post']['post_name']}" );
		continue;
	}
	$p    = $it['post'];
	$file = $it['meta']['_wp_attached_file'] ?? '';
	$id   = wp_insert_attachment(
		array(
			'import_id'      => $orig,
			'post_author'    => max( 1, (int) $p['post_author'] ),
			'post_date'      => $p['post_date'],
			'post_date_gmt'  => $p['post_date_gmt'],
			'post_content'   => $p['post_content'],
			'post_title'     => $p['post_title'],
			'post_excerpt'   => $p['post_excerpt'],
			'post_status'    => $p['post_status'],
			'post_name'      => $p['post_name'],
			'post_mime_type' => $p['post_mime_type'],
			'menu_order'     => $p['menu_order'],
			'comment_status' => $p['comment_status'],
			'ping_status'    => $p['ping_status'],
			// post_parent не переносим: id родителя в новой базе другой
			'guid'           => $p['guid'],
		),
		$file,
		0
	);
	if ( is_wp_error( $id ) || ! $id ) {
		WP_CLI::warning( "не создан: {$p['post_name']} (" . ( is_wp_error( $id ) ? $id->get_error_message() : '?' ) . ')' );
		continue;
	}
	foreach ( $it['meta'] as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	if ( isset( $it['meta']['_wp_attachment_metadata'] ) ) {
		// как есть из дампа: все размеры + ручные кропы crop-thumbnails
		wp_update_attachment_metadata( $id, $it['meta']['_wp_attachment_metadata'] );
	}
	clean_attachment_cache( $id );
	$done++;
}

WP_CLI::success( "медиа: восстановлено $done из " . count( $items ) );
