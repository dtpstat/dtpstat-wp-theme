<?php
/**
 * Хедер по макету: лого «карта дтп», меню, кнопка «Поддержать проект».
 *
 * @package dtpstat-child
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="dtp-header">
	<div class="dtp-header__in">
		<a class="dtp-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Карта ДТП — на главную">
			<?php // Лого — как на dtp-stat.ru: вектор, трассированный с их logo.png (2280×340). ?>
			<img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/img/logo.svg' ); ?>" width="2280" height="340" alt="">
		</a>

		<nav class="dtp-nav" aria-label="<?php esc_attr_e( 'Главное меню', 'dtpstat-child' ); ?>">
			<?php
			wp_nav_menu( [
				'theme_location' => 'primary',
				'container'      => false,
				'fallback_cb'    => false,
				'depth'          => 1,
			] );
			?>
			<?php // на мобильных кнопка «Поддержать проект» из шапки скрыта —
			  // дублируем её ссылкой в открывающемся меню, иначе страницу не достать
			?>
			<a class="dtp-nav__support" href="<?php echo esc_url( home_url( '/support-project/' ) ); ?>">Поддержать проект</a>
		</nav>

		<button class="dtp-burger" aria-label="Меню">
			<span></span><span></span><span></span>
		</button>

		<a class="dtp-btn" href="<?php echo esc_url( home_url( '/support-project/' ) ); ?>">Поддержать проект</a>
	</div>
</header>

<main id="content" class="dtp-main">
