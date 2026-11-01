import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, PageHeader, ResponsiveTable, Select, Tabs, TextField, useToast } from '../core3/ui';
import { RISK_TONE, STATUS_TONE, downloadCsv, paymentLabel, qs } from './util';

const TABS = [
	{ id: 'upcoming', label: __( 'Upcoming', 'dev3-ai-booking-appointments' ) },
	{ id: 'pending', label: __( 'Pending', 'dev3-ai-booking-appointments' ) },
	{ id: 'confirmed', label: __( 'Confirmed', 'dev3-ai-booking-appointments' ) },
	{ id: 'completed', label: __( 'Completed', 'dev3-ai-booking-appointments' ) },
	{ id: 'no_show', label: __( 'No-shows', 'dev3-ai-booking-appointments' ) },
	{ id: 'cancelled', label: __( 'Cancelled', 'dev3-ai-booking-appointments' ) },
	{ id: '', label: __( 'All', 'dev3-ai-booking-appointments' ) },
];

export default function Bookings( { catalog, openBooking, newBooking, version, changed } ) {
	const toast = useToast();
	const [ status, setStatus ] = useState( 'upcoming' );
	const [ filters, setFilters ] = useState( { search: '', staff: '', service: '', payment: '', from: '', to: '' } );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ query, setQuery ] = useState( '' );

	const params = { ...filters, search: query, status, page, per_page: 20, order: status === 'upcoming' || status === 'pending' ? 'asc' : 'desc' };
	const load = () => {
		setError( null );
		request( `bookings${ qs( params ) }` )
			.then( setData )
			.catch( setError );
	};
	useEffect( load, [ status, page, query, filters.staff, filters.service, filters.payment, filters.from, filters.to, version ] );
	useEffect( () => {
		const t = window.setTimeout( () => {
			setPage( 1 );
			setQuery( filters.search );
		}, 300 );
		return () => window.clearTimeout( t );
	}, [ filters.search ] );

	const set = ( k ) => ( v ) => {
		setFilters( { ...filters, [ k ]: v } );
		if ( k !== 'search' ) {
			setPage( 1 );
		}
	};

	const exportCsv = () =>
		request( `bookings/export${ qs( { ...params, page: 1 } ) }` )
			.then( ( r ) => {
				downloadCsv( r.filename, r.csv );
				toast.success( sprintf( /* translators: %d: rows. */ __( 'Exported %d bookings.', 'dev3-ai-booking-appointments' ), r.count ) );
			} )
			.catch( ( e ) => toast.error( e ) );

	const quick = ( b, s ) =>
		request( `bookings/${ b.id }/status`, { method: 'POST', data: { status: s } } )
			.then( () => {
				toast.success( __( 'Booking updated.', 'dev3-ai-booking-appointments' ) );
				changed();
			} )
			.catch( ( e ) => toast.error( e ) );

	const bulk = ( action ) =>
		request( 'bookings/bulk', { method: 'POST', data: { action, ids: ( data ? data.rows : [] ).map( ( b ) => b.id ) } } )
			.then( ( r ) => {
				toast.success( sprintf( /* translators: %d: count. */ __( '%d bookings updated.', 'dev3-ai-booking-appointments' ), r.done ) );
				changed();
			} )
			.catch( ( e ) => toast.error( e ) );

	const counts = data ? data.counts : {};
	const tabs = TABS.map( ( t ) => ( { ...t, badge: t.id && t.id !== 'upcoming' && counts[ t.id ] ? counts[ t.id ] : undefined } ) );

	const columns = [
		{ key: 'when', label: __( 'When', 'dev3-ai-booking-appointments' ), primary: true, render: ( b ) => ( <span><strong>{ b.day_label.slice( 0, 3 ) } { b.date_label }</strong><br /><span className="dev3bk-muted">{ b.time_label }</span></span> ) },
		{ key: 'customer', label: __( 'Customer', 'dev3-ai-booking-appointments' ), render: ( b ) => ( b.customer ? <span>{ b.customer.name }<br /><span className="dev3bk-muted">{ b.customer.email }</span></span> : '–' ) },
		{ key: 'service', label: __( 'Service', 'dev3-ai-booking-appointments' ), render: ( b ) => <span className="dev3bk-swatch" style={ { '--dev3bk-sw': b.service_color } }>{ b.service }</span> },
		{ key: 'staff', label: __( 'Staff', 'dev3-ai-booking-appointments' ), hideOnMobile: false },
		{ key: 'status', label: __( 'Status', 'dev3-ai-booking-appointments' ), render: ( b ) => <Badge tone={ STATUS_TONE[ b.status ] }>{ b.status_label }</Badge> },
		{ key: 'payment', label: __( 'Payment', 'dev3-ai-booking-appointments' ), render: ( b ) => `${ b.amount_label } · ${ paymentLabel( b ) }` },
		{ key: 'risk', label: __( 'No-show risk', 'dev3-ai-booking-appointments' ), render: ( b ) => <Badge tone={ RISK_TONE[ b.risk.level ] } dot>{ b.risk.score }</Badge>, align: 'end' },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Bookings', 'dev3-ai-booking-appointments' ) }
				description={ __( 'Search, filter and manage every appointment.', 'dev3-ai-booking-appointments' ) }
				actions={
					<>
						{ status === 'pending' && data && data.rows.length > 0 && (
							<Button variant="secondary" icon="check" onClick={ () => bulk( 'confirm' ) }>
								{ __( 'Confirm all shown', 'dev3-ai-booking-appointments' ) }
							</Button>
						) }
						<Button variant="secondary" icon="file" onClick={ exportCsv }>
							{ __( 'Export CSV', 'dev3-ai-booking-appointments' ) }
						</Button>
						<Button variant="primary" icon="plus" onClick={ () => newBooking() }>
							{ __( 'New booking', 'dev3-ai-booking-appointments' ) }
						</Button>
					</>
				}
			/>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			<Card flush>
				<div className="dev3bk-toolbar">
					<Tabs tabs={ tabs } value={ status } onChange={ ( s ) => { setStatus( s ); setPage( 1 ); } } label={ __( 'Booking status', 'dev3-ai-booking-appointments' ) } />
					<div className="dev3bk-filters">
						<TextField label={ __( 'Search', 'dev3-ai-booking-appointments' ) } hideLabel type="search" placeholder={ __( 'Search ref, name, email, phone', 'dev3-ai-booking-appointments' ) } value={ filters.search } onChange={ set( 'search' ) } />
						<Select label={ __( 'Staff', 'dev3-ai-booking-appointments' ) } hideLabel value={ filters.staff } onChange={ set( 'staff' ) } options={ [ { value: '', label: __( 'All staff', 'dev3-ai-booking-appointments' ) }, ...catalog.staff.map( ( s ) => ( { value: String( s.id ), label: s.name } ) ) ] } />
						<Select label={ __( 'Service', 'dev3-ai-booking-appointments' ) } hideLabel value={ filters.service } onChange={ set( 'service' ) } options={ [ { value: '', label: __( 'All services', 'dev3-ai-booking-appointments' ) }, ...catalog.services.map( ( s ) => ( { value: String( s.id ), label: s.name } ) ) ] } />
						<Select
							label={ __( 'Payment', 'dev3-ai-booking-appointments' ) }
							hideLabel
							value={ filters.payment }
							onChange={ set( 'payment' ) }
							options={ [
								{ value: '', label: __( 'Any payment', 'dev3-ai-booking-appointments' ) },
								{ value: 'unpaid', label: __( 'Unpaid', 'dev3-ai-booking-appointments' ) },
								{ value: 'paid', label: __( 'Paid', 'dev3-ai-booking-appointments' ) },
								{ value: 'refunded', label: __( 'Refunded', 'dev3-ai-booking-appointments' ) },
							] }
						/>
						<TextField label={ __( 'From', 'dev3-ai-booking-appointments' ) } type="date" value={ filters.from } onChange={ set( 'from' ) } />
						<TextField label={ __( 'To', 'dev3-ai-booking-appointments' ) } type="date" value={ filters.to } onChange={ set( 'to' ) } />
					</div>
				</div>
				<ResponsiveTable
					columns={ columns }
					rows={ data ? data.rows : [] }
					loading={ ! data }
					onRowClick={ ( b ) => openBooking( b.id ) }
					rowActions={ ( b ) => (
						<span onClick={ ( e ) => e.stopPropagation() } onKeyDown={ ( e ) => e.stopPropagation() } role="presentation">
							{ b.status === 'pending' ? (
								<Button size="sm" variant="secondary" onClick={ () => quick( b, 'confirmed' ) }>
									{ __( 'Confirm', 'dev3-ai-booking-appointments' ) }
								</Button>
							) : (
								<Button size="sm" variant="ghost" onClick={ () => openBooking( b.id ) }>
									{ __( 'Open', 'dev3-ai-booking-appointments' ) }
								</Button>
							) }
						</span>
					) }
					pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					empty={ { icon: 'list', title: __( 'No bookings match', 'dev3-ai-booking-appointments' ), description: __( 'Change the filters or create a booking.', 'dev3-ai-booking-appointments' ) } }
				/>
			</Card>
		</>
	);
}
