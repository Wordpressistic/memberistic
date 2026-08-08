/**
 * Memberistic — Saved Views helper
 *
 * Tiny module the list apps (Members, Payments, Check-ins, Activity) share to
 * expose per-user named filter buckets backed by /memberistic/v1/saved-views.
 *
 * Each consumer calls SavedViews.attach({ context, filters, onApply }) and
 * receives an h-element to drop into its toolbar.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element || !wp.apiFetch) return;

	const { createElement: h, useState, useEffect, useCallback } = wp.element;
	const apiFetch = wp.apiFetch;
	const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
	const sprintf = (wp.i18n && wp.i18n.sprintf) ? wp.i18n.sprintf : function (s) { return s; };

	const NS = 'memberistic/v1';

	function SavedViewsBar(props) {
		const { context, currentFilters, onApply } = props;

		const [views, setViews] = useState([]);
		const [loading, setLoading] = useState(false);
		const [error, setError] = useState('');
		const [naming, setNaming] = useState(false);
		const [draftName, setDraftName] = useState('');
		const [activeId, setActiveId] = useState('');

		const load = useCallback(function () {
			setLoading(true);
			apiFetch({ path: '/' + NS + '/saved-views?context=' + encodeURIComponent(context) }).then(
				function (data) { setViews(Array.isArray(data) ? data : []); setLoading(false); },
				function () { setLoading(false); }
			);
		}, [context]);

		useEffect(load, [load]);

		function onSelect(e) {
			const id = e.target.value;
			setActiveId(id);
			if (!id) return;
			const match = views.find(function (v) { return v.id === id; });
			if (match && match.filters) onApply(match.filters);
		}

		function saveCurrent() {
			const name = draftName.trim();
			if (!name) { setError(__('Give the view a name.', 'memberistic')); return; }
			setError('');
			apiFetch({
				path: '/' + NS + '/saved-views',
				method: 'POST',
				data: { context: context, name: name, filters: currentFilters || {} },
			}).then(
				function (saved) {
					setNaming(false);
					setDraftName('');
					setViews(function (prev) { return prev.concat([saved]); });
					setActiveId(saved.id);
				},
				function (err) { setError((err && err.message) || __('Could not save view.', 'memberistic')); }
			);
		}

		function deleteActive() {
			if (!activeId) return;
			const match = views.find(function (v) { return v.id === activeId; });
			if (!match) return;
			if (!window.confirm(sprintf(__('Delete saved view "%s"?', 'memberistic'), match.name))) return;
			apiFetch({
				path: '/' + NS + '/saved-views/' + encodeURIComponent(activeId),
				method: 'DELETE',
			}).then(
				function () {
					setViews(function (prev) { return prev.filter(function (v) { return v.id !== activeId; }); });
					setActiveId('');
				},
				function (err) { window.alert((err && err.message) || __('Could not delete view.', 'memberistic')); }
			);
		}

		return h('div', { className: 'mb-saved-views' },
			naming
				? h('span', { className: 'mb-saved-views__naming' },
					h('input', {
						type: 'text',
						className: 'mb-toolbar__select',
						value: draftName,
						placeholder: __('Name this view', 'memberistic'),
						onChange: function (e) { setDraftName(e.target.value); },
						autoFocus: true,
					}),
					h('button', { className: 'button button-primary', onClick: saveCurrent }, __('Save', 'memberistic')),
					h('button', { className: 'button', onClick: function () { setNaming(false); setDraftName(''); setError(''); } }, __('Cancel', 'memberistic')),
					error ? h('span', { className: 'mb-saved-views__error' }, error) : null
				)
				: h('span', { className: 'mb-saved-views__row' },
					h('select', { className: 'mb-toolbar__select', value: activeId, onChange: onSelect, disabled: loading },
						[h('option', { key: 'none', value: '' }, __('Saved Views…', 'memberistic'))].concat(
							views.map(function (v) { return h('option', { key: v.id, value: v.id }, v.name); })
						)
					),
					h('button', { className: 'button', onClick: function () { setNaming(true); } }, __('Save current', 'memberistic')),
					activeId
						? h('button', { className: 'button', onClick: deleteActive, title: __('Delete the selected saved view', 'memberistic') }, __('Delete', 'memberistic'))
						: null
				)
		);
	}

	window.MemberisticSavedViews = {
		Bar: SavedViewsBar,
	};
})(window.wp);
