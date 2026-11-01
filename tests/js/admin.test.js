import apiFetch from '@wordpress/api-fetch';
import { act } from 'react';
import { layoutLanes, rangeFor } from '../../src/admin/Calendar';
import { addDays, addMonths, endOfMonth, minutesOf, qs, startOfWeek } from '../../src/admin/util';
import { WeekEditor } from '../../src/admin/ScheduleParts';
import NewBooking from '../../src/admin/NewBooking';
import Notifications from '../../src/admin/Notifications';
import { ToastProvider } from '../../src/core3/ui';
import { change, click, render } from './render';

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

const route = ( map ) =>
	apiFetch.mockImplementation( ( { url, method = 'GET', data } ) => {
		const path = url.replace( '/wp-json/dev3bk/v1/', '' );
		for ( const key of Object.keys( map ) ) {
			const [ m, p ] = key.split( ' ' );
			if ( m === method && path.startsWith( p ) ) {
				return Promise.resolve( typeof map[ key ] === 'function' ? map[ key ]( data, path ) : map[ key ] );
			}
		}
		return new Promise( () => {} );
	} );

describe( 'date helpers', () => {
	it( 'does calendar math on local days across DST and month ends', () => {
		expect( addDays( '2026-10-24', 2 ) ).toBe( '2026-10-26' );
		expect( addDays( '2026-03-28', 2 ) ).toBe( '2026-03-30' );
		expect( startOfWeek( '2026-10-25', 1 ) ).toBe( '2026-10-19' );
		expect( startOfWeek( '2026-10-25', 0 ) ).toBe( '2026-10-25' );
		expect( endOfMonth( '2026-02-10' ) ).toBe( '2026-02-28' );
		expect( addMonths( '2026-12-15', 1 ) ).toBe( '2027-01-01' );
		expect( minutesOf( '2026-10-25T13:45' ) ).toBe( 825 );
		expect( qs( { a: 1, b: '', c: 0, d: 'x y' } ) ).toBe( '?a=1&d=x%20y' );
	} );

	it( 'builds day, week and month ranges', () => {
		expect( rangeFor( 'day', '2026-10-25' ).days ).toEqual( [ '2026-10-25' ] );
		const week = rangeFor( 'week', '2026-10-21' );
		expect( week.from ).toBe( '2026-10-19' );
		expect( week.days ).toHaveLength( 7 );
		const month = rangeFor( 'month', '2026-10-10' );
		expect( month.days.length % 7 ).toBe( 0 );
		expect( month.days ).toContain( '2026-10-31' );
		expect( month.from <= '2026-10-01' ).toBe( true );
	} );

	it( 'puts overlapping events side by side', () => {
		const out = layoutLanes( [
			{ s: 600, e: 660, b: { id: 1 } },
			{ s: 630, e: 690, b: { id: 2 } },
			{ s: 700, e: 760, b: { id: 3 } },
		] );
		const by = Object.fromEntries( out.map( ( e ) => [ e.b.id, e ] ) );
		expect( by[ 1 ].lanes ).toBe( 2 );
		expect( by[ 2 ].lane ).toBe( 1 );
		expect( by[ 3 ].lanes ).toBe( 1 );
	} );
} );

describe( 'week editor', () => {
	it( 'opens and closes days and adds ranges', async () => {
		let week = { mon: [ [ '09:00', '17:00' ] ] };
		const onChange = jest.fn( ( w ) => ( week = w ) );
		const view = await render( <WeekEditor value={ week } onChange={ onChange } /> );
		const tuesday = view.$$( '[role=switch]' )[ 1 ];
		await click( tuesday );
		expect( week.tue ).toEqual( [ [ '09:00', '17:00' ] ] );
		await click( view.getByText( 'Copy Monday to Tuesday–Friday' ) );
		expect( week.fri ).toEqual( [ [ '09:00', '17:00' ] ] );
	} );
} );

describe( 'new booking with DEV3 AI', () => {
	it( 'finds real slots from a description and books the picked one', async () => {
		const posted = [];
		route( {
			'POST ai/nl': {
				source: 'ai',
				summary: 'Tuesday after 17:00 with Anna',
				relaxed: false,
				needs: '',
				constraints: { service_id: 3 },
				slots: [ { date: '2026-10-27', day_label: 'Tue 27 Oct', start: '2026-10-27T17:00:00Z', label: '17:00', staff: [ 7 ] } ],
			},
			'GET availability': { days: { '2026-10-27': [ { start: '2026-10-27T17:00:00Z', label: '17:00', staff: [ 7 ] } ] } },
			'POST bookings': ( data ) => {
				posted.push( data );
				return { id: 99, ref: 'ABCDEFGH' };
			},
			'GET customers': { rows: [ { id: 5, name: 'Jamie Rivera', email: 'jamie@example.com' } ], total: 1 },
		} );
		const catalog = { services: [ { id: 3, name: 'Haircut', status: 'active', staff: [ 7 ] } ], staff: [ { id: 7, name: 'Anna', services: [ 3 ] } ] };
		const onCreated = jest.fn();
		const view = await render(
			<ToastProvider>
				<NewBooking preset={ { ai: true } } catalog={ catalog } onClose={ () => {} } onCreated={ onCreated } onUsage={ () => {} } />
			</ToastProvider>
		);
		await change( view.$( '.dev3bk-findslot input' ), 'Tuesday after 5 with Anna' );
		await click( view.$( '.dev3bk-findslot button[type=submit]' ) );
		await view.flush();
		expect( view.queryByText( /Looking for: Tuesday after 17:00 with Anna/ ) ).not.toBeNull();
		await click( view.$( '.dev3bk-slotpick__slot' ) );
		await view.flush();
		const search = view.$$( 'input' ).find( ( i ) => i.closest( '.dev3bk-ui-field' ) && /Search by name/.test( i.closest( '.dev3bk-ui-field' ).textContent ) );
		await change( search, 'jam' );
		await act( async () => new Promise( ( r ) => setTimeout( r, 300 ) ) );
		await view.flush();
		await click( view.getByText( 'Jamie Rivera' ).closest( 'button' ) );
		await click( view.getByText( 'Create booking' ).closest( 'button' ) );
		await view.flush();
		expect( posted[ 0 ] ).toMatchObject( { service: 3, staff: 7, start: '2026-10-27T17:00:00Z', customer_id: 5, ai: true } );
		expect( onCreated ).toHaveBeenCalled();
	} );
} );

describe( 'notifications', () => {
	it( 'shows an AI draft for review and saves it only on approval', async () => {
		const saves = [];
		const tpl = { id: 'reminder_1', label: 'Reminder 1', audience: 'customer', group: 'reminder', required: [ 'manage_url' ], subject: 'Reminder: {service}', body: 'Hi {customer_name} {manage_url}', enabled: true, customized: false, source: 'default' };
		route( {
			'GET templates': { templates: [ tpl ], placeholders: [ 'customer_name', 'service', 'manage_url' ], sample: {} },
			'POST templates/reminder_1/ai': { draft: { subject: 'See you soon, {customer_name}', body: 'Hello! Move it here: {manage_url}' }, missing: [], model: 'gemini-2.5-flash', tokens: 300, cached: false },
			'PUT templates/reminder_1': ( data ) => {
				saves.push( data );
				return { ...tpl, ...data, customized: true, source: data.source };
			},
		} );
		const settings = { reminder1_hours: 24, reminder2_hours: 2, followup_hours: 0, admin_notify: true, staff_notify: true };
		const view = await render(
			<ToastProvider>
				<Notifications settings={ settings } save={ () => Promise.resolve( settings ) } onUsage={ () => {} } />
			</ToastProvider>
		);
		await view.flush();
		await click( view.$$( '.dev3bk-tpls__row button' ).find( ( b ) => /Edit/.test( b.textContent ) ) );
		await click( view.getByText( 'Generate with DEV3 AI' ).closest( 'button' ) );
		await view.flush();
		expect( view.queryByText( 'Not saved yet' ) ).not.toBeNull();
		expect( saves ).toHaveLength( 0 );
		await click( view.getByText( 'Approve & save' ).closest( 'button' ) );
		await view.flush();
		expect( saves[ 0 ] ).toMatchObject( { subject: 'See you soon, {customer_name}', source: 'ai' } );
	} );
} );
