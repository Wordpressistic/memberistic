(function () {
	'use strict';

	function setCycle(root, cycle) {
		var prices = root.querySelectorAll('.memberistic-price-value');
		var labels = root.querySelectorAll('.memberistic-price-cycle');
		var buttons = root.querySelectorAll('[data-memberistic-cycle]');

		prices.forEach(function (price) {
			var value = price.getAttribute('data-' + cycle);
			price.textContent = '$' + Number(value || 0).toFixed(2);
		});

		labels.forEach(function (label) {
			label.textContent = cycle === 'annual' ? '/ year' : '/ month';
		});

		buttons.forEach(function (button) {
			button.classList.toggle('is-active', button.getAttribute('data-memberistic-cycle') === cycle);
		});
	}

	document.addEventListener('click', function (event) {
		var toastClose = event.target.closest('[data-memberistic-toast-close]');
		if (toastClose) {
			var toast = toastClose.closest('.memberistic-toast');
			if (toast) toast.remove();
			return;
		}

		var button = event.target.closest('[data-memberistic-cycle]');

		if (!button) {
			return;
		}

		var root = button.closest('.memberistic-plans-grid-wrap');

		if (!root) {
			return;
		}

		setCycle(root, button.getAttribute('data-memberistic-cycle'));
	});

	document.addEventListener('DOMContentLoaded', function () {
		var toast = document.querySelector('.memberistic-toast');
		if (toast) {
			window.setTimeout(function () {
				toast.classList.add('is-visible');
			}, 60);
		}
	});
})();
