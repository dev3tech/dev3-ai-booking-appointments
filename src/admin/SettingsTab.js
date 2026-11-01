import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, Button, Card, ColorField, ErrorNotice, Notice, NumberField, PageHeader, Select, Stack, Tabs, Textarea, TextField, Toggle, useToast } from '../core3/ui';

const KEYS = [
	'business_name',
	'business_email',
	'timezone',
	'time_format',
	'week_start',
	'slot_interval',
	'min_notice',
	'max_advance',
	'default_status',
	'hold_minutes',
	'phone_field',
	'notes_field',
	'allow_cancel',
	'allow_reschedule',
	'cancel_cutoff_hours',
	'reschedule_cutoff_hours',
	'max_reschedules',
	'refund_window_hours',
	'auto_refund',
	'policy_text',
	'currency',
	'pay_later',
	'stripe_enabled',
	'woo_enabled',
	'accent_color',
	'ai_nl',
	'ai_risk',
	'booking_page',
	'account_page',
	'delete_on_uninstall',
];

function StripeCard() {
	const toast = useToast();
	const [ view, setView ] = useState( null );
	const [ keys, setKeys ] = useState( { publishable: '', secret: '', webhook: '' } );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		request( 'payments/stripe' )
			.then( ( v ) => {
				setView( v );
				setKeys( { publishable: v.publishable, secret: v.secret_masked, webhook: v.webhook_set ? '••••••••' : '' } );
			} )
			.catch( setError );
	}, [] );
	const save = () => {
		setBusy( true );
		setError( null );
		request( 'payments/stripe', { method: 'PUT', data: keys } )
			.then( ( v ) => {
				setView( v );
				setKeys( { publishable: v.publishable, secret: v.secret_masked, webhook: v.webhook_set ? '••••••••' : '' } );
				toast.success( __( 'Stripe keys saved (encrypted).', 'dev3-ai-booking-appointments' ) );
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card
			title={ __( 'Stripe', 'dev3-ai-booking-appointments' ) }
			description={ __( 'Customers pay on Stripe\'s secure checkout page. Keys are stored encrypted and never sent to the browser.', 'dev3-ai-booking-appointments' ) }
			actions={ view && ( view.secret_set ? <Badge tone={ view.test_mode ? 'warning' : 'success' }>{ view.test_mode ? __( 'Test mode', 'dev3-ai-booking-appointments' ) : __( 'Live', 'dev3-ai-booking-appointments' ) }</Badge> : <Badge>{ __( 'Not connected', 'dev3-ai-booking-appointments' ) }</Badge> ) }
			footer={
				<Button variant="secondary" loading={ busy } onClick={ save }>
					{ __( 'Save Stripe keys', 'dev3-ai-booking-appointments' ) }
				</Button>
			}
		>
			<Stack gap={ 12 }>
				{ error && <ErrorNotice error={ error } /> }
				<div className="dev3bk-formrow">
					<TextField label={ __( 'Publishable key', 'dev3-ai-booking-appointments' ) } value={ keys.publishable } onChange={ ( v ) => setKeys( { ...keys, publishable: v } ) } placeholder="pk_test_…" autoComplete="off" />
					<TextField label={ __( 'Secret key', 'dev3-ai-booking-appointments' ) } type="password" value={ keys.secret } onChange={ ( v ) => setKeys( { ...keys, secret: v } ) } placeholder="sk_test_…" autoComplete="new-password" />
				</div>
				<TextField label={ __( 'Webhook signing secret', 'dev3-ai-booking-appointments' ) } type="password" value={ keys.webhook } onChange={ ( v ) => setKeys( { ...keys, webhook: v } ) } placeholder="whsec_…" autoComplete="new-password" help={ __( 'Optional but recommended: confirms payments even if the customer closes the tab.', 'dev3-ai-booking-appointments' ) } />
				{ view && (
					<Notice tone="info" title={ __( 'Webhook endpoint', 'dev3-ai-booking-appointments' ) }>
						<code className="dev3bk-code">{ view.webhook_url }</code>
						<br />
						{ __( 'Events:', 'dev3-ai-booking-appointments' ) } { view.webhook_events.join( ', ' ) }
					</Notice>
				) }
			</Stack>
		</Card>
	);
}

export default function SettingsTab( { settings, save, catalog } ) {
	const toast = useToast();
	const [ tab, setTab ] = useState( 'general' );
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const [ pages, setPages ] = useState( false );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );
	const dirty = KEYS.some( ( k ) => form[ k ] !== settings[ k ] );

	const submit = () => {
		setBusy( true );
		const patch = {};
		KEYS.forEach( ( k ) => {
			patch[ k ] = form[ k ] === '' && typeof settings[ k ] === 'number' ? settings[ k ] : form[ k ];
		} );
		save( patch )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', 'dev3-ai-booking-appointments' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};

	const createPages = () => {
		setPages( true );
		request( 'pages', { method: 'POST' } )
			.then( ( r ) => {
				setForm( { ...form, booking_page: r.booking_page, account_page: r.account_page } );
				toast.success( __( 'Pages are ready.', 'dev3-ai-booking-appointments' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setPages( false ) );
	};

	const tzs = ( catalog.timezones || [] ).map( ( z ) => ( { value: z, label: z.replace( /_/g, ' ' ) } ) );

	return (
		<>
			<PageHeader
				title={ __( 'Settings', 'dev3-ai-booking-appointments' ) }
				description={ __( 'Booking rules, policies, payments and the booking widget.', 'dev3-ai-booking-appointments' ) }
				actions={
					<Button variant="primary" icon="check" disabled={ ! dirty } loading={ busy } onClick={ submit }>
						{ __( 'Save settings', 'dev3-ai-booking-appointments' ) }
					</Button>
				}
			/>
			<Tabs
				value={ tab }
				onChange={ setTab }
				label={ __( 'Settings sections', 'dev3-ai-booking-appointments' ) }
				tabs={ [
					{ id: 'general', label: __( 'General', 'dev3-ai-booking-appointments' ) },
					{ id: 'rules', label: __( 'Booking rules', 'dev3-ai-booking-appointments' ) },
					{ id: 'policy', label: __( 'Policies', 'dev3-ai-booking-appointments' ) },
					{ id: 'payments', label: __( 'Payments', 'dev3-ai-booking-appointments' ) },
					{ id: 'widget', label: __( 'Widget & pages', 'dev3-ai-booking-appointments' ) },
					{ id: 'ai', label: __( 'DEV3 AI & data', 'dev3-ai-booking-appointments' ) },
				] }
			/>
			<div style={ { marginTop: 16 } }>
				{ tab === 'general' && (
					<Card title={ __( 'Business', 'dev3-ai-booking-appointments' ) }>
						<div className="dev3bk-formrow">
							<TextField label={ __( 'Business name', 'dev3-ai-booking-appointments' ) } value={ form.business_name } onChange={ set( 'business_name' ) } placeholder={ __( 'Site title', 'dev3-ai-booking-appointments' ) } />
							<TextField label={ __( 'Business email', 'dev3-ai-booking-appointments' ) } type="email" value={ form.business_email } onChange={ set( 'business_email' ) } help={ __( 'Receives booking notifications; used as Reply-To.', 'dev3-ai-booking-appointments' ) } />
						</div>
						<div className="dev3bk-formrow">
							<Select label={ __( 'Business time zone', 'dev3-ai-booking-appointments' ) } value={ form.timezone } onChange={ set( 'timezone' ) } options={ [ { value: '', label: __( 'Same as WordPress', 'dev3-ai-booking-appointments' ) }, ...tzs ] } />
							<Select
								label={ __( 'Time format', 'dev3-ai-booking-appointments' ) }
								value={ String( form.time_format ) }
								onChange={ set( 'time_format' ) }
								options={ [
									{ value: '24', label: '17:30' },
									{ value: '12', label: '5:30 PM' },
								] }
							/>
							<Select
								label={ __( 'Week starts on', 'dev3-ai-booking-appointments' ) }
								value={ String( form.week_start ) }
								onChange={ ( v ) => set( 'week_start' )( Number( v ) ) }
								options={ [
									{ value: '1', label: __( 'Monday', 'dev3-ai-booking-appointments' ) },
									{ value: '0', label: __( 'Sunday', 'dev3-ai-booking-appointments' ) },
								] }
							/>
						</div>
					</Card>
				) }
				{ tab === 'rules' && (
					<Card title={ __( 'Booking rules', 'dev3-ai-booking-appointments' ) } description={ __( 'Defaults; each service can override them.', 'dev3-ai-booking-appointments' ) }>
						<div className="dev3bk-formrow">
							<NumberField label={ __( 'Slot step (minutes)', 'dev3-ai-booking-appointments' ) } value={ form.slot_interval } onChange={ set( 'slot_interval' ) } min={ 5 } max={ 240 } step={ 5 } />
							<NumberField label={ __( 'Minimum notice (minutes)', 'dev3-ai-booking-appointments' ) } value={ form.min_notice } onChange={ set( 'min_notice' ) } min={ 0 } />
							<NumberField label={ __( 'Book up to (days ahead)', 'dev3-ai-booking-appointments' ) } value={ form.max_advance } onChange={ set( 'max_advance' ) } min={ 1 } max={ 730 } />
						</div>
						<div className="dev3bk-formrow">
							<Select
								label={ __( 'New online bookings', 'dev3-ai-booking-appointments' ) }
								value={ form.default_status }
								onChange={ set( 'default_status' ) }
								options={ [
									{ value: 'confirmed', label: __( 'Confirm automatically', 'dev3-ai-booking-appointments' ) },
									{ value: 'pending', label: __( 'Wait for my approval', 'dev3-ai-booking-appointments' ) },
								] }
							/>
							<NumberField label={ __( 'Hold unpaid bookings for (minutes)', 'dev3-ai-booking-appointments' ) } value={ form.hold_minutes } onChange={ set( 'hold_minutes' ) } min={ 5 } max={ 120 } help={ __( 'Stripe holds at least 35 minutes, WooCommerce 60.', 'dev3-ai-booking-appointments' ) } />
						</div>
						<div className="dev3bk-formrow">
							<Select
								label={ __( 'Phone number', 'dev3-ai-booking-appointments' ) }
								value={ form.phone_field }
								onChange={ set( 'phone_field' ) }
								options={ [
									{ value: 'optional', label: __( 'Optional', 'dev3-ai-booking-appointments' ) },
									{ value: 'required', label: __( 'Required', 'dev3-ai-booking-appointments' ) },
									{ value: 'hidden', label: __( 'Hidden', 'dev3-ai-booking-appointments' ) },
								] }
							/>
							<Toggle label={ __( 'Ask for notes', 'dev3-ai-booking-appointments' ) } checked={ form.notes_field } onChange={ set( 'notes_field' ) } />
						</div>
					</Card>
				) }
				{ tab === 'policy' && (
					<Card title={ __( 'Cancellation & rescheduling', 'dev3-ai-booking-appointments' ) } description={ __( 'Customers change bookings from their manage link or account. Admins are never limited.', 'dev3-ai-booking-appointments' ) }>
						<div className="dev3bk-formrow">
							<Toggle label={ __( 'Allow online cancellation', 'dev3-ai-booking-appointments' ) } checked={ form.allow_cancel } onChange={ set( 'allow_cancel' ) } />
							<Toggle label={ __( 'Allow online rescheduling', 'dev3-ai-booking-appointments' ) } checked={ form.allow_reschedule } onChange={ set( 'allow_reschedule' ) } />
						</div>
						<div className="dev3bk-formrow">
							<NumberField label={ __( 'Cancel up to (hours before)', 'dev3-ai-booking-appointments' ) } value={ form.cancel_cutoff_hours } onChange={ set( 'cancel_cutoff_hours' ) } min={ 0 } />
							<NumberField label={ __( 'Reschedule up to (hours before)', 'dev3-ai-booking-appointments' ) } value={ form.reschedule_cutoff_hours } onChange={ set( 'reschedule_cutoff_hours' ) } min={ 0 } />
							<NumberField label={ __( 'Max reschedules per booking', 'dev3-ai-booking-appointments' ) } value={ form.max_reschedules } onChange={ set( 'max_reschedules' ) } min={ 0 } max={ 20 } />
						</div>
						<div className="dev3bk-formrow">
							<NumberField label={ __( 'Full refund if cancelled (hours before)', 'dev3-ai-booking-appointments' ) } value={ form.refund_window_hours } onChange={ set( 'refund_window_hours' ) } min={ 0 } />
							<Toggle label={ __( 'Refund Stripe payments automatically', 'dev3-ai-booking-appointments' ) } checked={ form.auto_refund } onChange={ set( 'auto_refund' ) } help={ __( 'Inside the refund window only.', 'dev3-ai-booking-appointments' ) } />
						</div>
						<Textarea label={ __( 'Policy text shown to customers', 'dev3-ai-booking-appointments' ) } value={ form.policy_text } onChange={ set( 'policy_text' ) } rows={ 3 } maxLength={ 1000 } help={ __( 'Leave empty to generate it from the rules above.', 'dev3-ai-booking-appointments' ) } />
					</Card>
				) }
				{ tab === 'payments' && (
					<Stack gap={ 16 }>
						<Card title={ __( 'Payment methods', 'dev3-ai-booking-appointments' ) }>
							<div className="dev3bk-formrow">
								<Toggle label={ __( 'Pay at the appointment', 'dev3-ai-booking-appointments' ) } checked={ form.pay_later } onChange={ set( 'pay_later' ) } />
								<Toggle label={ __( 'Stripe checkout', 'dev3-ai-booking-appointments' ) } checked={ form.stripe_enabled } onChange={ set( 'stripe_enabled' ) } />
								<Toggle label={ __( 'WooCommerce checkout', 'dev3-ai-booking-appointments' ) } checked={ form.woo_enabled } onChange={ set( 'woo_enabled' ) } disabled={ ! config.wooActive } help={ config.wooActive ? __( 'Uses your WooCommerce gateways, taxes and emails.', 'dev3-ai-booking-appointments' ) : __( 'Install and activate WooCommerce to use it.', 'dev3-ai-booking-appointments' ) } />
							</div>
							<div className="dev3bk-formrow">
								<TextField label={ __( 'Currency (ISO code)', 'dev3-ai-booking-appointments' ) } value={ form.currency } onChange={ set( 'currency' ) } maxLength={ 3 } help={ form.woo_enabled ? __( 'WooCommerce\'s currency is used while WooCommerce checkout is on.', 'dev3-ai-booking-appointments' ) : '' } />
							</div>
						</Card>
						<StripeCard />
					</Stack>
				) }
				{ tab === 'widget' && (
					<Stack gap={ 16 }>
						<Card title={ __( 'Booking widget', 'dev3-ai-booking-appointments' ) } description={ __( 'Add the "Booking form" block or the [dev3bk_booking] shortcode to any page. Options: service, staff, category.', 'dev3-ai-booking-appointments' ) }>
							<div className="dev3bk-formrow">
								<ColorField label={ __( 'Accent colour', 'dev3-ai-booking-appointments' ) } value={ form.accent_color } onChange={ set( 'accent_color' ) } />
							</div>
							<code className="dev3bk-code">[dev3bk_booking service=&quot;3&quot;]</code> <code className="dev3bk-code">[dev3bk_my_bookings]</code>
						</Card>
						<Card
							title={ __( 'Pages', 'dev3-ai-booking-appointments' ) }
							description={ __( 'The account page is where emailed "Manage booking" links land.', 'dev3-ai-booking-appointments' ) }
							footer={
								<Button variant="secondary" loading={ pages } onClick={ createPages }>
									{ __( 'Create missing pages', 'dev3-ai-booking-appointments' ) }
								</Button>
							}
						>
							<div className="dev3bk-formrow">
								<NumberField label={ __( 'Booking page ID', 'dev3-ai-booking-appointments' ) } value={ form.booking_page } onChange={ set( 'booking_page' ) } min={ 0 } />
								<NumberField label={ __( 'Account page ID', 'dev3-ai-booking-appointments' ) } value={ form.account_page } onChange={ set( 'account_page' ) } min={ 0 } />
							</div>
						</Card>
					</Stack>
				) }
				{ tab === 'ai' && (
					<Card tone="ai" title={ __( 'DEV3 AI features', 'dev3-ai-booking-appointments' ) } description={ __( 'Connect your Google Gemini key in API Config. Everything keeps working with AI off.', 'dev3-ai-booking-appointments' ) }>
						<Stack gap={ 12 }>
							<Toggle label={ __( 'Natural-language booking ("Tuesday after 5 with Anna")', 'dev3-ai-booking-appointments' ) } checked={ form.ai_nl } onChange={ set( 'ai_nl' ) } help={ __( 'Shown in the widget. Without a key, a built-in rule parser answers instead. Only real free times are ever offered.', 'dev3-ai-booking-appointments' ) } />
							<Toggle label={ __( 'No-show risk hint', 'dev3-ai-booking-appointments' ) } checked={ form.ai_risk } onChange={ set( 'ai_risk' ) } help={ __( 'Adds a plain-language explanation and suggested nudge to the rule-based risk score.', 'dev3-ai-booking-appointments' ) } />
							<Toggle label={ __( 'Delete all booking data when the plugin is uninstalled', 'dev3-ai-booking-appointments' ) } checked={ form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } />
						</Stack>
					</Card>
				) }
			</div>
		</>
	);
}
