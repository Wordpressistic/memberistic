/**
 * Memberistic — Premium Payments Console
 *
 * Filterable payments table with date range, status, gateway, and search.
 * Click a row to deep-link to that member's detail panel in the Members app.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element || !wp.apiFetch) return;

	const { createElement: h, Fragment, useState, useEffect, useCallback, useMemo, render } = wp.element;
	const apiFetch = wp.apiFetch;
	const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
	const sprintf = (wp.i18n && wp.i18n.sprintf) ? wp.i18n.sprintf : function (s) { return s; };

	const ROOT_ID = 'memberistic-payments-app';
	const NS = 'memberistic/v1';
	const STATUSES = ['', 'completed', 'pending', 'failed', 'refunded'];

	function statusLabel(s) {
		return String(s || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}
	function statusTone(s) {
		const map = { completed: 'success', pending: 'warning', failed: 'danger', refunded: 'info' };
		return map[s] || 'muted';
	}
	function StatusPill(props) {
		return h('span', { className: 'mb-pill mb-pill--' + statusTone(props.status) }, statusLabel(props.status || 'unknown'));
	}
	function formatCurrency(value) {
		return '$' + (Number(value) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function formatDate(iso) {
		if (!iso) return '—';
		const ts = Date.parse(iso.replace(' ', 'T') + 'Z');
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

	function PaymentsApp() {
		const [filters, setFilters] = useState({ search: '', status: '', from: '', to: '' });
		const debouncedSearch = useDebounced(filters.search, 250);

		const [payments, setPayments] = useState([]);
		const [pageStats, setPageStats] = useState(null);
		const [loading, setLoading] = useState(true);
		const [error, setError] = useState(null);

		const [page, setPage] = useState(1);
		const [perPage, setPerPage] = useState(50);
		const [total, setTotal] = useState(0);
		const [totalPages, setTotalPages] = useState(1);

		useEffect(function () { setPage(1); }, [debouncedSearch, filters.status, filters.from, filters.to, perPage]);

		useEffect(function () {
			const params = new URLSearchParams();
			if (debouncedSearch) params.set('search', debouncedSearch);
			if (filters.status) params.set('status', filters.status);
			if (filters.from) params.set('from', filters.from + ' 00:00:00');
			if (filters.to) params.set('to', filters.to + ' 23:59:59');
			params.set('limit', String(perPage));
			params.set('offset', String((page - 1) * perPage));

			setLoading(true);
			apiFetch({ path: '/' + NS + '/payments?' + params.toString(), parse: false }).then(
				function (response) {
					const t = parseInt(response.headers.get('X-WP-Total') || '0', 10);
					const tp = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);
					setTotal(isNaN(t) ? 0 : t);
					setTotalPages(isNaN(tp) || tp < 1 ? 1 : tp);
					return response.json();
				},
				function (err) {
					setError((err && err.message) || __('Could not load payments.', 'memberistic'));
					setLoading(false);
					throw err;
				}
			).then(
				function (data) { setPayments(Array.isArray(data) ? data : []); setLoading(false); },
				function () { /* error already handled */ }
			);
		}, [debouncedSearch, filters.status, filters.from, filters.to, page, perPage]);

		useEffect(function () {
			apiFetch({ path: '/' + NS + '/payments/stats' }).then(setPageStats, function () { /* silent */ });
		}, []);

		const summary = useMemo(function () {
			let totalShown = 0, completed = 0, failed = 0;
			(payments || []).forEach(function (p) {
				const amt = Number(p.amount) || 0;
				if (p.status === 'completed') { completed += amt; totalShown += amt; }
				if (p.status === 'failed') failed += 1;
			});
			return { total: totalShown, completed: completed, failed: failed, count: (payments || []).length };
		}, [payments]);

		function update(field) {
			return function (e) { setFilters(Object.assign({}, filters, { [field]: e.target.value })); };
		}

		function exportCsv() {
			const header = ['Payment ID', 'Member ID', 'Primary Member', 'Amount', 'Currency', 'Method', 'Gateway', 'Status', 'Paid', 'Transaction ID', 'Created'];
			const rows = (payments || []).map(function (p) {
				return [
					p.id || '',
					p.membership_uuid || '',
					p.full_name || '',
					(Number(p.amount) || 0).toFixed(2),
					p.currency || '',
					p.payment_method || '',
					p.payment_gateway || '',
					p.status || '',
					p.paid_at || '',
					p.gateway_transaction_id || '',
					p.created_at || '',
				];
			});
			downloadCsv('memberistic-payments-' + new Date().toISOString().slice(0, 10) + '.csv', [header].concat(rows));
		}

		function memberHref(p) {
			return p.membership_id ? 'admin.php?page=memberistic-members&action=view&id=' + encodeURIComponent(p.membership_id) : '#';
		}

		return h('div', { className: 'mb-app' },
			h('header', { className: 'mb-app__header' },
				h('div', null,
					h('h1', null, __('Payments', 'memberistic')),
					h('p', { className: 'mb-app__sub' }, __('Search and audit every recorded payment across members, gateways, and dates.', 'memberistic'))
				),
				h('button', { className: 'button', onClick: exportCsv, disabled: !(payments || []).length }, __('Export CSV', 'memberistic'))
			),

			h('section', { className: 'mb-grid mb-grid--stats mb-grid--fade' },
				pageStats
					? [
						h(StatCard, {
							key: 'rev',
							tone: 'primary',
							label: __('Lifetime revenue', 'memberistic'),
							value: formatCurrency(pageStats.revenue_total),
							sub: sprintf(__('%d completed payments', 'memberistic'), Number(pageStats.completed_payments) || 0),
						}),
						h(StatCard, {
							key: 'rev-m',
							tone: 'success',
							label: __('Revenue this month', 'memberistic'),
							value: formatCurrency(pageStats.revenue_this_month),
							sub: sprintf(__('vs %s last month', 'memberistic'), formatCurrency(pageStats.revenue_prev_month)),
							trend: (typeof pageStats.revenue_growth_pct === 'number') ? pageStats.revenue_growth_pct : null,
						}),
						h(StatCard, {
							key: 'new',
							tone: 'info',
							label: __('New-member payments', 'memberistic'),
							value: (Number(pageStats.new_member_payments) || 0).toLocaleString(),
							hint: __('First completed payment per membership', 'memberistic'),
						}),
						h(StatCard, {
							key: 'renew',
							tone: 'success',
							label: __('Renewal payments', 'memberistic'),
							value: (Number(pageStats.renewal_payments) || 0).toLocaleString(),
							hint: __('Second-or-later completed payment per membership', 'memberistic'),
						}),
						h(StatCard, {
							key: 'failed',
							tone: (Number(pageStats.failed_payments) || 0) > 0 ? 'danger' : 'info',
							label: __('Failed payments', 'memberistic'),
							value: (Number(pageStats.failed_payments) || 0).toLocaleString(),
						}),
						h(StatCard, {
							key: 'shown',
							tone: 'primary',
							label: __('Visible on page', 'memberistic'),
							value: summary.count.toLocaleString(),
							sub: total > 0 ? sprintf(__('of %d in current filter', 'memberistic'), total) : '',
						}),
					]
					: [0, 1, 2, 3, 4, 5].map(function (i) { return h('div', { key: 'sk-' + i, className: 'mb-stat mb-stat--skeleton' }); })
			),

			h('div', { className: 'mb-toolbar' },
				h('input', {
					type: 'search', className: 'mb-toolbar__search', value: filters.search,
					placeholder: __('Search by member, ID, or transaction reference', 'memberistic'),
					onChange: update('search'),
				}),
				h('select', { className: 'mb-toolbar__select', value: filters.status, onChange: update('status') },
					STATUSES.map(function (s) { return h('option', { key: 's-' + s, value: s }, s === '' ? __('All Statuses', 'memberistic') : statusLabel(s)); })
				),
				h('input', { type: 'date', className: 'mb-toolbar__select', value: filters.from, onChange: update('from') }),
				h('input', { type: 'date', className: 'mb-toolbar__select', value: filters.to, onChange: update('to') }),
				h('span', { className: 'mb-toolbar__count' },
					total > 0
						? sprintf(__('Showing %1$d–%2$d of %3$d', 'memberistic'),
							((page - 1) * perPage) + 1,
							Math.min(page * perPage, total),
							total)
						: sprintf(__('%s shown', 'memberistic'), summary.count)
				),
				window.MemberisticSavedViews
					? h(window.MemberisticSavedViews.Bar, {
						context: 'payments',
						currentFilters: filters,
						onApply: function (next) {
							setFilters({
								search: next.search || '',
								status: next.status || '',
								from: next.from || '',
								to: next.to || '',
							});
						},
					})
					: null
			),

			error ? h('div', { className: 'mb-banner mb-banner--error' }, error) : null,

			h('div', { className: 'mb-card mb-card--list' },
				loading && !payments.length
					? h('div', { className: 'mb-empty' }, __('Loading payments…', 'memberistic'))
					: !payments.length
						? h('div', { className: 'mb-empty' }, __('No payments match these filters.', 'memberistic'))
						: h('table', { className: 'mb-table' },
							h('thead', null,
								h('tr', null,
									h('th', null, __('Member ID', 'memberistic')),
									h('th', null, __('Primary Member', 'memberistic')),
									h('th', null, __('Amount', 'memberistic')),
									h('th', null, __('Method', 'memberistic')),
									h('th', null, __('Gateway', 'memberistic')),
									h('th', null, __('Status', 'memberistic')),
									h('th', null, __('Paid', 'memberistic')),
									h('th', { className: 'mb-table__actions' }, __('Actions', 'memberistic'))
								)
							),
							h('tbody', null,
								payments.map(function (p) {
									return h('tr', { key: p.id },
										h('td', null, h('code', null, p.membership_uuid || '—')),
										h('td', null, p.full_name || __('Not assigned', 'memberistic')),
										h('td', null, formatCurrency(p.amount) + ' ' + (p.currency || '')),
										h('td', null, statusLabel(p.payment_method || 'manual')),
										h('td', null, statusLabel(p.payment_gateway || 'manual')),
										h('td', null, h(StatusPill, { status: p.status })),
										h('td', null, formatDate(p.paid_at || p.created_at)),
										h('td', { className: 'mb-table__actions' },
											h('a', { className: 'button-link', href: memberHref(p) }, __('Open member', 'memberistic'))
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

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById(ROOT_ID);
		if (root) render(h(PaymentsApp), root);
	});
})(window.wp);
