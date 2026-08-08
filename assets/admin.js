(function ($) {
	'use strict';

	$(document).on('click', '.memberistic-delete-link', function (event) {
		if (!window.confirm(window.memberisticAdmin && window.memberisticAdmin.confirmDelete ? window.memberisticAdmin.confirmDelete : 'Are you sure?')) {
			event.preventDefault();
		}
	});

	function activateSettingsTab(tab) {
		var selected = tab || 'general';
		$('.memberistic-tab[data-memberistic-tab]').removeClass('is-active').attr('aria-selected', 'false');
		$('.memberistic-tab[data-memberistic-tab="' + selected + '"]').addClass('is-active').attr('aria-selected', 'true');
		$('.memberistic-tab-panel[data-memberistic-panel]').removeClass('is-active').attr('hidden', true);
		$('.memberistic-tab-panel[data-memberistic-panel="' + selected + '"]').addClass('is-active').removeAttr('hidden');
	}

	$(document).on('click', '.memberistic-tab[data-memberistic-tab]', function () {
		var tab = $(this).data('memberistic-tab');
		activateSettingsTab(tab);
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', '#memberistic-' + tab);
		}
	});

	$(function () {
		var hashTab = window.location.hash.replace('#memberistic-', '');
		if ($('.memberistic-tab[data-memberistic-tab="' + hashTab + '"]').length) {
			activateSettingsTab(hashTab);
		} else {
			activateSettingsTab('general');
		}
	});
})(jQuery);
