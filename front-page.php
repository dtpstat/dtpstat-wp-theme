<?php
/**
 * Главная: карта во весь экран — сверху только navbar, без футера.
 * Сам айфрейм живёт в контенте страницы (его не трогаем), шаблон
 * только растягивает его на всё место под хедером.
 *
 * @package dtpstat-child
 */
get_header();

while ( have_posts() ) :
	the_post();
	?>
	<div class="dtp-map">
		<div class="dtp-map__in"><?php the_content(); ?></div>
	</div>
	<?php
endwhile;

// footer.php не подключаем (он с полосой сбора), но wp_footer() нужен для скриптов.
wp_footer();
