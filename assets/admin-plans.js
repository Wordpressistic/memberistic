/**
 * Memberistic — Premium Plans Console
 *
 * Live list of plans with an inline create/edit panel. Uses the same REST
 * endpoints as the Members app — plus DELETE for safe-delete (blocked when
 * active memberships are attached).
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element || !wp.apiFetch) {
		return;
	}

	const { createElement: h, Fragment, useState, useEffect, useCallback, render } = wp.element;
	const apiFetch = wp.apiFetch;
	const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
	const sprintf = (wp.i18n && wp.i18n.sprintf) ? wp.i18n.sprintf : function (s) { return s; };

	const ROOT_ID = 'memberistic-plans-app';
	const NS = 'memberistic/v1';
	const STATUSES = ['active', 'inactive'];

	const settings = (window.memberisticPlansSettings && typeof window.memberisticPlansSettings === 'object')
		? window.memberisticPlansSettings
		: {};
	const initialAction = settings.initialAction || '';
	const initialPlanId = Number(settings.initialPlanId) || 0;

	function statusLabel(status) {
		return String(status || '')
			.replace(/_/g, ' ')
			.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}

	function statusTone(status) {
		const map = { active: 'success', inactive: 'muted' };
		return map[status] || 'muted';
	}

	function StatusPill(props) {
		return h('span', { className: 'mb-pill mb-pill--' + statusTone(props.status) }, statusLabel(props.status || 'unknown'));
	}

	function formatPrice(value) {
		const n = Number(value) || 0;
		return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function slugify(value) {
		return String(value || '')
			.toLowerCase()
			.replace(/[^\w\s-]/g, '')
			.trim()
			.replace(/\s+/g, '-')
			.replace(/-+/g, '-');
	}

	function parseBenefits(raw) {
		if (Array.isArray(raw)) return raw;
		if (!raw) return [];
		try {
			const parsed = JSON.parse(String(raw));
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}

	function benefitsToText(raw) {
		return parseBenefits(raw).join('\n');
	}

	function textToBenefits(text) {
		return String(text || '')
			.split(/\r?\n/)
			.map(function (s) { return s.trim(); })
			.filter(Boolean);
	}

	/* ------------------------------------------------------------------ */
	/* Editor panel                                                        */
	/* ------------------------------------------------------------------ */

	function Field(props) {
		return h('label', { className: 'mb-field' + (props.full ? ' mb-field--full' : '') },
			h('span', { className: 'mb-field__label' }, props.label),
			props.children,
			props.hint ? h('span', { className: 'mb-field__hint' }, props.hint) : null
		);
	}

	function PlanEditor(props) {
		const editing = !!props.plan;
		const initial = props.plan || {};
		const [autoSlug, setAutoSlug] = useState(!editing);

		const [values, setValues] = useState({
			name: initial.name || '',
			slug: initial.slug || '',
			description: initial.description || '',
			monthly_price: initial.monthly_price || '0',
			annual_price: initial.annual_price || '0',
			included_people: initial.included_people || 1,
			benefits: benefitsToText(initial.benefits),
			is_featured: !!Number(initial.is_featured),
			sort_order: Number(initial.sort_order) || 0,
			status: initial.status || 'active',
		});
		const [busy, setBusy] = useState('');
		const [error, setError] = useState('');

		function update(field) {
			return function (e) {
				const v = e.target.value;
				setValues(function (prev) { return Object.assign({}, prev, { [field]: v }); });
			};
		}

		function updateName(e) {
			const v = e.target.value;
			setValues(function (prev) {
				return Object.assign({}, prev, {
					name: v,
					slug: autoSlug ? slugify(v) : prev.slug,
				});
			});
		}

		function updateSlug(e) {
			setAutoSlug(false);
			setValues(function (prev) { return Object.assign({}, prev, { slug: slugify(e.target.value) }); });
		}

		function toggleFeatured(e) {
			const v = !!e.target.checked;
			setValues(function (prev) { return Object.assign({}, prev, { is_featured: v }); });
		}

		function submit(e) {
			e.preventDefault();
			setError('');

			if (!values.name.trim()) { setError(__('Plan name is required.', 'memberistic')); return; }
			if (!values.slug.trim()) { setError(__('Plan slug is required.', 'memberistic')); return; }

			const payload = {
				name: values.name,
				slug: values.slug,
				description: values.description,
				monthly_price: Number(values.monthly_price) || 0,
				annual_price: Number(values.annual_price) || 0,
				included_people: Number(values.included_people) || 1,
				benefits: textToBenefits(values.benefits),
				is_featured: values.is_featured ? 1 : 0,
				sort_order: Number(values.sort_order) || 0,
				status: values.status,
			};

			setBusy('save');
			apiFetch({
				path: '/' + NS + '/plans' + (editing ? '/' + initial.id : ''),
				method: editing ? 'PUT' : 'POST',
				data: payload,
			}).then(
				function (saved) { setBusy(''); props.onSaved(saved); },
				function (err) {
					setBusy('');
					setError((err && err.message) || __('Could not save the plan.', 'memberistic'));
				}
			);
		}

		function deletePlan() {
			if (!editing) return;
			if (!window.confirm(__('Delete this plan? Plans with active memberships cannot be deleted.', 'memberistic'))) return;
			setBusy('delete');
			apiFetch({
				path: '/' + NS + '/plans/' + initial.id,
				method: 'DELETE',
			}).then(
				function () { setBusy(''); props.onDeleted(initial.id); },
				function (err) {
					setBusy('');
					setError((err && err.message) || __('Could not delete the plan.', 'memberistic'));
				}
			);
		}

		return h('aside', { className: 'mb-panel', role: 'dialog', 'aria-modal': 'true' },
			h('div', { className: 'mb-panel__scrim', onClick: props.onCancel }),
			h('div', { className: 'mb-panel__sheet' },
				h('header', { className: 'mb-panel__header' },
					h('div', null,
						h('div', { className: 'mb-panel__crumb' }, editing ? __('Edit Plan', 'memberistic') : __('New Plan', 'memberistic')),
						h('h2', null, editing ? (initial.name || __('Plan', 'memberistic')) : __('Create a plan', 'memberistic')),
						h('div', { className: 'mb-panel__meta' },
							editing ? h(StatusPill, { status: initial.status }) : null,
							editing && initial.slug
								? h('span', { className: 'mb-panel__metaItem' }, h('code', null, initial.slug))
								: null
						)
					),
					h('div', { className: 'mb-panel__actions' },
						editing ? h('button', {
							className: 'button',
							onClick: deletePlan,
							disabled: !!busy,
						}, busy === 'delete' ? __('Deleting…', 'memberistic') : __('Delete plan', 'memberistic')) : null,
						h('button', { className: 'mb-panel__close', onClick: props.onCancel, 'aria-label': __('Close panel', 'memberistic') }, '×')
					)
				),
				h('div', { className: 'mb-panel__body' },
					h('form', { className: 'mb-form', onSubmit: submit },
						error ? h('div', { className: 'mb-banner mb-banner--error' }, error) : null,

						h('h3', { className: 'mb-form__section' }, __('Identity', 'memberistic')),
						h('div', { className: 'mb-form__grid' },
							h(Field, { label: __('Name', 'memberistic') },
								h('input', { type: 'text', value: values.name, onChange: updateName, required: true })
							),
							h(Field, { label: __('Slug', 'memberistic'), hint: __('Used in URLs and integrations.', 'memberistic') },
								h('input', { type: 'text', value: values.slug, onChange: updateSlug, required: true })
							),
							h(Field, { label: __('Status', 'memberistic') },
								h('select', { value: values.status, onChange: update('status') },
									STATUSES.map(function (s) { return h('option', { key: s, value: s }, statusLabel(s)); })
								)
							),
							h(Field, { label: __('Sort order', 'memberistic') },
								h('input', { type: 'number', min: '0', value: values.sort_order, onChange: update('sort_order') })
							)
						),
						h(Field, { label: __('Description', 'memberistic'), full: true },
							h('textarea', { rows: 2, value: values.description, onChange: update('description') })
						),

						h('h3', { className: 'mb-form__section' }, __('Pricing & capacity', 'memberistic')),
						h('div', { className: 'mb-form__grid' },
							h(Field, { label: __('Monthly price', 'memberistic') },
								h('input', { type: 'number', min: '0', step: '0.01', value: values.monthly_price, onChange: update('monthly_price') })
							),
							h(Field, { label: __('Annual price', 'memberistic') },
								h('input', { type: 'number', min: '0', step: '0.01', value: values.annual_price, onChange: update('annual_price') })
							),
							h(Field, { label: __('Included people', 'memberistic') },
								h('input', { type: 'number', min: '1', value: values.included_people, onChange: update('included_people') })
							),
							h(Field, { label: __('Feature on plan grid', 'memberistic') },
								h('span', { className: 'mb-field__checkbox' },
									h('input', { type: 'checkbox', checked: values.is_featured, onChange: toggleFeatured }),
									h('span', null, values.is_featured ? __('Highlighted', 'memberistic') : __('Standard', 'memberistic'))
								)
							)
						),

						h('h3', { className: 'mb-form__section' }, __('Benefits', 'memberistic')),
						h(Field, { label: __('Bullet list — one benefit per line', 'memberistic'), full: true },
							h('textarea', { rows: 5, value: values.benefits, onChange: update('benefits'), placeholder: __('e.g. Range access\nDiscount on instruction', 'memberistic') })
						),

						h('div', { className: 'mb-form__actions' },
							h('button', { type: 'button', className: 'button', onClick: props.onCancel, disabled: !!busy }, __('Cancel', 'memberistic')),
							h('button', { type: 'submit', className: 'button button-primary', disabled: !!busy },
								busy === 'save'
									? __('Saving…', 'memberistic')
									: editing ? __('Save changes', 'memberistic') : __('Create plan', 'memberistic')
							)
						)
					)
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* List view                                                           */
	/* ------------------------------------------------------------------ */

	function PlansApp() {
		const [plans, setPlans] = useState([]);
		const [planStats, setPlanStats] = useState([]);
		const [loading, setLoading] = useState(true);
		const [error, setError] = useState(null);
		const [editing, setEditing] = useState(initialAction === 'add' ? { plan: null } : null);
		const [revision, setRevision] = useState(0);

		const load = useCallback(function () {
			setLoading(true);
			apiFetch({ path: '/' + NS + '/plans' }).then(
				function (data) { setPlans(Array.isArray(data) ? data : []); setLoading(false); },
				function (err) {
					setError((err && err.message) || __('Could not load plans.', 'memberistic'));
					setLoading(false);
				}
			);
			apiFetch({ path: '/' + NS + '/plans/stats' }).then(
				function (data) { setPlanStats(Array.isArray(data) ? data : []); },
				function () { /* silent */ }
			);
		}, []);

		useEffect(load, [load, revision]);

		useEffect(function () {
			if (initialAction === 'edit' && initialPlanId && plans.length && !editing) {
				const match = plans.find(function (p) { return Number(p.id) === initialPlanId; });
				if (match) setEditing({ plan: match });
			}
		}, [plans]); // eslint-disable-line react-hooks/exhaustive-deps

		const refresh = useCallback(function () { setRevision(function (r) { return r + 1; }); }, []);

		function openCreate() { setEditing({ plan: null }); }
		function openEdit(plan) { setEditing({ plan: plan }); }
		function closeEditor() { setEditing(null); }

		const statsById = (function () {
			const m = {};
			(planStats || []).forEach(function (s) { m[Number(s.plan_id)] = s; });
			return m;
		})();

		function renderBenefits(raw) {
			const list = parseBenefits(raw).slice(0, 4);
			if (!list.length) return null;
			return h('ul', { className: 'mb-plan-card__benefits' },
				list.map(function (b, i) { return h('li', { key: 'b-' + i }, b); })
			);
		}

		function PlanCard(props) {
			const p = props.plan;
			const s = statsById[Number(p.id)] || { total: 0, active: 0 };
			const inactiveOrChurn = Math.max(0, Number(s.total) - Number(s.active));

			return h('div', {
				className: 'mb-plan-card' + (Number(p.is_featured) ? ' is-featured' : ''),
				onClick: function () { openEdit(p); },
				role: 'button',
				tabIndex: 0,
				onKeyDown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openEdit(p); } },
			},
				Number(p.is_featured) ? h('span', { className: 'mb-plan-card__ribbon' }, __('Featured', 'memberistic')) : null,
				h('div', { className: 'mb-plan-card__head' },
					h('div', null,
						h('h3', { className: 'mb-plan-card__name' }, p.name || __('Untitled', 'memberistic')),
						h('span', { className: 'mb-plan-card__slug' }, p.slug || '')
					),
					h(StatusPill, { status: p.status })
				),
				p.description ? h('p', { className: 'mb-plan-card__desc' }, p.description) : null,
				h('div', { className: 'mb-plan-card__prices' },
					h('div', { className: 'mb-plan-card__price' },
						h('span', { className: 'mb-plan-card__price-label' }, __('Monthly', 'memberistic')),
						h('span', { className: 'mb-plan-card__price-value' }, formatPrice(p.monthly_price))
					),
					h('div', { className: 'mb-plan-card__price' },
						h('span', { className: 'mb-plan-card__price-label' }, __('Annual', 'memberistic')),
						h('span', { className: 'mb-plan-card__price-value' }, formatPrice(p.annual_price))
					)
				),
				renderBenefits(p.benefits),
				h('div', { className: 'mb-plan-card__meta' },
					h('span', null, sprintf(__('Capacity: %s', 'memberistic'), Number(p.included_people) || 1)),
					h('span', null, '·'),
					h('span', null, sprintf(__('Sort %d', 'memberistic'), Number(p.sort_order) || 0))
				),
				h('div', { className: 'mb-plan-card__actions' },
					h('button', { type: 'button', className: 'button button-primary', onClick: function (e) { e.stopPropagation(); openEdit(p); } }, __('Edit plan', 'memberistic'))
				),
				h('div', { className: 'mb-plan-card__members' },
					h('div', { className: 'mb-plan-card__members-stat' },
						h('span', { className: 'mb-plan-card__members-label' }, __('Total', 'memberistic')),
						h('span', { className: 'mb-plan-card__members-value' }, (Number(s.total) || 0).toLocaleString())
					),
					h('div', { className: 'mb-plan-card__members-stat' },
						h('span', { className: 'mb-plan-card__members-label' }, __('Active', 'memberistic')),
						h('span', { className: 'mb-plan-card__members-value is-active' }, (Number(s.active) || 0).toLocaleString())
					),
					h('div', { className: 'mb-plan-card__members-stat' },
						h('span', { className: 'mb-plan-card__members-label' }, __('Other', 'memberistic')),
						h('span', { className: 'mb-plan-card__members-value' + (inactiveOrChurn > 0 ? ' is-warn' : '') }, inactiveOrChurn.toLocaleString())
					)
				)
			);
		}

		return h('div', { className: 'mb-app' },
			h('header', { className: 'mb-app__header' },
				h('div', null,
					h('h1', null, __('Plans', 'memberistic')),
					h('p', { className: 'mb-app__sub' }, __('Configure your membership tiers, pricing, capacity, and bullet-point benefits.', 'memberistic'))
				),
				h('button', { className: 'button button-primary', onClick: openCreate }, __('Add New Plan', 'memberistic'))
			),

			error ? h('div', { className: 'mb-banner mb-banner--error' }, error) : null,

			loading && !plans.length
				? h('div', { className: 'mb-card mb-empty', role: 'status', 'aria-live': 'polite' }, __('Loading plans…', 'memberistic'))
				: !plans.length
					? h('div', { className: 'mb-card mb-empty mb-empty--first-run' },
						h('h2', { className: 'mb-empty__title' }, __('Create your first membership plan', 'memberistic')),
						h('p', { className: 'mb-empty__body' }, __('A plan defines what a membership costs, how many people it covers, and what members get. Nothing is offered for sale until you activate one.', 'memberistic')),
						h('p', null,
							h('button', { className: 'button button-primary button-hero', onClick: openCreate }, __('Add your first plan', 'memberistic'))
						),
						h('p', { className: 'mb-empty__hint' }, __('Prefer a starting point? The plugin bundles example plan sets for gyms, studios, clubs, associations, and ranges in templates/plans/. They import as inactive so you can review the pricing before publishing.', 'memberistic'))
					)
					: h('div', { className: 'mb-plan-grid mb-grid--fade' },
						plans.map(function (p) { return h(PlanCard, { key: p.id, plan: p }); })
					),

			editing ? h(PlanEditor, {
				plan: editing.plan,
				onCancel: closeEditor,
				onSaved: function () { closeEditor(); refresh(); },
				onDeleted: function () { closeEditor(); refresh(); },
			}) : null
		);
	}

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById(ROOT_ID);
		if (root) {
			render(h(PlansApp), root);
		}
	});
})(window.wp);
