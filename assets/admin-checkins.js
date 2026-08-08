/**
 * Memberistic — Premium Check-ins Console
 *
 * Filterable check-ins log with date range, type filter, and per-day rollup.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element || !wp.apiFetch) return;

	const { createElement: h, Fragment, useState, useEffect, useMemo, render } = wp.element;
	const apiFetch = wp.apiFetch;
	const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
	const sprintf = (wp.i18n && wp.i18n.sprintf) ? wp.i18n.sprintf : function (s) { return s; };

	const ROOT_ID = 'memberistic-checkins-app';
	const NS = 'memberistic/v1';
	const TYPES = ['', 'walk_in', 'booking', 'class', 'event', 'guest'];

	function statusLabel(s) { return String(s || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }
	function statusTone(s) {
		const map = { checked_in: 'success', checked_out: 'info' };
		return map[s] || 'muted';
	}
	function StatusPill(p) { return h('span', { className: 'mb-pill mb-pill--' + statusTone(p.status) }, statusLabel(p.status || 'unknown')); }
	function formatDateTime(iso) {
		if (!iso) return '—';
		const ts = Date.parse(iso.replace(' ', 'T') + 'Z');
		if (isNaN(ts)) return iso;
		return new Date(ts).toLocaleString();
	}
	function todayIso() {
		const d = new Date();
		const m = String(d.getMonth() + 1).padStart(2, '0');
		const day = String(d.getDate()).padStart(2, '0');
		return d.getFullYear() + '-' + m + '-' + day;
	}
	function useDebounced(value, delay) {
		const [v, setV] = useState(value);
		useEffect(function () { const id = window.setTimeout(function () { setV(value); }, delay); return function () { window.clearTimeout(id); }; }, [value, delay]);
		return v;
	}

	function CheckinsApp() {
		const [filters, setFilters] = useState({ search: '', checkin_type: '', from: '', to: '' });
		const debouncedSearch = useDebounced(filters.search, 250);

		const [rows, setRows] = useState([]);
		const [loading, setLoading] = useState(true);
		const [error, setError] = useState(null);

		useEffect(function () {
			const params = new URLSearchParams();
			if (debouncedSearch) params.set('search', debouncedSearch);
			if (filters.checkin_type) params.set('checkin_type', filters.checkin_type);
			if (filters.from) params.set('from', filters.from + ' 00:00:00');
			if (filters.to) params.set('to', filters.to + ' 23:59:59');
			params.set('limit', '200');

			setLoading(true);
			apiFetch({ path: '/' + NS + '/checkins?' + params.toString() }).then(
				function (data) { setRows(Array.isArray(data) ? data : []); setLoading(false); },
				function (err) {
					setError((err && err.message) || __('Could not load check-ins.', 'memberistic'));
					setLoading(false);
				}
			);
		}, [debouncedSearch, filters.checkin_type, filters.from, filters.to]);

		function update(field) { return function (e) { setFilters(Object.assign({}, filters, { [field]: e.target.value })); }; }

		const summary = useMemo(function () {
			const today = todayIso();
			let todayCount = 0;
			const byType = {};
			const distinctMembers = new Set();
			(rows || []).forEach(function (r) {
				const iso = (r.checked_in_at || '').slice(0, 10);
				if (iso === today) todayCount++;
				byType[r.checkin_type || 'walk_in'] = (byType[r.checkin_type || 'walk_in'] || 0) + 1;
				if (r.membership_id) distinctMembers.add(r.membership_id);
			});
			const topType = Object.keys(byType).sort(function (a, b) { return byType[b] - byType[a]; })[0];
			return {
				count: (rows || []).length,
				today: todayCount,
				distinctMembers: distinctMembers.size,
				topType: topType || '—',
			};
		}, [rows]);

		return h('div', { className: 'mb-app' },
			h('header', { className: 'mb-app__header' },
				h('div', null,
					h('h1', null, __('Check-ins', 'memberistic')),
					h('p', { className: 'mb-app__sub' }, __('Every walk-in and booking recorded across the front desk and POS bridge.', 'memberistic'))
				)
			),

			h('section', { className: 'mb-grid mb-grid--stats' },
				h('div', { className: 'mb-stat mb-stat--primary' },
					h('div', { className: 'mb-stat__head' }, h('span', { className: 'mb-stat__label' }, __('Records', 'memberistic'))),
					h('strong', { className: 'mb-stat__value' }, summary.count.toLocaleString())
				),
				h('div', { className: 'mb-stat mb-stat--success' },
					h('div', { className: 'mb-stat__head' }, h('span', { className: 'mb-stat__label' }, __('Today', 'memberistic'))),
					h('strong', { className: 'mb-stat__value' }, summary.today.toLocaleString())
				),
				h('div', { className: 'mb-stat mb-stat--info' },
					h('div', { className: 'mb-stat__head' }, h('span', { className: 'mb-stat__label' }, __('Distinct members', 'memberistic'))),
					h('strong', { className: 'mb-stat__value' }, summary.distinctMembers.toLocaleString())
				),
				h('div', { className: 'mb-stat' },
					h('div', { className: 'mb-stat__head' }, h('span', { className: 'mb-stat__label' }, __('Top type', 'memberistic'))),
					h('strong', { className: 'mb-stat__value' }, statusLabel(summary.topType))
				)
			),

			h('div', { className: 'mb-toolbar' },
				h('input', { type: 'search', className: 'mb-toolbar__search', value: filters.search, placeholder: __('Search by member or ID', 'memberistic'), onChange: update('search') }),
				h('select', { className: 'mb-toolbar__select', value: filters.checkin_type, onChange: update('checkin_type') },
					TYPES.map(function (t) { return h('option', { key: 't-' + t, value: t }, t === '' ? __('All Types', 'memberistic') : statusLabel(t)); })
				),
				h('input', { type: 'date', className: 'mb-toolbar__select', value: filters.from, onChange: update('from') }),
				h('input', { type: 'date', className: 'mb-toolbar__select', value: filters.to, onChange: update('to') }),
				h('span', { className: 'mb-toolbar__count' }, sprintf(__('%s shown', 'memberistic'), summary.count)),
				window.MemberisticSavedViews
					? h(window.MemberisticSavedViews.Bar, {
						context: 'checkins',
						currentFilters: filters,
						onApply: function (next) {
							setFilters({
								search: next.search || '',
								checkin_type: next.checkin_type || '',
								from: next.from || '',
								to: next.to || '',
							});
						},
					})
					: null
			),

			error ? h('div', { className: 'mb-banner mb-banner--error' }, error) : null,

			h('div', { className: 'mb-card mb-card--list' },
				loading && !rows.length
					? h('div', { className: 'mb-empty' }, __('Loading check-ins…', 'memberistic'))
					: !rows.length
						? h('div', { className: 'mb-empty' }, __('No check-ins match these filters.', 'memberistic'))
						: h('table', { className: 'mb-table' },
							h('thead', null,
								h('tr', null,
									h('th', null, __('Member ID', 'memberistic')),
									h('th', null, __('Person', 'memberistic')),
									h('th', null, __('Type', 'memberistic')),
									h('th', null, __('Checked in', 'memberistic')),
									h('th', null, __('Status', 'memberistic')),
									h('th', { className: 'mb-table__actions' }, __('Actions', 'memberistic'))
								)
							),
							h('tbody', null,
								rows.map(function (r) {
									return h('tr', { key: r.id },
										h('td', null, h('code', null, r.membership_uuid || '—')),
										h('td', null, r.full_name || ('#' + r.person_id)),
										h('td', null, statusLabel(r.checkin_type || 'walk_in')),
										h('td', null, formatDateTime(r.checked_in_at)),
										h('td', null, h(StatusPill, { status: r.status || 'checked_in' })),
										h('td', { className: 'mb-table__actions' },
											r.membership_id
												? h('a', { className: 'button-link', href: 'admin.php?page=memberistic-members&action=view&id=' + encodeURIComponent(r.membership_id) }, __('Open member', 'memberistic'))
												: '—'
										)
									);
								})
							)
						)
			)
		);
	}

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById(ROOT_ID);
		if (root) render(h(CheckinsApp), root);
	});
})(window.wp);
