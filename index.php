<?php
/**
 * Фолбэк-лента (архивы, поиск): та же сетка карточек.
 *
 * @package dtpstat-child
 */
get_header();
?>
<div class="dtp-container dtp-page dtp-page--left">
	<h1 class="dtp-blog__title"><?php echo esc_html( wp_get_document_title() ); ?></h1>
	<div style="height:40px"></div>
	<?php if ( have_posts() ) : ?>
		<div class="dtp-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				$cats = dtpstat_post_categories();
				?>
				<a class="dtp-card" href="<?php the_permalink(); ?>">
					<div class="dtp-card__img">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'dtp-card' ); ?>
						<?php endif; ?>
						<?php if ( $cats ) : ?>
							<div class="dtp-card__chips">
								<?php foreach ( array_slice( $cats, 0, 3 ) as $cat ) : ?>
									<span class="dtp-chip"><?php echo esc_html( $cat->name ); ?></span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
					<h2 class="dtp-card__title"><?php the_title(); ?></h2>
				</a>
				<?php
			endwhile;
			?>
		</div>
		<?php
		the_posts_pagination( [ 'mid_size' => 1, 'prev_text' => '←', 'next_text' => '→' ] );
		?>
	<?php else : ?>
		<p>Ничего не найдено.</p>
	<?php endif; ?>
</div>
<?php
get_footer();
