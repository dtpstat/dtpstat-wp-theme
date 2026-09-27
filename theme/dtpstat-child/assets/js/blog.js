// Блог: фильтры (дропдауны «Все темы»/«Сначала новые», календарь из макета
// 271:473, поиск) и светлые чипы на тёмных обложках.
// Без стрелочных функций и опциональных цепочек — старые WebView падают
// с SyntaxError и молча теряют все фильтры.
(function () {
	'use strict';

	var form = document.querySelector('.dtp-blog__filters');

	// ---- дропдауны (чип = кнопка, список = белая панель с тенью хедера) ----
	function closeAllDropdowns(except) {
		form.querySelectorAll('.dtp-dd').forEach(function (dd) {
			if (dd === except) return;
			dd.classList.remove('is-open');
			var menu = dd.querySelector('.dtp-dd__menu');
			var btn = dd.querySelector('.dtp-dd__btn');
			if (menu) menu.hidden = true;
			if (btn) btn.setAttribute('aria-expanded', 'false');
		});
	}

	// чистые URL: пустые и дефолтные значения в GET не тащим
	// (disabled-поля не сабмитятся)
	function cleanValues() {
		form.querySelectorAll('input, select').forEach(function (el) {
			var v = el.value;
			if (v === ''
				|| (el.name === 'topic' && v === '0')
				|| (el.name === 'order' && v === 'new')) {
				el.disabled = true;
			}
		});
	}

	function submitClean() {
		cleanValues();
		form.submit();
	}

	if (form) {
		// нативный сабмит (Enter в поле поиска)
		form.addEventListener('submit', cleanValues);

		form.querySelectorAll('.dtp-dd').forEach(function (dd) {
			var btn = dd.querySelector('.dtp-dd__btn');
			var menu = dd.querySelector('.dtp-dd__menu');
			var input = dd.querySelector('input[type="hidden"]');
			var label = dd.querySelector('.dtp-dd__label');
			if (!btn || !menu || !input) return;

			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				var open = dd.classList.contains('is-open');
				closeAllDropdowns();
				closeCalendar();
				closeSearch();
				if (!open) {
					dd.classList.add('is-open');
					menu.hidden = false;
					btn.setAttribute('aria-expanded', 'true');
				}
			});

			menu.querySelectorAll('.dtp-dd__item').forEach(function (item) {
				item.addEventListener('click', function () {
					input.value = item.dataset.value;
					if (label) label.textContent = item.textContent;
					menu.querySelectorAll('.dtp-dd__item').forEach(function (i) {
						i.classList.toggle('is-selected', i === item);
					});
					submitClean(); // кнопки «применить» в макете нет
				});
			});
		});

		// клик мимо и Esc закрывают всё открытое
		document.addEventListener('click', function (e) {
			if (!e.target.closest || !e.target.closest('.dtp-dd, .dtp-calwrap, .dtp-searchpop')) {
				closeAllDropdowns();
				closeCalendar();
				closeSearch();
			}
		});
		document.addEventListener('keyup', function (e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				closeAllDropdowns();
				closeCalendar();
				closeSearch();
			}
		});
	}

	// ---- календарь (макет Group 100, 271:692: панель 226×N, клетки 26×26 с
	//      шагом 30; дни с записями #B7D7E6, выбранный — navy) ----
	var MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
		'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
	var calWrap = form ? form.querySelector('.dtp-calwrap') : null;
	var calBtn = calWrap ? calWrap.querySelector('[data-cal]') : null;
	var calBox = calWrap ? calWrap.querySelector('.dtp-cal') : null;
	var calDays = {};      // 'YYYY-MM' → [дни с записями]
	var calOpen = null;    // 'YYYY-MM' показанного месяца
	var calSelected = '';  // 'YYYY-MM-DD' активного фильтра

	function pad2(n) { return (n < 10 ? '0' : '') + n; }

	function monthShift(ym, delta) {
		var y = parseInt(ym.slice(0, 4), 10);
		var m = parseInt(ym.slice(5, 7), 10) - 1 + delta;
		y += Math.floor(m / 12);
		m = ((m % 12) + 12) % 12;
		return y + '-' + pad2(m + 1);
	}

	function closeCalendar() {
		if (!calBox || calBox.hidden) return;
		calBox.hidden = true;
		calBox.innerHTML = '';
		if (calBtn) calBtn.setAttribute('aria-expanded', 'false');
	}

	function renderCalendar() {
		if (!calBox) return;
		var y = parseInt(calOpen.slice(0, 4), 10);
		var m = parseInt(calOpen.slice(5, 7), 10); // 1..12
		var first = (new Date(y, m - 1, 1).getDay() + 6) % 7; // Пн=0
		var total = new Date(y, m, 0).getDate();
		var rows = Math.ceil((first + total) / 7);
		var prevTotal = new Date(y, m - 1, 0).getDate();
		var days = calDays[calOpen] || [];
		var i, d;

		var html = '<div class="dtp-cal__head">'
			+ '<button type="button" class="dtp-cal__nav" data-yeardelta="-1" aria-label="Предыдущий год"><svg width="15" height="15" viewBox="0 0 15 15"><path d="M9.5 3L5 7.5L9.5 12" fill="none" stroke="currentColor" stroke-width="1.4"/></svg></button>'
			+ '<span class="dtp-cal__ym dtp-cal__year">' + y + '</span>'
			+ '<button type="button" class="dtp-cal__nav" data-yeardelta="1" aria-label="Следующий год"><svg width="15" height="15" viewBox="0 0 15 15"><path d="M5.5 3L10 7.5L5.5 12" fill="none" stroke="currentColor" stroke-width="1.4"/></svg></button>'
			+ '<button type="button" class="dtp-cal__nav" data-step="-1" aria-label="Предыдущий месяц"><svg width="15" height="15" viewBox="0 0 15 15"><path d="M9.5 3L5 7.5L9.5 12" fill="none" stroke="currentColor" stroke-width="1.4"/></svg></button>'
			+ '<span class="dtp-cal__ym dtp-cal__month">' + MONTHS[m - 1] + '</span>'
			+ '<button type="button" class="dtp-cal__nav" data-step="1" aria-label="Следующий месяц"><svg width="15" height="15" viewBox="0 0 15 15"><path d="M5.5 3L10 7.5L5.5 12" fill="none" stroke="currentColor" stroke-width="1.4"/></svg></button>'
			// крестик в правом углу шапки закрывает попап (в макете вместо иконки)
			+ '<button type="button" class="dtp-cal__close" aria-label="Закрыть календарь"><svg width="10" height="10" viewBox="0 0 10 10"><path d="M1 1l8 8M9 1L1 9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></button>'
			+ '</div><div class="dtp-cal__grid">';

		for (i = 0; i < rows * 7; i++) {
			d = i - first + 1;
			if (d < 1) {
				// хвост предыдущего месяца — серые номера, как в макете («31»)
				html += '<span class="dtp-cal__cell is-out">' + (prevTotal + d) + '</span>';
			} else if (d > total) {
				html += '<span class="dtp-cal__cell is-out">' + (d - total) + '</span>';
			} else {
				var ym = calOpen;
				var full = ym + '-' + pad2(d);
				var has = days.indexOf(d) !== -1;
				var cls = 'dtp-cal__cell' + (has ? ' has-posts' : '');
				if (full === calSelected) cls += ' is-selected';
				html += '<button type="button" class="' + cls + '" data-date="' + full + '"'
					+ (has ? '' : ' disabled') + '>' + d + '</button>';
			}
		}
		html += '</div>';
		calBox.innerHTML = html;
		calBox.hidden = false;

		calBox.querySelectorAll('[data-step]').forEach(function (b) {
			b.addEventListener('click', function (e) {
				e.stopPropagation();
				var next = monthShift(calOpen, parseInt(b.dataset.step, 10));
				var keys = Object.keys(calDays);
				if (keys.length) {
					var min = keys[0], max = keys[keys.length - 1];
					if (next < min || next > max) return; // за пределы архива не выходим
				}
				calOpen = next;
				renderCalendar();
			});
		});
		calBox.querySelectorAll('[data-yeardelta]').forEach(function (b) {
			b.addEventListener('click', function (e) {
				e.stopPropagation();
				var next = monthShift(calOpen, parseInt(b.dataset.yeardelta, 10) * 12);
				var keys = Object.keys(calDays);
				if (keys.length) {
					var min = keys[0], max = keys[keys.length - 1];
					if (next < min || next > max) return;
				}
				calOpen = next;
				renderCalendar();
			});
		});
		calBox.querySelectorAll('.dtp-cal__cell[data-date]').forEach(function (b) {
			b.addEventListener('click', function (e) {
				e.stopPropagation();
				var dateInput = form.querySelector('input[name="date"]');
				if (!dateInput) return;
				// повторный клик по выбранному дню сбрасывает фильтр
				dateInput.value = calSelected === b.dataset.date ? '' : b.dataset.date;
				submitClean();
			});
		});
		var close = calBox.querySelector('.dtp-cal__close');
		if (close) {
			close.addEventListener('click', function (e) {
				e.stopPropagation();
				closeCalendar();
			});
		}
	}

	if (calWrap && calBtn && calBox) {
		try {
			calDays = JSON.parse(calBox.dataset.days) || {};
		} catch (e) {
			calDays = {};
		}
		calSelected = calBox.dataset.selected || '';
		calOpen = calBox.dataset.open || calOpen || Object.keys(calDays)[0];

		// иконка-кнопка НЕ меняется при открытии — у попапа своя кнопка-крестик
		calBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			var open = !calBox.hidden;
			closeAllDropdowns();
			closeSearch();
			if (open) {
				closeCalendar();
			} else {
				renderCalendar();
				calBtn.setAttribute('aria-expanded', 'true');
			}
		});
	}

	// ---- поиск: НЕ поповер. Лупа разворачивает широкое поле на месте
	//      «Все темы»/«Сначала новые»/календаря (form.is-searching), сама
	//      остаётся справа от поля одной лупой; спрятали поле — сбросили запрос ----
	var searchPop = document.getElementById('dtp-search');

	function closeSearch() {
		if (!searchPop || searchPop.hidden) return;
		searchPop.hidden = true;
		form.classList.remove('is-searching');
		if (searchBtn) searchBtn.setAttribute('aria-expanded', 'false');
		var i = searchPop.querySelector('input');
		if (i) i.value = ''; // спрятали — гасим значение, чтобы GET не тащил
	}

	if (form && searchPop) {
		var searchBtn = form.querySelector('[data-toggle="dtp-search"]');
		if (searchBtn) {
			searchBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				var open = !searchPop.hidden;
				closeAllDropdowns();
				closeCalendar();
				if (open) {
					closeSearch();
				} else {
					form.classList.add('is-searching');
					searchPop.hidden = false;
					searchBtn.setAttribute('aria-expanded', 'true');
					var inner = searchPop.querySelector('input');
					if (inner && inner.focus) inner.focus();
				}
			});
		}
	}

	// ---- тёмная обложка → светлые чипы ----
	// В макете у чипов два вида: голубые #B7D7E6 (Frame 615-618) и белые
	// (Frame 619/620) — для тёмных фото. Яркость меряем по нижней трети
	// обложки (чипы лежат именно там).
	var THRESHOLD = 140;
	document.querySelectorAll('.dtp-card__img').forEach(function (box) {
		var img = box.querySelector('img');
		if (!img || !box.querySelector('.dtp-chip')) return;
		var measure = function () {
			var w = 50;
			var h = Math.max(1, Math.round(w * (img.naturalHeight / (img.naturalWidth || 1)) || 1));
			var cv = document.createElement('canvas');
			cv.width = w;
			cv.height = h;
			var ctx = cv.getContext('2d', { willReadFrequently: true });
			if (!ctx) return;
			ctx.drawImage(img, 0, 0, w, h);
			var y0 = Math.round(h * 0.7);
			var data;
			try {
				data = ctx.getImageData(0, y0, w, h - y0); // same-origin — иначе исключение
			} catch (e) {
				return;
			}
			var sum = 0, n = 0;
			for (var i = 0; i < data.data.length; i += 4) {
				sum += 0.2126 * data.data[i] + 0.7152 * data.data[i + 1] + 0.0722 * data.data[i + 2];
				n++;
			}
			if (n && sum / n < THRESHOLD) {
				box.querySelectorAll('.dtp-chip').forEach(function (c) {
					c.classList.add('dtp-chip--light');
				});
			}
		};
		if (img.complete && img.naturalWidth) {
			measure();
		} else {
			img.addEventListener('load', measure, { once: true });
		}
	});
})();
