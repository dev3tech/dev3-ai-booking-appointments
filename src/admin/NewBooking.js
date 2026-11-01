import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Button, Card, ErrorNotice, Modal, Notice, Select, Stack, Tabs, Textarea, TextField, Toggle, cx, useToast } from '../core3/ui';
import SlotPicker from './SlotPicker';
import { fmtDay, qs, today } from './util';

/**
 * DEV3 AI "Find a slot": natural language → real free slots (server-checked).
 *
 * @param {Object}   props         Props.
 * @param {number}   props.service Service.
 * @param {number}   props.staff   Staff.
 * @param {Function} props.onPick  Pick { date, start, staff }.
 * @param {Function} props.onUsage Usage refresh.
 * @param {boolean}  props.focus   Autofocus.
 * @return {Element} Box.
 */
export function FindSlot( { service, staff, onPick, onUsage, focus } ) {
	const [ text, setText ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ res, setRes ] = useState( null );
	const [ error, setError ] = useState( null );
	const run = ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		if ( ! text.trim() ) {
			return;
		}
		setBusy( true );
		setError( null );
		request( 'ai/nl', { method: 'POST', data: { text, service, staff } } )
			.then( ( r ) => {
				setRes( r );
				onUsage();
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card tone="ai" title={ __( 'Find a slot with DEV3 AI', 'dev3-ai-booking-appointments' ) } description={ __( 'Describe it like a customer would: "Tuesday after 5 with Anna". Only real free times are suggested.', 'dev3-ai-booking-appointments' ) }>
			<form className="dev3bk-findslot" onSubmit={ run }>
				<TextField label={ __( 'Request', 'dev3-ai-booking-appointments' ) } hideLabel value={ text } onChange={ setText } placeholder={ __( 'e.g. next Friday morning with Ben', 'dev3-ai-booking-appointments' ) } autoFocus={ focus } />
				<AiButton type="submit" loading={ busy } loadingText={ __( 'Searching…', 'dev3-ai-booking-appointments' ) }>
					{ __( 'Find times', 'dev3-ai-booking-appointments' ) }
				</AiButton>
			</form>
			{ error && <ErrorNotice error={ error } /> }
			{ res && res.needs === 'service' && <Notice tone="warning">{ __( 'Which service? Choose one below or name it in the request.', 'dev3-ai-booking-appointments' ) }</Notice> }
			{ res && res.needs !== 'service' && (
				<div className="dev3bk-findslot__res" aria-live="polite">
					<p className="dev3bk-muted">
						{ res.summary && sprintf( /* translators: %s: summary. */ __( 'Looking for: %s', 'dev3-ai-booking-appointments' ), res.summary ) }{ ' ' }
						{ res.source === 'rules' ? __( '(rule parser — AI off or unavailable)', 'dev3-ai-booking-appointments' ) : '' }
					</p>
					{ res.relaxed && <Notice tone="info">{ __( 'No exact match. Nearest free times:', 'dev3-ai-booking-appointments' ) }</Notice> }
					{ res.slots.length === 0 ? (
						<p>{ __( 'No free times found in the booking window.', 'dev3-ai-booking-appointments' ) }</p>
					) : (
						<div className="dev3bk-slotpick__grid">
							{ res.slots.map( ( s ) => (
								<button key={ s.start + s.staff[ 0 ] } type="button" className="dev3bk-slotpick__slot is-wide" onClick={ () => onPick( { date: s.date, start: s.start, staff: s.staff[ 0 ], service: res.constraints.service_id } ) }>
									<b>{ s.day_label }</b> { s.label }
								</button>
							) ) }
						</div>
					) }
				</div>
			) }
		</Card>
	);
}

export default function NewBooking( { preset, catalog, onClose, onCreated, onUsage } ) {
	const toast = useToast();
	const first = catalog.services.find( ( s ) => s.status === 'active' ) || catalog.services[ 0 ];
	const [ service, setService ] = useState( preset.service || ( first ? first.id : 0 ) );
	const [ staff, setStaff ] = useState( preset.staff || 0 );
	const [ date, setDate ] = useState( preset.date || today() );
	const [ slot, setSlot ] = useState( { start: '', staff: 0, force: !! preset.time, time: preset.time || '' } );
	const [ mode, setMode ] = useState( 'existing' );
	const [ search, setSearch ] = useState( '' );
	const [ found, setFound ] = useState( [] );
	const [ customer, setCustomer ] = useState( null );
	const [ form, setForm ] = useState( { name: '', email: '', phone: '', notes: '', status: 'confirmed', payment_status: 'unpaid', notify: true } );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ ai, setAi ] = useState( !! preset.ai );

	useEffect( () => {
		if ( mode !== 'existing' || search.trim().length < 2 ) {
			setFound( [] );
			return undefined;
		}
		const t = window.setTimeout( () => {
			request( `customers${ qs( { search, per_page: 6 } ) }` )
				.then( ( r ) => setFound( r.rows ) )
				.catch( () => setFound( [] ) );
		}, 250 );
		return () => window.clearTimeout( t );
	}, [ search, mode ] );

	const svc = catalog.services.find( ( s ) => s.id === Number( service ) );
	const staffOptions = catalog.staff.filter( ( s ) => ! svc || s.services.includes( svc.id ) );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );
	const ready = service && ( slot.force ? slot.time && ( staff || slot.staff ) : slot.start ) && ( mode === 'existing' ? customer : form.email && form.name );

	const submit = () => {
		setBusy( true );
		setError( null );
		const data = {
			service,
			staff: slot.staff || staff,
			force: slot.force,
			notes: form.notes,
			status: form.status,
			payment_status: form.payment_status,
			notify: form.notify,
			ai: !! slot.ai,
		};
		if ( slot.force ) {
			data.local = `${ date } ${ slot.time }`;
		} else {
			data.start = slot.start;
		}
		if ( mode === 'existing' && customer ) {
			data.customer_id = customer.id;
		} else {
			Object.assign( data, { name: form.name, email: form.email, phone: form.phone } );
		}
		request( 'bookings', { method: 'POST', data } )
			.then( ( b ) => {
				toast.success( sprintf( /* translators: %s: reference. */ __( 'Booking %s created.', 'dev3-ai-booking-appointments' ), b.ref ) );
				onCreated( b );
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			open
			onClose={ onClose }
			size="lg"
			title={ __( 'New booking', 'dev3-ai-booking-appointments' ) }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', 'dev3-ai-booking-appointments' ) }
					</Button>
					<Button variant="primary" disabled={ ! ready } loading={ busy } onClick={ submit }>
						{ __( 'Create booking', 'dev3-ai-booking-appointments' ) }
					</Button>
				</>
			}
		>
			<Stack gap={ 16 }>
				{ error && <ErrorNotice error={ error } /> }
				{ ai ? (
					<FindSlot
						service={ Number( service ) }
						staff={ Number( staff ) }
						focus
						onUsage={ onUsage }
						onPick={ ( p ) => {
							if ( p.service ) {
								setService( p.service );
							}
							setDate( p.date );
							setStaff( p.staff );
							setSlot( { start: p.start, staff: p.staff, force: false, time: '', ai: true } );
							setAi( false );
						} }
					/>
				) : (
					<Button variant="link" icon="sparkles" onClick={ () => setAi( true ) }>
						{ __( 'Describe the time instead (DEV3 AI)', 'dev3-ai-booking-appointments' ) }
					</Button>
				) }
				<div className="dev3bk-formrow">
					<Select
						label={ __( 'Service', 'dev3-ai-booking-appointments' ) }
						value={ String( service ) }
						onChange={ ( v ) => {
							setService( Number( v ) );
							setSlot( { ...slot, start: '' } );
						} }
						options={ catalog.services.map( ( s ) => ( { value: String( s.id ), label: s.status === 'hidden' ? `${ s.name } (${ __( 'hidden', 'dev3-ai-booking-appointments' ) })` : s.name } ) ) }
					/>
					<Select
						label={ __( 'Staff', 'dev3-ai-booking-appointments' ) }
						value={ String( staff ) }
						onChange={ ( v ) => {
							setStaff( Number( v ) );
							setSlot( { ...slot, start: '' } );
						} }
						options={ [ { value: '0', label: __( 'Any available', 'dev3-ai-booking-appointments' ) }, ...staffOptions.map( ( s ) => ( { value: String( s.id ), label: s.name } ) ) ] }
					/>
					<TextField
						label={ __( 'Day', 'dev3-ai-booking-appointments' ) }
						type="date"
						value={ date }
						onChange={ ( d ) => {
							setDate( d );
							setSlot( { ...slot, start: '' } );
						} }
						help={ date ? fmtDay( date, { weekday: 'long' } ) : '' }
					/>
				</div>
				<SlotPicker service={ Number( service ) } staff={ Number( staff ) } date={ date } value={ slot } onChange={ setSlot } />
				<div>
					<Tabs
						variant="pills"
						label={ __( 'Customer', 'dev3-ai-booking-appointments' ) }
						value={ mode }
						onChange={ setMode }
						tabs={ [
							{ id: 'existing', label: __( 'Existing customer', 'dev3-ai-booking-appointments' ) },
							{ id: 'new', label: __( 'New customer', 'dev3-ai-booking-appointments' ) },
						] }
					/>
					<div style={ { marginTop: 12 } }>
						{ mode === 'existing' ? (
							<>
								<TextField label={ __( 'Search by name, email or phone', 'dev3-ai-booking-appointments' ) } value={ customer ? `${ customer.name } <${ customer.email }>` : search } onChange={ ( v ) => { setCustomer( null ); setSearch( v ); } } />
								{ ! customer && found.length > 0 && (
									<ul className="dev3bk-pick" role="listbox" aria-label={ __( 'Matching customers', 'dev3-ai-booking-appointments' ) }>
										{ found.map( ( c ) => (
											<li key={ c.id }>
												<button type="button" role="option" aria-selected="false" className={ cx( 'dev3bk-pick__item' ) } onClick={ () => setCustomer( c ) }>
													<b>{ c.name || c.email }</b> <span>{ c.email }</span>
												</button>
											</li>
										) ) }
									</ul>
								) }
							</>
						) : (
							<div className="dev3bk-formrow">
								<TextField label={ __( 'Name', 'dev3-ai-booking-appointments' ) } value={ form.name } onChange={ set( 'name' ) } required />
								<TextField label={ __( 'Email', 'dev3-ai-booking-appointments' ) } type="email" value={ form.email } onChange={ set( 'email' ) } required />
								<TextField label={ __( 'Phone', 'dev3-ai-booking-appointments' ) } type="tel" value={ form.phone } onChange={ set( 'phone' ) } />
							</div>
						) }
					</div>
				</div>
				<div className="dev3bk-formrow">
					<Select
						label={ __( 'Status', 'dev3-ai-booking-appointments' ) }
						value={ form.status }
						onChange={ set( 'status' ) }
						options={ [
							{ value: 'confirmed', label: __( 'Confirmed', 'dev3-ai-booking-appointments' ) },
							{ value: 'pending', label: __( 'Pending approval', 'dev3-ai-booking-appointments' ) },
						] }
					/>
					<Select
						label={ __( 'Payment', 'dev3-ai-booking-appointments' ) }
						value={ form.payment_status }
						onChange={ set( 'payment_status' ) }
						options={ [
							{ value: 'unpaid', label: __( 'Pay at the appointment', 'dev3-ai-booking-appointments' ) },
							{ value: 'paid', label: __( 'Already paid', 'dev3-ai-booking-appointments' ) },
						] }
					/>
				</div>
				<Textarea label={ __( 'Notes', 'dev3-ai-booking-appointments' ) } value={ form.notes } onChange={ set( 'notes' ) } rows={ 2 } maxLength={ 1000 } />
				<Toggle label={ __( 'Email the customer and staff', 'dev3-ai-booking-appointments' ) } checked={ form.notify } onChange={ set( 'notify' ) } />
			</Stack>
		</Modal>
	);
}
