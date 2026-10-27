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
					<img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/img/logo.svg' ); ?>" width="2280" height="340" alt="Карта ДТП">
				</a>
				<p>Проект посвящен проблеме дорожно-транспортных происшествий в России. Цель проекта — повышение безопасности дорожного движения и снижение смертности в ДТП.</p>
			</div>

			<div class="dtp-fcol">
				<h4>Связаться:</h4>
				<?php $ic = get_stylesheet_directory() . '/assets/img/social/'; ?>
				<?php
				$contact_telegram = dtp_external_url( 'DTP_CONTACT_TELEGRAM_URL' );
				$contact_label = dtp_env( 'DTP_CONTACT_TELEGRAM_LABEL', $contact_telegram );
				$contact_email = sanitize_email( dtp_env( 'DTP_CONTACT_EMAIL' ) );
				?>
				<?php if ( $contact_telegram ) : ?>
					<p class="dtp-contact dtp-contact--icon">
						<?php echo file_get_contents( $ic . 'telegram-sm.svg' ); // phpcs:ignore ?>
						<a href="<?php echo esc_url( $contact_telegram ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $contact_label ); ?></a>
					</p>
				<?php endif; ?>
				<?php if ( is_email( $contact_email ) ) : ?>
					<p class="dtp-contact dtp-contact--icon">
						<?php echo file_get_contents( get_stylesheet_directory() . '/assets/img/envelope-sm.svg' ); // phpcs:ignore ?>
						<a href="<?php echo esc_url( 'mailto:' . $contact_email ); ?>"><?php echo esc_html( $contact_email ); ?></a>
					</p>
				<?php endif; ?>
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
				// Порядок и иконки — из макета; внешние URL задаются в .env WordPress.
				$dir = get_stylesheet_directory() . '/assets/img/social/';
				$socials = [
					'Telegram'  => [dtp_external_url( 'DTP_SOCIAL_TELEGRAM_URL' ), 'telegram.svg', ''],
					'X'         => [dtp_external_url( 'DTP_SOCIAL_X_URL' ), 'twitter.svg', ''],
					'Instagram' => [dtp_external_url( 'DTP_SOCIAL_INSTAGRAM_URL' ), 'instagram.svg', ''],
					'Patreon'   => [dtp_external_url( 'DTP_SOCIAL_PATREON_URL' ), 'patreon.svg', 'dtp-socials__black'],
					'Boosty'    => [dtp_external_url( 'DTP_SOCIAL_BOOSTY_URL' ), 'boosty.svg', 'dtp-socials__black'],
				];
				foreach ( $socials as $name => [ $url, $file, $class ] ) {
					if ( ! $url ) {
						continue;
					}
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
