/**
 * Страница «Скачать данные»: поиск по строкам регионов.
 * Кнопки скачивания — прямые ссылки на выгрузки dtp-stat.ru/opendata
 * (строки генерирует bin/apply-design.py), поэтому fetch к API не нужен.
 */
(function () {
	var search = document.querySelector('.js-dl-search');
	if (search) {
		search.addEventListener('input', function () {
			var v = search.value.trim().toLowerCase();
			document.querySelectorAll('.js-dl-row').forEach(function (row) {
				row.style.display = !v || row.dataset.name.toLowerCase().includes(v) ? '' : 'none';
			});
		});
	}

	// «Инструкция для API» в шапке страницы раскрывает скрытую секцию с
	// инструкцией (без JS работает как обычный якорь на #dtp-api)
	var api = document.querySelector('.js-acc');
	if (api) {
		api.addEventListener('click', function (e) {
			var acc = document.getElementById(api.dataset.target);
			if (!acc) return;
			e.preventDefault();
			acc.open = !acc.open;
			if (acc.open) acc.scrollIntoView();
		});
	}
})();
