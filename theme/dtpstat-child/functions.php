<?php
/**
 * Functions: dtpstat-child
 * Вёрстка по макету design/ (Figma). Дочерняя тема Neve.
 */

add_action( 'wp_enqueue_scripts', function () {
	$dir = get_stylesheet_directory_uri();
	$ver = '0.2.33';

	// Бургер-меню — на всех страницах (на главной футера нет).
	wp_enqueue_script( 'dtpstat-main', $dir . '/assets/js/main.js', [], $ver, true );

	// Шрифты — раньше стилей.
	wp_enqueue_style( 'dtpstat-fonts', $dir . '/assets/css/fonts.css', [], $ver );
	// Основной стиль темы.
	wp_enqueue_style( 'dtpstat-main', $dir . '/assets/css/main.css', [ 'dtpstat-fonts' ], $ver );
	// Базовый style.css дочерней темы (заголовок темы).
	wp_enqueue_style( 'dtpstat-child', $dir . '/style.css', [ 'dtpstat-main' ], $ver );

	// Стиль родительского Neve не нужен — вся вёрстка своя.
	wp_dequeue_style( 'neve_style' );
	wp_deregister_style( 'neve_style' );

	// Скачивание файлов на странице «Скачать данные».
	if ( is_page( 'download' ) ) {
		wp_enqueue_script( 'dtpstat-download', $dir . '/assets/js/download.js', [], $ver, true );
	}

	// Блог: рабочие фильтры (селекты, месяц, поиск) + светлые чипы на тёмных обложках.
	if ( is_home() || is_search() ) {
		wp_enqueue_script( 'dtpstat-blog', $dir . '/assets/js/blog.js', [], $ver, true );
	}
}, 20 );

/** Меню. */
add_action( 'after_setup_theme', function () {
	register_nav_menus( [
		'primary' => __( 'Главное меню', 'dtpstat-child' ),
		'footer'  => __( 'Меню футера (карта сайта)', 'dtpstat-child' ),
	] );
	add_theme_support( 'post-thumbnails' );
	/*
	 * Форматы картинок = финальные пропорции в вёрстке (hard crop).
	 * Под них плагин crop-thumbnails даёт в админке интерактивный кроп
	 * («подвигать» картинку при назначении), поэтому размеры должны
	 * совпадать с тем, что реально видно на сайте:
	 *   dtp-card   — обложки карточек блога, 16:9 (как в вёрстке);
	 *   dtp-person — фото команды 272×345 @2x;
	 *   dtp-job    — фото вакансий 272×200 @2x;
	 *   dtp-cover  — обложка записи, 16:9 в колонке 856.
	 */
	add_image_size( 'dtp-card', 480, 270, true );
	add_image_size( 'dtp-person', 544, 690, true );
	add_image_size( 'dtp-job', 544, 400, true );
	add_image_size( 'dtp-cover', 856, 482, true );
} );

/** Размер основного контента (колонка 656px по макету + запас для медиа). */
add_filter( 'embed_defaults', fn() => [ 'width' => 856, 'height' => 482 ] );

/** Класс body для страницы «Скачать данные»: её H1 живёт в контенте (рядом с «Инструкция для API»). */
add_filter( 'body_class', function ( $classes ) {
	return is_page( 'download' ) ? array_merge( $classes, [ 'page-download' ] ) : $classes;
} );

/** Карточка поста: категории для чипов (служебные «Без рубрики»/«Uncategorized» не показываем). */
function dtpstat_post_categories( $post_id = null ) {
	$post_id  = $post_id ?: get_the_ID();
	$defaults = [ (int) get_option( 'default_category' ) ];
	$cats     = wp_get_post_categories( $post_id );
	$out      = [];
	foreach ( $cats as $cid ) {
		$t = get_term( $cid );
		if ( $t && ! is_wp_error( $t ) && ! in_array( (int) $cid, $defaults, true )
			&& ! in_array( $t->name, [ 'Без рубрики', 'Uncategorized' ], true ) ) {
			$out[] = $t;
		}
	}
	return $out;
}

/**
 * Данные сбора — ЕДИНСТВЕННЫЙ источник цифр для всех полос и подписей
 * (мини-бар в хедере, полоса в футере, блок на «Поддержать проект»).
 * В макете полоса была нарисована на 78%, а подпись говорила 15 546 из
 * 50 000 (31%) — противоречие макета; по решению Ильи цифра и шкала
 * считаются из одной переменной, так что полоса всегда = raised/goal.
 */
function dtp_fund() {
	static $fund = null;
	if ( null === $fund ) {
		$fund = apply_filters( 'dtp_fund', [
			'raised' => 15546,
			'goal'   => 50000,
			'days'   => 6,
		] );
	}
	return $fund;
}

/** 15546 → «15 546» (в макете пробельные группы тысяч). */
function dtp_num( $n ) {
	return number_format( (float) $n, 0, '', ' ' );
}

/** Русские множественные формы: dtp_plural(6, 'день', 'дня', 'дней') → «дней». */
function dtp_plural( $n, $one, $few, $many ) {
	$n = abs( (int) $n );
	if ( $n % 10 === 1 && $n % 100 !== 11 ) {
		return $one;
	}
	if ( $n % 10 >= 2 && $n % 10 <= 4 && ( $n % 100 < 12 || $n % 100 > 14 ) ) {
		return $few;
	}
	return $many;
}

/** Подпись «До конца сбора N дней» из dtp_fund(). */
function dtp_fund_days() {
	$f = dtp_fund();
	/* translators: %d — дней до конца сбора */
	return sprintf( 'До конца сбора %d %s', (int) $f['days'], dtp_plural( $f['days'], 'день', 'дня', 'дней' ) );
}

/**
 * CSS-переменные сбора: от них считается и ширина заливки полосы
 * (--fund-pct), и растяжка радужного градиента на весь трек
 * (--fund-stretch = goal/raised). Меняется цифра здесь — полосы
 * и подписи согласуются сами.
 */
function dtp_fund_css() {
	$f   = dtp_fund();
	$pct = $f['goal'] > 0 ? $f['raised'] / $f['goal'] : 0;
	printf(
		'<style id="dtp-fund-vars">:root{--fund-pct:%s%%;--fund-stretch:%s;}</style>',
		esc_attr( round( $pct * 100, 2 ) ),
		esc_attr( $pct > 0 ? round( 1 / $pct, 4 ) : 1 )
	);
}
add_action( 'wp_head', 'dtp_fund_css', 1 );

/** Полоса сбора + подпись (шорткод [dtp_fund_bar] в контенте «Поддержать проект»). */
add_shortcode( 'dtp_fund_bar', function () {
	$f = dtp_fund();
	return '<div class="dtp-fund__bar"><i class="dtp-fund__fill"></i></div>'
		. '<div class="dtp-fund__row">'
		. '<span class="dtp-fund__raised">Собрано: ' . esc_html( dtp_num( $f['raised'] ) ) . ' ₽ из ' . esc_html( dtp_num( $f['goal'] ) ) . ' ₽</span>'
		. '<span class="dtp-fund__days">' . esc_html( dtp_fund_days() ) . '</span>'
		. '</div>';
} );

/**
 * Фильтры блога (GET-параметры формы в home.php):
 *   topic  — категория,  order=old — сначала старые,
 *   date   — YYYY-MM-DD (календарь), month — YYYY-MM (старые ссылки), s — поиск.
 */
add_action( 'pre_get_posts', function ( \WP_Query $q ) {
	if ( is_admin() || ! $q->is_main_query() ) {
		return;
	}
	if ( $q->is_home() ) {
		if ( ! empty( $_GET['topic'] ) ) {
			$q->set( 'cat', (int) $_GET['topic'] );
		}
		if ( ( $_GET['order'] ?? '' ) === 'old' ) {
			$q->set( 'orderby', 'date' );
			$q->set( 'order', 'ASC' );
		}
		if ( ! empty( $_GET['date'] ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $_GET['date'], $d ) ) {
			$q->set( 'year', (int) $d[1] );
			$q->set( 'monthnum', (int) $d[2] );
			$q->set( 'day', (int) $d[3] );
		} elseif ( ! empty( $_GET['month'] ) && preg_match( '/^(\d{4})-(\d{2})$/', $_GET['month'], $m ) ) {
			$q->set( 'year', (int) $m[1] );
			$q->set( 'monthnum', (int) $m[2] );
		}
	}
	// поиск «по записям» — ищем только в постах (не в страницах)
	if ( $q->is_search() ) {
		$q->set( 'post_type', 'post' );
	}
} );

/** Результаты поиска по записям показываем сеткой блога (home.php). */
add_filter( 'template_include', function ( $tpl ) {
	return ( is_search() && locate_template( 'home.php' ) ) ? locate_template( 'home.php' ) : $tpl;
} );

/** Обрезка цитаты для карточки. */
function dtpstat_excerpt( $words = 20 ) {
	$text = get_the_excerpt();
	$parts = preg_split( '/\s+/', wp_strip_all_tags( $text ) );
	if ( count( $parts ) > $words ) {
		$parts = array_slice( $parts, 0, $words );
		$text  = implode( ' ', $parts ) . '…';
	}
	return $text;
}

/**
 * Сущности, редактируемые из админки (пункты «Команда» и «Спонсоры» в меню):
 *   dtp_person  — участники проекта: заголовок = имя, цитата = роль,
 *                 миниатюра = фото, «Порядок страницы» = порядок карточек;
 *   dtp_sponsor — спонсоры («Особая благодарность»): заголовок = имя, тот же порядок.
 * На страницы вставляются шорткодами [dtp_team] и [dtp_sponsors] — правка
 * карточек не требует трогать контент страниц.
 */
add_action( 'init', function () {
	register_post_type( 'dtp_person', [
		'labels'            => [
			'name'          => 'Команда',
			'singular_name' => 'Участник команды',
			'add_new_item'  => 'Добавить участника',
			'edit_item'     => 'Редактировать участника',
		],
		'public'            => false,
		'show_ui'           => true,
		'show_in_rest'      => true,
		'rest_base'         => 'team',
		'supports'          => [ 'title', 'excerpt', 'thumbnail', 'page-attributes' ],
		'has_archive'       => false,
		'rewrite'           => false,
		'menu_icon'         => 'dashicons-groups',
		'menu_position'     => 21,
	] );
	register_post_type( 'dtp_sponsor', [
		'labels'            => [
			'name'          => 'Спонсоры',
			'singular_name' => 'Спонсор',
			'add_new_item'  => 'Добавить спонсора',
			'edit_item'     => 'Редактировать спонсора',
		],
		'public'            => false,
		'show_ui'           => true,
		'show_in_rest'      => true,
		'rest_base'         => 'sponsors',
		'supports'          => [ 'title', 'page-attributes' ],
		'has_archive'       => false,
		'rewrite'           => false,
		'menu_icon'         => 'dashicons-heart',
		'menu_position'     => 22,
	] );
} );

/**
 * [dtp_team] — сетка «Команда некоммерческого проекта» на «О проекте»:
 * участники из dtp_person + плитка «Станьте частью команды» (ссылка на join-team).
 */
add_shortcode( 'dtp_team', function () {
	$q = new \WP_Query( [
		'post_type'      => 'dtp_person',
		'posts_per_page' => 50,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	] );
	if ( ! $q->have_posts() ) {
		return '';
	}
	$out = '<div class="dtp-team">';
	foreach ( $q->posts as $person ) {
		$img = get_the_post_thumbnail( $person, 'dtp-person', [
			'class' => 'dtp-person__img',
			'alt'   => get_the_title( $person ),
		] );
		// без фото — серая заглушка той же карточки 272×345
		$out .= '<div class="dtp-person">' . ( $img ?: '<div class="dtp-person__img"></div>' )
			. '<div class="dtp-person__name">' . esc_html( get_the_title( $person ) ) . '</div>'
			. '<div class="dtp-person__role">' . esc_html( $person->post_excerpt ) . '</div></div>';
	}
	$out .= '<a class="dtp-person dtp-person--join" href="' . esc_url( home_url( '/join-team/' ) ) . '">'
		. '<div class="dtp-plus">+</div><div>Станьте частью<br>команды</div></a></div>';
	return $out;
} );

/** [dtp_sponsors] — «Особая благодарность»: имена через красные точки. */
add_shortcode( 'dtp_sponsors', function () {
	$q = new \WP_Query( [
		'post_type'      => 'dtp_sponsor',
		'posts_per_page' => 50,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	] );
	$names = wp_list_pluck( $q->posts, 'post_title' );
	if ( ! $names ) {
		return '';
	}
	return '<div class="dtp-dots"><b>' . implode( '</b><i></i><b>', array_map( 'esc_html', $names ) ) . '</b></div>';
} );

/**
 * [dtp_vacancies] — карточки «Станьте частью команды» собираются автоматически
 * из записей с меткой «Вакансия» (slug vacancy): фото = миниатюра записи,
 * заголовок = название записи (ссылка ведёт на полный текст), скилы = первый
 * <ul> из текста записи («вычленяются» из него), кнопка — письмо на почту.
 */
add_shortcode( 'dtp_vacancies', function () {
	$tag = get_term_by( 'slug', 'vacancy', 'post_tag' );
	if ( ! $tag ) {
		return '';
	}
	$q = new \WP_Query( [
		'post_type'      => 'post',
		'tag_id'         => $tag->term_id,
		'posts_per_page' => 12,
		'no_found_rows'  => true,
	] );
	if ( ! $q->have_posts() ) {
		// подсказка видна только редакторам — посетителям пусто
		return current_user_can( 'edit_posts' )
			? '<p><em>Вакансий нет: создайте запись с меткой «Вакансия», и карточка появится здесь сама.</em></p>'
			: '';
	}
	$out = '<div class="dtp-jobs">';
	foreach ( $q->posts as $vacancy ) {
		$title = get_the_title( $vacancy );
		$img   = get_the_post_thumbnail( $vacancy, 'dtp-job', [ 'class' => 'dtp-job__img', 'alt' => $title ] );
		$out  .= '<div class="dtp-job">' . ( $img ?: '<div class="dtp-job__img"></div>' )
			. '<h3 class="dtp-job__title"><a href="' . esc_url( get_permalink( $vacancy ) ) . '">' . esc_html( $title ) . '</a></h3>';
		// скилы: первый <ul> в тексте записи (убрав gutenberg-комментарии)
		if ( preg_match( '/<ul[^>]*>(.*?)<\/ul>/s', $vacancy->post_content, $ul ) ) {
			$inner = preg_replace( '/<!--.*?-->/s', '', $ul[1] );
			if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/s', $inner, $lis ) ) {
				$out .= '<p>Если вы:</p><ul>';
				foreach ( $lis[1] as $li ) {
					$out .= '<li>' . wp_kses_post( trim( $li ) ) . '</li>';
				}
				$out .= '</ul>';
			}
		}
		// футер-обёртка: авто-отступ сверху прижимает кнопку к низу плитки,
		// чтобы в ряду карточек кнопки стояли на одной высоте
		$out .= '<div class="dtp-job__footer"><a class="dtp-btn" href="mailto:dtp.stat@gmail.com?subject='
			. rawurlencode( 'Волонтёрство: ' . $title ) . '">Откликнуться</a></div></div>';
	}
	return $out . '</div>';
} );
