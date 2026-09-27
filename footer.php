<?php
/**
 * Футер по макету: полоса сбора, колонки, CC, соцсети.
 *
 * @package dtpstat-child
 */
?>
</main><!-- /.dtp-main -->

<footer class="dtp-footer">
	<div class="dtp-container">

		<div class="dtp-fund">
			<div class="dtp-fund__main">
				<?php // цифры и ширина полосы — из одного источника dtp_fund() (functions.php) ?>
				<div class="dtp-fund__bar"><i class="dtp-fund__fill"></i></div>
				<div class="dtp-fund__row">
					<span class="dtp-fund__raised">Собрано: <?php echo esc_html( dtp_num( dtp_fund()['raised'] ) ); ?> ₽ из <?php echo esc_html( dtp_num( dtp_fund()['goal'] ) ); ?> ₽</span>
					<span class="dtp-fund__days"><?php echo esc_html( dtp_fund_days() ); ?></span>
				</div>
			</div>
			<a class="dtp-btn" href="<?php echo esc_url( home_url( '/support-project/' ) ); ?>">Поддержать проект</a>
		</div>

		<hr>

		<div class="dtp-fcols">
			<div class="dtp-fcol dtp-fcol--about">
				<a class="dtp-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php echo file_get_contents( get_stylesheet_directory() . '/assets/img/logo.svg' ); // phpcs:ignore ?>
				</a>
				<p>Проект посвящен проблеме дорожно-транспортных происшествий в России. Цель проекта — повышение безопасности дорожного движения и снижение смертности в ДТП.</p>
			</div>

			<div class="dtp-fcol">
				<h4>Связаться:</h4>
				<?php $ic = get_stylesheet_directory() . '/assets/img/social/'; ?>
				<p class="dtp-contact dtp-contact--icon">
					<?php echo file_get_contents( $ic . 'telegram-sm.svg' ); // phpcs:ignore ?>
					<a href="https://t.me/shehovsov_md" target="_blank" rel="noopener">@shehovsov_md</a>
				</p>
				<p class="dtp-contact dtp-contact--icon">
					<?php echo file_get_contents( get_stylesheet_directory() . '/assets/img/envelope-sm.svg' ); // phpcs:ignore ?>
					<a href="mailto:dtp.stat@gmail.com">dtp.stat@gmail.com</a>
				</p>
			</div>

			<div class="dtp-fcol">
				<h4>Карта сайта:</h4>
				<?php
				wp_nav_menu( [
					'theme_location' => 'footer',
					'container'      => false,
					'menu_class'     => 'dtp-fmenu',
					'fallback_cb'    => false,
					'depth'          => 1,
				] );
				?>
			</div>
		</div>

		<hr>

		<div class="dtp-fbottom">
			<div class="dtp-cc">
				<?php
				echo file_get_contents( get_stylesheet_directory() . '/assets/img/cc.svg' ); // phpcs:ignore
				echo file_get_contents( get_stylesheet_directory() . '/assets/img/by.svg' ); // phpcs:ignore
				?>
			</div>
			<div class="dtp-fcopy">Использование материалов возможно с указанием активной ссылки на сайт.</div>

			<div class="dtp-socials">
				<?php
				// Порядок и иконки — из макета (Frame 579: 487, 488, 492, 490, 491):
				// Telegram, X (Twitter), Instagram, Patreon, Boosty.
				// Ссылки сняты со старого dtp-stat.ru (краул 120 страниц, 01.09.2026):
				// t.me/dtp_stat и twitter.com/dtp_stat — в футере каждой страницы,
				// patreon.com/crash_map и boosty.to/dtp-stat — из постов о сборах.
				// instagram.com/dtp_stat — от Ильи (02.09.2026), до этого был `#`.
				$dir = get_stylesheet_directory() . '/assets/img/social/';
				$socials = [
					'Telegram'  => ['https://t.me/dtp_stat', 'telegram.svg', ''],
					'X'         => ['https://twitter.com/dtp_stat', 'twitter.svg', ''],
					'Instagram' => ['https://www.instagram.com/dtp_stat', 'instagram.svg', ''],
					'Patreon'   => ['https://patreon.com/crash_map', 'patreon.svg', 'dtp-socials__black'],
					'Boosty'    => ['https://boosty.to/dtp-stat', 'boosty.svg', 'dtp-socials__black'],
				];
				foreach ( $socials as $name => [ $url, $file, $class ] ) {
					printf(
						'<a href="%s" aria-label="%s" class="%s">%s</a>',
						esc_url( $url ),
						esc_attr( $name ),
						esc_attr( $class ),
						file_get_contents( $dir . $file ) // phpcs:ignore
					);
				}
				?>
			</div>
		</div>

	</div>
</footer>

<?php // бургер-меню живёт в assets/js/main.js (грузится на всех страницах:
      // на главной футера нет, и скрипт отсюда не выполнялся) ?>
<?php wp_footer(); ?>
</body>
</html>
