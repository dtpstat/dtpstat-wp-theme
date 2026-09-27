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
