import { url } from '../../src/front/api';
import { t } from '../../src/front/picker';
import { addDays, todayIn } from '../../src/front/time';
import { boot } from '../../src/front/app';

const strings = new Proxy(
	{ stepOf: 'Step %1$d of %2$d', minutes: '%d min', nlResults: 'Free times for: %s', timesIn: 'Times shown in %s', doneText: 'We sent the details to %s.', status: {} },
	{ get: ( o, k ) => ( k in o ? o[ k ] : String( k ) ) }
);

const flush = () => new Promise( ( r ) => setTimeout( r, 0 ) );
const tick = async ( n = 4 ) => {
	for ( let i = 0; i < n; i++ ) {
		await flush();
	}
};

function fakeFetch( routes, calls ) {
	return jest.fn( ( u, opts ) => {
		const path = u.replace( '/wp-json/dev3bk/v1/', '' ).split( '?' )[ 0 ];
		calls.push( { path, url: u, method: opts.method, body: opts.body ? JSON.parse( opts.body ) : null } );
		const handler = routes[ `${ opts.method } ${ path }` ];
		const data = typeof handler === 'function' ? handler( opts.body ? JSON.parse( opts.body ) : null, u ) : handler;
		return Promise.resolve( { ok: !! handler, status: handler ? 200 : 404, json: () => Promise.resolve( data || { message: 'nope' } ) } );
	} );
}

const catalog = {
	services: [
		{ id: 1, name: 'Haircut', description: '', category: 'Hair', color: '#4f46e5', duration: 45, price: 45, price_label: '£45.00', deposit: '', capacity: 1, location: '', staff: [ 7, 8 ], payments: [ 'later' ] },
		{ id: 2, name: 'Beard trim', description: '', category: 'Grooming', color: '#0891b2', duration: 20, price: 20, price_label: '£20.00', deposit: '', capacity: 1, location: '', staff: [ 8 ], payments: [ 'later' ] },
	],
	staff: [
		{ id: 7, name: 'Anna Berg', bio: '', color: '#4f46e5', services: [ 1 ] },
		{ id: 8, name: 'Ben Ode', bio: '', color: '#0891b2', services: [ 1, 2 ] },
	],
	tz: 'Europe/London',
	phone_field: 'optional',
	notes_field: true,
	policy: 'Free cancellation up to 24 hours before.',
	ai: true,
	payment_label: { later: 'Pay at the appointment' },
	user: null,
};

describe( 'storefront helpers', () => {
	it( 'formats strings and urls', () => {
		expect( t( strings, 'stepOf', 2, 5 ) ).toBe( 'Step 2 of 5' );
		expect( t( strings, 'minutes', 45 ) ).toBe( '45 min' );
		expect( url( '/index.php?rest_route=/dev3bk/v1/', 'public/availability', { service: 1, tz: 'Europe/London', empty: '' } ) ).toBe( '/index.php?rest_route=/dev3bk/v1/public/availability&service=1&tz=Europe%2FLondon' );
		expect( addDays( '2026-10-31', 1 ) ).toBe( '2026-11-01' );
		expect( todayIn( 'UTC' ) ).toMatch( /^\d{4}-\d{2}-\d{2}$/ );
	} );
} );

describe( 'booking widget', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'walks service → staff → time → details and books with pay later', async () => {
		const calls = [];
		const today = todayIn( Intl.DateTimeFormat().resolvedOptions().timeZone );
		const day = addDays( today, 1 );
		const win = {
			document,
			location: { search: '', assign: jest.fn() },
			URLSearchParams: window.URLSearchParams,
			scrollBy: () => {},
			fetch: fakeFetch(
				{
					'GET public/catalog': catalog,
					'GET public/availability': { tz: 'Europe/London', days: { [ day ]: [ { start: `${ day }T17:00:00Z`, label: '17:00', hour: 17, staff: [ 7 ], seats_left: 1 } ] }, next: '', last: addDays( today, 60 ) },
					'POST public/nl': { source: 'rules', summary: 'Tomorrow after 17:00', relaxed: false, slots: [ { date: day, start: `${ day }T17:00:00Z`, label: '17:00', staff: [ 7 ] } ] },
					'POST public/bookings': ( body ) => ( { booking: { id: 1, ref: 'ABCDEFGH', status: 'confirmed', service: 'Haircut', staff: 'Anna Berg', day_label: 'Monday', date_label: day, time_label: '17:00 – 17:45', google_url: 'https://calendar.google.com/x', ics_url: '/ics', manage_url: '/manage', key: 'k', echo: body }, next: { type: 'done', url: '' } } ),
				},
				calls
			),
			dev3bkFront: { restUrl: '/wp-json/dev3bk/v1/', nonce: '', strings, locale: 'en-GB', loginUrl: '/login' },
		};
		document.body.innerHTML = '<div class="dev3bk-widget" data-dev3bk="{}"></div>';
		boot( win );
		await tick();
		const root = document.querySelector( '.dev3bk-widget' );
		expect( root.querySelector( '.dev3bk-title' ).textContent ).toBe( 'chooseService' );
		expect( root.querySelectorAll( '.dev3bk-tile' ) ).toHaveLength( 2 );
		root.querySelector( '.dev3bk-tile' ).click();
		expect( root.querySelector( '.dev3bk-title' ).textContent ).toBe( 'chooseStaff' );
		[ ...root.querySelectorAll( '.dev3bk-tile' ) ].find( ( x ) => /Anna/.test( x.textContent ) ).click();
		await tick();
		expect( root.querySelector( '.dev3bk-title' ).textContent ).toBe( 'pickTime' );
		const continueBtn = () => root.querySelector( '.dev3bk-btn--primary' );
		expect( continueBtn().disabled ).toBe( true );
		// Natural-language search returns real slots from the server.
		root.querySelector( '.dev3bk-nl input' ).value = 'tomorrow after 5';
		root.querySelector( '.dev3bk-nl__form' ).dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
		await tick();
		expect( calls.find( ( c ) => c.path === 'public/nl' ).body ).toMatchObject( { text: 'tomorrow after 5', service: 1, staff: 7 } );
		root.querySelector( '.dev3bk-nl__res .dev3bk-slot' ).click();
		await tick();
		expect( continueBtn().disabled ).toBe( false );
		continueBtn().click();
		expect( root.querySelector( '.dev3bk-title' ).textContent ).toBe( 'yourDetails' );
		continueBtn().click(); // Empty form → validation.
		expect( root.querySelector( 'input[name=name]' ).getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		const type = ( name, v ) => {
			const el = root.querySelector( `input[name=${ name }]` );
			el.value = v;
			el.dispatchEvent( new window.Event( 'input' ) );
		};
		type( 'name', 'Jordan Ellis' );
		type( 'email', 'jordan@example.com' );
		continueBtn().click();
		await tick();
		const post = calls.find( ( c ) => c.path === 'public/bookings' );
		expect( post.body ).toMatchObject( { service: 1, staff: 7, start: `${ day }T17:00:00Z`, name: 'Jordan Ellis', email: 'jordan@example.com', payment: 'later', ai: true } );
		expect( root.querySelector( '.dev3bk-done__icon' ) ).not.toBeNull();
		expect( root.textContent ).toContain( 'We sent the details to jordan@example.com.' );
		expect( root.querySelector( 'a[href="https://calendar.google.com/x"]' ) ).not.toBeNull();
	} );

	it( 'opens a booking from the emailed link and cancels it', async () => {
		const calls = [];
		const booking = { ref: 'ABCDEFGH', key: 'k', status: 'confirmed', service: 'Haircut', service_id: 1, staff_id: 7, staff: 'Anna', day_label: 'Monday', date_label: '2 Nov', time_label: '17:00 – 17:45', tz: 'Europe/London', location: '', amount_label: '£45.00', payment_status: 'unpaid', can_cancel: true, can_reschedule: true, upcoming: true, policy: 'Policy', google_url: '#', ics_url: '#', currency: 'GBP', refund_amount: 0 };
		const win = {
			document,
			location: { search: '?dev3bk_ref=ABCDEFGH&dev3bk_key=k', assign: jest.fn() },
			URLSearchParams: window.URLSearchParams,
			fetch: fakeFetch(
				{
					'GET public/catalog': catalog,
					'GET public/bookings/ABCDEFGH': booking,
					'POST public/bookings/ABCDEFGH/cancel': { ...booking, status: 'cancelled', can_cancel: false, can_reschedule: false, upcoming: false },
				},
				calls
			),
			dev3bkFront: { restUrl: '/wp-json/dev3bk/v1/', nonce: '', strings, locale: 'en-GB', loginUrl: '/login' },
		};
		document.body.innerHTML = '<div class="dev3bk-account"></div>';
		boot( win );
		await tick( 6 );
		const root = document.querySelector( '.dev3bk-account' );
		expect( root.querySelector( '.dev3bk-title' ).textContent ).toBe( 'Haircut' );
		expect( calls.find( ( c ) => c.path === 'public/bookings/ABCDEFGH' ).url ).toContain( 'key=k' );
		[ ...root.querySelectorAll( '.dev3bk-btn' ) ].find( ( b ) => /cancel/.test( b.textContent ) ).click();
		root.querySelector( 'textarea' ).value = 'Travel';
		root.querySelector( 'textarea' ).dispatchEvent( new window.Event( 'input' ) );
		root.querySelector( '.dev3bk-panel .dev3bk-btn--danger' ).click();
		await tick();
		expect( calls.find( ( c ) => c.path.endsWith( '/cancel' ) ).body ).toEqual( { key: 'k', reason: 'Travel' } );
		expect( root.textContent ).toContain( 'cancelledMsg' );
	} );
} );
