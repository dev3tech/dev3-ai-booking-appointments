import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, ColorField, Drawer, ErrorNotice, Modal, Notice, PageHeader, ResponsiveTable, Select, Stack, Tabs, Textarea, TextField, Toggle, useToast } from '../core3/ui';
import { ExceptionsEditor, WeekEditor } from './ScheduleParts';
import { DAYS, initials } from './util';

const summaryOf = ( hours ) => {
	const open = DAYS.filter( ( d ) => ( hours[ d ] || [] ).length );
	return open.length ? open.map( ( d ) => d.charAt( 0 ).toUpperCase() + d.slice( 1, 3 ) ).join( ' ' ) : __( 'No hours', 'dev3-ai-booking-appointments' );
};

export function StaffEditor( { member, catalog, onClose, onSaved, settings } ) {
	const toast = useToast();
	const [ tab, setTab ] = useState( 'profile' );
	const [ form, setForm ] = useState( {
		name: '',
		email: '',
		phone: '',
		bio: '',
		color: '#0ea5e9',
		timezone: '',
		status: 'active',
		use_business_hours: true,
		hours: settings.business_hours,
		breaks: {},
		services: [],
		exceptions: [],
		...member,
	} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );

	const reloadExceptions = () =>
		request( `exceptions?staff=${ form.id }` )
			.then( ( r ) => setForm( ( f ) => ( { ...f, exceptions: r.exceptions } ) ) )
			.catch( () => {} );

	const save = () => {
		setBusy( true );
		setError( null );
		const data = { ...form };
		delete data.exceptions;
		delete data.feed_url;
		request( form.id ? `staff/${ form.id }` : 'staff', { method: form.id ? 'PUT' : 'POST', data } )
			.then( ( s ) => {
				toast.success( __( 'Staff member saved.', 'dev3-ai-booking-appointments' ) );
				onSaved( s, ! form.id );
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	const rotate = () =>
		request( `staff/${ form.id }/feed-token`, { method: 'POST' } )
			.then( ( s ) => {
				setForm( { ...form, feed_url: s.feed_url } );
				toast.success( __( 'New calendar link created. The old one stopped working.', 'dev3-ai-booking-appointments' ) );
			} )
			.catch( ( e ) => toast.error( e ) );

	const tzOptions = [ { value: '', label: __( 'Business time zone', 'dev3-ai-booking-appointments' ) }, ...( catalog.timezones || [] ).map( ( z ) => ( { value: z, label: z.replace( /_/g, ' ' ) } ) ) ];

	return (
		<Drawer
			open
			onClose={ onClose }
			size="lg"
			title={ form.id ? form.name : __( 'New staff member', 'dev3-ai-booking-appointments' ) }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', 'dev3-ai-booking-appointments' ) }
					</Button>
					<Button variant="primary" loading={ busy } onClick={ save } disabled={ ! form.name }>
						{ __( 'Save', 'dev3-ai-booking-appointments' ) }
					</Button>
				</>
			}
		>
			<Stack gap={ 16 }>
				{ error && <ErrorNotice error={ error } /> }
				<Tabs
					value={ tab }
					onChange={ setTab }
					label={ __( 'Staff sections', 'dev3-ai-booking-appointments' ) }
					tabs={ [
						{ id: 'profile', label: __( 'Profile', 'dev3-ai-booking-appointments' ) },
						{ id: 'hours', label: __( 'Working hours', 'dev3-ai-booking-appointments' ) },
						{ id: 'off', label: __( 'Time off', 'dev3-ai-booking-appointments' ), disabled: ! form.id },
						{ id: 'calendar', label: __( 'Calendar feed', 'dev3-ai-booking-appointments' ), disabled: ! form.id },
					] }
				/>
				{ tab === 'profile' && (
					<>
						<div className="dev3bk-formrow">
							<TextField label={ __( 'Name', 'dev3-ai-booking-appointments' ) } value={ form.name } onChange={ set( 'name' ) } required />
							<TextField label={ __( 'Email', 'dev3-ai-booking-appointments' ) } type="email" value={ form.email } onChange={ set( 'email' ) } help={ __( 'Gets new-booking emails (private).', 'dev3-ai-booking-appointments' ) } />
							<TextField label={ __( 'Phone', 'dev3-ai-booking-appointments' ) } type="tel" value={ form.phone } onChange={ set( 'phone' ) } />
						</div>
						<Textarea label={ __( 'Short bio (shown in the booking widget)', 'dev3-ai-booking-appointments' ) } value={ form.bio } onChange={ set( 'bio' ) } rows={ 2 } maxLength={ 1000 } />
						<div className="dev3bk-formrow">
							<Select label={ __( 'Time zone', 'dev3-ai-booking-appointments' ) } value={ form.timezone } onChange={ set( 'timezone' ) } options={ tzOptions } />
							<ColorField label={ __( 'Calendar colour', 'dev3-ai-booking-appointments' ) } value={ form.color } onChange={ set( 'color' ) } />
							<Select
								label={ __( 'Status', 'dev3-ai-booking-appointments' ) }
								value={ form.status }
								onChange={ set( 'status' ) }
								options={ [
									{ value: 'active', label: __( 'Active', 'dev3-ai-booking-appointments' ) },
									{ value: 'inactive', label: __( 'Inactive (not bookable)', 'dev3-ai-booking-appointments' ) },
								] }
							/>
						</div>
						<fieldset className="dev3bk-fieldset">
							<legend>{ __( 'Services', 'dev3-ai-booking-appointments' ) }</legend>
							<div className="dev3bk-checks">
								{ catalog.services.map( ( s ) => (
									<Checkbox key={ s.id } label={ s.name } checked={ form.services.includes( s.id ) } onChange={ ( on ) => setForm( { ...form, services: on ? [ ...form.services, s.id ] : form.services.filter( ( id ) => id !== s.id ) } ) } />
								) ) }
							</div>
						</fieldset>
					</>
				) }
				{ tab === 'hours' && (
					<>
						<Toggle label={ __( 'Use the business hours', 'dev3-ai-booking-appointments' ) } checked={ form.use_business_hours } onChange={ set( 'use_business_hours' ) } help={ __( 'Turn off to give this person their own weekly hours and breaks.', 'dev3-ai-booking-appointments' ) } />
						{ ! form.use_business_hours && (
							<>
								<Card title={ __( 'Weekly hours', 'dev3-ai-booking-appointments' ) }>
									<WeekEditor value={ form.hours } onChange={ set( 'hours' ) } />
								</Card>
								<Card title={ __( 'Breaks', 'dev3-ai-booking-appointments' ) } description={ __( 'Repeating breaks such as lunch.', 'dev3-ai-booking-appointments' ) }>
									<WeekEditor kind="breaks" value={ form.breaks } onChange={ set( 'breaks' ) } />
								</Card>
							</>
						) }
					</>
				) }
				{ tab === 'off' && form.id && <ExceptionsEditor staffId={ form.id } items={ form.exceptions || [] } onChange={ reloadExceptions } /> }
				{ tab === 'calendar' && form.id && (
					<Card title={ __( 'Private calendar feed', 'dev3-ai-booking-appointments' ) } description={ __( 'Subscribe in Google Calendar ("From URL"), Apple Calendar or Outlook to see this person\'s bookings. Keep the link private.', 'dev3-ai-booking-appointments' ) }>
						<TextField label={ __( 'iCal URL', 'dev3-ai-booking-appointments' ) } value={ form.feed_url || '' } readOnly onFocus={ ( e ) => e.target.select() } />
						<div style={ { display: 'flex', gap: 8, marginTop: 8, flexWrap: 'wrap' } }>
							<Button size="sm" variant="secondary" icon="copy" onClick={ () => window.navigator.clipboard && window.navigator.clipboard.writeText( form.feed_url ).then( () => toast.success( __( 'Copied.', 'dev3-ai-booking-appointments' ) ) ) }>
								{ __( 'Copy link', 'dev3-ai-booking-appointments' ) }
							</Button>
							<Button size="sm" variant="ghost" icon="refresh" onClick={ rotate }>
								{ __( 'Create a new link', 'dev3-ai-booking-appointments' ) }
							</Button>
						</div>
					</Card>
				) }
				{ ! form.id && tab !== 'profile' && tab !== 'hours' && <Notice>{ __( 'Save first to add time off.', 'dev3-ai-booking-appointments' ) }</Notice> }
			</Stack>
		</Drawer>
	);
}

export default function StaffScreen( { catalog, reloadCatalog, changed, settings } ) {
	const toast = useToast();
	const [ editing, setEditing ] = useState( null );
	const [ removing, setRemoving ] = useState( null );
	const svcName = ( id ) => ( catalog.services.find( ( s ) => s.id === id ) || {} ).name;

	const columns = [
		{
			key: 'name',
			label: __( 'Name', 'dev3-ai-booking-appointments' ),
			primary: true,
			render: ( s ) => (
				<span className="dev3bk-person">
					<span className="dev3bk-avatar" style={ { background: s.color } } aria-hidden="true">
						{ initials( s.name ) }
					</span>
					<span>
						<strong>{ s.name }</strong>
						{ s.email && (
							<>
								<br />
								<span className="dev3bk-muted">{ s.email }</span>
							</>
						) }
					</span>
				</span>
			),
		},
		{ key: 'services', label: __( 'Services', 'dev3-ai-booking-appointments' ), render: ( s ) => s.services.map( svcName ).filter( Boolean ).join( ', ' ) || '–' },
		{ key: 'hours', label: __( 'Hours', 'dev3-ai-booking-appointments' ), render: ( s ) => ( s.use_business_hours ? __( 'Business hours', 'dev3-ai-booking-appointments' ) : summaryOf( s.hours ) ) },
		{ key: 'timezone', label: __( 'Time zone', 'dev3-ai-booking-appointments' ), render: ( s ) => s.timezone || __( 'Business', 'dev3-ai-booking-appointments' ) },
		{ key: 'status', label: __( 'Status', 'dev3-ai-booking-appointments' ), render: ( s ) => <Badge tone={ s.status === 'active' ? 'success' : 'neutral' }>{ s.status === 'active' ? __( 'Active', 'dev3-ai-booking-appointments' ) : __( 'Inactive', 'dev3-ai-booking-appointments' ) }</Badge> },
	];

	const remove = () =>
		request( `staff/${ removing.id }`, { method: 'DELETE' } )
			.then( () => {
				setRemoving( null );
				reloadCatalog();
				changed();
				toast.success( __( 'Staff member removed.', 'dev3-ai-booking-appointments' ) );
			} )
			.catch( ( e ) => toast.error( e ) );

	return (
		<>
			<PageHeader
				title={ __( 'Staff', 'dev3-ai-booking-appointments' ) }
				description={ __( 'Who can be booked, their services, hours, breaks, time off and calendar feed.', 'dev3-ai-booking-appointments' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setEditing( {} ) }>
						{ __( 'Add staff', 'dev3-ai-booking-appointments' ) }
					</Button>
				}
			/>
			<Card flush>
				<ResponsiveTable
					columns={ columns }
					rows={ catalog.staff }
					onRowClick={ setEditing }
					rowActions={ ( s ) => (
						<span onClick={ ( e ) => e.stopPropagation() } onKeyDown={ ( e ) => e.stopPropagation() } role="presentation">
							<Button size="sm" variant="ghost" icon="edit" onClick={ () => setEditing( s ) }>
								{ __( 'Edit', 'dev3-ai-booking-appointments' ) }
							</Button>
							<Button size="sm" variant="ghost" icon="trash" label={ __( 'Delete', 'dev3-ai-booking-appointments' ) } onClick={ () => setRemoving( s ) } />
						</span>
					) }
					empty={ { icon: 'users', title: __( 'No staff yet', 'dev3-ai-booking-appointments' ), description: __( 'Add the people (or rooms/resources) customers book.', 'dev3-ai-booking-appointments' ), action: <Button onClick={ () => setEditing( {} ) }>{ __( 'Add staff', 'dev3-ai-booking-appointments' ) }</Button> } }
				/>
			</Card>
			{ editing && (
				<StaffEditor
					member={ editing }
					catalog={ catalog }
					settings={ settings }
					onClose={ () => setEditing( null ) }
					onSaved={ ( s, created ) => {
						reloadCatalog();
						changed();
						setEditing( created ? s : null );
					} }
				/>
			) }
			<Modal
				open={ !! removing }
				onClose={ () => setRemoving( null ) }
				size="sm"
				title={ __( 'Remove this staff member?', 'dev3-ai-booking-appointments' ) }
				description={ __( 'People with bookings are archived so their history stays intact.', 'dev3-ai-booking-appointments' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setRemoving( null ) } data-autofocus>
							{ __( 'Keep', 'dev3-ai-booking-appointments' ) }
						</Button>
						<Button variant="danger" onClick={ remove }>
							{ __( 'Remove', 'dev3-ai-booking-appointments' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
