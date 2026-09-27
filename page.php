<?php
/**
 * Статическая страница: контейнер 856px, H1 по центру (как в макете).
 *
 * @package dtpstat-child
 */
get_header();

while ( have_posts() ) :
	the_post();
	?>
	<div class="dtp-container dtp-page">
		<?php if ( ! is_page( 'download' ) ) : // у download H1 живёт в контенте, рядом с «Инструкция для API» ?>
			<h1 class="dtp-page__title"><?php the_title(); ?></h1>
		<?php endif; ?>
		<div class="dtp-entry <?php echo is_page( 'download' ) ? 'dtp-page--left' : ''; ?>">
			<?php the_content(); ?>
		</div>
	</div>
	<?php
endwhile;

get_footer();
