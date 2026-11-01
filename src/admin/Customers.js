import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Drawer, ErrorNotice, PageHeader, ResponsiveTable, Skeleton, Stack, Stat, StatGrid, Textarea, TextField, useToast } from '../core3/ui';
import { STATUS_TONE, money, qs } from './util';

function CustomerDrawer( { id, onClose, openBooking, onSaved } ) {
	const toast = useToast();
	const [ c, setC ] = useState( null );
	const [ form, setForm ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		setC( null );
		request( `customers/${ id }` )
			.then( ( d ) => {
				setC( d );
				setForm( { name: d.name, phone: d.phone, notes: d.notes } );
			} )
			.catch( setError );
	}, [ id ] );
	const save = () => {
		setBusy( true );
		request( `customers/${ id }`, { method: 'PATCH', data: form } )
			.then( ( d ) => {
				setC( d );
				toast.success( __( 'Customer saved.', 'dev3-ai-booking-appointments' ) );
				onSaved();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Drawer
			open
			onClose={ onClose }
			size="lg"
			title={ c ? c.name || c.email : __( 'Customer', 'dev3-ai-booking-appointments' ) }
			description={ c ? c.email : '' }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Close', 'dev3-ai-booking-appointments' ) }
					</Button>
					<Button variant="primary" loading={ busy } onClick={ save } disabled={ ! c }>
						{ __( 'Save', 'dev3-ai-booking-appointments' ) }
					</Button>
				</>
			}
		>
			{ error && <ErrorNotice error={ error } /> }
			{ ! c && ! error && <Skeleton lines={ 6 } /> }
			{ c && (
				<Stack gap={ 16 }>
					<StatGrid min={ 120 }>
						<Stat label={ __( 'Bookings', 'dev3-ai-booking-appointments' ) } value={ c.stats.total } />
						<Stat label={ __( 'Completed', 'dev3-ai-booking-appointments' ) } value={ c.stats.completed } tone="success" />
						<Stat label={ __( 'No-shows', 'dev3-ai-booking-appointments' ) } value={ c.stats.no_show } tone={ c.stats.no_show ? 'danger' : 'neutral' } />
						<Stat label={ __( 'Paid', 'dev3-ai-booking-appointments' ) } value={ money( c.stats.paid ) } />
					</StatGrid>
					<div className="dev3bk-formrow">
						<TextField label={ __( 'Name', 'dev3-ai-booking-appointments' ) } value={ form.name } onChange={ ( v ) => setForm( { ...form, name: v } ) } />
						<TextField label={ __( 'Phone', 'dev3-ai-booking-appointments' ) } value={ form.phone } onChange={ ( v ) => setForm( { ...form, phone: v } ) } />
					</div>
					<Textarea label={ __( 'Private notes', 'dev3-ai-booking-appointments' ) } value={ form.notes } onChange={ ( v ) => setForm( { ...form, notes: v } ) } rows={ 3 } maxLength={ 2000 } />
					<Card title={ __( 'History', 'dev3-ai-booking-appointments' ) } flush>
						<ResponsiveTable
							rows={ c.bookings }
							onRowClick={ ( b ) => openBooking( b.id ) }
							columns={ [
								{ key: 'when', label: __( 'When', 'dev3-ai-booking-appointments' ), primary: true, render: ( b ) => `${ b.date_label } · ${ b.time_label.split( ' – ' )[ 0 ] }` },
								{ key: 'service', label: __( 'Service', 'dev3-ai-booking-appointments' ) },
								{ key: 'staff', label: __( 'Staff', 'dev3-ai-booking-appointments' ) },
								{ key: 'status', label: __( 'Status', 'dev3-ai-booking-appointments' ), render: ( b ) => <Badge tone={ STATUS_TONE[ b.status ] }>{ b.status_label }</Badge> },
							] }
							empty={ { title: __( 'No bookings', 'dev3-ai-booking-appointments' ) } }
						/>
					</Card>
				</Stack>
			) }
		</Drawer>
	);
}

export default function Customers( { openBooking, version } ) {
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ open, setOpen ] = useState( 0 );
	const [ tick, setTick ] = useState( 0 );

	useEffect( () => {
		const t = window.setTimeout( () => {
			setQuery( search );
			setPage( 1 );
		}, 300 );
		return () => window.clearTimeout( t );
	}, [ search ] );
	useEffect( () => {
		setError( null );
		request( `customers${ qs( { search: query, page, per_page: 20 } ) }` )
			.then( setData )
			.catch( setError );
	}, [ query, page, version, tick ] );

	return (
		<>
			<PageHeader title={ __( 'Customers', 'dev3-ai-booking-appointments' ) } description={ __( 'Everyone who booked, with their history and no-show record.', 'dev3-ai-booking-appointments' ) } />
			{ error && <ErrorNotice error={ error } /> }
			<Card flush>
				<div className="dev3bk-toolbar">
					<div className="dev3bk-filters">
						<TextField label={ __( 'Search customers', 'dev3-ai-booking-appointments' ) } hideLabel type="search" placeholder={ __( 'Search name, email or phone', 'dev3-ai-booking-appointments' ) } value={ search } onChange={ setSearch } />
					</div>
				</div>
				<ResponsiveTable
					loading={ ! data }
					rows={ data ? data.rows : [] }
					onRowClick={ ( c ) => setOpen( c.id ) }
					pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					columns={ [
						{ key: 'name', label: __( 'Customer', 'dev3-ai-booking-appointments' ), primary: true, render: ( c ) => <span><strong>{ c.name || c.email }</strong><br /><span className="dev3bk-muted">{ c.email }</span></span> },
						{ key: 'phone', label: __( 'Phone', 'dev3-ai-booking-appointments' ), render: ( c ) => c.phone || '–' },
						{ key: 'bookings', label: __( 'Bookings', 'dev3-ai-booking-appointments' ), align: 'end' },
						{ key: 'no_shows', label: __( 'No-shows', 'dev3-ai-booking-appointments' ), align: 'end', render: ( c ) => ( c.no_shows ? <Badge tone="danger">{ c.no_shows }</Badge> : '0' ) },
						{ key: 'revenue', label: __( 'Paid', 'dev3-ai-booking-appointments' ), align: 'end', render: ( c ) => money( c.revenue ) },
						{ key: 'last', label: __( 'Last booking', 'dev3-ai-booking-appointments' ), render: ( c ) => ( c.last_booking ? new Date( c.last_booking.replace( ' ', 'T' ) + 'Z' ).toLocaleDateString() : '–' ) },
					] }
					empty={ { icon: 'file', title: query ? sprintf( /* translators: %s: search. */ __( 'No customers match "%s"', 'dev3-ai-booking-appointments' ), query ) : __( 'No customers yet', 'dev3-ai-booking-appointments' ), description: __( 'Customers are added automatically when they book.', 'dev3-ai-booking-appointments' ) } }
				/>
			</Card>
			{ open > 0 && <CustomerDrawer id={ open } onClose={ () => setOpen( 0 ) } openBooking={ ( id ) => { setOpen( 0 ); openBooking( id ); } } onSaved={ () => setTick( ( t ) => t + 1 ) } /> }
		</>
	);
}
