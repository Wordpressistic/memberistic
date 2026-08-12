/**
 * Memberistic — Premium Activity Console
 *
 * Filterable activity timeline with type filter, date range, and search.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element || !wp.apiFetch) return;

	const { createElement: h, Fragment, useState, useEffect, render } = wp.element;
	const apiFetch = wp.apiFetch;
	const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
	const sprintf = (wp.i18n && wp.i18n.sprintf) ? wp.i18n.sprintf : function (s) { return s; };

	const ROOT_ID = 'memberistic-activity-app';
	const NS = 'memberistic/v1';

	function statusLabel(s) { return String(s || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }
	function relativeTime(iso) {
		if (!iso) return '';
		const ts = Date.parse(iso.replace(' ', 'T') + 'Z');
		if (isNaN(ts)) return iso;
		const diff = Math.round((Date.now() - ts) / 1000);
		if (diff < 60) return __('just now', 'memberistic');
		if (diff < 3600) return sprintf(__('%dm ago', 'memberistic'), Math.round(diff / 60));
		if (diff < 86400) return sprintf(__('%dh ago', 'memberistic'), Math.round(diff / 3600));
		if (diff < 2592000) return sprintf(__('%dd ago', 'memberistic'), Math.round(diff / 86400));
		return new Date(ts).toLocaleDateString();
	}
	function formatDateTime(iso) {
		if (!iso) return '—';
		const ts = Date.parse(iso.replace(' ', 'T') + 'Z');
		return isNaN(ts) ? iso : new Date(ts).toLocaleString();
	}
	function useDebounced(value, delay) {
		const [v, setV] = useState(value);
		useEffect(function () { const id = window.setTimeout(function () { setV(value); }, delay); return function () { window.clearTimeout(id); }; }, [value, delay]);
		return v;
	}

	function ActivityApp() {
		const [filters, setFilters] = useState({ search: '', activity_type: '', from: '', to: '' });
		const debouncedSearch = useDebounced(filters.search, 250);

		const [types, setTypes] = useState([]);
		const [rows, setRows] = useState([]);
		const [loading, setLoading] = useState(true);
		const [error, setError] = useState(null);

		useEffect(function () {
			apiFetch({ path: '/' + NS + '/activity/types' }).then(
				function (data) { setTypes(Array.isArray(data) ? data : []); },
				function () { /* silent */ }
			);
		}, []);

		useEffect(function () {
			const params = new URLSearchParams();
			if (debouncedSearch) params.set('search', debouncedSearch);
			if (filters.activity_type) params.set('activity_type', filters.activity_type);
			if (filters.from) params.set('from', filters.from + ' 00:00:00');
			if (filters.to) params.set('to', filters.to + ' 23:59:59');
			params.set('limit', '200');

			setLoading(true);
			apiFetch({ path: '/' + NS + '/activity?' + params.toString() }).then(
				function (data) { setRows(Array.isArray(data) ? data : []); setLoading(false); },
				function (err) {
					setError((err && err.message) || __('Could not load activity.', 'memberistic'));
					setLoading(false);
				}
			);
		}, [debouncedSearch, filters.activity_type, filters.from, filters.to]);

		function update(field) { return function (e) { setFilters(Object.assign({}, filters, { [field]: e.target.value })); }; }

		return h('div', { className: 'mb-app' },
			h('header', { className: 'mb-app__header' },
				h('div', null,
					h('h1', null, __('Activity', 'memberistic')),
					h('p', { className: 'mb-app__sub' }, __('Every membership, payment, check-in, and staff event logged on the system.', 'memberistic'))
				)
			),

			h('div', { className: 'mb-toolbar' },
				h('input', { type: 'search', className: 'mb-toolbar__search', value: filters.search, placeholder: __('Search by title, description, or member ID', 'memberistic'), onChange: update('search') }),
				h('select', { className: 'mb-toolbar__select', value: filters.activity_type, onChange: update('activity_type') },
					[h('option', { key: 'all', value: '' }, __('All Activity', 'memberistic'))].concat(
						types.map(function (t) { return h('option', { key: t, value: t }, statusLabel(t)); })
					)
				),
				h('input', { type: 'date', className: 'mb-toolbar__select', value: filters.from, onChange: update('from') }),
				h('input', { type: 'date', className: 'mb-toolbar__select', value: filters.to, onChange: update('to') }),
				h('span', { className: 'mb-toolbar__count' }, sprintf(__('%s shown', 'memberistic'), (rows || []).length)),
				window.MemberisticSavedViews
					? h(window.MemberisticSavedViews.Bar, {
						context: 'activity',
						currentFilters: filters,
						onApply: function (next) {
							setFilters({
								search: next.search || '',
								activity_type: next.activity_type || '',
								from: next.from || '',
								to: next.to || '',
							});
						},
					})
					: null
			),

			error ? h('div', { className: 'mb-banner mb-banner--error' }, error) : null,

			h('div', { className: 'mb-card' },
				loading && !rows.length
					? h('p', { className: 'mb-empty' }, __('Loading activity…', 'memberistic'))
					: !rows.length
						? h('p', { className: 'mb-empty' }, __('No activity matches these filters.', 'memberistic'))
						: h('ul', { className: 'mb-timeline mb-timeline--full' },
							rows.map(function (row) {
								return h('li', { key: row.id, className: 'mb-timeline__item' },
									h('span', { className: 'mb-timeline__dot' }),
									h('div', { className: 'mb-timeline__body' },
										h('div', { className: 'mb-timeline__head' },
											h('strong', null, row.title || statusLabel(row.activity_type)),
											row.membership_id
												? h('a', { className: 'mb-timeline__link', href: 'admin.php?page=memberistic-members&action=view&id=' + encodeURIComponent(row.membership_id) }, row.membership_uuid || ('#' + row.membership_id))
												: null
										),
										row.description ? h('p', { className: 'mb-timeline__desc' }, row.description) : null,
										h('span', { className: 'mb-timeline__meta' },
											statusLabel(row.activity_type),
											' · ',
											h('span', { title: formatDateTime(row.created_at) }, relativeTime(row.created_at))
										)
									)
								);
							})
						)
			)
		);
	}

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById(ROOT_ID);
		if (root) render(h(ActivityApp), root);
	});
})(window.wp);
