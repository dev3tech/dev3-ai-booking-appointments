import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import CalendarScreen from './Calendar';
import Bookings from './Bookings';
import Services from './Services';
import StaffScreen from './Staff';
import Schedule from './Schedule';
import Customers from './Customers';
import Notifications from './Notifications';
import SettingsTab from './SettingsTab';
import BookingDrawer from './BookingDrawer';
import NewBooking from './NewBooking';

const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-booking-appointments' ), icon: 'dashboard' },
	{ id: 'calendar', label: __( 'Calendar', 'dev3-ai-booking-appointments' ), icon: 'columns' },
	{ id: 'bookings', label: __( 'Bookings', 'dev3-ai-booking-appointments' ), icon: 'list' },
	{ id: 'services', label: __( 'Services', 'dev3-ai-booking-appointments' ), icon: 'box' },
	{ id: 'staff', label: __( 'Staff', 'dev3-ai-booking-appointments' ), icon: 'users' },
	{ id: 'schedule', label: __( 'Schedule', 'dev3-ai-booking-appointments' ), icon: 'clock' },
	{ id: 'customers', label: __( 'Customers', 'dev3-ai-booking-appointments' ), icon: 'file' },
	{ id: 'notifications', label: __( 'Notifications', 'dev3-ai-booking-appointments' ), icon: 'inbox' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-booking-appointments' ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-booking-appointments' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-booking-appointments' ), icon: 'chart' },
];

export default function App() {
	const [ route, navigate ] = useHashRoute(
		NAV.map( ( n ) => n.id ),
		'dashboard'
	);
	const [ settings, setSettings ] = useState( null );
	const [ catalog, setCatalog ] = useState( { services: [], staff: [] } );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ open, setOpen ] = useState( 0 );
	const [ creating, setCreating ] = useState( null );
	const [ version, setVersion ] = useState( 0 );
	const [ pending, setPending ] = useState( null );

	const loadCatalog = useCallback(
		() =>
			Promise.all( [ request( 'services' ), request( 'staff' ) ] ).then( ( [ s, st ] ) => {
				setCatalog( { services: s.services, staff: st.staff, timezones: st.timezones } );
			} ),
		[]
	);

	const load = () => {
		setError( null );
		Promise.all( [ request( 'settings' ), loadCatalog() ] )
			.then( ( [ s ] ) => setSettings( s ) )
			.catch( setError );
	};
	useEffect( load, [] );

	// Deep link: #/bookings/123 opens the drawer.
	useEffect( () => {
		const id = Number( route.params && route.params[ 0 ] );
		if ( route.id === 'bookings' && id ) {
			setOpen( id );
		}
	}, [ route.path ] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );

	const changed = () => setVersion( ( v ) => v + 1 );
	const ctx = {
		settings,
		save,
		catalog,
		reloadCatalog: loadCatalog,
		openBooking: setOpen,
		newBooking: ( preset = {} ) => setCreating( preset ),
		version,
		changed,
		onUsage: () => setMeter( ( n ) => n + 1 ),
		navigate,
		setPending,
	};

	const screens = {
		dashboard: () => <Dashboard { ...ctx } />,
		calendar: () => <CalendarScreen { ...ctx } />,
		bookings: () => <Bookings { ...ctx } />,
		services: () => <Services { ...ctx } />,
		staff: () => <StaffScreen { ...ctx } />,
		schedule: () => <Schedule { ...ctx } />,
		customers: () => <Customers { ...ctx } />,
		notifications: () => <Notifications { ...ctx } />,
		settings: () => <SettingsTab { ...ctx } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-booking-appointments' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-booking-appointments' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-booking-appointments' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-booking-appointments' ) } />
				<UsageTab />
			</>
		),
	};

	const nav = NAV.map( ( n ) => ( n.id === 'bookings' && pending ? { ...n, badge: pending } : n ) );

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Booking & Appointments for WordPress' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ nav }
			active={ route.id }
			narrowNav="select"
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-booking-appointments' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 6 } /> }
			{ settings && screens[ route.id ]() }
			{ settings && (
				<BookingDrawer
					id={ open }
					onClose={ () => setOpen( 0 ) }
					onChanged={ changed }
					catalog={ catalog }
					onUsage={ ctx.onUsage }
				/>
			) }
			{ settings && creating && (
				<NewBooking
					preset={ creating }
					catalog={ catalog }
					onClose={ () => setCreating( null ) }
					onCreated={ ( b ) => {
						setCreating( null );
						changed();
						setOpen( b.id );
					} }
					onUsage={ ctx.onUsage }
				/>
			) }
		</AppShell>
	);
}
