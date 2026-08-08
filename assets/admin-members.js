/**
 * Memberistic — Premium Members Console
 *
 * Live filterable list, slide-in detail panel with inline add/edit forms,
 * and a Create flow that opens directly in the panel — no full-page reloads.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element || !wp.apiFetch) {
		return;
	}

	const { createElement: h, Fragment, useState, useEffect, useCallback, useMemo, render } = wp.element;
	const apiFetch = wp.apiFetch;
	const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
	const sprintf = (wp.i18n && wp.i18n.sprintf) ? wp.i18n.sprintf : function (s) { return s; };

	const ROOT_ID = 'memberistic-members-app';
	const NS = 'memberistic/v1';
	const STATUS_OPTIONS = ['', 'pending', 'active', 'past_due', 'expired', 'cancelled', 'paused', 'comped', 'trial'];
	const EDITABLE_STATUSES = ['pending', 'active', 'past_due', 'expired', 'cancelled', 'paused', 'comped', 'trial'];
	const WAIVER_OPTIONS = ['missing', 'signed', 'expired', 'needs_review', 'rejected'];
	const PAYMENT_METHODS = ['manual', 'cash', 'card', 'check', 'transfer', 'pos', 'stripe', 'other'];
	const PAYMENT_STATUSES = ['completed', 'pending', 'failed', 'refunded'];
	const CHECKIN_TYPES = ['walk_in', 'booking', 'class', 'event', 'guest'];

	const settings = (window.memberisticMembersSettings && typeof window.memberisticMembersSettings === 'object')
		? window.memberisticMembersSettings
		: {};
	const canCreate = !!settings.canCreate;
	const initialAction = settings.initialAction || '';
	const initialSelectedId = Number(settings.initialSelectedId) || 0;

	/* ------------------------------------------------------------------ */
	/* Formatters                                                          */
	/* ------------------------------------------------------------------ */

	function statusLabel(status) {
		return String(status || '')
			.replace(/_/g, ' ')
			.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}

	function statusTone(status) {
		const map = {
			active: 'success', comped: 'success', signed: 'success', completed: 'success',
			pending: 'warning', past_due: 'danger', missing: 'warning',
			cancelled: 'muted', expired: 'muted', paused: 'info', trial: 'info',
			rejected: 'danger', failed: 'danger', refunded: 'info',
		};
		return map[status] || 'muted';
	}

	function formatDate(iso) {
		if (!iso) return '—';
		const ts = Date.parse(iso.replace(' ', 'T') + 'Z');
		if (isNaN(ts)) return iso;
		return new Date(ts).toLocaleDateString();
	}

	function formatCurrency(value) {
		return '$' + (Number(value) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function toDateInputValue(iso) {
		if (!iso) return '';
		const ts = Date.parse(iso.replace(' ', 'T') + 'Z');
		if (isNaN(ts)) return '';
		const d = new Date(ts);
		const m = String(d.getMonth() + 1).padStart(2, '0');
		const day = String(d.getDate()).padStart(2, '0');
		return d.getFullYear() + '-' + m + '-' + day;
	}

	function StatusPill(props) {
		return h('span', { className: 'mb-pill mb-pill--' + statusTone(props.status) }, statusLabel(props.status || 'unknown'));
	}

	function useDebounced(value, delay) {
		const [debounced, setDebounced] = useState(value);
		useEffect(function () {
			const id = window.setTimeout(function () { setDebounced(value); }, delay);
			return function () { window.clearTimeout(id); };
		}, [value, delay]);
		return debounced;
	}

	/* ------------------------------------------------------------------ */
	/* Inline forms                                                        */
	/* ------------------------------------------------------------------ */

	function Field(props) {
		return h('label', { className: 'mb-field' + (props.full ? ' mb-field--full' : '') },
			h('span', { className: 'mb-field__label' }, props.label),
			props.children
		);
	}

	function FormActions(props) {
		return h('div', { className: 'mb-form__actions' },
			h('button', {
				type: 'button',
				className: 'button',
				disabled: props.busy,
				onClick: props.onCancel,
			}, __('Cancel', 'memberistic')),
			h('button', {
				type: 'submit',
				className: 'button button-primary',
				disabled: props.busy,
			}, props.busy ? __('Saving…', 'memberistic') : (props.submitLabel || __('Save', 'memberistic')))
		);
	}

	function FormError(props) {
		if (!props.error) return null;
		return h('div', { className: 'mb-banner mb-banner--error' }, props.error);
	}

	function AddPersonForm(props) {
		const [values, setValues] = useState({ full_name: '', email: '', phone: '', relationship: '', waiver_status: 'missing' });
		const [busy, setBusy] = useState(false);
		const [error, setError] = useState('');

		function update(field) {
			return function (e) { setValues(Object.assign({}, values, { [field]: e.target.value })); };
		}

		function submit(e) {
			e.preventDefault();
			setError('');
			if (!values.full_name.trim()) { setError(__('Full name is required.', 'memberistic')); return; }
			setBusy(true);
			apiFetch({
				path: '/' + NS + '/memberships/' + props.membershipId + '/people',
				method: 'POST',
				data: values,
			}).then(
				function () { setBusy(false); props.onSaved(); },
				function (err) { setBusy(false); setError((err && err.message) || __('Could not add person.', 'memberistic')); }
			);
		}

		return h('form', { className: 'mb-form', onSubmit: submit },
			h(FormError, { error: error }),
			h('div', { className: 'mb-form__grid' },
				h(Field, { label: __('Full name', 'memberistic') },
					h('input', { type: 'text', value: values.full_name, onChange: update('full_name'), required: true })
				),
				h(Field, { label: __('Email', 'memberistic') },
					h('input', { type: 'email', value: values.email, onChange: update('email') })
				),
				h(Field, { label: __('Phone', 'memberistic') },
					h('input', { type: 'text', value: values.phone, onChange: update('phone') })
				),
				h(Field, { label: __('Relationship', 'memberistic') },
					h('input', { type: 'text', value: values.relationship, onChange: update('relationship'), placeholder: __('e.g. Spouse, Child', 'memberistic') })
				),
				h(Field, { label: __('Waiver Status', 'memberistic') },
					h('select', { value: values.waiver_status, onChange: update('waiver_status') },
						WAIVER_OPTIONS.map(function (w) { return h('option', { key: w, value: w }, statusLabel(w)); })
					)
				)
			),
			h(FormActions, { busy: busy, onCancel: props.onCancel, submitLabel: __('Add person', 'memberistic') })
		);
	}

	function AddPaymentForm(props) {
		const [values, setValues] = useState({ amount: '', currency: 'USD', payment_method: 'manual', status: 'completed', paid_at: toDateInputValue(new Date().toISOString()) });
		const [busy, setBusy] = useState(false);
		const [error, setError] = useState('');

		function update(field) {
			return function (e) { setValues(Object.assign({}, values, { [field]: e.target.value })); };
		}

		function submit(e) {
			e.preventDefault();
			setError('');
			const amount = Number(values.amount);
			if (!isFinite(amount) || amount <= 0) { setError(__('Enter a positive amount.', 'memberistic')); return; }
			setBusy(true);
			apiFetch({
				path: '/' + NS + '/memberships/' + props.membershipId + '/payments',
				method: 'POST',
				data: Object.assign({}, values, { amount: amount, paid_at: values.paid_at || undefined }),
			}).then(
				function () { setBusy(false); props.onSaved(); },
				function (err) { setBusy(false); setError((err && err.message) || __('Could not record payment.', 'memberistic')); }
			);
		}

		return h('form', { className: 'mb-form', onSubmit: submit },
			h(FormError, { error: error }),
			h('div', { className: 'mb-form__grid' },
				h(Field, { label: __('Amount', 'memberistic') },
					h('input', { type: 'number', min: '0', step: '0.01', value: values.amount, onChange: update('amount'), required: true })
				),
				h(Field, { label: __('Currency', 'memberistic') },
					h('input', { type: 'text', maxLength: 3, value: values.currency, onChange: update('currency') })
				),
				h(Field, { label: __('Payment method', 'memberistic') },
					h('select', { value: values.payment_method, onChange: update('payment_method') },
						PAYMENT_METHODS.map(function (m) { return h('option', { key: m, value: m }, statusLabel(m)); })
					)
				),
				h(Field, { label: __('Status', 'memberistic') },
					h('select', { value: values.status, onChange: update('status') },
						PAYMENT_STATUSES.map(function (s) { return h('option', { key: s, value: s }, statusLabel(s)); })
					)
				),
				h(Field, { label: __('Paid on', 'memberistic') },
					h('input', { type: 'date', value: values.paid_at, onChange: update('paid_at') })
				)
			),
			h(FormActions, { busy: busy, onCancel: props.onCancel, submitLabel: __('Record payment', 'memberistic') })
		);
	}

	function AddNoteForm(props) {
		const [note, setNote] = useState('');
		const [busy, setBusy] = useState(false);
		const [error, setError] = useState('');

		function submit(e) {
			e.preventDefault();
			setError('');
			if (!note.trim()) { setError(__('Please enter a note.', 'memberistic')); return; }
			setBusy(true);
			apiFetch({
				path: '/' + NS + '/memberships/' + props.membershipId + '/notes',
				method: 'POST',
				data: { note: note },
			}).then(
				function () { setBusy(false); setNote(''); props.onSaved(); },
				function (err) { setBusy(false); setError((err && err.message) || __('Could not save note.', 'memberistic')); }
			);
		}

		return h('form', { className: 'mb-form', onSubmit: submit },
			h(FormError, { error: error }),
			h(Field, { label: __('Staff-only note', 'memberistic'), full: true },
				h('textarea', { rows: 3, value: note, onChange: function (e) { setNote(e.target.value); }, required: true })
			),
			h(FormActions, { busy: busy, onCancel: props.onCancel, submitLabel: __('Save note', 'memberistic') })
		);
	}

	function AddCheckinForm(props) {
		const people = props.people || [];
		const defaultPerson = people[0] ? people[0].id : 0;
		const [values, setValues] = useState({ person_id: defaultPerson, checkin_type: 'walk_in', notes: '' });
		const [busy, setBusy] = useState(false);
		const [error, setError] = useState('');

		function update(field) {
			return function (e) {
				const v = e.target.value;
				setValues(Object.assign({}, values, { [field]: field === 'person_id' ? Number(v) : v }));
			};
		}

		function submit(e) {
			e.preventDefault();
			setError('');
			if (!values.person_id) { setError(__('Select a person to check in.', 'memberistic')); return; }
			setBusy(true);
			apiFetch({
				path: '/' + NS + '/memberships/' + props.membershipId + '/checkins',
				method: 'POST',
				data: values,
			}).then(
				function () { setBusy(false); props.onSaved(); },
				function (err) { setBusy(false); setError((err && err.message) || __('Could not record check-in.', 'memberistic')); }
			);
		}

		return h('form', { className: 'mb-form', onSubmit: submit },
			h(FormError, { error: error }),
			h('div', { className: 'mb-form__grid' },
				h(Field, { label: __('Person', 'memberistic') },
					h('select', { value: String(values.person_id), onChange: update('person_id'), required: true },
						[h('option', { key: 'none', value: '0' }, __('Select a person', 'memberistic'))].concat(
							people.map(function (p) { return h('option', { key: p.id, value: String(p.id) }, p.full_name + ' (' + statusLabel(p.role) + ')'); })
						)
					)
				),
				h(Field, { label: __('Type', 'memberistic') },
					h('select', { value: values.checkin_type, onChange: update('checkin_type') },
						CHECKIN_TYPES.map(function (c) { return h('option', { key: c, value: c }, statusLabel(c)); })
					)
				)
			),
			h(Field, { label: __('Notes', 'memberistic'), full: true },
				h('textarea', { rows: 2, value: values.notes, onChange: update('notes') })
			),
			h(FormActions, { busy: busy, onCancel: props.onCancel, submitLabel: __('Record check-in', 'memberistic') })
		);
	}

	function EditPersonForm(props) {
		const person = props.person || {};
		const [values, setValues] = useState({
			full_name: person.full_name || '',
			email: person.email || '',
			phone: person.phone || '',
			relationship: person.relationship || '',
			waiver_status: person.waiver_status || 'missing',
			waiver_signed_at: toDateInputValue(person.waiver_signed_at),
			waiver_expires_at: toDateInputValue(person.waiver_expires_at),
			status: person.status || 'active',
		});
		const [busy, setBusy] = useState(false);
		const [error, setError] = useState('');

		function update(field) {
			return function (e) { setValues(Object.assign({}, values, { [field]: e.target.value })); };
		}

		function submit(e) {
			e.preventDefault();
			setError('');
			if (!values.full_name.trim()) { setError(__('Full name is required.', 'memberistic')); return; }
			setBusy(true);
			const payload = {
				full_name: values.full_name,
				email: values.email,
				phone: values.phone,
				relationship: values.relationship,
				waiver_status: values.waiver_status,
				waiver_signed_at: values.waiver_signed_at ? values.waiver_signed_at + ' 00:00:00' : '',
				waiver_expires_at: values.waiver_expires_at ? values.waiver_expires_at + ' 00:00:00' : '',
				status: values.status,
			};
			apiFetch({
				path: '/' + NS + '/people/' + person.id,
				method: 'POST',
				data: payload,
			}).then(
				function () { setBusy(false); props.onSaved(); },
				function (err) { setBusy(false); setError((err && err.message) || __('Could not update person.', 'memberistic')); }
			);
		}

		const isPrimary = (person.role === 'primary');
		const personStatuses = ['active', 'inactive', 'removed'];

		return h('form', { className: 'mb-form mb-form--inline-edit', onSubmit: submit },
			h(FormError, { error: error }),
			h('div', { className: 'mb-form__grid' },
				h(Field, { label: __('Full name', 'memberistic') },
					h('input', { type: 'text', value: values.full_name, onChange: update('full_name'), required: true })
				),
				h(Field, { label: __('Email', 'memberistic') },
					h('input', { type: 'email', value: values.email, onChange: update('email') })
				),
				h(Field, { label: __('Phone', 'memberistic') },
					h('input', { type: 'text', value: values.phone, onChange: update('phone') })
				),
				h(Field, { label: __('Relationship', 'memberistic') },
					h('input', {
						type: 'text',
						value: values.relationship,
						onChange: update('relationship'),
						placeholder: isPrimary ? __('Primary member', 'memberistic') : __('e.g. Spouse, Child', 'memberistic'),
						disabled: isPrimary,
					})
				),
				h(Field, { label: __('Waiver status', 'memberistic') },
					h('select', { value: values.waiver_status, onChange: update('waiver_status') },
						WAIVER_OPTIONS.map(function (w) { return h('option', { key: w, value: w }, statusLabel(w)); })
					)
				),
				h(Field, { label: __('Waiver signed on', 'memberistic') },
					h('input', { type: 'date', value: values.waiver_signed_at, onChange: update('waiver_signed_at') })
				),
				h(Field, { label: __('Waiver expires on', 'memberistic') },
					h('input', { type: 'date', value: values.waiver_expires_at, onChange: update('waiver_expires_at') })
				),
				h(Field, { label: __('Member status', 'memberistic') },
					h('select', { value: values.status, onChange: update('status') },
						personStatuses.map(function (s) { return h('option', { key: s, value: s }, statusLabel(s)); })
					)
				)
			),
			h(FormActions, { busy: busy, onCancel: props.onCancel, submitLabel: __('Save person', 'memberistic') })
		);
	}

	function EditBasicsForm(props) {
		const detail = props.detail;
		const [values, setValues] = useState({
			status: detail.status || 'pending',
			billing_cycle: detail.billing_cycle || 'monthly',
			renewal_date: toDateInputValue(detail.renewal_date),
			notes: detail.notes || '',
		});
		const [busy, setBusy] = useState(false);
		const [error, setError] = useState('');

		function update(field) {
			return function (e) { setValues(Object.assign({}, values, { [field]: e.target.value })); };
		}

		function submit(e) {
			e.preventDefault();
			setError('');
			setBusy(true);
			const payload = {
				status: values.status,
				billing_cycle: values.billing_cycle,
				renewal_date: values.renewal_date ? values.renewal_date + ' 00:00:00' : '',
				notes: values.notes,
			};
			apiFetch({
				path: '/' + NS + '/memberships/' + detail.id,
				method: 'POST',
				data: payload,
			}).then(
				function () { setBusy(false); props.onSaved(); },
				function (err) { setBusy(false); setError((err && err.message) || __('Could not update membership.', 'memberistic')); }
			);
		}

		return h('form', { className: 'mb-form mb-form--inline-edit', onSubmit: submit },
			h(FormError, { error: error }),
			h('div', { className: 'mb-form__grid' },
				h(Field, { label: __('Status', 'memberistic') },
					h('select', { value: values.status, onChange: update('status') },
						EDITABLE_STATUSES.map(function (s) { return h('option', { key: s, value: s }, statusLabel(s)); })
					)
				),
				h(Field, { label: __('Billing cycle', 'memberistic') },
					h('select', { value: values.billing_cycle, onChange: update('billing_cycle') },
						h('option', { value: 'monthly' }, __('Monthly', 'memberistic')),
						h('option', { value: 'annual' }, __('Annual', 'memberistic'))
					)
				),
				h(Field, { label: __('Renewal date', 'memberistic') },
					h('input', { type: 'date', value: values.renewal_date, onChange: update('renewal_date') })
				)
			),
			h(Field, { label: __('Notes', 'memberistic'), full: true },
				h('textarea', { rows: 2, value: values.notes, onChange: update('notes') })
			),
			h(FormActions, { busy: busy, onCancel: props.onCancel, submitLabel: __('Save changes', 'memberistic') })
		);
	}

	function CreateMembershipForm(props) {
		const [values, setValues] = useState({
			plan_id: 0,
			billing_cycle: 'monthly',
			status: 'pending',
			start_date: toDateInputValue(new Date().toISOString()),
			full_name: '',
			email: '',
			phone: '',
			waiver_status: 'missing',
			notes: '',
		});
		const [busy, setBusy] = useState(false);
		const [error, setError] = useState('');

		function update(field) {
			return function (e) {
				const v = e.target.value;
				setValues(Object.assign({}, values, { [field]: field === 'plan_id' ? Number(v) : v }));
			};
		}

		function submit(e) {
			e.preventDefault();
			setError('');
			if (!values.plan_id) { setError(__('Choose a plan.', 'memberistic')); return; }
			if (!values.full_name.trim()) { setError(__('Primary member name is required.', 'memberistic')); return; }
			setBusy(true);
			const payload = Object.assign({}, values, {
				start_date: values.start_date ? values.start_date + ' 00:00:00' : '',
			});
			apiFetch({
				path: '/' + NS + '/memberships',
				method: 'POST',
				data: payload,
			}).then(
				function (created) {
					setBusy(false);
					props.onCreated(created && created.id);
				},
				function (err) {
					setBusy(false);
					setError((err && err.message) || __('Could not create membership.', 'memberistic'));
				}
			);
		}

		return h('aside', { className: 'mb-panel', role: 'dialog', 'aria-modal': 'true' },
			h('div', { className: 'mb-panel__scrim', onClick: props.onCancel }),
			h('div', { className: 'mb-panel__sheet' },
				h('header', { className: 'mb-panel__header' },
					h('div', null,
						h('div', { className: 'mb-panel__crumb' }, __('New Membership', 'memberistic')),
						h('h2', null, __('Create a new member', 'memberistic')),
						h('div', { className: 'mb-panel__meta' },
							h('span', { className: 'mb-panel__metaItem' }, __('Saves immediately. You can add people, payments, and notes after.', 'memberistic'))
						)
					),
					h('div', { className: 'mb-panel__actions' },
						h('button', { className: 'mb-panel__close', onClick: props.onCancel, 'aria-label': __('Close panel', 'memberistic') }, '×')
					)
				),
				h('div', { className: 'mb-panel__body' },
					h('form', { className: 'mb-form', onSubmit: submit },
						h(FormError, { error: error }),
						h('h3', { className: 'mb-form__section' }, __('Membership', 'memberistic')),
						h('div', { className: 'mb-form__grid' },
							h(Field, { label: __('Plan', 'memberistic') },
								h('select', { value: String(values.plan_id), onChange: update('plan_id'), required: true },
									[h('option', { key: 'none', value: '0' }, __('Select a plan', 'memberistic'))].concat(
										(props.plans || []).map(function (p) { return h('option', { key: p.id, value: String(p.id) }, p.name); })
									)
								)
							),
							h(Field, { label: __('Billing cycle', 'memberistic') },
								h('select', { value: values.billing_cycle, onChange: update('billing_cycle') },
									h('option', { value: 'monthly' }, __('Monthly', 'memberistic')),
									h('option', { value: 'annual' }, __('Annual', 'memberistic'))
								)
							),
							h(Field, { label: __('Status', 'memberistic') },
								h('select', { value: values.status, onChange: update('status') },
									EDITABLE_STATUSES.map(function (s) { return h('option', { key: s, value: s }, statusLabel(s)); })
								)
							),
							h(Field, { label: __('Start date', 'memberistic') },
								h('input', { type: 'date', value: values.start_date, onChange: update('start_date') })
							)
						),
						h('h3', { className: 'mb-form__section' }, __('Primary member', 'memberistic')),
						h('div', { className: 'mb-form__grid' },
							h(Field, { label: __('Full name', 'memberistic') },
								h('input', { type: 'text', value: values.full_name, onChange: update('full_name'), required: true })
							),
							h(Field, { label: __('Email', 'memberistic') },
								h('input', { type: 'email', value: values.email, onChange: update('email') })
							),
							h(Field, { label: __('Phone', 'memberistic') },
								h('input', { type: 'text', value: values.phone, onChange: update('phone') })
							),
							h(Field, { label: __('Waiver status', 'memberistic') },
								h('select', { value: values.waiver_status, onChange: update('waiver_status') },
									WAIVER_OPTIONS.map(function (w) { return h('option', { key: w, value: w }, statusLabel(w)); })
								)
							)
						),
						h(Field, { label: __('Notes', 'memberistic'), full: true },
							h('textarea', { rows: 2, value: values.notes, onChange: update('notes') })
						),
						h(FormActions, { busy: busy, onCancel: props.onCancel, submitLabel: __('Create membership', 'memberistic') })
					)
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Detail Panel                                                        */
	/* ------------------------------------------------------------------ */

	function DetailPanel(props) {
		const { membershipId, onClose, onMutated } = props;

		const [tab, setTab] = useState('people');
		const [detail, setDetail] = useState(null);
		const [loading, setLoading] = useState(true);
		const [error, setError] = useState(null);
		const [busy, setBusy] = useState('');
		const [openForm, setOpenForm] = useState('');
		const [editingBasics, setEditingBasics] = useState(false);
		const [editingPersonId, setEditingPersonId] = useState(0);
		const [removingPersonId, setRemovingPersonId] = useState(0);
		const [emailTemplates, setEmailTemplates] = useState([]);
		const [sendingTemplate, setSendingTemplate] = useState('');
		const [emailFeedback, setEmailFeedback] = useState(null);

		const load = useCallback(function () {
			setLoading(true);
			apiFetch({ path: '/' + NS + '/memberships/' + membershipId }).then(
				function (data) { setDetail(data); setLoading(false); },
				function (err) {
					setError((err && err.message) || __('Could not load membership.', 'memberistic'));
					setLoading(false);
				}
			);
		}, [membershipId]);

		useEffect(function () {
			if (!membershipId) return;
			setDetail(null);
			setError(null);
			setOpenForm('');
			setEditingBasics(false);
			setEditingPersonId(0);
			setRemovingPersonId(0);
			setEmailFeedback(null);
			load();
		}, [membershipId, load]);

		useEffect(function () {
			if (emailTemplates.length) return;
			apiFetch({ path: '/' + NS + '/email-templates' }).then(setEmailTemplates, function () { /* silent */ });
		}, []); // eslint-disable-line react-hooks/exhaustive-deps

		function sendEmail(templateId) {
			if (!templateId || !membershipId) return;
			setSendingTemplate(templateId);
			setEmailFeedback(null);
			apiFetch({
				path: '/' + NS + '/memberships/' + membershipId + '/emails',
				method: 'POST',
				data: { template: templateId },
			}).then(
				function () {
					setSendingTemplate('');
					setEmailFeedback({ tone: 'success', message: __('Email sent.', 'memberistic') });
					load();
				},
				function (err) {
					setSendingTemplate('');
					setEmailFeedback({ tone: 'error', message: (err && err.message) || __('Email failed.', 'memberistic') });
				}
			);
		}

		useEffect(function () {
			function onKey(e) { if (e.key === 'Escape') onClose(); }
			window.addEventListener('keydown', onKey);
			return function () { window.removeEventListener('keydown', onKey); };
		}, [onClose]);

		function afterMutation() {
			setOpenForm('');
			setEditingBasics(false);
			setEditingPersonId(0);
			setRemovingPersonId(0);
			load();
			if (onMutated) onMutated();
		}

		function removePerson(person) {
			if (!person || !person.id) return;
			const msg = sprintf(__('Remove %s from this membership? This cannot be undone.', 'memberistic'), person.full_name || ('#' + person.id));
			if (!window.confirm(msg)) return;
			setRemovingPersonId(person.id);
			apiFetch({
				path: '/' + NS + '/people/' + person.id,
				method: 'DELETE',
			}).then(
				function () { setRemovingPersonId(0); afterMutation(); },
				function (err) {
					setRemovingPersonId(0);
					window.alert((err && err.message) || __('Could not remove person.', 'memberistic'));
				}
			);
		}

		function runAction(action, confirmMsg) {
			if (confirmMsg && !window.confirm(confirmMsg)) return;
			setBusy(action);
			apiFetch({
				path: '/' + NS + '/memberships/' + membershipId + '/' + action,
				method: 'POST',
			}).then(
				function () { setBusy(''); afterMutation(); },
				function (err) {
					window.alert((err && err.message) || __('Action failed.', 'memberistic'));
					setBusy('');
				}
			);
		}

		if (!membershipId) return null;

		const panelBody = (function () {
			if (loading && !detail) {
				return h('div', { className: 'mb-panel__loading' }, __('Loading…', 'memberistic'));
			}

			if (error && !detail) {
				return h('div', { className: 'mb-banner mb-banner--error' }, error);
			}

			if (!detail) return null;

			const emailCount = (detail.activity || []).filter(function (a) { return a.activity_type === 'email_sent'; }).length;
			const tabs = [
				{ id: 'people', label: __('People', 'memberistic'), count: (detail.people || []).length },
				{ id: 'payments', label: __('Payments', 'memberistic'), count: (detail.payments || []).length },
				{ id: 'activity', label: __('Activity', 'memberistic'), count: (detail.activity || []).length },
				{ id: 'notes', label: __('Notes', 'memberistic'), count: (detail.notes || []).length },
				{ id: 'checkins', label: __('Check-ins', 'memberistic'), count: (detail.checkins || []).length },
				{ id: 'emails', label: __('Emails', 'memberistic'), count: emailCount },
			];

			return h(Fragment, null,
				h('header', { className: 'mb-panel__header' },
					h('div', null,
						h('div', { className: 'mb-panel__crumb' }, h('code', null, detail.membership_uuid)),
						h('h2', null, detail.full_name || __('Unassigned', 'memberistic')),
						editingBasics
							? null
							: h('div', { className: 'mb-panel__meta' },
								h(StatusPill, { status: detail.status }),
								h('span', { className: 'mb-panel__metaItem' }, detail.plan_name || __('Unknown plan', 'memberistic')),
								h('span', { className: 'mb-panel__metaItem' }, statusLabel(detail.billing_cycle || '')),
								h('span', { className: 'mb-panel__metaItem' }, sprintf(__('Renews %s', 'memberistic'), formatDate(detail.renewal_date)))
							)
					),
					h('div', { className: 'mb-panel__actions' },
						h('button', {
							className: 'button',
							onClick: function () { setEditingBasics(function (v) { return !v; }); },
						}, editingBasics ? __('Discard edit', 'memberistic') : __('Edit basics', 'memberistic')),
						h('button', {
							className: 'button button-primary',
							disabled: busy === 'renew' || detail.status === 'cancelled',
							onClick: function () { runAction('renew'); },
						}, busy === 'renew' ? __('Renewing…', 'memberistic') : __('Renew', 'memberistic')),
						h('button', {
							className: 'button',
							disabled: busy === 'cancel' || detail.status === 'cancelled',
							onClick: function () { runAction('cancel', __('Cancel this membership? This cannot be undone.', 'memberistic')); },
						}, busy === 'cancel' ? __('Cancelling…', 'memberistic') : __('Cancel', 'memberistic')),
						h('button', { className: 'mb-panel__close', onClick: onClose, 'aria-label': __('Close panel', 'memberistic') }, '×')
					)
				),

				editingBasics
					? h('div', { className: 'mb-panel__inline-edit' },
						h(EditBasicsForm, {
							detail: detail,
							onCancel: function () { setEditingBasics(false); },
							onSaved: afterMutation,
						})
					)
					: null,

				h('nav', { className: 'mb-tabs' },
					tabs.map(function (t) {
						return h('button', {
							key: t.id,
							className: 'mb-tabs__tab' + (tab === t.id ? ' is-active' : ''),
							onClick: function () { setTab(t.id); setOpenForm(''); },
						},
							t.label,
							h('span', { className: 'mb-tabs__count' }, t.count)
						);
					})
				),

				h('div', { className: 'mb-panel__body' },
					renderTabHeader(tab, detail, openForm, setOpenForm),
					renderTabForm(tab, detail, openForm, afterMutation, function () { setOpenForm(''); }),
					'emails' === tab
						? renderEmailsTab(detail, emailTemplates, sendingTemplate, emailFeedback, sendEmail)
						: renderTab(tab, detail, {
							editingPersonId: editingPersonId,
							removingPersonId: removingPersonId,
							onEditPerson: function (p) { setEditingPersonId(p && p.id ? p.id : 0); },
							onCancelEditPerson: function () { setEditingPersonId(0); },
							onSavedPerson: afterMutation,
							onRemovePerson: removePerson,
						})
				)
			);
		})();

		return h('aside', { className: 'mb-panel', role: 'dialog', 'aria-modal': 'true' },
			h('div', { className: 'mb-panel__scrim', onClick: onClose }),
			h('div', { className: 'mb-panel__sheet' }, panelBody)
		);
	}

	function renderTabHeader(tab, detail, openForm, setOpenForm) {
		const map = {
			people:   { label: __('Add Person', 'memberistic'),    canAdd: true },
			payments: { label: __('Record Payment', 'memberistic'), canAdd: true },
			activity: { label: null,                                 canAdd: false },
			notes:    { label: __('Add Note', 'memberistic'),       canAdd: true },
			checkins: { label: __('Record Check-in', 'memberistic'), canAdd: true },
		};
		const cfg = map[tab];
		if (!cfg || !cfg.canAdd) return null;

		// Limit "add person" if plan is at capacity.
		let disabled = false;
		let title = '';
		if (tab === 'people' && detail.included_people) {
			disabled = (detail.people || []).length >= Number(detail.included_people);
			if (disabled) title = __('Plan capacity reached.', 'memberistic');
		}

		return h('div', { className: 'mb-tab-toolbar' },
			h('button', {
				className: 'button button-primary',
				disabled: disabled,
				title: title,
				onClick: function () { setOpenForm(openForm === tab ? '' : tab); },
			}, openForm === tab ? __('Close form', 'memberistic') : ('+ ' + cfg.label))
		);
	}

	function renderTabForm(tab, detail, openForm, onSaved, onCancel) {
		if (openForm !== tab) return null;

		const common = { membershipId: detail.id, onSaved: onSaved, onCancel: onCancel };

		if (tab === 'people')   return h(AddPersonForm, common);
		if (tab === 'payments') return h(AddPaymentForm, common);
		if (tab === 'notes')    return h(AddNoteForm, common);
		if (tab === 'checkins') return h(AddCheckinForm, Object.assign({}, common, { people: detail.people || [] }));
		return null;
	}

	function renderTab(tab, detail, handlers) {
		handlers = handlers || {};
		if (tab === 'people') return renderPeople(detail, handlers);
		if (tab === 'payments') return renderPayments(detail);
		if (tab === 'activity') return renderActivity(detail);
		if (tab === 'notes') return renderNotes(detail);
		if (tab === 'checkins') return renderCheckins(detail);
		return null;
	}

	function renderPeople(detail, handlers) {
		const people = detail.people || [];
		if (!people.length) return h('p', { className: 'mb-empty' }, __('No people linked to this membership yet.', 'memberistic'));

		const editingPersonId = handlers.editingPersonId || 0;
		const removingPersonId = handlers.removingPersonId || 0;

		return h('table', { className: 'mb-table mb-table--people' },
			h('thead', null,
				h('tr', null,
					h('th', null, __('Name', 'memberistic')),
					h('th', null, __('Role', 'memberistic')),
					h('th', null, __('Email', 'memberistic')),
					h('th', null, __('Phone', 'memberistic')),
					h('th', null, __('Waiver', 'memberistic')),
					h('th', null, __('Status', 'memberistic')),
					h('th', { className: 'mb-table__actions' }, __('Actions', 'memberistic'))
				)
			),
			h('tbody', null,
				people.reduce(function (rows, p) {
					const isEditing = editingPersonId === p.id;
					const isRemoving = removingPersonId === p.id;
					const isPrimary = (p.role === 'primary');

					rows.push(
						h('tr', { key: 'row-' + p.id, className: isEditing ? 'is-selected' : '' },
							h('td', null, p.full_name || '—'),
							h('td', null, statusLabel(p.role || '')),
							h('td', null, p.email || '—'),
							h('td', null, p.phone || '—'),
							h('td', null,
								h(StatusPill, { status: p.waiver_status || 'missing' }),
								p.waiver_expires_at
									? h('span', { className: 'mb-cell-meta' }, sprintf(__('exp. %s', 'memberistic'), formatDate(p.waiver_expires_at)))
									: null
							),
							h('td', null, h(StatusPill, { status: p.status || 'unknown' })),
							h('td', { className: 'mb-table__actions' },
								h('button', {
									className: 'button-link',
									onClick: function () {
										if (isEditing) {
											handlers.onCancelEditPerson && handlers.onCancelEditPerson();
										} else {
											handlers.onEditPerson && handlers.onEditPerson(p);
										}
									},
								}, isEditing ? __('Close', 'memberistic') : __('Edit', 'memberistic')),
								isPrimary
									? null
									: h('button', {
										className: 'button-link button-link-delete',
										disabled: isRemoving,
										onClick: function () { handlers.onRemovePerson && handlers.onRemovePerson(p); },
										style: { marginLeft: '8px' },
									}, isRemoving ? __('Removing…', 'memberistic') : __('Remove', 'memberistic'))
							)
						)
					);

					if (isEditing) {
						rows.push(
							h('tr', { key: 'edit-' + p.id, className: 'mb-row-editor' },
								h('td', { colSpan: 7 },
									h(EditPersonForm, {
										person: p,
										onCancel: handlers.onCancelEditPerson,
										onSaved: handlers.onSavedPerson,
									})
								)
							)
						);
					}

					return rows;
				}, [])
			)
		);
	}

	function renderPayments(detail) {
		const payments = detail.payments || [];
		if (!payments.length) return h('p', { className: 'mb-empty' }, __('No payments recorded.', 'memberistic'));

		return h('table', { className: 'mb-table' },
			h('thead', null,
				h('tr', null,
					h('th', null, __('Amount', 'memberistic')),
					h('th', null, __('Method', 'memberistic')),
					h('th', null, __('Gateway', 'memberistic')),
					h('th', null, __('Status', 'memberistic')),
					h('th', null, __('Paid', 'memberistic'))
				)
			),
			h('tbody', null,
				payments.map(function (p) {
					return h('tr', { key: p.id },
						h('td', null, formatCurrency(p.amount) + ' ' + (p.currency || '')),
						h('td', null, statusLabel(p.payment_method || 'manual')),
						h('td', null, statusLabel(p.payment_gateway || 'manual')),
						h('td', null, h(StatusPill, { status: p.status })),
						h('td', null, formatDate(p.paid_at || p.created_at))
					);
				})
			)
		);
	}

	function renderActivity(detail) {
		const activity = detail.activity || [];
		if (!activity.length) return h('p', { className: 'mb-empty' }, __('No activity logged yet.', 'memberistic'));

		return h('ul', { className: 'mb-timeline' },
			activity.map(function (row) {
				return h('li', { key: row.id, className: 'mb-timeline__item' },
					h('span', { className: 'mb-timeline__dot' }),
					h('div', { className: 'mb-timeline__body' },
						h('strong', null, row.title || statusLabel(row.activity_type)),
						h('span', { className: 'mb-timeline__meta' }, statusLabel(row.activity_type) + ' · ' + formatDate(row.created_at))
					)
				);
			})
		);
	}

	function renderNotes(detail) {
		const notes = detail.notes || [];
		if (!notes.length) return h('p', { className: 'mb-empty' }, __('No notes yet.', 'memberistic'));

		return h('ul', { className: 'mb-notes' },
			notes.map(function (n) {
				return h('li', { key: n.id, className: 'mb-notes__item' },
					h('div', { className: 'mb-notes__body' }, n.note),
					h('span', { className: 'mb-notes__meta' }, formatDate(n.created_at))
				);
			})
		);
	}

	function renderEmailsTab(detail, templates, sendingTemplate, feedback, onSend) {
		const recent = (detail.activity || []).filter(function (a) { return a.activity_type === 'email_sent'; }).slice(0, 10);

		return h(Fragment, null,
			feedback
				? h('div', { className: 'mb-banner mb-banner--' + (feedback.tone === 'error' ? 'error' : 'success') }, feedback.message)
				: null,

			!templates.length
				? h('p', { className: 'mb-empty' }, __('No email templates available.', 'memberistic'))
				: h('div', { className: 'mb-email-grid' },
					templates.map(function (tpl) {
						const busy = sendingTemplate === tpl.id;
						return h('div', { key: tpl.id, className: 'mb-email-card' },
							h('div', { className: 'mb-email-card__head' },
								h('strong', null, tpl.label),
								h('code', { className: 'mb-email-card__id' }, tpl.id)
							),
							h('p', { className: 'mb-email-card__desc' }, tpl.description),
							h('button', {
								className: 'button button-primary',
								disabled: !!sendingTemplate,
								onClick: function () { onSend(tpl.id); },
							}, busy ? __('Sending…', 'memberistic') : __('Send to primary member', 'memberistic'))
						);
					})
				),

			h('h3', { className: 'mb-section-heading' }, __('Recent emails sent', 'memberistic')),
			!recent.length
				? h('p', { className: 'mb-empty' }, __('No emails have been sent to this member yet.', 'memberistic'))
				: h('ul', { className: 'mb-timeline mb-timeline--full' },
					recent.map(function (row) {
						return h('li', { key: row.id, className: 'mb-timeline__item' },
							h('span', { className: 'mb-timeline__dot' }),
							h('div', { className: 'mb-timeline__body' },
								h('strong', null, row.title || __('Email sent', 'memberistic')),
								h('span', { className: 'mb-timeline__meta' },
									(row.related_object_id ? statusLabel(row.related_object_id) + ' · ' : ''),
									formatDate(row.created_at)
								)
							)
						);
					})
				)
		);
	}

	function renderCheckins(detail) {
		const checkins = detail.checkins || [];
		if (!checkins.length) return h('p', { className: 'mb-empty' }, __('No check-ins recorded.', 'memberistic'));

		return h('table', { className: 'mb-table' },
			h('thead', null,
				h('tr', null,
					h('th', null, __('Person', 'memberistic')),
					h('th', null, __('Type', 'memberistic')),
					h('th', null, __('Checked in', 'memberistic')),
					h('th', null, __('Status', 'memberistic'))
				)
			),
			h('tbody', null,
				checkins.map(function (c) {
					return h('tr', { key: c.id },
						h('td', null, c.full_name || ('#' + c.person_id)),
						h('td', null, statusLabel(c.checkin_type || '')),
						h('td', null, formatDate(c.checked_in_at)),
						h('td', null, h(StatusPill, { status: c.status || 'checked_in' }))
					);
				})
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* List View                                                           */
	/* ------------------------------------------------------------------ */

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

	/* ------------------------------------------------------------------ */
	/* Pagination                                                          */
	/* ------------------------------------------------------------------ */

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

	/* ------------------------------------------------------------------ */
	/* Summary cards                                                      */
	/* ------------------------------------------------------------------ */

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

	function MembersApp() {
		const [filters, setFilters] = useState({ search: '', status: '', plan_id: 0 });
		const debouncedSearch = useDebounced(filters.search, 250);

		const [plans, setPlans] = useState([]);
		const [members, setMembers] = useState([]);
		const [stats, setStats] = useState(null);
		const [loading, setLoading] = useState(true);
		const [error, setError] = useState(null);
		const [selectedId, setSelectedId] = useState(initialSelectedId || null);
		const [creating, setCreating] = useState(initialAction === 'add' && canCreate);
		const [revision, setRevision] = useState(0);
		const [selected, setSelected] = useState({});
		const [bulkAction, setBulkAction] = useState('');
		const [bulkWaiver, setBulkWaiver] = useState('signed');
		const [bulkBusy, setBulkBusy] = useState(false);
		const [bulkProgress, setBulkProgress] = useState('');

		const [page, setPage] = useState(1);
		const [perPage, setPerPage] = useState(50);
		const [totalMembers, setTotalMembers] = useState(0);
		const [totalPages, setTotalPages] = useState(1);

		useEffect(function () {
			apiFetch({ path: '/' + NS + '/plans?status=active' }).then(setPlans, function () { /* silent */ });
		}, []);

		// Reset to page 1 whenever filters change.
		useEffect(function () { setPage(1); }, [debouncedSearch, filters.status, filters.plan_id, perPage]);

		useEffect(function () {
			const params = new URLSearchParams();
			if (debouncedSearch) params.set('search', debouncedSearch);
			if (filters.status) params.set('status', filters.status);
			if (filters.plan_id) params.set('plan_id', String(filters.plan_id));
			params.set('limit', String(perPage));
			params.set('offset', String((page - 1) * perPage));

			setLoading(true);
			apiFetch({ path: '/' + NS + '/memberships?' + params.toString(), parse: false }).then(
				function (response) {
					const total = parseInt(response.headers.get('X-WP-Total') || '0', 10);
					const totalP = parseInt(response.headers.get('X-WP-TotalPages') || '1', 10);
					setTotalMembers(isNaN(total) ? 0 : total);
					setTotalPages(isNaN(totalP) || totalP < 1 ? 1 : totalP);
					return response.json();
				},
				function (err) {
					setError((err && err.message) || __('Could not load members.', 'memberistic'));
					setLoading(false);
					throw err;
				}
			).then(
				function (data) {
					setMembers(Array.isArray(data) ? data : []);
					setLoading(false);
				},
				function () { /* error already handled */ }
			);
		}, [debouncedSearch, filters.status, filters.plan_id, page, perPage, revision]);

		useEffect(function () {
			apiFetch({ path: '/' + NS + '/memberships/stats' }).then(setStats, function () { /* silent */ });
		}, [revision]);

		const refresh = useCallback(function () { setRevision(function (r) { return r + 1; }); }, []);

		const selectedIds = useMemo(function () {
			return Object.keys(selected).filter(function (id) { return selected[id]; }).map(Number);
		}, [selected]);

		const allOnPageSelected = members.length > 0 && members.every(function (m) { return !!selected[m.id]; });

		function toggleRow(id) {
			setSelected(function (prev) {
				const next = Object.assign({}, prev);
				if (next[id]) { delete next[id]; } else { next[id] = true; }
				return next;
			});
		}

		function togglePage() {
			if (allOnPageSelected) {
				setSelected(function (prev) {
					const next = Object.assign({}, prev);
					members.forEach(function (m) { delete next[m.id]; });
					return next;
				});
			} else {
				setSelected(function (prev) {
					const next = Object.assign({}, prev);
					members.forEach(function (m) { next[m.id] = true; });
					return next;
				});
			}
		}

		function clearSelection() { setSelected({}); }

		function runBulk() {
			if (!bulkAction || !selectedIds.length) return;

			if (bulkAction === 'create_group') {
				// Hand the selected memberships to the Corporate Groups
				// "Create Group" screen, which pre-fills seats + attaches
				// these existing members once the group is created.
				const ids = selectedIds.join(',');
				const url = (settings.corporateNewUrl || (window.location.origin + '/wp-admin/admin.php?page=memberistic-corporate-groups&view=new'))
					+ '&from_members=' + encodeURIComponent(ids);
				window.location.href = url;
				return;
			}

			if (bulkAction === 'export') {
				const map = {};
				members.forEach(function (m) { map[m.id] = m; });
				const header = ['Member ID', 'Primary Member', 'Plan', 'Status', 'Billing', 'Renewal', 'People', 'Waiver'];
				const rows = selectedIds.map(function (id) {
					const m = map[id] || {};
					return [
						m.membership_uuid || '',
						m.full_name || '',
						m.plan_name || '',
						m.status || '',
						m.billing_cycle || '',
						m.renewal_date || '',
						Number(m.people_count) || 0,
						m.waiver_status || '',
					];
				});
				downloadCsv('memberistic-members-' + new Date().toISOString().slice(0, 10) + '.csv', [header].concat(rows));
				return;
			}

			if (bulkAction === 'waiver') {
				const msg = sprintf(__('Set waiver status to %1$s for %2$d membership(s)? All people on each selected membership will be updated.', 'memberistic'), statusLabel(bulkWaiver), selectedIds.length);
				if (!window.confirm(msg)) return;

				setBulkBusy(true);
				setBulkProgress(__('Updating waivers…', 'memberistic'));
				apiFetch({
					path: '/' + NS + '/memberships/bulk-waiver',
					method: 'POST',
					data: { membership_ids: selectedIds, waiver_status: bulkWaiver, scope: 'all' },
				}).then(
					function (res) {
						setBulkBusy(false);
						setBulkProgress('');
						clearSelection();
						setBulkAction('');
						refresh();
						window.alert(sprintf(__('Updated waiver status on %1$d membership(s) (%2$d people).', 'memberistic'), Number(res && res.updated_memberships) || 0, Number(res && res.updated_people) || 0));
					},
					function (err) {
						setBulkBusy(false);
						setBulkProgress('');
						window.alert((err && err.message) || __('Bulk waiver update failed.', 'memberistic'));
					}
				);
				return;
			}

			const verb = bulkAction === 'renew' ? __('renew', 'memberistic') : __('cancel', 'memberistic');
			const msg = sprintf(__('Are you sure you want to %1$s %2$d membership(s)? This cannot be undone.', 'memberistic'), verb, selectedIds.length);
			if (!window.confirm(msg)) return;

			setBulkBusy(true);
			setBulkProgress(sprintf(__('Processing 0 of %d…', 'memberistic'), selectedIds.length));
			let done = 0;
			const failures = [];

			const path = function (id) { return '/' + NS + '/memberships/' + id + '/' + bulkAction; };

			const promises = selectedIds.map(function (id) {
				return apiFetch({ path: path(id), method: 'POST' }).then(
					function () {
						done++;
						setBulkProgress(sprintf(__('Processing %1$d of %2$d…', 'memberistic'), done, selectedIds.length));
					},
					function (err) {
						done++;
						failures.push({ id: id, message: (err && err.message) || __('failed', 'memberistic') });
						setBulkProgress(sprintf(__('Processing %1$d of %2$d…', 'memberistic'), done, selectedIds.length));
					}
				);
			});

			Promise.all(promises).then(function () {
				setBulkBusy(false);
				setBulkProgress('');
				clearSelection();
				setBulkAction('');
				refresh();
				if (failures.length) {
					window.alert(sprintf(__('Bulk %1$s finished with %2$d failure(s). First error: %3$s', 'memberistic'), verb, failures.length, failures[0].message));
				}
			});
		}

		const cardData = (function () {
			if (!stats) return null;
			const c = stats.counts || {};
			const newSub = (stats.new_prev_month != null)
				? sprintf(__('vs %d last month', 'memberistic'), Number(stats.new_prev_month) || 0)
				: '';
			return [
				{ label: __('Total Members', 'memberistic'), value: (Number(c.total) || 0).toLocaleString(), tone: 'primary' },
				{ label: __('Active', 'memberistic'), value: (Number(c.active) || 0).toLocaleString(), tone: 'success' },
				{ label: __('Pending', 'memberistic'), value: (Number(c.pending) || 0).toLocaleString(), tone: 'warning' },
				{ label: __('Past Due', 'memberistic'), value: (Number(c.past_due) || 0).toLocaleString(), tone: 'danger' },
				{ label: __('Expired', 'memberistic'), value: (Number(c.expired) || 0).toLocaleString(), tone: 'info' },
				{ label: __('Cancelled', 'memberistic'), value: (Number(c.cancelled) || 0).toLocaleString(), tone: 'info' },
				{
					label: __('New this month', 'memberistic'),
					value: (Number(stats.new_this_month) || 0).toLocaleString(),
					sub: newSub,
					trend: (typeof stats.new_growth_pct === 'number') ? stats.new_growth_pct : null,
					tone: 'success',
				},
				{ label: __('Waiver missing', 'memberistic'), value: (Number(stats.waiver_missing) || 0).toLocaleString(), tone: 'warning' },
			];
		})();

		return h('div', { className: 'mb-app' },
			h('header', { className: 'mb-app__header' },
				h('div', null,
					h('h1', null, __('Members', 'memberistic')),
					h('p', { className: 'mb-app__sub' }, __('Search, filter, and triage memberships without leaving this page.', 'memberistic'))
				),
				canCreate
					? h('button', {
						className: 'button button-primary',
						onClick: function () { setCreating(true); },
					}, __('Add New Member', 'memberistic'))
					: null
			),

			h('section', { className: 'mb-grid mb-grid--stats mb-grid--fade' },
				cardData
					? cardData.map(function (c, i) {
						return h(StatCard, Object.assign({ key: 'card-' + i }, c));
					})
					: [0, 1, 2, 3, 4, 5, 6, 7].map(function (i) { return h('div', { key: 'sk' + i, className: 'mb-stat mb-stat--skeleton' }); })
			),

			h('div', { className: 'mb-toolbar' },
				h('input', {
					type: 'search',
					className: 'mb-toolbar__search',
					value: filters.search,
					placeholder: __('Search by name, email, phone, or member ID', 'memberistic'),
					onChange: function (e) { setFilters(Object.assign({}, filters, { search: e.target.value })); },
				}),
				h('select', {
					className: 'mb-toolbar__select',
					value: filters.status,
					onChange: function (e) { setFilters(Object.assign({}, filters, { status: e.target.value })); },
				},
					STATUS_OPTIONS.map(function (s) {
						return h('option', { key: 's-' + s, value: s }, s === '' ? __('All Statuses', 'memberistic') : statusLabel(s));
					})
				),
				h('select', {
					className: 'mb-toolbar__select',
					value: String(filters.plan_id),
					onChange: function (e) { setFilters(Object.assign({}, filters, { plan_id: Number(e.target.value) })); },
				},
					[h('option', { key: 'p-0', value: '0' }, __('All Plans', 'memberistic'))].concat(
						(plans || []).map(function (p) {
							return h('option', { key: 'p-' + p.id, value: String(p.id) }, p.name);
						})
					)
				),
				h('button', { className: 'button', onClick: refresh }, __('Refresh', 'memberistic')),
				h('span', { className: 'mb-toolbar__count' },
					totalMembers > 0
						? sprintf(__('Showing %1$d–%2$d of %3$d', 'memberistic'),
							((page - 1) * perPage) + 1,
							Math.min(page * perPage, totalMembers),
							totalMembers)
						: sprintf(__('%s shown', 'memberistic'), (members || []).length)
				),
				window.MemberisticSavedViews
					? h(window.MemberisticSavedViews.Bar, {
						context: 'members',
						currentFilters: { search: filters.search, status: filters.status, plan_id: filters.plan_id },
						onApply: function (next) {
							setFilters({
								search: next.search || '',
								status: next.status || '',
								plan_id: Number(next.plan_id) || 0,
							});
						},
					})
					: null
			),

			error
				? h('div', { className: 'mb-banner mb-banner--error' }, error)
				: null,

			selectedIds.length
				? h('div', { className: 'mb-bulkbar' },
					h('span', { className: 'mb-bulkbar__count' }, sprintf(__('%d selected', 'memberistic'), selectedIds.length)),
					h('select', {
						className: 'mb-toolbar__select',
						value: bulkAction,
						onChange: function (e) { setBulkAction(e.target.value); },
						disabled: bulkBusy,
					},
						h('option', { value: '' }, __('Choose bulk action…', 'memberistic')),
						h('option', { value: 'renew' }, __('Renew memberships', 'memberistic')),
						h('option', { value: 'cancel' }, __('Cancel memberships', 'memberistic')),
						h('option', { value: 'waiver' }, __('Change waiver status…', 'memberistic')),
						h('option', { value: 'create_group' }, __('Create corporate group from selected', 'memberistic')),
						h('option', { value: 'export' }, __('Export to CSV', 'memberistic'))
					),
					bulkAction === 'waiver'
						? h('select', {
							className: 'mb-toolbar__select',
							value: bulkWaiver,
							onChange: function (e) { setBulkWaiver(e.target.value); },
							disabled: bulkBusy,
							'aria-label': __('Waiver status', 'memberistic'),
						},
							WAIVER_OPTIONS.map(function (w) { return h('option', { key: 'w-' + w, value: w }, statusLabel(w)); })
						)
						: null,
					h('button', {
						className: 'button button-primary',
						onClick: runBulk,
						disabled: !bulkAction || bulkBusy,
					}, bulkBusy ? (bulkProgress || __('Working…', 'memberistic')) : __('Apply', 'memberistic')),
					h('button', { className: 'button', onClick: clearSelection, disabled: bulkBusy }, __('Clear', 'memberistic'))
				)
				: null,

			h('div', { className: 'mb-card mb-card--list mb-card--has-pagination' },
				loading && !members.length
					? h('div', { className: 'mb-empty' }, __('Loading members…', 'memberistic'))
					: !members.length
						? h('div', { className: 'mb-empty' }, __('No members match these filters.', 'memberistic'))
						: h('table', { className: 'mb-table mb-table--members' },
							h('thead', null,
								h('tr', null,
									h('th', { className: 'mb-table__check' },
										h('input', {
											type: 'checkbox',
											checked: allOnPageSelected,
											onChange: togglePage,
											'aria-label': __('Select all on page', 'memberistic'),
										})
									),
									h('th', null, __('Member ID', 'memberistic')),
									h('th', null, __('Primary Member', 'memberistic')),
									h('th', null, __('Plan', 'memberistic')),
									h('th', null, __('Status', 'memberistic')),
									h('th', null, __('Billing', 'memberistic')),
									h('th', null, __('Renewal', 'memberistic')),
									h('th', null, __('People', 'memberistic')),
									h('th', null, __('Waiver', 'memberistic')),
									h('th', { className: 'mb-table__actions' }, __('Actions', 'memberistic'))
								)
							),
							h('tbody', null,
								members.map(function (m) {
									const isOpen = String(selectedId) === String(m.id);
									const isChecked = !!selected[m.id];
									return h('tr', {
										key: m.id,
										className: 'mb-row' + (isOpen ? ' is-selected' : ''),
										onClick: function () { setSelectedId(m.id); },
									},
										h('td', { className: 'mb-table__check', onClick: function (e) { e.stopPropagation(); } },
											h('input', {
												type: 'checkbox',
												checked: isChecked,
												onChange: function () { toggleRow(m.id); },
												'aria-label': sprintf(__('Select membership %s', 'memberistic'), m.membership_uuid || m.id),
											})
										),
										h('td', null, h('code', null, m.membership_uuid)),
										h('td', null, m.full_name || __('Not assigned', 'memberistic')),
										h('td', null, m.plan_name || __('Unknown', 'memberistic')),
										h('td', null, h(StatusPill, { status: m.status })),
										h('td', null, statusLabel(m.billing_cycle || '')),
										h('td', null, formatDate(m.renewal_date)),
										h('td', null, Number(m.people_count) || 0),
										h('td', null, h(StatusPill, { status: m.waiver_status || 'missing' })),
										h('td', { className: 'mb-table__actions', onClick: function (e) { e.stopPropagation(); } },
											h('button', { className: 'button-link', onClick: function () { setSelectedId(m.id); } }, __('Open', 'memberistic'))
										)
									);
								})
							)
						)
			),

			totalMembers > 0
				? h(Pagination, {
					page: page,
					perPage: perPage,
					totalPages: totalPages,
					total: totalMembers,
					onPage: setPage,
					onPerPage: setPerPage,
				})
				: null,

			creating
				? h(CreateMembershipForm, {
					plans: plans,
					onCancel: function () { setCreating(false); },
					onCreated: function (newId) {
						setCreating(false);
						refresh();
						if (newId) setSelectedId(newId);
					},
				})
				: null,

			selectedId && !creating
				? h(DetailPanel, {
					membershipId: selectedId,
					onClose: function () { setSelectedId(null); },
					onMutated: refresh,
				})
				: null
		);
	}

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById(ROOT_ID);
		if (root) {
			render(h(MembersApp), root);
		}
	});
})(window.wp);
