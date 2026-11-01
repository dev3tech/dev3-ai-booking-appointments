import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Icon, Notice, PageHeader, Skeleton, Stat, StatGrid } from '../core3/ui';
import { RISK_TONE, STATUS_TONE } from './util';

/**
 * Compact booking row (agenda / lists).
 *
 * @param {Object}   props        Props.
 * @param {Object}   props.b      Booking.
 * @param {Function} props.onOpen Open.
 * @param {boolean}  props.risk   Show the risk badge.
 * @return {Element} Row.
 */
export function AgendaRow( { b, onOpen, risk = true } ) {
	return (
		<li>
			<button type="button" className="dev3bk-agenda__row" onClick={ () => onOpen( b.id ) } style={ { '--dev3bk-row-color': b.staff_color || b.service_color } }>
				<span className="dev3bk-agenda__time">{ b.time_label.split( ' – ' )[ 0 ] }</span>
				<span className="dev3bk-agenda__main">
					<span className="dev3bk-agenda__title">{ b.customer ? b.customer.name || b.customer.email : b.ref }</span>
					<span className="dev3bk-agenda__meta">
						{ b.service } · { b.staff }
						{ b.date_label && <> · { b.day_label.slice( 0, 3 ) } { b.date_label }</> }
					</span>
				</span>
				<span className="dev3bk-agenda__badges">
					{ risk && b.risk && b.risk.level === 'high' && (
						<Badge tone="danger" dot>
							{ __( 'No-show risk', 'dev3-ai-booking-appointments' ) }
						</Badge>
					) }
					<Badge tone={ STATUS_TONE[ b.status ] }>{ b.status_label }</Badge>
				</span>
			</button>
		</li>
	);
}

export default function Dashboard( { openBooking, newBooking, navigate, version, setPending } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = () => {
		setError( null );
		request( 'dashboard' )
			.then( ( d ) => {
				setData( d );
				setPending( d.kpis.pending || null );
			} )
			.catch( setError );
	};
	useEffect( load, [ version ] );

	const k = data ? data.kpis : {};
	const checklist = data
		? [
				{ ok: data.checklist.services, label: __( 'Add your services', 'dev3-ai-booking-appointments' ), href: '#/services' },
				{ ok: data.checklist.staff, label: __( 'Add staff and their working hours', 'dev3-ai-booking-appointments' ), href: '#/staff' },
				{ ok: data.checklist.page, label: __( 'Publish the booking page', 'dev3-ai-booking-appointments' ), href: '#/settings' },
				{ ok: data.checklist.payments, label: __( 'Connect online payments for services that require them', 'dev3-ai-booking-appointments' ), href: '#/settings' },
				{ ok: data.checklist.email, label: __( 'Set the business email for notifications', 'dev3-ai-booking-appointments' ), href: '#/settings' },
				{ ok: data.checklist.ai, label: __( 'Connect DEV3 AI (optional)', 'dev3-ai-booking-appointments' ), href: '#/api' },
		  ]
		: [];
	const todo = checklist.filter( ( c ) => ! c.ok );

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-booking-appointments' ) }
				description={ __( 'Today at a glance, approvals and bookings that may need a nudge.', 'dev3-ai-booking-appointments' ) }
				actions={
					<>
						{ data && data.links.booking_page && (
							<Button variant="secondary" icon="external" href={ data.links.booking_page } target="_blank" rel="noreferrer">
								{ __( 'View booking page', 'dev3-ai-booking-appointments' ) }
							</Button>
						) }
						<Button variant="primary" icon="plus" onClick={ () => newBooking() }>
							{ __( 'New booking', 'dev3-ai-booking-appointments' ) }
						</Button>
					</>
				}
			/>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			<StatGrid min={ 170 }>
				<Stat label={ __( 'Today', 'dev3-ai-booking-appointments' ) } value={ k.today ?? '–' } hint={ __( 'appointments', 'dev3-ai-booking-appointments' ) } icon="clock" tone="brand" loading={ ! data } />
				<Stat label={ __( 'Next 7 days', 'dev3-ai-booking-appointments' ) } value={ k.week ?? '–' } hint={ __( 'booked', 'dev3-ai-booking-appointments' ) } icon="columns" loading={ ! data } />
				<Stat label={ __( 'Revenue (30 days)', 'dev3-ai-booking-appointments' ) } value={ k.revenue_label ?? '–' } hint={ __( 'paid online or marked paid', 'dev3-ai-booking-appointments' ) } icon="chart" tone="success" loading={ ! data } />
				<Stat
					label={ __( 'No-show rate', 'dev3-ai-booking-appointments' ) }
					value={ data ? `${ k.no_show_rate }%` : '–' }
					hint={ __( 'last 90 days', 'dev3-ai-booking-appointments' ) }
					icon="alert"
					tone={ k.no_show_rate > 10 ? 'danger' : 'neutral' }
					loading={ ! data }
				/>
			</StatGrid>
			{ data && todo.length > 0 && (
				<Card title={ __( 'Finish setting up', 'dev3-ai-booking-appointments' ) } description={ sprintf( /* translators: 1: done, 2: total. */ __( '%1$d of %2$d done', 'dev3-ai-booking-appointments' ), checklist.length - todo.length, checklist.length ) } style={ { marginTop: 16 } }>
					<ul className="dev3bk-checklist">
						{ checklist.map( ( c ) => (
							<li key={ c.label } className={ c.ok ? 'is-done' : '' }>
								<Icon name={ c.ok ? 'success' : 'info' } size={ 18 } />
								{ c.ok ? <span>{ c.label }</span> : <a href={ c.href }>{ c.label }</a> }
							</li>
						) ) }
					</ul>
				</Card>
			) }
			<div style={ { marginTop: 16 } }>
				<Columns min={ 320 }>
					<Card
						title={ __( 'Today', 'dev3-ai-booking-appointments' ) }
						actions={
							<Button variant="ghost" size="sm" onClick={ () => navigate( 'calendar' ) }>
								{ __( 'Open calendar', 'dev3-ai-booking-appointments' ) }
							</Button>
						}
					>
						{ ! data && <Skeleton lines={ 4 } /> }
						{ data && data.today.length === 0 && <EmptyState compact icon="clock" title={ __( 'Nothing booked today', 'dev3-ai-booking-appointments' ) } description={ __( 'New online bookings appear here as they come in.', 'dev3-ai-booking-appointments' ) } /> }
						{ data && data.today.length > 0 && (
							<ul className="dev3bk-agenda">
								{ data.today.map( ( b ) => (
									<AgendaRow key={ b.id } b={ { ...b, date_label: '' } } onOpen={ openBooking } />
								) ) }
							</ul>
						) }
					</Card>
					<Card title={ __( 'Waiting for approval', 'dev3-ai-booking-appointments' ) } description={ __( 'Requests stay pending until you confirm them.', 'dev3-ai-booking-appointments' ) }>
						{ ! data && <Skeleton lines={ 3 } /> }
						{ data && data.pending.length === 0 && <EmptyState compact icon="success" title={ __( 'All caught up', 'dev3-ai-booking-appointments' ) } /> }
						{ data && data.pending.length > 0 && (
							<ul className="dev3bk-agenda">
								{ data.pending.map( ( b ) => (
									<AgendaRow key={ b.id } b={ b } onOpen={ openBooking } />
								) ) }
							</ul>
						) }
					</Card>
				</Columns>
			</div>
			<Card
				tone="ai"
				style={ { marginTop: 16 } }
				title={ __( 'DEV3 AI · No-show watch', 'dev3-ai-booking-appointments' ) }
				description={ __( 'Upcoming bookings (14 days) with a high no-show risk score. Open one for the reasons and a suggested nudge.', 'dev3-ai-booking-appointments' ) }
			>
				{ ! data && <Skeleton lines={ 2 } /> }
				{ data && data.risky.length === 0 && <Notice tone="success">{ __( 'No high-risk bookings right now.', 'dev3-ai-booking-appointments' ) }</Notice> }
				{ data && data.risky.length > 0 && (
					<ul className="dev3bk-agenda">
						{ data.risky.map( ( b ) => (
							<li key={ b.id }>
								<button type="button" className="dev3bk-agenda__row" onClick={ () => openBooking( b.id ) } style={ { '--dev3bk-row-color': b.staff_color } }>
									<span className="dev3bk-agenda__time">{ b.risk.score }</span>
									<span className="dev3bk-agenda__main">
										<span className="dev3bk-agenda__title">{ b.customer ? b.customer.name : b.ref }</span>
										<span className="dev3bk-agenda__meta">
											{ b.day_label.slice( 0, 3 ) } { b.date_label } · { b.time_label } · { b.risk.reasons.slice( 0, 2 ).join( ', ' ) }
										</span>
									</span>
									<span className="dev3bk-agenda__badges">
										<Badge tone={ RISK_TONE[ b.risk.level ] }>{ __( 'High risk', 'dev3-ai-booking-appointments' ) }</Badge>
									</span>
								</button>
							</li>
						) ) }
					</ul>
				) }
			</Card>
		</>
	);
}
