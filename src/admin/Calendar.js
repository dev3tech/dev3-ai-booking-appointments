import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, EmptyState, ErrorNotice, IconButton, PageHeader, Select, Skeleton, Tabs, cx } from '../core3/ui';
import { AgendaRow } from './Dashboard';
import { addDays, addMonths, endOfMonth, fmtDay, fmtMinutes, minutesOf, qs, startOfMonth, startOfWeek, today } from './util';

const HOUR = 56; // px per hour in the time grid.

/**
 * Measures an element's width (ResizeObserver with a resize fallback).
 *
 * @return {Array} [ ref, width ]
 */
export function useWidth() {
	const ref = useRef( null );
	const [ width, setWidth ] = useState( 1024 );
	useEffect( () => {
		const el = ref.current;
		if ( ! el ) {
			return undefined;
		}
		const measure = () => setWidth( el.getBoundingClientRect().width || 1024 );
		measure();
		if ( window.ResizeObserver ) {
			const ro = new window.ResizeObserver( measure );
			ro.observe( el );
			return () => ro.disconnect();
		}
		window.addEventListener( 'resize', measure );
		return () => window.removeEventListener( 'resize', measure );
	}, [] );
	return [ ref, width ];
}

/**
 * Assigns side-by-side lanes to overlapping events.
 *
 * @param {Array} events Events with start/end minutes.
 * @return {Array} Events with lane + lanes.
 */
export function layoutLanes( events ) {
	const sorted = [ ...events ].sort( ( a, b ) => a.s - b.s || b.e - a.e );
	const out = [];
	let cluster = [];
	let clusterEnd = -1;
	const flush = () => {
		const lanes = [];
		cluster.forEach( ( ev ) => {
			let lane = lanes.findIndex( ( end ) => end <= ev.s );
			if ( lane === -1 ) {
				lane = lanes.length;
				lanes.push( ev.e );
			} else {
				lanes[ lane ] = ev.e;
			}
			ev.lane = lane;
		} );
		cluster.forEach( ( ev ) => out.push( { ...ev, lanes: lanes.length } ) );
		cluster = [];
		clusterEnd = -1;
	};
	sorted.forEach( ( ev ) => {
		if ( cluster.length && ev.s >= clusterEnd ) {
			flush();
		}
		cluster.push( ev );
		clusterEnd = Math.max( clusterEnd, ev.e );
	} );
	if ( cluster.length ) {
		flush();
	}
	return out;
}

/**
 * Visible range for a view.
 *
 * @param {string} view   day|week|month.
 * @param {string} anchor Anchor day.
 * @return {Object} { from, to, days[] }
 */
export function rangeFor( view, anchor ) {
	if ( view === 'day' ) {
		return { from: anchor, to: anchor, days: [ anchor ] };
	}
	if ( view === 'week' ) {
		const from = startOfWeek( anchor );
		const days = Array.from( { length: 7 }, ( _, i ) => addDays( from, i ) );
		return { from, to: days[ 6 ], days };
	}
	const first = startOfMonth( anchor );
	const from = startOfWeek( first );
	const last = endOfMonth( anchor );
	const days = [];
	for ( let d = from; days.length < 42 && ( d <= last || days.length % 7 !== 0 ); d = addDays( d, 1 ) ) {
		days.push( d );
	}
	return { from, to: days[ days.length - 1 ], days };
}

function EventBlock( { ev, lo, onOpen, colorBy, showStaff } ) {
	const b = ev.b;
	const top = ( ( ev.s - lo * 60 ) / 60 ) * HOUR;
	const height = Math.max( 22, ( ( ev.e - ev.s ) / 60 ) * HOUR - 2 );
	const color = colorBy === 'service' ? b.service_color : b.staff_color;
	return (
		<button
			type="button"
			className={ cx( 'dev3bk-cal__event', `is-${ b.status }`, height < 36 && 'is-short' ) }
			style={ {
				top,
				height,
				left: `calc(${ ( ev.lane / ev.lanes ) * 100 }% + 2px)`,
				width: `calc(${ 100 / ev.lanes }% - 4px)`,
				'--dev3bk-ev': color,
			} }
			onClick={ () => onOpen( b.id ) }
			title={ `${ b.time_label } · ${ b.service } · ${ b.customer ? b.customer.name : '' }` }
		>
			<span className="dev3bk-cal__event-title">
				<b className="dev3bk-cal__event-time">{ b.time_label.split( ' – ' )[ 0 ] }</b>{ ' ' }
				{ b.risk && b.risk.level === 'high' && (
					<span className="dev3bk-cal__risk" title={ __( 'High no-show risk', 'dev3-ai-booking-appointments' ) }>
						!
					</span>
				) }
				{ b.customer ? b.customer.name || b.customer.email : b.ref }
			</span>
			<span className="dev3bk-cal__event-meta">
				{ b.service }
				{ showStaff ? ` · ${ b.staff }` : '' }
			</span>
		</button>
	);
}

function TimeGrid( { columns, hours, onOpen, onEmpty, colorBy, showStaff } ) {
	const [ lo, hi ] = hours;
	const rows = Array.from( { length: hi - lo }, ( _, i ) => lo + i );
	const nowDay = today();
	return (
		<div className="dev3bk-cal__grid" style={ { '--dev3bk-cols': columns.length } }>
			<div className="dev3bk-cal__head">
				<div className="dev3bk-cal__gutter" aria-hidden="true" />
				{ columns.map( ( c ) => (
					<div key={ c.key } className={ cx( 'dev3bk-cal__colhead', c.day === nowDay && 'is-today' ) }>
						{ c.dot && <span className="dev3bk-cal__dot" style={ { background: c.dot } } aria-hidden="true" /> }
						<span>{ c.label }</span>
						{ c.sub && <span className="dev3bk-cal__colsub">{ c.sub }</span> }
					</div>
				) ) }
			</div>
			<div className="dev3bk-cal__body" style={ { height: rows.length * HOUR } }>
				<div className="dev3bk-cal__gutter">
					{ rows.map( ( h ) => (
						<span key={ h } className="dev3bk-cal__hour" style={ { top: ( h - lo ) * HOUR } }>
							{ fmtMinutes( h * 60 ) }
						</span>
					) ) }
				</div>
				{ columns.map( ( c ) => (
					<div key={ c.key } className="dev3bk-cal__col">
						{ rows.map( ( h ) => (
							<button
								key={ h }
								type="button"
								className="dev3bk-cal__slot"
								style={ { top: ( h - lo ) * HOUR, height: HOUR } }
								onClick={ () => onEmpty( c, h ) }
								aria-label={ sprintf( /* translators: 1: column label, 2: time. */ __( 'New booking: %1$s at %2$s', 'dev3-ai-booking-appointments' ), c.label, fmtMinutes( h * 60 ) ) }
								tabIndex={ -1 }
							/>
						) ) }
						{ layoutLanes( c.events ).map( ( ev ) => (
							<EventBlock key={ ev.b.id } ev={ ev } lo={ lo } onOpen={ onOpen } colorBy={ colorBy } showStaff={ showStaff } />
						) ) }
					</div>
				) ) }
			</div>
		</div>
	);
}

function MonthGrid( { days, anchor, byDay, onDay, onOpen, narrow, selected, colorBy } ) {
	const month = anchor.slice( 0, 7 );
	const heads = days.slice( 0, 7 ).map( ( d ) => fmtDay( d, { weekday: narrow ? 'narrow' : 'short' } ) );
	const nowDay = today();
	return (
		<div className={ cx( 'dev3bk-cal__month', narrow && 'is-narrow' ) }>
			{ heads.map( ( h, i ) => (
				<div key={ `h${ i }` } className="dev3bk-cal__mhead">
					{ h }
				</div>
			) ) }
			{ days.map( ( d ) => {
				const list = byDay[ d ] || [];
				const out = d.slice( 0, 7 ) !== month;
				return (
					<div key={ d } className={ cx( 'dev3bk-cal__mday', out && 'is-out', d === nowDay && 'is-today', selected === d && 'is-selected' ) }>
						<button type="button" className="dev3bk-cal__mnum" onClick={ () => onDay( d ) } aria-label={ sprintf( /* translators: 1: date, 2: count. */ _n( '%1$s, %2$d booking', '%1$s, %2$d bookings', list.length, 'dev3-ai-booking-appointments' ), fmtDay( d, { weekday: 'long', day: 'numeric', month: 'long' } ), list.length ) }>
							{ Number( d.slice( 8 ) ) }
						</button>
						{ narrow ? (
							list.length > 0 && (
								<span className="dev3bk-cal__mdots" aria-hidden="true">
									{ list.slice( 0, 3 ).map( ( b ) => (
										<i key={ b.id } style={ { background: colorBy === 'service' ? b.service_color : b.staff_color } } />
									) ) }
									{ list.length > 3 && <b>+{ list.length - 3 }</b> }
								</span>
							)
						) : (
							<>
								{ list.slice( 0, 3 ).map( ( b ) => (
									<button key={ b.id } type="button" className={ cx( 'dev3bk-cal__chip', `is-${ b.status }` ) } style={ { '--dev3bk-ev': colorBy === 'service' ? b.service_color : b.staff_color } } onClick={ () => onOpen( b.id ) }>
										<b>{ b.time_label.split( ' – ' )[ 0 ] }</b> { b.customer ? b.customer.name : b.ref }
									</button>
								) ) }
								{ list.length > 3 && (
									<button type="button" className="dev3bk-cal__more" onClick={ () => onDay( d, true ) }>
										{ sprintf( /* translators: %d: count. */ __( '+%d more', 'dev3-ai-booking-appointments' ), list.length - 3 ) }
									</button>
								) }
							</>
						) }
					</div>
				);
			} ) }
		</div>
	);
}

export default function CalendarScreen( { catalog, openBooking, newBooking, version } ) {
	const [ view, setView ] = useState( () => ( window.innerWidth < 600 ? 'day' : 'week' ) );
	const [ anchor, setAnchor ] = useState( today() );
	const [ staff, setStaff ] = useState( '' );
	const [ service, setService ] = useState( '' );
	const [ colorBy, setColorBy ] = useState( 'staff' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ picked, setPicked ] = useState( today() );
	const [ ref, width ] = useWidth();
	const narrow = width < 720;
	const range = useMemo( () => rangeFor( view, anchor ), [ view, anchor ] );

	const load = () => {
		setError( null );
		request( `calendar${ qs( { from: range.from, to: range.to, staff, service } ) }` )
			.then( setData )
			.catch( setError );
	};
	useEffect( load, [ range.from, range.to, staff, service, version ] );

	const bookings = data ? data.bookings : [];
	const byDay = useMemo( () => {
		const out = {};
		bookings.forEach( ( b ) => {
			const d = b.local.slice( 0, 10 );
			( out[ d ] = out[ d ] || [] ).push( b );
		} );
		return out;
	}, [ bookings ] );
	const ev = ( b ) => {
		const s = minutesOf( b.local );
		const endDay = b.local_end.slice( 0, 10 );
		const e = endDay !== b.local.slice( 0, 10 ) ? 24 * 60 : minutesOf( b.local_end );
		return { b, s, e: Math.max( e, s + 15 ) };
	};
	let hours = data ? data.hours : [ 8, 19 ];
	bookings.forEach( ( b ) => {
		const s = Math.floor( minutesOf( b.local ) / 60 );
		const e = Math.ceil( minutesOf( b.local_end ) / 60 ) || 24;
		hours = [ Math.min( hours[ 0 ], s ), Math.max( hours[ 1 ], Math.min( 24, e ) ) ];
	} );

	const step = ( dir ) => {
		if ( view === 'day' ) {
			setAnchor( addDays( anchor, dir ) );
		} else if ( view === 'week' ) {
			setAnchor( addDays( anchor, 7 * dir ) );
		} else {
			setAnchor( addMonths( anchor, dir ) );
		}
	};
	const title = () => {
		if ( view === 'day' ) {
			return fmtDay( anchor, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } );
		}
		if ( view === 'week' ) {
			return `${ fmtDay( range.from, { day: 'numeric', month: 'short' } ) } – ${ fmtDay( range.to, { day: 'numeric', month: 'short', year: 'numeric' } ) }`;
		}
		return fmtDay( anchor, { month: 'long', year: 'numeric' } );
	};

	const staffList = ( data ? data.staff : catalog.staff ).filter( ( s ) => ! staff || String( s.id ) === String( staff ) );
	const empty = ( col, h ) => newBooking( { date: col.day, time: `${ String( h ).padStart( 2, '0' ) }:00`, staff: col.staff || ( staff ? Number( staff ) : 0 ) } );

	let body = null;
	if ( ! data ) {
		body = <Skeleton lines={ 8 } />;
	} else if ( view === 'month' ) {
		body = (
			<>
				<MonthGrid
					days={ range.days }
					anchor={ anchor }
					byDay={ byDay }
					narrow={ narrow }
					selected={ narrow ? picked : '' }
					colorBy={ colorBy }
					onOpen={ openBooking }
					onDay={ ( d, jump ) => {
						if ( narrow && ! jump ) {
							setPicked( d );
							return;
						}
						setAnchor( d );
						setView( 'day' );
					} }
				/>
				{ narrow && (
					<div className="dev3bk-cal__list">
						<h3 className="dev3bk-cal__listhead">{ fmtDay( picked, { weekday: 'long', day: 'numeric', month: 'long' } ) }</h3>
						{ ( byDay[ picked ] || [] ).length ? (
							<ul className="dev3bk-agenda">
								{ byDay[ picked ].map( ( b ) => (
									<AgendaRow key={ b.id } b={ { ...b, date_label: '' } } onOpen={ openBooking } />
								) ) }
							</ul>
						) : (
							<EmptyState compact icon="clock" title={ __( 'No bookings this day', 'dev3-ai-booking-appointments' ) } action={ <Button size="sm" variant="secondary" icon="plus" onClick={ () => newBooking( { date: picked } ) }>{ __( 'New booking', 'dev3-ai-booking-appointments' ) }</Button> } />
						) }
					</div>
				) }
			</>
		);
	} else if ( narrow ) {
		// Phones: agenda list per day (day and week views).
		body = (
			<div className="dev3bk-cal__list">
				{ range.days.map( ( d ) => (
					<section key={ d } className="dev3bk-cal__listday">
						<h3 className={ cx( 'dev3bk-cal__listhead', d === today() && 'is-today' ) }>
							{ fmtDay( d, { weekday: 'long', day: 'numeric', month: 'short' } ) }
							<Badge tone="neutral">{ ( byDay[ d ] || [] ).length }</Badge>
						</h3>
						{ ( byDay[ d ] || [] ).length ? (
							<ul className="dev3bk-agenda">
								{ byDay[ d ].map( ( b ) => (
									<AgendaRow key={ b.id } b={ { ...b, date_label: '' } } onOpen={ openBooking } />
								) ) }
							</ul>
						) : (
							<p className="dev3bk-muted">{ __( 'No bookings.', 'dev3-ai-booking-appointments' ) }</p>
						) }
					</section>
				) ) }
			</div>
		);
	} else if ( view === 'day' ) {
		const cols = staffList.map( ( s ) => ( {
			key: s.id,
			staff: s.id,
			day: anchor,
			label: s.name,
			dot: s.color,
			events: ( byDay[ anchor ] || [] ).filter( ( b ) => b.staff_id === s.id ).map( ev ),
		} ) );
		body = cols.length ? (
			<TimeGrid columns={ cols } hours={ hours } onOpen={ openBooking } onEmpty={ empty } colorBy={ colorBy } showStaff={ false } />
		) : (
			<EmptyState icon="users" title={ __( 'No staff yet', 'dev3-ai-booking-appointments' ) } description={ __( 'Add staff members to see their day.', 'dev3-ai-booking-appointments' ) } action={ <Button href="#/staff">{ __( 'Add staff', 'dev3-ai-booking-appointments' ) }</Button> } />
		);
	} else {
		const cols = range.days.map( ( d ) => ( {
			key: d,
			day: d,
			label: fmtDay( d, { weekday: 'short' } ),
			sub: fmtDay( d, { day: 'numeric', month: 'short' } ),
			events: ( byDay[ d ] || [] ).map( ev ),
		} ) );
		body = <TimeGrid columns={ cols } hours={ hours } onOpen={ openBooking } onEmpty={ empty } colorBy={ colorBy } showStaff />;
	}

	const total = bookings.filter( ( b ) => b.status !== 'cancelled' ).length;

	return (
		<>
			<PageHeader
				title={ __( 'Calendar', 'dev3-ai-booking-appointments' ) }
				description={ sprintf( /* translators: %s: timezone. */ __( 'All times in %s.', 'dev3-ai-booking-appointments' ), ( data && data.tz ) || '' ) }
				actions={
					<>
						<Button variant="secondary" icon="sparkles" onClick={ () => newBooking( { ai: true } ) }>
							{ __( 'Find a slot with DEV3 AI', 'dev3-ai-booking-appointments' ) }
						</Button>
						<Button variant="primary" icon="plus" onClick={ () => newBooking( { date: view === 'day' ? anchor : '' } ) }>
							{ __( 'New booking', 'dev3-ai-booking-appointments' ) }
						</Button>
					</>
				}
			/>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			<Card flush className="dev3bk-cal">
				<div ref={ ref } className="dev3bk-cal__toolbar">
					<div className="dev3bk-cal__nav">
						<Button variant="secondary" size="sm" onClick={ () => setAnchor( today() ) }>
							{ __( 'Today', 'dev3-ai-booking-appointments' ) }
						</Button>
						<IconButton icon="chevronLeft" label={ __( 'Previous', 'dev3-ai-booking-appointments' ) } onClick={ () => step( -1 ) } />
						<IconButton icon="chevronRight" label={ __( 'Next', 'dev3-ai-booking-appointments' ) } onClick={ () => step( 1 ) } />
						<h3 className="dev3bk-cal__title" aria-live="polite">
							{ title() }
							<span className="dev3bk-cal__count">{ sprintf( /* translators: %d: count. */ _n( '%d booking', '%d bookings', total, 'dev3-ai-booking-appointments' ), total ) }</span>
						</h3>
					</div>
					<div className="dev3bk-cal__controls">
						<Tabs
							variant="pills"
							label={ __( 'Calendar view', 'dev3-ai-booking-appointments' ) }
							value={ view }
							onChange={ setView }
							tabs={ [
								{ id: 'day', label: __( 'Day', 'dev3-ai-booking-appointments' ) },
								{ id: 'week', label: __( 'Week', 'dev3-ai-booking-appointments' ) },
								{ id: 'month', label: __( 'Month', 'dev3-ai-booking-appointments' ) },
							] }
						/>
						<Select
							label={ __( 'Staff', 'dev3-ai-booking-appointments' ) }
							hideLabel
							value={ staff }
							onChange={ setStaff }
							options={ [ { value: '', label: __( 'All staff', 'dev3-ai-booking-appointments' ) }, ...catalog.staff.map( ( s ) => ( { value: String( s.id ), label: s.name } ) ) ] }
						/>
						<Select
							label={ __( 'Service', 'dev3-ai-booking-appointments' ) }
							hideLabel
							value={ service }
							onChange={ setService }
							options={ [ { value: '', label: __( 'All services', 'dev3-ai-booking-appointments' ) }, ...catalog.services.map( ( s ) => ( { value: String( s.id ), label: s.name } ) ) ] }
						/>
						<Select
							label={ __( 'Colour by', 'dev3-ai-booking-appointments' ) }
							hideLabel
							value={ colorBy }
							onChange={ setColorBy }
							options={ [
								{ value: 'staff', label: __( 'Colour by staff', 'dev3-ai-booking-appointments' ) },
								{ value: 'service', label: __( 'Colour by service', 'dev3-ai-booking-appointments' ) },
							] }
						/>
					</div>
				</div>
				<div className={ cx( 'dev3bk-cal__view', `is-${ view }` ) }>{ body }</div>
			</Card>
		</>
	);
}
