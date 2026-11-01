/**
 * Date helpers on customer-local "YYYY-MM-DD" strings (calendar math in UTC).
 */
export const toDate = ( d ) => new Date( `${ d }T12:00:00Z` );
export const ymd = ( d ) => d.toISOString().slice( 0, 10 );
export function addDays( day, n ) {
	const d = toDate( day );
	d.setUTCDate( d.getUTCDate() + n );
	return ymd( d );
}

/**
 * Browser time zone or a fallback.
 *
 * @param {string} fallback Fallback.
 * @return {string} IANA zone.
 */
export function browserTz( fallback ) {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || fallback;
	} catch ( e ) {
		return fallback;
	}
}

/**
 * Formats a local day.
 *
 * @param {string} day    Day.
 * @param {Object} opts   Intl options.
 * @param {string} locale Locale.
 * @return {string} Label.
 */
export function fmtDay( day, opts, locale ) {
	try {
		return new Intl.DateTimeFormat( locale || undefined, { ...opts, timeZone: 'UTC' } ).format( toDate( day ) );
	} catch ( e ) {
		return day;
	}
}

/**
 * Today's date in a zone.
 *
 * @param {string} tz Zone.
 * @return {string} Y-m-d.
 */
export function todayIn( tz ) {
	try {
		const parts = new Intl.DateTimeFormat( 'en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' } ).formatToParts( new Date() );
		const get = ( t ) => parts.find( ( p ) => p.type === t ).value;
		return `${ get( 'year' ) }-${ get( 'month' ) }-${ get( 'day' ) }`;
	} catch ( e ) {
		return ymd( new Date() );
	}
}

/**
 * Time zones for the switcher.
 *
 * @param {string[]} must Zones that must be included.
 * @return {string[]} Zones.
 */
export function zones( must = [] ) {
	let list = [];
	try {
		list = Intl.supportedValuesOf ? Intl.supportedValuesOf( 'timeZone' ) : [];
	} catch ( e ) {
		list = [];
	}
	if ( ! list.length ) {
		list = [ 'UTC', 'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Tokyo', 'Australia/Sydney' ];
	}
	must.forEach( ( z ) => {
		if ( z && ! list.includes( z ) ) {
			list.unshift( z );
		}
	} );
	return list;
}
