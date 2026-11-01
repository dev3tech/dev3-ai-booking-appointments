import { __ } from '@wordpress/i18n';
import config from '../core3/config';

/**
 * Date helpers on business-local "YYYY-MM-DD" strings (calendar math in UTC, so no DST surprises).
 */
export const toDate = ( ymd ) => new Date( `${ ymd }T00:00:00Z` );
export const ymd = ( d ) => d.toISOString().slice( 0, 10 );
export const addDays = ( day, n ) => {
	const d = toDate( day );
	d.setUTCDate( d.getUTCDate() + n );
	return ymd( d );
};
export const today = () => config.today || ymd( new Date() );
export const weekday = ( day ) => toDate( day ).getUTCDay(); // 0 = Sunday.

/**
 * First day of the week containing a day.
 *
 * @param {string} day  Day.
 * @param {number} start 0 = Sunday, 1 = Monday.
 * @return {string} Day.
 */
export function startOfWeek( day, start = Number( config.weekStart ?? 1 ) ) {
	const diff = ( weekday( day ) - start + 7 ) % 7;
	return addDays( day, -diff );
}

export const startOfMonth = ( day ) => `${ day.slice( 0, 7 ) }-01`;

/**
 * Last day of the month of a day.
 *
 * @param {string} day Day.
 * @return {string} Day.
 */
export function endOfMonth( day ) {
	const d = toDate( startOfMonth( day ) );
	d.setUTCMonth( d.getUTCMonth() + 1 );
	d.setUTCDate( 0 );
	return ymd( d );
}

/**
 * Adds months to the first day of a month.
 *
 * @param {string} day Day.
 * @param {number} n   Months.
 * @return {string} First day of the target month.
 */
export function addMonths( day, n ) {
	const d = toDate( startOfMonth( day ) );
	d.setUTCMonth( d.getUTCMonth() + n );
	return ymd( d );
}

const locale = () => config.locale || undefined;

/**
 * Formats a local day.
 *
 * @param {string} day  Day.
 * @param {Object} opts Intl options.
 * @return {string} Label.
 */
export function fmtDay( day, opts = { weekday: 'short', day: 'numeric', month: 'short' } ) {
	try {
		return new Intl.DateTimeFormat( locale(), { ...opts, timeZone: 'UTC' } ).format( toDate( day ) );
	} catch ( e ) {
		return day;
	}
}

/**
 * Formats minutes after midnight as a clock label.
 *
 * @param {number} minutes Minutes.
 * @return {string} Label.
 */
export function fmtMinutes( minutes ) {
	const h = Math.floor( minutes / 60 ) % 24;
	const m = minutes % 60;
	if ( String( config.timeFormat ) === '12' ) {
		const hh = h % 12 || 12;
		return `${ hh }${ m ? ':' + String( m ).padStart( 2, '0' ) : '' } ${ h < 12 ? 'AM' : 'PM' }`;
	}
	return `${ String( h ).padStart( 2, '0' ) }:${ String( m ).padStart( 2, '0' ) }`;
}

/**
 * Minutes after midnight of "YYYY-MM-DDTHH:MM".
 *
 * @param {string} local Local datetime.
 * @return {number} Minutes.
 */
export const minutesOf = ( local ) => Number( local.slice( 11, 13 ) ) * 60 + Number( local.slice( 14, 16 ) );

/**
 * Status → badge tone.
 */
export const STATUS_TONE = {
	pending_payment: 'warning',
	pending: 'warning',
	confirmed: 'success',
	completed: 'info',
	cancelled: 'neutral',
	no_show: 'danger',
	expired: 'neutral',
};

export const RISK_TONE = { low: 'success', medium: 'warning', high: 'danger' };

export const statusOptions = () => [
	{ value: 'pending_payment', label: __( 'Awaiting payment', 'dev3-ai-booking-appointments' ) },
	{ value: 'pending', label: __( 'Pending approval', 'dev3-ai-booking-appointments' ) },
	{ value: 'confirmed', label: __( 'Confirmed', 'dev3-ai-booking-appointments' ) },
	{ value: 'completed', label: __( 'Completed', 'dev3-ai-booking-appointments' ) },
	{ value: 'no_show', label: __( 'No-show', 'dev3-ai-booking-appointments' ) },
	{ value: 'cancelled', label: __( 'Cancelled', 'dev3-ai-booking-appointments' ) },
];

export const paymentLabel = ( b ) => {
	const map = {
		none: __( 'Free', 'dev3-ai-booking-appointments' ),
		unpaid: __( 'Unpaid', 'dev3-ai-booking-appointments' ),
		paid: __( 'Paid', 'dev3-ai-booking-appointments' ),
		refunded: __( 'Refunded', 'dev3-ai-booking-appointments' ),
	};
	return map[ b.payment_status ] || b.payment_status;
};

export const methodLabel = ( m ) =>
	( {
		none: __( 'No payment', 'dev3-ai-booking-appointments' ),
		later: __( 'Pay at appointment', 'dev3-ai-booking-appointments' ),
		stripe: __( 'Stripe', 'dev3-ai-booking-appointments' ),
		woocommerce: __( 'WooCommerce', 'dev3-ai-booking-appointments' ),
	} )[ m ] || m;

/**
 * Money with the site currency.
 *
 * @param {number} n        Amount.
 * @param {string} currency Currency.
 * @return {string} Label.
 */
export function money( n, currency = config.currency || 'USD' ) {
	try {
		return new Intl.NumberFormat( locale(), { style: 'currency', currency } ).format( Number( n || 0 ) );
	} catch ( e ) {
		return `${ Number( n || 0 ).toFixed( 2 ) } ${ currency }`;
	}
}

/**
 * Initials for avatars.
 *
 * @param {string} name Name.
 * @return {string} Initials.
 */
export const initials = ( name = '' ) =>
	name
		.trim()
		.split( /\s+/ )
		.slice( 0, 2 )
		.map( ( p ) => p.charAt( 0 ).toUpperCase() )
		.join( '' );

/**
 * Triggers a CSV download.
 *
 * @param {string} filename Name.
 * @param {string} csv      Content.
 */
export function downloadCsv( filename, csv ) {
	const blob = new window.Blob( [ csv ], { type: 'text/csv;charset=utf-8' } );
	const url = window.URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();
	a.remove();
	window.setTimeout( () => window.URL.revokeObjectURL( url ), 1000 );
}

export const DAYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];

export const dayName = ( key ) =>
	( {
		mon: __( 'Monday', 'dev3-ai-booking-appointments' ),
		tue: __( 'Tuesday', 'dev3-ai-booking-appointments' ),
		wed: __( 'Wednesday', 'dev3-ai-booking-appointments' ),
		thu: __( 'Thursday', 'dev3-ai-booking-appointments' ),
		fri: __( 'Friday', 'dev3-ai-booking-appointments' ),
		sat: __( 'Saturday', 'dev3-ai-booking-appointments' ),
		sun: __( 'Sunday', 'dev3-ai-booking-appointments' ),
	} )[ key ];

/**
 * Query string from an object (skips empty values).
 *
 * @param {Object} params Params.
 * @return {string} "?a=1&b=2" or "".
 */
export function qs( params ) {
	const parts = Object.keys( params )
		.filter( ( k ) => params[ k ] !== '' && params[ k ] !== null && params[ k ] !== undefined && params[ k ] !== 0 )
		.map( ( k ) => `${ encodeURIComponent( k ) }=${ encodeURIComponent( params[ k ] ) }` );
	return parts.length ? `?${ parts.join( '&' ) }` : '';
}
