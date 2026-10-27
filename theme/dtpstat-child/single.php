<?php
/**
 * Одиночный пост: та же колонка, метаданные, контент.
 *
 * @package dtpstat-child
 */
get_header();

while ( have_posts() ) :
	the_post();
	?>
	<div class="dtp-container dtp-page dtp-page--left">
		<h1 class="dtp-page__title"><?php the_title(); ?></h1>
		<div class="dtp-postmeta">
			<?php
			echo esc_html( get_the_date() );
			// автор — как на старом сайте («14.08.2025 | Velonation»); технические
			// учётки, у которых display_name не отличается от логина, не показываем
			$author      = get_the_author_meta( 'display_name' );
			$author_link = strtolower( get_the_author_meta( 'user_login' ) );
			if ( $author && strtolower( $author ) !== $author_link ) {
				echo ' | ' . esc_html( $author );
			}
			$cats = dtpstat_post_categories();
			if ( $cats ) {
				echo ' · ' . esc_html( implode( ', ', wp_list_pluck( $cats, 'name' ) ) );
			}
			?>
		</div>
		<?php if ( has_post_thumbnail() ) : ?>
			<?php // обложка записи — целиком, без кропа (как на старом сайте);
				// srcset выключаем: кропнутые размеры старых генераций (dtp-cover
				// 856×482, dtp-card 480×270) не должны подставляться браузером.
				// Кроп остаётся только в «внешних» вставках — карточках сетки. ?>
			<?php add_filter( 'wp_calculate_image_srcset', '__return_empty_array' ); ?>
			<div class="dtp-cover"><?php the_post_thumbnail( 'full' ); ?></div>
			<?php remove_filter( 'wp_calculate_image_srcset', '__return_empty_array' ); ?>
		<?php endif; ?>
		<div class="dtp-entry">
			<?php the_content(); ?>
		</div>
	</div>
	<?php
endwhile;

get_footer();
