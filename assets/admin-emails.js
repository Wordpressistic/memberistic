/**
 * Memberistic — Member Email Directory (React console).
 *
 * KPI cards (sent today/week/month, deliverability, people with/without
 * email) over a paginated, filterable member-contact list. The CSV export
 * is built client-side from the full filtered set with proper columns.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element || !wp.apiFetch) return;

	const { createElement: h, Fragment, useState, useEffect, useMemo, render } = wp.element;
	const apiFetch = wp.apiFetch;
	const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
	const sprintf = (wp.i18n && wp.i18n.sprintf) ? wp.i18n.sprintf : function (s) { return s; };

	const ROOT_ID = 'memberistic-emails-app';
	const NS = 'memberistic/v1';
	const STATUS_OPTIONS = ['', 'active', 'pending', 'past_due', 'expired', 'cancelled', 'paused', 'comped', 'trial', 'needs_review'];
	const WAIVER_OPTIONS = ['', 'missing', 'signed', 'expired', 'needs_review', 'rejected'];

	function statusLabel(s) {
		return String(s || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}
	function statusTone(s) {
		const map = {
			active: 'success', signed: 'success', completed: 'success',
			pending: 'warning', missing: 'warning', past_due: 'danger',
			cancelled: 'muted', expired: 'muted', paused: 'info', trial: 'info',
			needs_review: 'warning', rejected: 'danger',
		};
		return map[s] || 'muted';
	}
	function StatusPill(props) {
		return h('span', { className: 'mb-pill mb-pill--' + statusTone(props.status) }, statusLabel(props.status || 'unknown'));
	}
	function formatDate(iso) {
		if (!iso) return '—';
		const ts = Date.parse(String(iso).replace(' ', 'T') + 'Z');
		return isNaN(ts) ? iso : new Date(ts).toLocaleDateString();
	}
	function useDebounced(value, delay) {
		const [v, setV] = useState(value);
		useEffect(function () { const id = window.setTimeout(function () { setV(value); }, delay); return function () { window.clearTimeout(id); }; }, [value, delay]);
		return v;
	}

	function StatCard(props) {
		const trend = (typeof props.trend === 'number') ? props.trend : null;
		return h('div', { className: 'mb-stat mb-stat--' + (props.tone || 'primary') },
			h('div', { className: 'mb-stat__head' },
				h('span', { className: 'mb-stat__label' }, props.label),
				props.hint ? h('span', { className: 'mb-stat__hint', title: props.hint }, '?') : null
			),
			h('strong', { className: 'mb-stat__value' }, props.value),
			props.sub ? h('span', { className: 'mb-stat__sub' }, props.sub) : null,
			trend !== null
				? h('span', { className: 'mb-stat__trend ' + (trend >= 0 ? 'is-up' : 'is-down') },
					(trend >= 0 ? '▲ +' : '▼ ') + Math.abs(trend).toFixed(1) + '%')
				: null
		);
	}

	function Pagination(props) {
		const page = Math.max(1, Number(props.page) || 1);
		const totalPages = Math.max(1, Number(props.totalPages) || 1);
		const perPage = Number(props.perPage) || 50;
		const total = Number(props.total) || 0;
		const onPage = props.onPage;
		const onPerPage = props.onPerPage;
		const perPageOptions = [25, 50, 100, 200];

		function go(n) {
			const next = Math.max(1, Math.min(totalPages, n));
			if (next !== page && onPage) onPage(next);
		}

		return h('nav', { className: 'mb-pager', 'aria-label': __('Pagination', 'memberistic') },
			h('div', { className: 'mb-pager__info' },
				sprintf(__('Page %1$d of %2$d · %3$d total', 'memberistic'), page, totalPages, total)
			),
			h('div', { className: 'mb-pager__controls' },
				h('button', { className: 'button', disabled: page <= 1, onClick: function () { go(1); } }, '«'),
				h('button', { className: 'button', disabled: page <= 1, onClick: function () { go(page - 1); } }, __('Prev', 'memberistic')),
				h('span', { className: 'mb-pager__page' }, page + ' / ' + totalPages),
				h('button', { className: 'button', disabled: page >= totalPages, onClick: function () { go(page + 1); } }, __('Next', 'memberistic')),
				h('button', { className: 'button', disabled: page >= totalPages, onClick: function () { go(totalPages); } }, '»'),
				onPerPage
					? h('select', {
						className: 'mb-toolbar__select',
						value: String(perPage),
						onChange: function (e) { onPerPage(Number(e.target.value) || 50); },
						'aria-label': __('Rows per page', 'memberistic'),
					},
						perPageOptions.map(function (n) { return h('option', { key: 'pp-' + n, value: String(n) }, n + ' / page'); })
					)
					: null
			)
		);
	}

	function downloadCsv(filename, rows) {
		const csv = rows.map(function (row) {
			return row.map(function (cell) {
				const s = String(cell == null ? '' : cell);
				return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
			}).join(',');
		}).join('\n');

		const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
	}

	function EmailsApp() {
		const [filters, setFilters] = useState({ search: '', status: '', waiver_status: '' });
		const debouncedSearch = useDebounced(filters.search, 250);

		const [rows, setRows] = useState([]);
		const [stats, setStats] = useState(null);
		const [loading, setLoading] = useState(true);
		const [error, setError] = useState(null);
		const [exporting, setExporting] = useState(false);

		const [page, setPage] = useState(1);
		const [perPage, setPerPage] = useState(50);
		const [total, setTotal] = useState(0);
		const [totalPages, setTotalPages] = useState(1);

		useEffect(function () { setPage(1); }, [debouncedSearch, filters.status, filters.waiver_status, perPage]);

		useEffect(function () {
			const params = new URLSearchParams();
			if (debouncedSearch) params.set('search', debouncedSearch);
			if (filters.status) params.set('status', filters.status);
			if (filters.waiver_status) params.set('waiver_status', filters.waiver_status);
			params.set('limit', String(perPage));
			params.set('offset', String((page - 1) * perPage));

			setLoading(true);
			apiFetch({ path: '/' + NS + '/emails/directory?' + params.toString(), parse: false }).then(
				function (response) {
					const t = parseInt(response.headers.get('X-WP-Total') || '0', 10);
					const tp = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);
					setTotal(isNaN(t) ? 0 : t);
					setTotalPages(isNaN(tp) || tp < 1 ? 1 : tp);
					return response.json();
				},
				function (err) {
					setError((err && err.message) || __('Could not load directory.', 'memberistic'));
					setLoading(false);
					throw err;
				}
			).then(
				function (data) { setRows(Array.isArray(data) ? data : []); setLoading(false); },
				function () { /* error already handled */ }
			);
		}, [debouncedSearch, filters.status, filters.waiver_status, page, perPage]);

		useEffect(function () {
			apiFetch({ path: '/' + NS + '/emails/stats' }).then(setStats, function () { /* silent */ });
		}, []);

		function update(field) {
			return function (e) { setFilters(Object.assign({}, filters, { [field]: e.target.value })); };
		}

		// Export the full filtered set (not just the current page).
		function exportAll() {
			setExporting(true);
			const params = new URLSearchParams();
			if (debouncedSearch) params.set('search', debouncedSearch);
			if (filters.status) params.set('status', filters.status);
			if (filters.waiver_status) params.set('waiver_status', filters.waiver_status);
			params.set('limit', '1000');

			const allRows = [];

			function fetchPage(offset) {
				params.set('offset', String(offset));
				return apiFetch({ path: '/' + NS + '/emails/directory?' + params.toString(), parse: false }).then(
					function (response) {
						return response.json().then(function (data) {
							const items = Array.isArray(data) ? data : [];
							allRows.push.apply(allRows, items);
							if (items.length >= 1000 && allRows.length < total) {
								return fetchPage(offset + 1000);
							}
							return null;
						});
					}
				);
			}

			fetchPage(0).then(
				function () {
					const header = [
						__('Name', 'memberistic'),
						__('Email', 'memberistic'),
						__('Phone', 'memberistic'),
						__('Role', 'memberistic'),
						__('Membership ID', 'memberistic'),
						__('Plan', 'memberistic'),
						__('Membership Status', 'memberistic'),
						__('Billing Cycle', 'memberistic'),
						__('Renewal Date', 'memberistic'),
						__('Waiver Status', 'memberistic'),
						__('Waiver Signed', 'memberistic'),
						__('Waiver Expires', 'memberistic'),
						__('Created', 'memberistic'),
					];
					const dataRows = allRows.map(function (r) {
						return [
							r.full_name || '',
							r.email || '',
							r.phone || '',
							statusLabel(r.role || ''),
							r.membership_uuid || '',
							r.plan_name || '',
							statusLabel(r.membership_status || ''),
							statusLabel(r.billing_cycle || ''),
							r.renewal_date || '',
							statusLabel(r.waiver_status || ''),
							r.waiver_signed_at || '',
							r.waiver_expires_at || '',
							r.person_created_at || '',
						];
					});
					downloadCsv('memberistic-member-emails-' + new Date().toISOString().slice(0, 10) + '.csv', [header].concat(dataRows));
					setExporting(false);
				},
				function (err) {
					setExporting(false);
					window.alert((err && err.message) || __('Export failed.', 'memberistic'));
				}
			);
		}

		const cards = (function () {
			if (!stats) return null;
			return [
				{ label: __('Sent today', 'memberistic'), value: (Number(stats.sent_today) || 0).toLocaleString(), tone: 'primary' },
				{ label: __('Sent this week', 'memberistic'), value: (Number(stats.sent_this_week) || 0).toLocaleString(), tone: 'info' },
				{ label: __('Sent this month', 'memberistic'), value: (Number(stats.sent_this_month) || 0).toLocaleString(), tone: 'success' },
				{
					label: __('Delivery rate', 'memberistic'),
					value: (stats.delivery_rate_pct == null) ? '—' : Number(stats.delivery_rate_pct).toFixed(1) + '%',
					sub: sprintf(__('%1$d sent · %2$d failed', 'memberistic'), Number(stats.total_sent) || 0, Number(stats.total_failed) || 0),
					tone: (Number(stats.delivery_rate_pct) >= 95) ? 'success' : 'warning',
				},
				{
					label: __('People with email', 'memberistic'),
					value: (Number(stats.people_with_email) || 0).toLocaleString(),
					sub: sprintf(__('%d missing', 'memberistic'), Number(stats.people_without_email) || 0),
					tone: 'primary',
				},
			];
		})();

		return h('div', { className: 'mb-app' },
			h('header', { className: 'mb-app__header' },
				h('div', null,
					h('h1', null, __('Member Email Directory', 'memberistic')),
					h('p', { className: 'mb-app__sub' }, __('A clean export-ready list of primary and linked member contacts for campaigns, staff follow-up, renewals, and waiver operations.', 'memberistic'))
				),
				h('button', {
					className: 'button button-primary',
					onClick: exportAll,
					disabled: exporting || !total,
				}, exporting ? __('Exporting…', 'memberistic') : __('Download CSV', 'memberistic'))
			),

			h('section', { className: 'mb-grid mb-grid--stats mb-grid--fade' },
				cards
					? cards.map(function (c, i) { return h(StatCard, Object.assign({ key: 'c-' + i }, c)); })
					: [0, 1, 2, 3, 4].map(function (i) { return h('div', { key: 'sk-' + i, className: 'mb-stat mb-stat--skeleton' }); })
			),

			h('div', { className: 'mb-toolbar' },
				h('input', {
					type: 'search',
					className: 'mb-toolbar__search',
					value: filters.search,
					placeholder: __('Search by name, email, phone, or member ID', 'memberistic'),
					onChange: update('search'),
				}),
				h('select', { className: 'mb-toolbar__select', value: filters.status, onChange: update('status') },
					STATUS_OPTIONS.map(function (s) { return h('option', { key: 's-' + s, value: s }, s === '' ? __('All Member Statuses', 'memberistic') : statusLabel(s)); })
				),
				h('select', { className: 'mb-toolbar__select', value: filters.waiver_status, onChange: update('waiver_status') },
					WAIVER_OPTIONS.map(function (w) { return h('option', { key: 'w-' + w, value: w }, w === '' ? __('All Waiver Statuses', 'memberistic') : statusLabel(w)); })
				),
				h('span', { className: 'mb-toolbar__count' },
					total > 0
						? sprintf(__('Showing %1$d–%2$d of %3$d', 'memberistic'),
							((page - 1) * perPage) + 1,
							Math.min(page * perPage, total),
							total)
						: sprintf(__('%s shown', 'memberistic'), rows.length)
				)
			),

			error ? h('div', { className: 'mb-banner mb-banner--error' }, error) : null,

			h('div', { className: 'mb-card mb-card--list' },
				loading && !rows.length
					? h('div', { className: 'mb-empty' }, __('Loading…', 'memberistic'))
					: !rows.length
						? h('div', { className: 'mb-empty' }, __('No contacts match these filters.', 'memberistic'))
						: h('table', { className: 'mb-table' },
							h('thead', null,
								h('tr', null,
									h('th', null, __('Name', 'memberistic')),
									h('th', null, __('Email', 'memberistic')),
									h('th', null, __('Phone', 'memberistic')),
									h('th', null, __('Role', 'memberistic')),
									h('th', null, __('Plan', 'memberistic')),
									h('th', null, __('Member Status', 'memberistic')),
									h('th', null, __('Waiver', 'memberistic')),
									h('th', null, __('Renewal', 'memberistic')),
									h('th', { className: 'mb-table__actions' }, __('Actions', 'memberistic'))
								)
							),
							h('tbody', null,
								rows.map(function (r) {
									const memberHref = r.membership_id
										? 'admin.php?page=memberistic-members&action=view&id=' + encodeURIComponent(r.membership_id)
										: '#';
									return h('tr', { key: r.id },
										h('td', null, r.full_name || '—'),
										h('td', null, r.email
											? h('a', { href: 'mailto:' + r.email }, r.email)
											: '—'
										),
										h('td', null, r.phone || '—'),
										h('td', null, statusLabel(r.role || '')),
										h('td', null, r.plan_name || '—'),
										h('td', null, h(StatusPill, { status: r.membership_status || 'unknown' })),
										h('td', null, h(StatusPill, { status: r.waiver_status || 'missing' })),
										h('td', null, formatDate(r.renewal_date)),
										h('td', { className: 'mb-table__actions' },
											h('a', { className: 'button-link', href: memberHref }, __('Open member', 'memberistic'))
										)
									);
								})
							)
						)
			),

			total > 0
				? h(Pagination, {
					page: page,
					perPage: perPage,
					totalPages: totalPages,
					total: total,
					onPage: setPage,
					onPerPage: setPerPage,
				})
				: null
		);
	}

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById(ROOT_ID);
		if (root) render(h(EmailsApp), root);
	});
})(window.wp);
