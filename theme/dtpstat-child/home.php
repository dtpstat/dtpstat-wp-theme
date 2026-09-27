<?php
/**
 * Страница блога (posts page) и результаты поиска: сетка карточек по макету 123:385.
 * Фильтры («Все темы», «Сначала новые», календарь, поиск) — рабочие:
 * GET-параметры обрабатываются в functions.php (pre_get_posts).
 *
 * @package dtpstat-child
 */
get_header();

$blog_id = (int) get_option( 'page_for_posts' );
$topic   = (int) ( $_GET['topic'] ?? 0 );
$order   = ( $_GET['order'] ?? 'new' );
$month   = ( $_GET['month'] ?? '' );
$date    = ( $_GET['date'] ?? '' );

// месяцы с заглавной — как в шапке календаря макета («Сентябрь»)
$month_names = [ 1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
	'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь' ];

// календарь из макета (271:473 «блог и исследования + календарь»):
// для каждого месяца — дни, в которые есть записи (подсвечиваются в сетке)
global $wpdb;
$days_map = [];
$months   = [];
foreach ( $wpdb->get_results(
	"SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, DAYOFMONTH(post_date) AS d
	 FROM {$wpdb->posts}
	 WHERE post_type = 'post' AND post_status = 'publish'
	 GROUP BY y, m, d ORDER BY y, m, d"
) as $r ) {
	$key          = sprintf( '%04d-%02d', $r->y, $r->m );
	$days_map[ $key ][] = (int) $r->d;
	if ( ! in_array( $key, $months, true ) ) {
		$months[] = $key;
	}
}

// служебные категории («Без рубрики»/«Uncategorized») в фильтр не даём — как в чипах
$skip_cats = [ (int) get_option( 'default_category' ), 'Без рубрики', 'Uncategorized' ];

$active = [];
if ( $topic && ( $t = get_term( $topic ) ) && ! is_wp_error( $t ) ) {
	$active[] = 'тема: ' . $t->name;
}
if ( $date && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dd ) ) {
	$active[] = 'дата: ' . $month_names[ (int) $dd[2] ] . ' ' . (int) $dd[3] . ', ' . $dd[1];
} elseif ( $month && preg_match( '/^(\d{4})-(\d{2})$/', $month, $mm ) ) {
	$active[] = 'месяц: ' . $month_names[ (int) $mm[2] ] . ' ' . $mm[1];
}
if ( get_search_query() ) {
	$active[] = 'поиск: «' . get_search_query() . '»';
}
if ( 'old' === $order ) {
	$active[] = 'сначала старые';
}
?>
<div class="dtp-container dtp-page dtp-page--left">

	<div class="dtp-blog__head">
		<h1 class="dtp-blog__title"><?php echo esc_html( get_the_title( $blog_id ) ?: 'Блог и исследования' ); ?></h1>
		<form class="dtp-blog__filters<?php echo get_search_query() ? ' is-searching' : ''; ?>"
		method="get" action="<?php echo esc_url( get_permalink( $blog_id ) ?: home_url( '/' ) ); ?>">

			<?php
			// Дропдауны «Все темы»/«Сначала новые» — чипы input 130/146×30 из макета;
			// раскрытый список сверстан по мотивам чек-листа проф-фильтра (64:1291):
			// белая панель с тенью хедера, у выбранного пункта квадрат-галочка.
			// Нативный select не стилизуется до конца — поэтому кастом на hidden-input.
			$topics = [];
			foreach ( get_categories( [ 'hide_empty' => false ] ) as $c ) {
				if ( in_array( (int) $c->term_id, array_filter( $skip_cats, 'is_int' ), true )
					|| in_array( $c->name, $skip_cats, true ) ) {
					continue;
				}
				$topics[] = $c;
			}
			$current_topic = 0;
			foreach ( $topics as $c ) {
				if ( (int) $c->term_id === $topic ) {
					$current_topic = $topic;
				}
			}
			?>
			<div class="dtp-dd dtp-dd--topic">
				<button type="button" class="dtp-select dtp-dd__btn" aria-haspopup="listbox" aria-expanded="false">
					<span class="dtp-dd__label"><?php
						echo esc_html( $current_topic ? get_term( $current_topic )->name : 'Все темы' );
					?></span>
				</button>
				<div class="dtp-dd__menu" role="listbox" hidden>
					<button type="button" class="dtp-dd__item<?php echo $current_topic ? '' : ' is-selected'; ?>" data-value="0">Все темы</button>
					<?php foreach ( $topics as $c ) : ?>
						<button type="button" class="dtp-dd__item<?php echo (int) $c->term_id === $current_topic ? ' is-selected' : ''; ?>"
							data-value="<?php echo (int) $c->term_id; ?>"><?php echo esc_html( $c->name ); ?></button>
					<?php endforeach; ?>
				</div>
				<input type="hidden" name="topic" value="<?php echo (int) $current_topic; ?>">
			</div>

			<div class="dtp-dd dtp-dd--order">
				<button type="button" class="dtp-select dtp-dd__btn" aria-haspopup="listbox" aria-expanded="false">
					<span class="dtp-dd__label"><?php echo esc_html( 'old' === $order ? 'Сначала старые' : 'Сначала новые' ); ?></span>
				</button>
				<div class="dtp-dd__menu" role="listbox" hidden>
					<button type="button" class="dtp-dd__item<?php echo 'old' === $order ? '' : ' is-selected'; ?>" data-value="new">Сначала новые</button>
					<button type="button" class="dtp-dd__item<?php echo 'old' === $order ? ' is-selected' : ''; ?>" data-value="old">Сначала старые</button>
				</div>
				<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
			</div>

			<?php
			// календарь из макета (Group 100, 271:692): 226×187, шапка «‹ год › ‹ месяц ›»,
			// сетка 26×26 с шагом 30; дни с записями — #B7D7E6, выбранный — navy.
			// Иконка в кнопке НЕ меняется при открытии (в макете у попапа своя иконка).
			// Сами дни достраивает blog.js из data-атрибутов.
			$cal_selected = $date && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $cd )
				? sprintf( '%04d-%02d-%02d', $cd[1], $cd[2], $cd[3] ) : '';
			$cal_open_month = $cal_selected ? substr( $cal_selected, 0, 7 )
				: ( $months ? $months[ count( $months ) - 1 ] : gmdate( 'Y-m' ) );
			?>
			<div class="dtp-calwrap<?php echo ( $cal_selected || $month ) ? ' has-value' : ''; ?>">
				<input type="hidden" name="date" value="<?php echo esc_attr( $cal_selected ); ?>">
				<button class="dtp-icbtn" type="button" data-cal aria-label="Фильтр по дате" aria-expanded="false">
					<?php echo file_get_contents( get_stylesheet_directory() . '/assets/img/calendar.svg' ); // phpcs:ignore ?>
				</button>
				<div class="dtp-cal" hidden
					data-days="<?php echo esc_attr( wp_json_encode( $days_map ) ); ?>"
					data-open="<?php echo esc_attr( $cal_open_month ); ?>"
					data-selected="<?php echo esc_attr( $cal_selected ); ?>"></div>
			</div>

			<?php // лупа из макета (INSTANCE 264:1972): по клику «Все темы»,
			      // «Сначала новые» и календарь прячутся, на их месте
			      // разворачивается широкое поле; лупа одна — она же кнопка
			      // закрытия поиска (см. is-searching в main.css) ?>
			<button class="dtp-icbtn" type="button" data-toggle="dtp-search" aria-label="Поиск по записям" aria-expanded="<?php echo get_search_query() ? 'true' : 'false'; ?>">
				<?php echo file_get_contents( get_stylesheet_directory() . '/assets/img/search.svg' ); // phpcs:ignore ?>
			</button>
			<span class="dtp-searchpop" id="dtp-search"<?php echo get_search_query() ? '' : ' hidden'; ?>>
				<span class="dtp-search dtp-search--blog">
					<input type="search" name="s" placeholder="Поиск по записям" value="<?php echo esc_attr( get_search_query() ); ?>">
				</span>
			</span>

		</form>
	</div>

	<?php if ( $active ) : ?>
		<p class="dtp-blog__active">
			<?php echo esc_html( implode( ' · ', $active ) ); ?>
			<a href="<?php echo esc_url( get_permalink( $blog_id ) ); ?>">сбросить</a>
		</p>
	<?php endif; ?>

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
		the_posts_pagination( [
			'mid_size'  => 1,
			'prev_text' => '←',
			'next_text' => '→',
			// пагинация не должна терять активные фильтры
			'add_args'  => array_filter( [
				'topic' => $topic ?: null,
				'order' => 'new' !== $order ? $order : null,
				'date'  => $date ?: null,
				's'     => get_search_query() ?: null,
			] ),
		] );
		?>

	<?php else : ?>
		<p class="dtp-blog__empty"><?php echo get_search_query() ? 'Ничего не найдено. Попробуйте изменить запрос.' : 'Постов пока нет.'; ?></p>
	<?php endif; ?>

</div>
<?php
get_footer();
