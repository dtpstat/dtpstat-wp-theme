// Общий скрипт темы: бургер-меню. Грузится на ВСЕХ страницах — на главной
// футера нет (front-page.php не зовёт get_footer()), а скрипт раньше жил
// в footer.php, поэтому на главной меню не открывалось.
// Без стрелочных функций — старые WebView падают с SyntaxError.
(function () {
	'use strict';

	var burger = document.querySelector('.dtp-burger');
	var nav = document.querySelector('.dtp-nav');
	if (!burger || !nav) return;
	burger.addEventListener('click', function () {
		nav.classList.toggle('is-open');
	});
})();

// Мост «карта во встроенном iframe → адресная строка сайта» (DTP-49): карта
// при открытии/закрытии попапа ДТП шлёт postMessage, мы правим адрес страницы
// через replaceState (историю не засоряем); встречно при загрузке передаём
// карте параметры ?popup/?center/?zoom со страницы, чтобы ссылка на сайт
// с открытым ДТП открывала попап внутри iframe.
// Без стрелочных функций — старые WebView падают с SyntaxError.
(function () {
	'use strict';
	if (!window.URL || !window.URLSearchParams) return;
	var iframe = document.querySelector('iframe[src*="dtpstat-pages"]');
	if (!iframe) return;
	var mapOrigin;
	try { mapOrigin = new URL(iframe.getAttribute('src'), location.href).origin; } catch (e) { return; }

	// Страница открыта ссылкой с ?popup=… — передаём карте один раз, при её загрузке
	var incoming = new URLSearchParams(location.search);
	if (incoming.has('popup') || incoming.has('center')) {
		var target = new URL(iframe.getAttribute('src'), location.href);
		['popup', 'center', 'zoom'].forEach(function (name) {
			if (incoming.has(name)) target.searchParams.set(name, incoming.get(name));
			else target.searchParams.delete(name);
		});
		iframe.setAttribute('src', target.toString());
	}

	// Попап открылся/закрылся внутри карты — обновляем адрес страницы
	window.addEventListener('message', function (event) {
		if (event.origin !== mapOrigin) return;
		var data = event.data;
		if (!data || data.source !== 'dtpstat-map') return;
		var query = new URLSearchParams(location.search);
		if (data.popup) {
			query.set('popup', data.popup);
			if (data.center) query.set('center', data.center);
			if (data.zoom !== undefined && data.zoom !== null) query.set('zoom', String(data.zoom));
		} else {
			query.delete('popup');
		}
		var search = query.toString();
		history.replaceState(null, '', search ? '?' + search : location.pathname);
	});
})();
