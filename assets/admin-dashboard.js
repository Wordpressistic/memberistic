/**
 * Memberistic — Premium Admin Dashboard
 *
 * Live-refreshing dashboard built on wp.element (React) and wp.apiFetch.
 * No build step: ships as plain JS using createElement / hooks directly.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element || !wp.apiFetch) {
		return;
	}

	const { createElement: h, Fragment, useState, useEffect, useMemo, render } = wp.element;
	const apiFetch = wp.apiFetch;
	const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
	const sprintf = (wp.i18n && wp.i18n.sprintf) ? wp.i18n.sprintf : function (s) { return s; };

	const ROOT_ID = 'memberistic-dashboard-app';
	const NS = 'memberistic/v1';
	const REFRESH_MS = 30000;

	function formatCurrency(value) {
		const n = Number(value) || 0;
		return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function formatCompactCurrency(value) {
		const n = Number(value) || 0;
		if (n >= 1000) {
			return '$' + (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
		}
		return '$' + Math.round(n);
	}

	function formatNumber(value) {
		return (Number(value) || 0).toLocaleString();
	}

	function statusLabel(status) {
		return String(status || '')
			.replace(/_/g, ' ')
			.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}

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

	function daysUntil(iso) {
		if (!iso) return null;
		const ts = Date.parse(iso.replace(' ', 'T') + 'Z');
		if (isNaN(ts)) return null;
		return Math.round((ts - Date.now()) / 86400000);
	}

	/* ------------------------------------------------------------------ */
	/* Reusable presentational components                                  */
	/* ------------------------------------------------------------------ */

	function StatCard(props) {
		const tone = props.tone || 'neutral';
		const trend = typeof props.trend === 'number' ? props.trend : null;
		return h('div', { className: 'mb-stat mb-stat--' + tone },
			h('div', { className: 'mb-stat__head' },
				h('span', { className: 'mb-stat__label' }, props.label),
				props.hint ? h('span', { className: 'mb-stat__hint', title: props.hint }, '?') : null
			),
			h('strong', { className: 'mb-stat__value' }, props.value),
			props.sub ? h('span', { className: 'mb-stat__sub' }, props.sub) : null,
			trend !== null
				? h('span', { className: 'mb-stat__trend ' + (trend >= 0 ? 'is-up' : 'is-down') },
					(trend >= 0 ? '▲ ' : '▼ ') + Math.abs(trend).toFixed(1) + '%'
				)
				: null
		);
	}

	function Sparkline(props) {
		const series = props.series || [];
		if (!series.length) {
			return h('div', { className: 'mb-sparkline mb-sparkline--empty' },
				h('span', null, __('No revenue data yet.', 'memberistic'))
			);
		}

		const values = series.map(function (p) { return Number(p.revenue) || 0; });
		const max = Math.max.apply(null, values.concat([1]));
		const width = 720;
		const height = 180;
		const padX = 32;
		const padY = 20;
		const innerW = width - padX * 2;
		const innerH = height - padY * 2;
		const step = series.length > 1 ? innerW / (series.length - 1) : 0;

		const points = series.map(function (p, i) {
			const x = padX + step * i;
			const y = padY + innerH - (innerH * ((Number(p.revenue) || 0) / max));
			return { x: x, y: y, label: p.label, revenue: Number(p.revenue) || 0 };
		});

		const linePath = points.map(function (pt, i) {
			return (i === 0 ? 'M' : 'L') + pt.x.toFixed(1) + ',' + pt.y.toFixed(1);
		}).join(' ');

		const areaPath = linePath
			+ ' L' + points[points.length - 1].x.toFixed(1) + ',' + (padY + innerH).toFixed(1)
			+ ' L' + points[0].x.toFixed(1) + ',' + (padY + innerH).toFixed(1) + ' Z';

		const gridLines = [0.25, 0.5, 0.75].map(function (frac) {
			const y = padY + innerH * frac;
			return h('line', {
				key: 'g' + frac,
				x1: padX, x2: width - padX,
				y1: y, y2: y,
				stroke: 'rgba(15,32,68,0.08)',
				strokeDasharray: '3 3',
			});
		});

		const dots = points.map(function (pt, i) {
			return h('g', { key: 'd' + i },
				h('circle', {
					cx: pt.x, cy: pt.y, r: 4,
					fill: '#fff',
					stroke: '#0f2044',
					strokeWidth: 2,
				}),
				h('title', null, pt.label + ' — ' + formatCurrency(pt.revenue))
			);
		});

		const xLabels = points.map(function (pt, i) {
			// Only label every other point to avoid crowding when 12+ months.
			if (series.length > 8 && i % 2 !== 0) return null;
			return h('text', {
				key: 'x' + i,
				x: pt.x,
				y: height - 4,
				fontSize: 11,
				textAnchor: 'middle',
				fill: '#6b7280',
			}, pt.label);
		});

		const yLabels = [0, 0.5, 1].map(function (frac) {
			const y = padY + innerH * (1 - frac);
			return h('text', {
				key: 'y' + frac,
				x: 4,
				y: y + 4,
				fontSize: 11,
				fill: '#6b7280',
			}, formatCompactCurrency(max * frac));
		});

		return h('svg', {
			className: 'mb-sparkline',
			viewBox: '0 0 ' + width + ' ' + height,
			preserveAspectRatio: 'none',
			role: 'img',
			'aria-label': __('Revenue trend', 'memberistic'),
		},
			h('defs', null,
				h('linearGradient', { id: 'mb-area', x1: 0, y1: 0, x2: 0, y2: 1 },
					h('stop', { offset: '0%', stopColor: '#0f2044', stopOpacity: 0.22 }),
					h('stop', { offset: '100%', stopColor: '#0f2044', stopOpacity: 0 })
				)
			),
			gridLines,
			h('path', { d: areaPath, fill: 'url(#mb-area)' }),
			h('path', { d: linePath, fill: 'none', stroke: '#0f2044', strokeWidth: 2.5 }),
			dots,
			xLabels,
			yLabels
		);
	}

	function Donut(props) {
		const segments = (props.segments || []).filter(function (s) { return s.value > 0; });
		const total = segments.reduce(function (sum, s) { return sum + Number(s.value); }, 0);
		const size = 160;
		const radius = 64;
		const cx = size / 2;
		const cy = size / 2;
		const stroke = 18;

		if (!total) {
			return h('div', { className: 'mb-donut mb-donut--empty' },
				h('span', null, __('No revenue split yet.', 'memberistic'))
			);
		}

		const palette = ['#0f2044', '#00c857', '#3b82f6', '#f59e0b', '#a855f7', '#ef4444', '#06b6d4'];
		let offset = 0;
		const circumference = 2 * Math.PI * radius;

		const arcs = segments.map(function (seg, i) {
			const frac = Number(seg.value) / total;
			const dash = frac * circumference;
			const arc = h('circle', {
				key: 'arc' + i,
				cx: cx, cy: cy, r: radius,
				fill: 'transparent',
				stroke: palette[i % palette.length],
				strokeWidth: stroke,
				strokeDasharray: dash.toFixed(2) + ' ' + (circumference - dash).toFixed(2),
				strokeDashoffset: (-offset).toFixed(2),
				transform: 'rotate(-90 ' + cx + ' ' + cy + ')',
			});
			offset += dash;
			return arc;
		});

		const legend = segments.map(function (seg, i) {
			const pct = ((Number(seg.value) / total) * 100).toFixed(1);
			return h('li', { key: 'leg' + i, className: 'mb-donut__legend-item' },
				h('span', { className: 'mb-donut__swatch', style: { background: palette[i % palette.length] } }),
				h('span', { className: 'mb-donut__legend-label' }, seg.label),
				h('span', { className: 'mb-donut__legend-value' }, formatCurrency(seg.value) + ' · ' + pct + '%')
			);
		});

		return h('div', { className: 'mb-donut' },
			h('svg', { viewBox: '0 0 ' + size + ' ' + size, width: size, height: size, role: 'img' },
				h('circle', { cx: cx, cy: cy, r: radius, fill: 'transparent', stroke: 'rgba(15,32,68,0.08)', strokeWidth: stroke }),
				arcs,
				h('text', { x: cx, y: cy - 4, textAnchor: 'middle', fontSize: 12, fill: '#6b7280' }, __('Total', 'memberistic')),
				h('text', { x: cx, y: cy + 14, textAnchor: 'middle', fontSize: 18, fontWeight: 700, fill: '#0f2044' }, formatCompactCurrency(total))
			),
			h('ul', { className: 'mb-donut__legend' }, legend)
		);
	}

	function Skeleton(props) {
		const rows = props.rows || 3;
		const out = [];
		for (let i = 0; i < rows; i++) {
			out.push(h('div', { key: 'sk' + i, className: 'mb-skel-row' }));
		}
		return h('div', { className: 'mb-skel' }, out);
	}

	/* ------------------------------------------------------------------ */
	/* Hooks                                                              */
	/* ------------------------------------------------------------------ */

	function useResource(path, deps) {
		const [state, setState] = useState({ data: null, loading: true, error: null });

		useEffect(function () {
			let cancelled = false;

			function load() {
				apiFetch({ path: path }).then(
					function (data) { if (!cancelled) setState({ data: data, loading: false, error: null }); },
					function (err) {
						if (cancelled) return;
						const msg = (err && (err.message || err.code)) || __('Request failed.', 'memberistic');
						setState(function (prev) { return { data: prev.data, loading: false, error: msg }; });
					}
				);
			}

			load();
			const id = window.setInterval(load, REFRESH_MS);
			return function () { cancelled = true; window.clearInterval(id); };
		}, deps || []);

		return state;
	}

	/* ------------------------------------------------------------------ */
	/* Main App                                                            */
	/* ------------------------------------------------------------------ */

	function Dashboard() {
		const stats = useResource('/' + NS + '/dashboard/stats');
		const expiring = useResource('/' + NS + '/dashboard/expiring-soon?days=30&limit=10');
		const activity = useResource('/' + NS + '/dashboard/recent-activity?limit=8');
		const history = useResource('/' + NS + '/dashboard/revenue-history?months=12');

		const isLoading = stats.loading && !stats.data;
		const data = stats.data || {};

		const revenueByPlan = useMemo(function () {
			if (!data.revenue_by_plan) return [];
			return data.revenue_by_plan.map(function (row) {
				return {
					label: row.plan_name || __('Unknown', 'memberistic'),
					value: Number(row.revenue) || 0,
				};
			});
		}, [data.revenue_by_plan]);

		const historyTrend = useMemo(function () {
			const series = (history.data || []).map(function (p) { return Number(p.revenue) || 0; });
			if (series.length < 2) return null;
			const last = series[series.length - 1];
			const prev = series[series.length - 2];
			if (!prev) return last > 0 ? 100 : 0;
			return ((last - prev) / prev) * 100;
		}, [history.data]);

		return h('div', { className: 'mb-app' },
			h('header', { className: 'mb-app__header' },
				h('div', null,
					h('h1', null, __('Memberistic Dashboard', 'memberistic')),
					h('p', { className: 'mb-app__sub' }, __('Live overview of memberships, revenue, and operations. Refreshes every 30 seconds.', 'memberistic'))
				),
				stats.error ? h('div', { className: 'mb-banner mb-banner--error' }, stats.error) : null
			),

			h('section', { className: 'mb-grid mb-grid--stats' },
				isLoading
					? [0, 1, 2, 3, 4, 5].map(function (i) { return h('div', { key: 'sk' + i, className: 'mb-stat mb-stat--skeleton' }); })
					: [
						h(StatCard, {
							key: 'active',
							label: __('Active Members', 'memberistic'),
							value: formatNumber(data.active_members),
							sub: sprintf(__('%s new this month', 'memberistic'), formatNumber(data.new_members_month)),
							tone: 'success',
						}),
						h(StatCard, {
							key: 'mrr',
							label: __('Monthly Revenue', 'memberistic'),
							value: formatCurrency(data.monthly_revenue),
							sub: sprintf(__('Annual: %s', 'memberistic'), formatCurrency(data.annual_revenue)),
							tone: 'primary',
							trend: historyTrend,
						}),
						h(StatCard, {
							key: 'expiring',
							label: __('Expiring in 30d', 'memberistic'),
							value: formatNumber(data.expiring_soon),
							sub: __('Schedule a renewal nudge', 'memberistic'),
							tone: 'warning',
						}),
						h(StatCard, {
							key: 'past_due',
							label: __('Past Due', 'memberistic'),
							value: formatNumber(data.past_due),
							sub: sprintf(__('%s failed payments', 'memberistic'), formatNumber(data.payment_failed)),
							tone: data.past_due > 0 ? 'danger' : 'neutral',
						}),
						h(StatCard, {
							key: 'checkins',
							label: __('Check-ins Today', 'memberistic'),
							value: formatNumber(data.checkins_today),
							sub: __('Front desk activity', 'memberistic'),
							tone: 'info',
						}),
						h(StatCard, {
							key: 'waiver',
							label: __('Waivers Missing', 'memberistic'),
							value: formatNumber(data.waiver_missing),
							sub: __('Across all active members', 'memberistic'),
							tone: data.waiver_missing > 0 ? 'warning' : 'neutral',
						}),
					]
			),

			h('section', { className: 'mb-grid mb-grid--charts' },
				h('div', { className: 'mb-card mb-card--chart' },
					h('div', { className: 'mb-card__head' },
						h('h2', null, __('Revenue — last 12 months', 'memberistic')),
						history.loading && !history.data
							? h('span', { className: 'mb-card__hint' }, __('Loading…', 'memberistic'))
							: null
					),
					history.error
						? h('div', { className: 'mb-banner mb-banner--error' }, history.error)
						: h(Sparkline, { series: history.data || [] })
				),
				h('div', { className: 'mb-card mb-card--chart' },
					h('div', { className: 'mb-card__head' },
						h('h2', null, __('Revenue by Plan', 'memberistic'))
					),
					h(Donut, { segments: revenueByPlan })
				)
			),

			h('section', { className: 'mb-grid mb-grid--lists' },
				h('div', { className: 'mb-card' },
					h('div', { className: 'mb-card__head' },
						h('h2', null, __('Renewals in next 30 days', 'memberistic')),
						h('a', { className: 'mb-card__action', href: 'admin.php?page=memberistic-members' }, __('View members →', 'memberistic'))
					),
					expiring.loading && !expiring.data
						? h(Skeleton, { rows: 4 })
						: (expiring.data || []).length === 0
							? h('p', { className: 'mb-empty' }, __('No memberships renewing in the next 30 days.', 'memberistic'))
							: h('ul', { className: 'mb-list mb-list--renewals' },
								(expiring.data || []).map(function (row) {
									const days = daysUntil(row.renewal_date);
									const urgent = days !== null && days <= 7;
									return h('li', { key: row.id, className: 'mb-list__row' + (urgent ? ' is-urgent' : '') },
										h('div', { className: 'mb-list__main' },
											h('strong', null, row.full_name || __('Not assigned', 'memberistic')),
											h('span', { className: 'mb-list__meta' }, row.plan_name || '')
										),
										h('div', { className: 'mb-list__side' },
											h('span', { className: 'mb-list__when' }, days === null ? '—' : (days <= 0 ? __('Today', 'memberistic') : sprintf(__('in %dd', 'memberistic'), days))),
											h('a', { className: 'mb-list__cta', href: 'admin.php?page=memberistic-members&action=view&id=' + encodeURIComponent(row.id) }, __('Open', 'memberistic'))
										)
									);
								})
							)
				),
				h('div', { className: 'mb-card' },
					h('div', { className: 'mb-card__head' },
						h('h2', null, __('Recent Activity', 'memberistic')),
						h('a', { className: 'mb-card__action', href: 'admin.php?page=memberistic-activity' }, __('Full log →', 'memberistic'))
					),
					activity.loading && !activity.data
						? h(Skeleton, { rows: 4 })
						: (activity.data || []).length === 0
							? h('p', { className: 'mb-empty' }, __('No activity logged yet.', 'memberistic'))
							: h('ul', { className: 'mb-list mb-list--activity' },
								(activity.data || []).map(function (row) {
									return h('li', { key: row.id, className: 'mb-list__row' },
										h('div', { className: 'mb-list__main' },
											h('strong', null, row.title || statusLabel(row.activity_type)),
											h('span', { className: 'mb-list__meta' }, statusLabel(row.activity_type))
										),
										h('span', { className: 'mb-list__when' }, relativeTime(row.created_at))
									);
								})
							)
				)
			)
		);
	}

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById(ROOT_ID);
		if (root) {
			render(h(Dashboard), root);
		}
	});
})(window.wp);
