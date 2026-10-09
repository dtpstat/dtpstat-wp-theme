// Общий скрипт темы: бургер-меню. Грузится на ВСЕХ страницах — на главной
// футера нет (front-page.php не зовёт get_footer()), а скрипт раньше жил
// в footer.php, поэтому на главной меню не открывалось.
// Без стрелочных функций — старые WebView падают с SyntaxError.
(function () {
	'use strict';

	// Deep-link карты (?popup/?center/?zoom/?region/?lang) живёт на адресе
	// WP-страницы, а айфрейм карты грузит pages.dev без параметров —
	// пересылаем их ему в src. Меняем src только при наличии параметров,
	// обычные визиты айфрейм не перезагружают
	var mapFrame = document.querySelector('.dtp-map iframe');
	if (mapFrame && window.URLSearchParams) {
		var incoming = new URLSearchParams(location.search);
		var forward = new URLSearchParams();
		['popup', 'center', 'zoom', 'region', 'lang'].forEach(function (name) {
			if (incoming.has(name)) forward.set(name, incoming.get(name));
		});
		var forwardQuery = forward.toString();
		if (forwardQuery) mapFrame.src = mapFrame.src.split('?')[0] + '?' + forwardQuery;
	}

	var burger = document.querySelector('.dtp-burger');
	var nav = document.querySelector('.dtp-nav');
	if (!burger || !nav) return;
	burger.addEventListener('click', function () {
		nav.classList.toggle('is-open');
	});

	// Мост «попап карты → адресная строка»: айфрейм карты шлёт
	// {source:'dtpstat-map', popup:<EM_NUMBER>|null} — адрес меняется на
	// /dtp/<id>/ (карточка ДТП), закрытие попапа возвращает адрес главной.
	// Карточка (/dtp/<id>/) шлёт такое же сообщение при загрузке — для неё
	// замена адреса на саму себя безвредна.
	window.addEventListener('message', function (e) {
		var d = e.data;
		if (!d || d.source !== 'dtpstat-map') return;
		// Айтрейм спрашивает origin сайта: ссылки «Подробнее о ДТП» и
		// «Показать ДТП рядом» должны оставаться на нашем домене
		if (d.ask === 'origin' && e.source) {
			e.source.postMessage({ source: 'dtpstat-site', origin: location.origin, token: d.token }, '*');
			return;
		}
		if (!history.replaceState) return;
		if (d.popup && /^\d+$/.test(String(d.popup))) {
			history.replaceState(null, '', '/dtp/' + d.popup + '/');
		} else if (d.popup === null) {
			history.replaceState(null, '', '/');
		}
	});
})();
