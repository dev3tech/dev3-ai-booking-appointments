import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, ColorField, Drawer, ErrorNotice, Modal, NumberField, PageHeader, ResponsiveTable, Select, Stack, Textarea, TextField, useToast } from '../core3/ui';
import { money } from './util';

const BLANK = {
	name: '',
	description: '',
	category: '',
	color: '#4f46e5',
	duration: 60,
	price: 0,
	deposit_percent: 0,
	capacity: 1,
	buffer_before: 0,
	buffer_after: 0,
	slot_interval: 0,
	min_notice: -1,
	max_advance: -1,
	payment_mode: 'optional',
	location: '',
	status: 'active',
	sort: 0,
	staff: [],
};

export function ServiceEditor( { service, staff, onClose, onSaved } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( { ...BLANK, ...service } );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );
	const num = ( k, fallback = 0 ) => ( form[ k ] === '' ? fallback : form[ k ] );

	const save = ( e ) => {
		e.preventDefault();
		setBusy( true );
		setError( null );
		const data = { ...form };
		[ 'duration', 'price', 'deposit_percent', 'capacity', 'buffer_before', 'buffer_after', 'slot_interval', 'sort' ].forEach( ( k ) => {
			data[ k ] = num( k );
		} );
		data.min_notice = num( 'min_notice', -1 );
		data.max_advance = num( 'max_advance', -1 );
		request( form.id ? `services/${ form.id }` : 'services', { method: form.id ? 'PUT' : 'POST', data } )
			.then( ( s ) => {
				toast.success( __( 'Service saved.', 'dev3-ai-booking-appointments' ) );
				onSaved( s );
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};

	return (
		<Drawer
			open
			onClose={ onClose }
			size="lg"
			title={ form.id ? __( 'Edit service', 'dev3-ai-booking-appointments' ) : __( 'New service', 'dev3-ai-booking-appointments' ) }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', 'dev3-ai-booking-appointments' ) }
					</Button>
					<Button variant="primary" type="submit" form="dev3bk-service-form" loading={ busy }>
						{ __( 'Save service', 'dev3-ai-booking-appointments' ) }
					</Button>
				</>
			}
		>
			<form id="dev3bk-service-form" onSubmit={ save }>
				<Stack gap={ 16 }>
					{ error && <ErrorNotice error={ error } /> }
					<div className="dev3bk-formrow">
						<TextField label={ __( 'Name', 'dev3-ai-booking-appointments' ) } value={ form.name } onChange={ set( 'name' ) } required />
						<TextField label={ __( 'Category', 'dev3-ai-booking-appointments' ) } value={ form.category } onChange={ set( 'category' ) } help={ __( 'Groups services in the booking widget.', 'dev3-ai-booking-appointments' ) } />
					</div>
					<Textarea label={ __( 'Description', 'dev3-ai-booking-appointments' ) } value={ form.description } onChange={ set( 'description' ) } rows={ 3 } maxLength={ 2000 } />
					<div className="dev3bk-formrow">
						<NumberField label={ __( 'Duration (minutes)', 'dev3-ai-booking-appointments' ) } value={ form.duration } onChange={ set( 'duration' ) } min={ 5 } max={ 720 } step={ 5 } />
						<NumberField label={ __( 'Price', 'dev3-ai-booking-appointments' ) } value={ form.price } onChange={ set( 'price' ) } min={ 0 } step={ 0.01 } help={ __( '0 = free.', 'dev3-ai-booking-appointments' ) } />
						<NumberField label={ __( 'Deposit (%)', 'dev3-ai-booking-appointments' ) } value={ form.deposit_percent } onChange={ set( 'deposit_percent' ) } min={ 0 } max={ 100 } help={ __( '0 = full price online.', 'dev3-ai-booking-appointments' ) } />
					</div>
					<div className="dev3bk-formrow">
						<NumberField label={ __( 'Capacity (seats per slot)', 'dev3-ai-booking-appointments' ) } value={ form.capacity } onChange={ set( 'capacity' ) } min={ 1 } max={ 500 } help={ __( 'More than 1 makes it a group class.', 'dev3-ai-booking-appointments' ) } />
						<NumberField label={ __( 'Buffer before (min)', 'dev3-ai-booking-appointments' ) } value={ form.buffer_before } onChange={ set( 'buffer_before' ) } min={ 0 } max={ 240 } step={ 5 } />
						<NumberField label={ __( 'Buffer after (min)', 'dev3-ai-booking-appointments' ) } value={ form.buffer_after } onChange={ set( 'buffer_after' ) } min={ 0 } max={ 240 } step={ 5 } />
					</div>
					<div className="dev3bk-formrow">
						<NumberField label={ __( 'Slot step (min)', 'dev3-ai-booking-appointments' ) } value={ form.slot_interval } onChange={ set( 'slot_interval' ) } min={ 0 } max={ 240 } step={ 5 } help={ __( '0 = use the default in Settings.', 'dev3-ai-booking-appointments' ) } />
						<NumberField label={ __( 'Minimum notice (min)', 'dev3-ai-booking-appointments' ) } value={ form.min_notice } onChange={ set( 'min_notice' ) } min={ -1 } help={ __( '-1 = use the default.', 'dev3-ai-booking-appointments' ) } />
						<NumberField label={ __( 'Book up to (days ahead)', 'dev3-ai-booking-appointments' ) } value={ form.max_advance } onChange={ set( 'max_advance' ) } min={ -1 } max={ 730 } help={ __( '-1 = use the default.', 'dev3-ai-booking-appointments' ) } />
					</div>
					<div className="dev3bk-formrow">
						<Select
							label={ __( 'Online payment', 'dev3-ai-booking-appointments' ) }
							value={ form.payment_mode }
							onChange={ set( 'payment_mode' ) }
							options={ [
								{ value: 'optional', label: __( 'Optional (online or pay later)', 'dev3-ai-booking-appointments' ) },
								{ value: 'required', label: __( 'Required (online only)', 'dev3-ai-booking-appointments' ) },
								{ value: 'off', label: __( 'Off (pay at the appointment)', 'dev3-ai-booking-appointments' ) },
							] }
						/>
						<TextField label={ __( 'Location', 'dev3-ai-booking-appointments' ) } value={ form.location } onChange={ set( 'location' ) } placeholder={ __( 'Address, room or video link', 'dev3-ai-booking-appointments' ) } />
						<ColorField label={ __( 'Colour', 'dev3-ai-booking-appointments' ) } value={ form.color } onChange={ set( 'color' ) } />
					</div>
					<fieldset className="dev3bk-fieldset">
						<legend>{ __( 'Who performs it', 'dev3-ai-booking-appointments' ) }</legend>
						{ staff.length === 0 && <p className="dev3bk-muted">{ __( 'Add staff first, then link them here.', 'dev3-ai-booking-appointments' ) }</p> }
						<div className="dev3bk-checks">
							{ staff.map( ( s ) => (
								<Checkbox
									key={ s.id }
									label={ s.name }
									checked={ form.staff.includes( s.id ) }
									onChange={ ( on ) => setForm( { ...form, staff: on ? [ ...form.staff, s.id ] : form.staff.filter( ( id ) => id !== s.id ) } ) }
								/>
							) ) }
						</div>
					</fieldset>
					<div className="dev3bk-formrow">
						<Select
							label={ __( 'Visibility', 'dev3-ai-booking-appointments' ) }
							value={ form.status }
							onChange={ set( 'status' ) }
							options={ [
								{ value: 'active', label: __( 'Bookable online', 'dev3-ai-booking-appointments' ) },
								{ value: 'hidden', label: __( 'Hidden (admin bookings only)', 'dev3-ai-booking-appointments' ) },
							] }
						/>
						<NumberField label={ __( 'Sort order', 'dev3-ai-booking-appointments' ) } value={ form.sort } onChange={ set( 'sort' ) } />
					</div>
				</Stack>
			</form>
		</Drawer>
	);
}

export default function Services( { catalog, reloadCatalog, changed } ) {
	const toast = useToast();
	const [ editing, setEditing ] = useState( null );
	const [ removing, setRemoving ] = useState( null );
	const staffName = ( id ) => ( catalog.staff.find( ( s ) => s.id === id ) || {} ).name;

	const columns = [
		{ key: 'name', label: __( 'Service', 'dev3-ai-booking-appointments' ), primary: true, sortable: true, render: ( s ) => <span className="dev3bk-swatch" style={ { '--dev3bk-sw': s.color } }><strong>{ s.name }</strong>{ s.category && <span className="dev3bk-muted"> · { s.category }</span> }</span> },
		{ key: 'duration', label: __( 'Duration', 'dev3-ai-booking-appointments' ), sortable: true, render: ( s ) => sprintf( /* translators: %d: minutes. */ __( '%d min', 'dev3-ai-booking-appointments' ), s.duration ) },
		{ key: 'price', label: __( 'Price', 'dev3-ai-booking-appointments' ), sortable: true, render: ( s ) => ( s.price > 0 ? money( s.price ) : __( 'Free', 'dev3-ai-booking-appointments' ) ) + ( s.deposit_percent > 0 && s.deposit_percent < 100 ? ` · ${ s.deposit_percent }% ${ __( 'deposit', 'dev3-ai-booking-appointments' ) }` : '' ) },
		{ key: 'capacity', label: __( 'Seats', 'dev3-ai-booking-appointments' ), render: ( s ) => ( s.capacity > 1 ? sprintf( /* translators: %d: seats. */ __( 'Group · %d', 'dev3-ai-booking-appointments' ), s.capacity ) : '1' ) },
		{ key: 'staff', label: __( 'Staff', 'dev3-ai-booking-appointments' ), render: ( s ) => ( s.staff.length ? s.staff.map( staffName ).filter( Boolean ).join( ', ' ) : <Badge tone="warning">{ __( 'No staff', 'dev3-ai-booking-appointments' ) }</Badge> ) },
		{ key: 'status', label: __( 'Status', 'dev3-ai-booking-appointments' ), render: ( s ) => <Badge tone={ s.status === 'active' ? 'success' : 'neutral' }>{ s.status === 'active' ? __( 'Bookable', 'dev3-ai-booking-appointments' ) : __( 'Hidden', 'dev3-ai-booking-appointments' ) }</Badge> },
	];

	const remove = () =>
		request( `services/${ removing.id }`, { method: 'DELETE' } )
			.then( () => {
				toast.success( __( 'Service removed.', 'dev3-ai-booking-appointments' ) );
				setRemoving( null );
				reloadCatalog();
				changed();
			} )
			.catch( ( e ) => toast.error( e ) );

	return (
		<>
			<PageHeader
				title={ __( 'Services', 'dev3-ai-booking-appointments' ) }
				description={ __( 'What customers can book: duration, price, deposits, group capacity and buffers.', 'dev3-ai-booking-appointments' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setEditing( {} ) }>
						{ __( 'New service', 'dev3-ai-booking-appointments' ) }
					</Button>
				}
			/>
			<Card flush>
				<ResponsiveTable
					columns={ columns }
					rows={ catalog.services }
					onRowClick={ setEditing }
					rowActions={ ( s ) => (
						<span onClick={ ( e ) => e.stopPropagation() } onKeyDown={ ( e ) => e.stopPropagation() } role="presentation">
							<Button size="sm" variant="ghost" icon="edit" onClick={ () => setEditing( s ) }>
								{ __( 'Edit', 'dev3-ai-booking-appointments' ) }
							</Button>
							<Button size="sm" variant="ghost" icon="trash" label={ __( 'Delete', 'dev3-ai-booking-appointments' ) } onClick={ () => setRemoving( s ) } />
						</span>
					) }
					empty={ { icon: 'box', title: __( 'No services yet', 'dev3-ai-booking-appointments' ), description: __( 'Create your first service, then link the staff who perform it.', 'dev3-ai-booking-appointments' ), action: <Button onClick={ () => setEditing( {} ) }>{ __( 'New service', 'dev3-ai-booking-appointments' ) }</Button> } }
				/>
			</Card>
			{ editing && (
				<ServiceEditor
					service={ editing }
					staff={ catalog.staff }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						setEditing( null );
						reloadCatalog();
						changed();
					} }
				/>
			) }
			<Modal
				open={ !! removing }
				onClose={ () => setRemoving( null ) }
				size="sm"
				title={ __( 'Delete this service?', 'dev3-ai-booking-appointments' ) }
				description={ __( 'Services with bookings are archived instead, so their history stays intact.', 'dev3-ai-booking-appointments' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setRemoving( null ) } data-autofocus>
							{ __( 'Keep it', 'dev3-ai-booking-appointments' ) }
						</Button>
						<Button variant="danger" onClick={ remove }>
							{ __( 'Delete', 'dev3-ai-booking-appointments' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
