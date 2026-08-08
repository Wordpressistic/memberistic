/**
 * Memberistic — Premium Settings Console
 *
 * Tabbed settings UI backed by /memberistic/v1/settings (GET + PUT). Replaces
 * the legacy options.php Settings API form; the WP option key is the same so
 * existing data is preserved and read by every other consumer unchanged.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.element || !wp.apiFetch) return;

	const { createElement: h, Fragment, useState, useEffect, useCallback, render } = wp.element;
	const apiFetch = wp.apiFetch;
	const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };

	const ROOT_ID = 'memberistic-settings-app';
	const NS = 'memberistic/v1';

	const TABS = [
		{ id: 'general',      label: __('General', 'memberistic') },
		{ id: 'pages',        label: __('Pages', 'memberistic') },
		{ id: 'payments',     label: __('Payments', 'memberistic') },
		{ id: 'emails',       label: __('Emails', 'memberistic') },
		{ id: 'integrations', label: __('Integrations', 'memberistic') },
		{ id: 'advanced',     label: __('Advanced', 'memberistic') },
	];

	const PAGE_FIELDS = [
		{ key: 'plans_page_id',           label: __('Membership Plans Page', 'memberistic') },
		{ key: 'checkout_page_id',        label: __('Checkout Page', 'memberistic') },
		{ key: 'account_page_id',         label: __('Member Account Page', 'memberistic') },
		{ key: 'renewal_page_id',         label: __('Renewal Page', 'memberistic') },
		{ key: 'login_page_id',           label: __('Login Page', 'memberistic') },
		{ key: 'thank_you_page_id',       label: __('Thank You Page', 'memberistic') },
		{ key: 'failed_payment_page_id',  label: __('Failed Payment Page', 'memberistic') },
		{ key: 'staff_dashboard_page_id', label: __('Staff Dashboard Page', 'memberistic') },
		{ key: 'booking_page_id',         label: __('Booking Page (Book a Lane)', 'memberistic') },
	];

	function Field(props) {
		return h('label', { className: 'mb-field' + (props.full ? ' mb-field--full' : '') },
			h('span', { className: 'mb-field__label' }, props.label),
			props.children,
			props.hint ? h('span', { className: 'mb-field__hint' }, props.hint) : null
		);
	}

	function TextInput(props) {
		return h('input', {
			type: props.type || 'text',
			value: props.value || '',
			placeholder: props.placeholder || '',
			onChange: function (e) { props.onChange(e.target.value); },
		});
	}

	function YesNo(props) {
		return h('span', { className: 'mb-field__checkbox' },
			h('input', {
				type: 'checkbox',
				checked: props.value === 'yes',
				onChange: function (e) { props.onChange(e.target.checked ? 'yes' : 'no'); },
			}),
			h('span', null, props.value === 'yes' ? __('Enabled', 'memberistic') : __('Disabled', 'memberistic'))
		);
	}

	function PageSelect(props) {
		return h('select', {
			value: String(props.value || 0),
			onChange: function (e) { props.onChange(Number(e.target.value)); },
		},
			[h('option', { key: 'none', value: '0' }, __('— Not mapped —', 'memberistic'))].concat(
				(props.pages || []).map(function (p) {
					return h('option', { key: p.id, value: String(p.id) }, p.title + ' (#' + p.id + ')');
				})
			)
		);
	}

	function SettingsApp() {
		const [tab, setTab] = useState('general');
		const [values, setValues] = useState(null);
		const [pages, setPages] = useState([]);
		const [loading, setLoading] = useState(true);
		const [saving, setSaving] = useState(false);
		const [error, setError] = useState('');
		const [notice, setNotice] = useState('');

		const load = useCallback(function () {
			setLoading(true);
			Promise.all([
				apiFetch({ path: '/' + NS + '/settings' }),
				apiFetch({ path: '/' + NS + '/settings/pages-options' }),
			]).then(
				function (results) {
					setValues(results[0] || {});
					setPages(Array.isArray(results[1]) ? results[1] : []);
					setLoading(false);
				},
				function (err) {
					setError((err && err.message) || __('Could not load settings.', 'memberistic'));
					setLoading(false);
				}
			);
		}, []);

		useEffect(load, [load]);

		function update(key) {
			return function (value) {
				setValues(function (prev) { return Object.assign({}, prev, { [key]: value }); });
				setNotice('');
			};
		}

		function save(e) {
			if (e && e.preventDefault) e.preventDefault();
			setSaving(true);
			setError('');
			setNotice('');
			apiFetch({
				path: '/' + NS + '/settings',
				method: 'PUT',
				data: values,
			}).then(
				function (next) {
					setValues(next);
					setSaving(false);
					setNotice(__('Settings saved.', 'memberistic'));
				},
				function (err) {
					setSaving(false);
					setError((err && err.message) || __('Could not save settings.', 'memberistic'));
				}
			);
		}

		function createPages(remap) {
			if (remap && !window.confirm(__('Remap will create branded Memberistic pages and overwrite the current mapping. Proceed?', 'memberistic'))) return;

			setSaving(true);
			apiFetch({
				path: '/' + NS + '/settings/pages',
				method: 'POST',
				data: { remap: !!remap },
			}).then(
				function (next) {
					setValues(next);
					setSaving(false);
					setNotice(remap ? __('Pages recreated and mapped.', 'memberistic') : __('Missing pages were created and mapped.', 'memberistic'));
				},
				function (err) {
					setSaving(false);
					setError((err && err.message) || __('Could not create pages.', 'memberistic'));
				}
			);
		}

		if (loading || !values) {
			return h('div', { className: 'mb-app' },
				h('div', { className: 'mb-empty' }, __('Loading settings…', 'memberistic'))
			);
		}

		return h('form', { className: 'mb-app', onSubmit: save },
			h('header', { className: 'mb-app__header' },
				h('div', null,
					h('h1', null, __('Memberistic Settings', 'memberistic')),
					h('p', { className: 'mb-app__sub' }, __('Brand, pages, payments, emails, and integration toggles. Changes save instantly to the same option as before.', 'memberistic'))
				)
			),

			error ? h('div', { className: 'mb-banner mb-banner--error' }, error) : null,
			notice ? h('div', { className: 'mb-banner mb-banner--success' }, notice) : null,

			h('nav', { className: 'mb-tabs mb-tabs--settings' },
				TABS.map(function (t) {
					return h('button', {
						key: t.id,
						type: 'button',
						className: 'mb-tabs__tab' + (tab === t.id ? ' is-active' : ''),
						onClick: function () { setTab(t.id); },
					}, t.label);
				})
			),

			h('div', { className: 'mb-card' },
				renderTab(tab, values, pages, update, createPages, saving)
			),

			h('div', { className: 'mb-form__actions mb-form__actions--sticky' },
				h('button', { type: 'submit', className: 'button button-primary', disabled: saving }, saving ? __('Saving…', 'memberistic') : __('Save changes', 'memberistic'))
			)
		);
	}

	function renderTab(tab, values, pages, update, createPages, saving) {
		if (tab === 'general')      return renderGeneral(values, update);
		if (tab === 'pages')        return renderPages(values, pages, update, createPages, saving);
		if (tab === 'payments')     return renderPayments(values, update);
		if (tab === 'emails')       return renderEmails(values, update);
		if (tab === 'integrations') return renderIntegrations(values, update);
		if (tab === 'advanced')     return renderAdvanced(values, update);
		return null;
	}

	function renderGeneral(v, update) {
		return h(Fragment, null,
			h('h2', { className: 'mb-card__head' }, __('General', 'memberistic')),
			h('div', { className: 'mb-form__grid' },
				h(Field, { label: __('Brand label', 'memberistic'), hint: __('Used in menus, emails, and headings.', 'memberistic') },
					h(TextInput, { value: v.brand_label, onChange: update('brand_label') })
				),
				h(Field, { label: __('Business name', 'memberistic') },
					h(TextInput, { value: v.business_name, onChange: update('business_name') })
				),
				h(Field, { label: __('Business phone', 'memberistic') },
					h(TextInput, { value: v.business_phone, onChange: update('business_phone') })
				),
				h(Field, { label: __('Primary brand color', 'memberistic') },
					h('input', { type: 'color', value: v.primary_brand_color || '#0F2044', onChange: function (e) { update('primary_brand_color')(e.target.value); } })
				),
				h(Field, { label: __('Currency', 'memberistic') },
					h(TextInput, { value: v.currency, onChange: function (val) { update('currency')((val || 'USD').toUpperCase().slice(0, 3)); } })
				)
			),
			h(Field, { label: __('Business address', 'memberistic'), full: true },
				h('textarea', { rows: 3, value: v.business_address || '', onChange: function (e) { update('business_address')(e.target.value); } })
			)
		);
	}

	function renderPages(v, pages, update, createPages, saving) {
		return h(Fragment, null,
			h('h2', { className: 'mb-card__head' }, __('Frontend pages', 'memberistic')),
			h('p', { className: 'mb-app__sub' }, __('Map each Memberistic shortcode page or let us create them for you.', 'memberistic')),

			h('div', { className: 'mb-form__grid' },
				PAGE_FIELDS.map(function (f) {
					return h(Field, { key: f.key, label: f.label },
						h(PageSelect, { value: v[f.key], pages: pages, onChange: update(f.key) })
					);
				})
			),

			h('div', { className: 'mb-form__actions mb-form__actions--inline' },
				h('button', { type: 'button', className: 'button', onClick: function () { createPages(false); }, disabled: saving }, __('Create missing pages', 'memberistic')),
				h('button', { type: 'button', className: 'button', onClick: function () { createPages(true); }, disabled: saving }, __('Reset to branded defaults', 'memberistic'))
			)
		);
	}

	function renderPayments(v, update) {
		return h(Fragment, null,
			h('h2', { className: 'mb-card__head' }, __('Payments', 'memberistic')),
			h(Field, { label: __('Enable Stripe Checkout', 'memberistic') },
				h(YesNo, { value: v.stripe_enabled, onChange: update('stripe_enabled') })
			),
			h('div', { className: 'mb-form__grid' },
				h(Field, { label: __('Stripe mode', 'memberistic') },
					h('select', { value: v.stripe_mode || 'test', onChange: function (e) { update('stripe_mode')(e.target.value); } },
						h('option', { value: 'test' }, __('Test', 'memberistic')),
						h('option', { value: 'live' }, __('Live', 'memberistic'))
					)
				),
				h(Field, { label: __('Webhook secret', 'memberistic'), hint: __('Required for live webhook verification.', 'memberistic') },
					h(TextInput, { type: 'password', value: v.stripe_webhook_secret, onChange: update('stripe_webhook_secret') })
				)
			),
			h('h3', { className: 'mb-form__section' }, __('Test keys', 'memberistic')),
			h('div', { className: 'mb-form__grid' },
				h(Field, { label: __('Publishable', 'memberistic') },
					h(TextInput, { value: v.stripe_test_publishable_key, onChange: update('stripe_test_publishable_key') })
				),
				h(Field, { label: __('Secret', 'memberistic') },
					h(TextInput, { type: 'password', value: v.stripe_test_secret_key, onChange: update('stripe_test_secret_key') })
				)
			),
			h('h3', { className: 'mb-form__section' }, __('Live keys', 'memberistic')),
			h('div', { className: 'mb-form__grid' },
				h(Field, { label: __('Publishable', 'memberistic') },
					h(TextInput, { value: v.stripe_live_publishable_key, onChange: update('stripe_live_publishable_key') })
				),
				h(Field, { label: __('Secret', 'memberistic') },
					h(TextInput, { type: 'password', value: v.stripe_live_secret_key, onChange: update('stripe_live_secret_key') })
				)
			)
		);
	}

	function renderEmails(v, update) {
		return h(Fragment, null,
			h('h2', { className: 'mb-card__head' }, __('Outgoing email', 'memberistic')),
			h('div', { className: 'mb-form__grid' },
				h(Field, { label: __('From name', 'memberistic') },
					h(TextInput, { value: v.email_from_name, onChange: update('email_from_name') })
				),
				h(Field, { label: __('From address', 'memberistic') },
					h(TextInput, { type: 'email', value: v.email_from_address, onChange: update('email_from_address') })
				)
			)
		);
	}

	function renderIntegrations(v, update) {
		// Render every module from the server-side Integrations registry
		// (delivered as the read-only `_integrations` snapshot on GET).
		// Each connectable card binds its YesNo toggle to the card's real
		// setting key, so saving here persists exactly like the legacy
		// Memberistic → Integrations page.
		var cards = Array.isArray(v._integrations) ? v._integrations : [];
		if (!cards.length) {
			return h(Fragment, null,
				h('h2', { className: 'mb-card__head' }, __('Integrations', 'memberistic')),
				h(Field, { label: __('WooCommerce bridge', 'memberistic'), hint: __('Sync completed orders to membership payments.', 'memberistic') },
					h(YesNo, { value: v.woocommerce_enabled, onChange: update('woocommerce_enabled') })
				)
			);
		}
		return h(Fragment, null,
			h('h2', { className: 'mb-card__head' }, __('Integrations', 'memberistic')),
			h('p', { className: 'mb-card__sub' }, __('Turn each integration on or off. Modules whose plugin is not installed are listed but locked.', 'memberistic')),
			cards.map(function (card) {
				var hint = card.desc;
				var locked = !card.coming_soon && !card.available;
				if (card.coming_soon) {
					hint = hint + ' — ' + __('Coming soon.', 'memberistic');
				} else if (locked && card.dep_label) {
					hint = hint + ' — ' + card.dep_label;
				}
				var control;
				if (card.coming_soon) {
					control = h('em', null, __('Coming soon', 'memberistic'));
				} else if (locked) {
					control = h('em', null, __('Plugin not detected', 'memberistic'));
				} else {
					var current = (v[card.setting] !== undefined && v[card.setting] !== '') ? v[card.setting] : card.default;
					control = h(YesNo, { value: current, onChange: update(card.setting) });
				}
				return h(Field, { key: card.key, label: card.name, hint: hint }, control);
			}),
			h('h3', { className: 'mb-form__section' }, __('Verifyistic options', 'memberistic')),
			h(Field, { label: __('Auto-stamp member records with verified age/DOB', 'memberistic') },
				h(YesNo, { value: v.integration_verifyistic_autostamp || 'yes', onChange: update('integration_verifyistic_autostamp') })
			),
			h(Field, { label: __('Require age verification before membership signup', 'memberistic') },
				h(YesNo, { value: v.integration_verifyistic_require_signup || 'no', onChange: update('integration_verifyistic_require_signup') })
			)
		);
	}

	function renderAdvanced(v, update) {
		return h(Fragment, null,
			h('h2', { className: 'mb-card__head' }, __('Advanced', 'memberistic')),
			h(Field, { label: __('Debug logging', 'memberistic') },
				h(YesNo, { value: v.enable_debug_logging, onChange: update('enable_debug_logging') })
			),
			h(Field, { label: __('Delete all data on uninstall', 'memberistic'), hint: __('When enabled, removing the plugin will drop every Memberistic table and option. Off by default.', 'memberistic') },
				h(YesNo, { value: v.delete_data_on_uninstall, onChange: update('delete_data_on_uninstall') })
			)
		);
	}

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById(ROOT_ID);
		if (root) render(h(SettingsApp), root);
	});
})(window.wp);
