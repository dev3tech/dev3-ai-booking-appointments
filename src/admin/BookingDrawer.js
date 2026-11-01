import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Checkbox, Drawer, ErrorNotice, Inline, Modal, Notice, Select, Skeleton, Stack, Textarea, TextField, useToast } from '../core3/ui';
import SlotPicker from './SlotPicker';
import { RISK_TONE, STATUS_TONE, methodLabel, money, paymentLabel, today } from './util';

function Row( { label, children } ) {
	return (
		<div className="dev3bk-dl__row">
			<dt>{ label }</dt>
			<dd>{ children }</dd>
		</div>
	);
}

export default function BookingDrawer( { id, onClose, onChanged, catalog, onUsage } ) {
	const toast = useToast();
	const [ b, setB ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ cancel, setCancel ] = useState( null );
	const [ move, setMove ] = useState( null );
	const [ notes, setNotes ] = useState( '' );

	useEffect( () => {
		setB( null );
		setError( null );
		setMove( null );
		if ( ! id ) {
			return;
		}
		request( `bookings/${ id }` )
			.then( ( d ) => {
				setB( d );
				setNotes( d.notes || '' );
			} )
			.catch( setError );
	}, [ id ] );

	const act = ( key, path, data, message ) => {
		setBusy( key );
		return request( `bookings/${ id }/${ path }`, { method: 'POST', data } )
			.then( ( d ) => {
				setB( d );
				onChanged();
				if ( message ) {
					toast.success( message );
				}
				return d;
			} )
			.catch( ( e ) => {
				toast.error( e );
				throw e;
			} )
			.finally( () => setBusy( '' ) );
	};

	const status = ( s, message ) => act( s, 'status', { status: s }, message ).catch( () => {} );
	const active = b && [ 'pending', 'confirmed', 'pending_payment' ].includes( b.status );
	const past = b && new Date( b.start ) < new Date();

	const saveNotes = () => {
		setBusy( 'notes' );
		request( `bookings/${ id }`, { method: 'PATCH', data: { notes } } )
			.then( ( d ) => {
				setB( d );
				toast.success( __( 'Notes saved.', 'dev3-ai-booking-appointments' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const explain = () => {
		setBusy( 'risk' );
		request( `bookings/${ id }/risk`, { method: 'POST' } )
			.then( ( d ) => {
				setB( d );
				onUsage();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const doMove = () => {
		const data = { staff: move.staff || b.staff_id, force: !! move.force };
		if ( move.force ) {
			data.local = `${ move.date } ${ move.time }`;
		} else {
			data.start = move.start;
		}
		act( 'move', 'reschedule', data, __( 'Booking moved. The customer was emailed.', 'dev3-ai-booking-appointments' ) )
			.then( () => setMove( null ) )
			.catch( () => {} );
	};

	const footer = b && (
		<>
			{ b.status === 'pending' && (
				<Button variant="primary" icon="check" loading={ busy === 'confirmed' } onClick={ () => status( 'confirmed', __( 'Booking confirmed.', 'dev3-ai-booking-appointments' ) ) }>
					{ __( 'Confirm', 'dev3-ai-booking-appointments' ) }
				</Button>
			) }
			{ active && past && (
				<>
					<Button variant="secondary" loading={ busy === 'completed' } onClick={ () => status( 'completed', __( 'Marked as completed.', 'dev3-ai-booking-appointments' ) ) }>
						{ __( 'Completed', 'dev3-ai-booking-appointments' ) }
					</Button>
					<Button variant="secondary" loading={ busy === 'no_show' } onClick={ () => status( 'no_show', __( 'Marked as no-show.', 'dev3-ai-booking-appointments' ) ) }>
						{ __( 'No-show', 'dev3-ai-booking-appointments' ) }
					</Button>
				</>
			) }
			{ active && (
				<Button variant="secondary" icon="clock" onClick={ () => setMove( { date: b.local.slice( 0, 10 ) >= today() ? b.local.slice( 0, 10 ) : today(), staff: b.staff_id, start: '', force: false } ) }>
					{ __( 'Reschedule', 'dev3-ai-booking-appointments' ) }
				</Button>
			) }
			{ ( active || b.status === 'no_show' || b.status === 'completed' ) && (
				<Button variant="danger" icon="x" onClick={ () => setCancel( { reason: '', refund: false } ) }>
					{ __( 'Cancel booking', 'dev3-ai-booking-appointments' ) }
				</Button>
			) }
		</>
	);

	return (
		<Drawer
			open={ !! id }
			onClose={ onClose }
			size="lg"
			title={ b ? sprintf( /* translators: %s: booking reference. */ __( 'Booking %s', 'dev3-ai-booking-appointments' ), b.ref ) : __( 'Booking', 'dev3-ai-booking-appointments' ) }
			description={ b ? `${ b.service } · ${ b.day_label }, ${ b.date_label } · ${ b.time_label }` : '' }
			footer={ footer }
		>
			{ error && <ErrorNotice error={ error } /> }
			{ ! b && ! error && <Skeleton lines={ 8 } /> }
			{ b && (
				<Stack gap={ 16 }>
					<Inline gap={ 8 }>
						<Badge tone={ STATUS_TONE[ b.status ] }>{ b.status_label }</Badge>
						<Badge tone={ b.payment_status === 'paid' ? 'success' : 'neutral' }>{ paymentLabel( b ) }</Badge>
						<Badge tone={ RISK_TONE[ b.risk.level ] } dot>
							{ sprintf( /* translators: %d: score. */ __( 'No-show risk %d', 'dev3-ai-booking-appointments' ), b.risk.score ) }
						</Badge>
						{ b.source === 'ai' && <Badge tone="brand">{ __( 'Booked via DEV3 AI', 'dev3-ai-booking-appointments' ) }</Badge> }
					</Inline>

					{ move && (
						<Card title={ __( 'Reschedule', 'dev3-ai-booking-appointments' ) } description={ __( 'Only free times are offered unless you override. The customer receives the new time and an updated calendar file.', 'dev3-ai-booking-appointments' ) } footer={ <><Button variant="ghost" onClick={ () => setMove( null ) }>{ __( 'Close', 'dev3-ai-booking-appointments' ) }</Button><Button variant="primary" disabled={ move.force ? ! move.time : ! move.start } loading={ busy === 'move' } onClick={ doMove }>{ __( 'Move booking', 'dev3-ai-booking-appointments' ) }</Button></> }>
							<div className="dev3bk-formrow">
								<TextField label={ __( 'Day', 'dev3-ai-booking-appointments' ) } type="date" value={ move.date } onChange={ ( date ) => setMove( { ...move, date, start: '' } ) } />
								<Select label={ __( 'Staff', 'dev3-ai-booking-appointments' ) } value={ String( move.staff ) } onChange={ ( v ) => setMove( { ...move, staff: Number( v ), start: '' } ) } options={ catalog.staff.filter( ( s ) => s.services.includes( b.service_id ) || s.id === b.staff_id ).map( ( s ) => ( { value: String( s.id ), label: s.name } ) ) } />
							</div>
							<SlotPicker service={ b.service_id } staff={ move.staff } date={ move.date } exclude={ b.id } value={ move } onChange={ ( v ) => setMove( { ...move, ...v } ) } />
						</Card>
					) }

					<Card title={ __( 'Customer', 'dev3-ai-booking-appointments' ) }>
						<dl className="dev3bk-dl">
							<Row label={ __( 'Name', 'dev3-ai-booking-appointments' ) }>{ b.customer ? b.customer.name : '–' }</Row>
							<Row label={ __( 'Email', 'dev3-ai-booking-appointments' ) }>{ b.customer ? <a href={ `mailto:${ b.customer.email }` }>{ b.customer.email }</a> : '–' }</Row>
							<Row label={ __( 'Phone', 'dev3-ai-booking-appointments' ) }>{ b.customer && b.customer.phone ? <a href={ `tel:${ b.customer.phone }` }>{ b.customer.phone }</a> : '–' }</Row>
							<Row label={ __( 'Their time zone', 'dev3-ai-booking-appointments' ) }>{ b.customer_tz }</Row>
						</dl>
					</Card>

					<Card title={ __( 'Appointment', 'dev3-ai-booking-appointments' ) }>
						<dl className="dev3bk-dl">
							<Row label={ __( 'Service', 'dev3-ai-booking-appointments' ) }>
								{ b.service } · { sprintf( /* translators: %d: minutes. */ __( '%d min', 'dev3-ai-booking-appointments' ), b.duration ) }
								{ b.seats > 1 ? ` · ${ sprintf( /* translators: %d: seats. */ __( '%d seats', 'dev3-ai-booking-appointments' ), b.seats ) }` : '' }
							</Row>
							<Row label={ __( 'Staff', 'dev3-ai-booking-appointments' ) }>{ b.staff }</Row>
							<Row label={ __( 'When', 'dev3-ai-booking-appointments' ) }>
								{ b.day_label }, { b.date_label } · { b.time_label }
							</Row>
							{ b.location && <Row label={ __( 'Where', 'dev3-ai-booking-appointments' ) }>{ b.location }</Row> }
							<Row label={ __( 'Price', 'dev3-ai-booking-appointments' ) }>
								{ b.amount_label }
								{ b.amount_due < b.amount && b.amount > 0 ? ` · ${ sprintf( /* translators: %s: amount. */ __( 'deposit %s', 'dev3-ai-booking-appointments' ), money( b.amount_due, b.currency ) ) }` : '' }
							</Row>
							<Row label={ __( 'Payment', 'dev3-ai-booking-appointments' ) }>
								{ methodLabel( b.payment_method ) } · { paymentLabel( b ) }
								{ b.paid_amount > 0 && ` · ${ money( b.paid_amount, b.currency ) }` }
								{ b.order_url && (
									<>
										{ ' · ' }
										<a href={ b.order_url }>{ sprintf( /* translators: %d: order number. */ __( 'Order #%d', 'dev3-ai-booking-appointments' ), b.wc_order_id ) }</a>
									</>
								) }
							</Row>
						</dl>
						<Inline gap={ 8 }>
							{ b.payment_status === 'unpaid' && (
								<Button size="sm" variant="secondary" loading={ busy === 'paid' } onClick={ () => act( 'paid', 'payment', { action: 'paid' }, __( 'Marked as paid.', 'dev3-ai-booking-appointments' ) ).catch( () => {} ) }>
									{ __( 'Mark as paid', 'dev3-ai-booking-appointments' ) }
								</Button>
							) }
							{ b.payment_status === 'paid' && b.paid_amount > 0 && (
								<Button size="sm" variant="ghost" loading={ busy === 'refund' } onClick={ () => act( 'refund', 'payment', { action: 'refund' }, __( 'Refund recorded.', 'dev3-ai-booking-appointments' ) ).catch( () => {} ) }>
									{ b.payment_method === 'stripe' ? __( 'Refund via Stripe', 'dev3-ai-booking-appointments' ) : __( 'Record refund', 'dev3-ai-booking-appointments' ) }
								</Button>
							) }
							{ active && (
								<>
									<Button size="sm" variant="ghost" icon="inbox" loading={ busy === 'mail' } onClick={ () => act( 'mail', 'notify', { template: b.status === 'pending' ? 'customer_pending' : 'customer_confirmed' }, __( 'Email sent.', 'dev3-ai-booking-appointments' ) ).catch( () => {} ) }>
										{ __( 'Resend confirmation', 'dev3-ai-booking-appointments' ) }
									</Button>
									<Button size="sm" variant="ghost" icon="clock" loading={ busy === 'remind' } onClick={ () => act( 'remind', 'notify', { template: 'reminder_1' }, __( 'Reminder sent.', 'dev3-ai-booking-appointments' ) ).catch( () => {} ) }>
										{ __( 'Send reminder now', 'dev3-ai-booking-appointments' ) }
									</Button>
								</>
							) }
							<Button size="sm" variant="link" icon="external" href={ b.manage_url } target="_blank" rel="noreferrer">
								{ __( 'Customer page', 'dev3-ai-booking-appointments' ) }
							</Button>
						</Inline>
					</Card>

					<Card tone="ai" title={ __( 'DEV3 AI · No-show risk hint', 'dev3-ai-booking-appointments' ) } description={ __( 'A hint for you only. Nothing changes automatically.', 'dev3-ai-booking-appointments' ) }>
						<div className="dev3bk-risk">
							<div className={ `dev3bk-risk__score is-${ b.risk.level }` } aria-hidden="true">
								{ b.risk.score }
							</div>
							<div>
								<p className="dev3bk-risk__level">
									{ { low: __( 'Low risk', 'dev3-ai-booking-appointments' ), medium: __( 'Medium risk', 'dev3-ai-booking-appointments' ), high: __( 'High risk', 'dev3-ai-booking-appointments' ) }[ b.risk.level ] }
									<span className="screen-reader-text">{ ` ${ b.risk.score } / 100` }</span>
								</p>
								{ b.risk.reasons.length > 0 && (
									<ul className="dev3bk-risk__reasons">
										{ b.risk.reasons.map( ( r ) => (
											<li key={ r }>{ r }</li>
										) ) }
									</ul>
								) }
							</div>
						</div>
						{ b.risk.ai && b.risk.ai.hint ? (
							<Notice tone="ai" title={ __( 'Suggested nudge', 'dev3-ai-booking-appointments' ) }>
								<p>{ b.risk.ai.hint }</p>
								<p>
									<strong>{ b.risk.ai.action }</strong>
								</p>
							</Notice>
						) : (
							active && (
								<AiButton size="sm" loading={ busy === 'risk' } loadingText={ __( 'Thinking…', 'dev3-ai-booking-appointments' ) } onClick={ explain }>
									{ __( 'Explain with DEV3 AI', 'dev3-ai-booking-appointments' ) }
								</AiButton>
							)
						) }
					</Card>

					<Card title={ __( 'Notes', 'dev3-ai-booking-appointments' ) } footer={ <Button size="sm" variant="secondary" loading={ busy === 'notes' } disabled={ notes === ( b.notes || '' ) } onClick={ saveNotes }>{ __( 'Save notes', 'dev3-ai-booking-appointments' ) }</Button> }>
						<Textarea label={ __( 'Notes (customer and internal)', 'dev3-ai-booking-appointments' ) } hideLabel value={ notes } onChange={ setNotes } rows={ 3 } maxLength={ 1000 } />
					</Card>

					<Card title={ __( 'Timeline', 'dev3-ai-booking-appointments' ) }>
						<ol className="dev3bk-timeline">
							{ b.events.map( ( e ) => (
								<li key={ e.id } className={ `is-${ e.actor }` }>
									<span className="dev3bk-timeline__msg">{ e.message }</span>
									<span className="dev3bk-timeline__meta">
										{ new Date( e.at ).toLocaleString() } · { e.actor }
									</span>
								</li>
							) ) }
						</ol>
					</Card>
				</Stack>
			) }
			<Modal
				open={ !! cancel }
				onClose={ () => setCancel( null ) }
				size="sm"
				title={ __( 'Cancel this booking?', 'dev3-ai-booking-appointments' ) }
				description={ __( 'The time is released and the customer is emailed.', 'dev3-ai-booking-appointments' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setCancel( null ) } data-autofocus>
							{ __( 'Keep booking', 'dev3-ai-booking-appointments' ) }
						</Button>
						<Button
							variant="danger"
							loading={ busy === 'cancelled' }
							onClick={ () =>
								act( 'cancelled', 'status', { status: 'cancelled', reason: cancel.reason, refund: cancel.refund }, __( 'Booking cancelled.', 'dev3-ai-booking-appointments' ) )
									.then( () => setCancel( null ) )
									.catch( () => {} )
							}
						>
							{ __( 'Cancel booking', 'dev3-ai-booking-appointments' ) }
						</Button>
					</>
				}
			>
				{ cancel && (
					<Stack gap={ 12 }>
						<TextField label={ __( 'Reason (shown in the timeline)', 'dev3-ai-booking-appointments' ) } value={ cancel.reason } onChange={ ( reason ) => setCancel( { ...cancel, reason } ) } />
						{ b && b.payment_status === 'paid' && b.paid_amount > 0 && (
							<Checkbox
								label={ sprintf( /* translators: %s: amount. */ __( 'Refund %s', 'dev3-ai-booking-appointments' ), money( b.paid_amount, b.currency ) ) }
								checked={ cancel.refund }
								onChange={ ( refund ) => setCancel( { ...cancel, refund } ) }
								help={ b.payment_method === 'stripe' ? __( 'Refunded to the card through Stripe.', 'dev3-ai-booking-appointments' ) : __( 'Recorded on the booking (and as a WooCommerce refund for checkout payments).', 'dev3-ai-booking-appointments' ) }
							/>
						) }
					</Stack>
				) }
			</Modal>
		</Drawer>
	);
}
