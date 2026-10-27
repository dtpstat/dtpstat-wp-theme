<?php
/**
 * Главная: карта во весь экран — сверху только navbar, без футера.
 *
 * Контент страницы остаётся в WordPress. Адрес ПЕРВОГО iframe карты
 * переопределяется из DTP_MAP_URL (или DTP_FRONTEND_URL) при каждом запросе:
 * обновлять post_content или импортировать WXR повторно не требуется.
 *
 * @package dtpstat-child
 */
get_header();

while ( have_posts() ) :
	the_post();
	$map_content = apply_filters( 'the_content', get_the_content() );
	$map_url     = dtp_map_url();

	if ( $map_url && class_exists( 'WP_HTML_Tag_Processor' ) ) {
		$processor = new WP_HTML_Tag_Processor( $map_content );
		if ( $processor->next_tag( 'IFRAME' ) ) {
			$processor->set_attribute( 'src', $map_url . '/' );
			$map_content = $processor->get_updated_html();
		}
	}
	?>
	<div class="dtp-map">
		<div class="dtp-map__in">
			<?php
			if ( $map_url ) {
				echo $map_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML из the_content с безопасной заменой URL.
			} elseif ( current_user_can( 'manage_options' ) ) {
				echo '<p>Настройте DTP_MAP_URL или DTP_FRONTEND_URL в проектном .env и сгенерируйте dtpstat-config.php.</p>';
			}
			?>
		</div>
	</div>
	<?php
endwhile;

// footer.php не подключаем (он с полосой сбора), но wp_footer() нужен для скриптов.
wp_footer();
