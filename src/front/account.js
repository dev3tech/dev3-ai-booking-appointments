import { fill, h, icon, id } from './dom';
import { createPicker, t } from './picker';
import { fmtDay, todayIn } from './time';

/**
 * Customer area: my bookings (logged in) or one booking via the emailed link; reschedule + cancel.
 *
 * @param {HTMLElement} root    Mount (.dev3bk-account).
 * @param {Object}      cfg     Boot config.
 * @param {Function}    request API client.
 * @param {Window}      win     Window.
 */
export function mountAccount( root, cfg, request, win ) {
	const s = cfg.strings;
	const params = new win.URLSearchParams( win.location.search );
	const link = { ref: params.get( 'dev3bk_ref' ) || '', key: params.get( 'dev3bk_key' ) || '' };
	const flags = { paid: params.get( 'dev3bk_paid' ) === '1', cancelled: params.get( 'dev3bk_cancelled' ) === '1' };
	const app = h( 'div', { class: 'dev3bk-app' } );
	const live = h( 'p', { class: 'dev3bk-sr', 'aria-live': 'polite' } );
	const state = { list: null, tab: 'upcoming', current: null, mode: 'view', notice: '', error: '', picked: null, busy: false, reason: '' };
	let catalog = null;

	const statusLabel = ( b ) => ( s.status && s.status[ b.status ] ) || b.status_label;
	const keyOf = ( b ) => b.key || link.key;

	function header( text ) {
		return h( 'h3', { class: 'dev3bk-title', tabindex: '-1', text } );
	}

	function notice() {
		const out = [];
		if ( state.notice ) {
			out.push( h( 'p', { class: 'dev3bk-ok', role: 'status' }, icon( 'check' ), h( 'span', { text: state.notice } ) ) );
		}
		if ( state.error ) {
			out.push( h( 'p', { class: 'dev3bk-alert', role: 'alert', text: state.error } ) );
		}
		return out;
	}

	function row( b ) {
		return h(
			'li',
			{},
			h(
				'button',
				{
					type: 'button',
					class: 'dev3bk-item',
					onClick: () => {
						state.current = b;
						state.mode = 'view';
						state.notice = '';
						render( true );
					},
				},
				h( 'span', { class: 'dev3bk-item__date' }, h( 'b', { text: b.date_label } ), h( 'span', { text: b.time_label } ) ),
				h( 'span', { class: 'dev3bk-item__main' }, h( 'span', { class: 'dev3bk-item__name', text: b.service } ), h( 'span', { class: 'dev3bk-item__meta', text: b.staff } ) ),
				h( 'span', { class: `dev3bk-pill dev3bk-pill--${ b.status }`, text: statusLabel( b ) } )
			)
		);
	}

	function listView() {
		const all = state.list || [];
		const upcoming = all.filter( ( b ) => b.upcoming ).reverse();
		const past = all.filter( ( b ) => ! b.upcoming );
		const shown = state.tab === 'upcoming' ? upcoming : past;
		const tabs = h(
			'div',
			{ class: 'dev3bk-cats', role: 'group', 'aria-label': s.myBookings },
			[
				[ 'upcoming', s.upcoming, upcoming.length ],
				[ 'past', s.past, past.length ],
			].map( ( [ k, label, n ] ) =>
				h( 'button', {
					type: 'button',
					class: `dev3bk-pill${ state.tab === k ? ' is-on' : '' }`,
					'aria-pressed': state.tab === k ? 'true' : 'false',
					text: `${ label } (${ n })`,
					onClick: () => {
						state.tab = k;
						render();
					},
				} )
			)
		);
		return [
			header( s.myBookings ),
			...notice(),
			tabs,
			shown.length ? h( 'ul', { class: 'dev3bk-items' }, shown.map( row ) ) : h( 'p', { class: 'dev3bk-hint', text: all.length ? s.noUpcoming : s.noBookings } ),
		];
	}

	function detailView() {
		const b = state.current;
		const out = [];
		if ( state.list ) {
			out.push(
				h(
					'button',
					{
						type: 'button',
						class: 'dev3bk-btn dev3bk-btn--ghost dev3bk-back',
						onClick: () => {
							state.current = null;
							state.notice = '';
							state.error = '';
							render( true );
						},
					},
					icon( 'left' ),
					h( 'span', { text: s.allBookings } )
				)
			);
		}
		out.push( header( b.service ), ...notice() );
		out.push(
			h(
				'div',
				{ class: 'dev3bk-detail' },
				h( 'span', { class: `dev3bk-pill dev3bk-pill--${ b.status }`, text: statusLabel( b ) } ),
				h(
					'dl',
					{ class: 'dev3bk-sum' },
					[
						[ s.when, `${ b.day_label }, ${ b.date_label } · ${ b.time_label } (${ b.tz.replace( /_/g, ' ' ) })` ],
						[ s.with, b.staff ],
						b.location ? [ s.where, b.location ] : null,
						[ s.price, b.amount_label + ( b.payment_status === 'paid' ? ` · ${ s.paid }` : b.payment_status === 'refunded' ? ` · ${ s.refunded }` : b.payment_status === 'unpaid' && b.status !== 'pending_payment' ? ` · ${ s.unpaid }` : '' ) ],
						[ s.reference, b.ref ],
					]
						.filter( Boolean )
						.map( ( [ k, v ] ) => h( 'div', { class: 'dev3bk-sum__row' }, h( 'dt', { text: k || ' ' } ), h( 'dd', { text: v } ) ) )
				)
			)
		);
		if ( state.mode === 'reschedule' ) {
			out.push( rescheduleView( b ) );
		} else if ( state.mode === 'cancel' ) {
			out.push( cancelView( b ) );
		} else {
			const actions = [];
			if ( b.can_reschedule ) {
				actions.push( h( 'button', { type: 'button', class: 'dev3bk-btn dev3bk-btn--primary', onClick: () => setMode( 'reschedule' ) }, icon( 'clock' ), h( 'span', { text: s.reschedule } ) ) );
			}
			if ( b.can_cancel ) {
				actions.push( h( 'button', { type: 'button', class: 'dev3bk-btn dev3bk-btn--danger', onClick: () => setMode( 'cancel' ) }, icon( 'x' ), h( 'span', { text: s.cancel } ) ) );
			}
			if ( b.upcoming && b.status !== 'pending_payment' ) {
				actions.push( h( 'a', { class: 'dev3bk-btn dev3bk-btn--soft', href: b.google_url, target: '_blank', rel: 'noopener' }, icon( 'cal' ), h( 'span', { text: s.addGoogle } ) ) );
				actions.push( h( 'a', { class: 'dev3bk-btn dev3bk-btn--soft', href: b.ics_url, download: `booking-${ b.ref }.ics` }, icon( 'cal' ), h( 'span', { text: s.downloadIcs } ) ) );
			}
			out.push( h( 'div', { class: 'dev3bk-actions' }, actions ) );
			if ( b.upcoming && ! b.can_cancel && ! b.can_reschedule ) {
				out.push( h( 'p', { class: 'dev3bk-hint', text: s.cannotChange } ) );
			}
		}
		if ( b.policy ) {
			out.push( h( 'p', { class: 'dev3bk-policy' }, h( 'strong', { text: `${ s.policy }: ` } ), b.policy ) );
		}
		return out;
	}

	function setMode( mode ) {
		state.mode = mode;
		state.picked = null;
		state.error = '';
		state.notice = '';
		render( true );
	}

	function rescheduleView( b ) {
		const wrap = h( 'div', { class: 'dev3bk-panel' } );
		const confirm = h( 'button', { type: 'button', class: 'dev3bk-btn dev3bk-btn--primary', disabled: true }, h( 'span', { text: s.pickNewTime } ) );
		const doMove = () => {
			state.busy = true;
			confirm.disabled = true;
			request( `public/bookings/${ b.ref }/reschedule`, { method: 'POST', data: { key: keyOf( b ), start: state.picked.start } } )
				.then( ( nb ) => done( nb, s.rescheduledMsg ) )
				.catch( fail );
		};
		confirm.addEventListener( 'click', doMove );
		const picker = createPicker( {
			request,
			strings: s,
			locale: cfg.locale,
			service: b.service_id,
			staff: b.staff_id,
			tz: b.tz,
			today: todayIn( b.tz ),
			nl: catalog ? catalog.ai : false,
			selected: null,
			onPick: ( slot ) => {
				state.picked = slot;
				confirm.disabled = false;
				fill( confirm, h( 'span', { text: t( s, 'confirmMove', `${ fmtDay( slot.date, { weekday: 'short', day: 'numeric', month: 'short' }, cfg.locale ) } ${ slot.label }` ) } ) );
			},
			onTz: () => {},
		} );
		fill( wrap, h( 'p', { class: 'dev3bk-label', text: s.pickNewTime } ), picker.el, h( 'div', { class: 'dev3bk-actions' }, h( 'button', { type: 'button', class: 'dev3bk-btn dev3bk-btn--ghost', text: s.close, onClick: () => setMode( 'view' ) } ), confirm ) );
		return wrap;
	}

	function cancelView( b ) {
		const rid = id( 'dev3bk-reason' );
		const info = b.payment_status === 'paid' ? ( b.refund_amount > 0 ? t( s, 'refundInfo', new Intl.NumberFormat( cfg.locale || undefined, { style: 'currency', currency: b.currency } ).format( b.refund_amount ) ) : s.noRefund ) : '';
		const button = h(
			'button',
			{
				type: 'button',
				class: 'dev3bk-btn dev3bk-btn--danger',
				onClick: ( e ) => {
					e.currentTarget.disabled = true;
					request( `public/bookings/${ b.ref }/cancel`, { method: 'POST', data: { key: keyOf( b ), reason: state.reason } } )
						.then( ( nb ) => done( nb, s.cancelledMsg ) )
						.catch( fail );
				},
			},
			h( 'span', { text: s.cancel } )
		);
		return h(
			'div',
			{ class: 'dev3bk-panel' },
			h( 'p', { class: 'dev3bk-label', text: s.cancelTitle } ),
			info ? h( 'p', { class: 'dev3bk-hint', text: info } ) : null,
			h( 'div', { class: 'dev3bk-field' }, h( 'label', { for: rid, class: 'dev3bk-label', text: s.cancelReason } ), h( 'textarea', { id: rid, class: 'dev3bk-input', rows: '2', maxlength: '255', onInput: ( e ) => ( state.reason = e.target.value ) } ) ),
			h( 'div', { class: 'dev3bk-actions' }, h( 'button', { type: 'button', class: 'dev3bk-btn dev3bk-btn--ghost', text: s.keep, onClick: () => setMode( 'view' ) } ), button )
		);
	}

	function done( nb, message ) {
		state.current = { ...nb, key: keyOf( nb ) };
		state.mode = 'view';
		state.notice = message;
		state.error = '';
		state.busy = false;
		if ( state.list ) {
			state.list = state.list.map( ( x ) => ( x.ref === nb.ref ? state.current : x ) );
		}
		live.textContent = message;
		render( true );
	}

	function fail( err ) {
		state.busy = false;
		state.error = err.message;
		render();
	}

	function guestView() {
		return [
			header( s.myBookings ),
			h( 'p', { class: 'dev3bk-hint', text: s.guestText } ),
			h( 'div', { class: 'dev3bk-actions' }, h( 'a', { class: 'dev3bk-btn dev3bk-btn--primary', href: cfg.loginUrl, text: s.login } ) ),
		];
	}

	function render( focus ) {
		let parts;
		if ( state.current ) {
			parts = detailView();
		} else if ( state.list ) {
			parts = listView();
		} else {
			parts = guestView();
		}
		fill( app, ...parts );
		app.appendChild( live );
		if ( focus ) {
			const tt = app.querySelector( '.dev3bk-title' );
			if ( tt ) {
				tt.focus( { preventScroll: false } );
			}
		}
	}

	const start = () => {
		fill( root, app );
		root.removeAttribute( 'aria-busy' );
		if ( link.ref && link.key ) {
			const load = flags.paid ? request( `public/bookings/${ link.ref }/payment`, { method: 'POST', data: { key: link.key } } ) : request( `public/bookings/${ link.ref }`, { query: { key: link.key } } );
			return load
				.then( ( b ) => {
					state.current = b;
					if ( flags.paid ) {
						state.notice = b.payment_status === 'paid' ? s.paymentReceived : '';
						state.error = b.payment_status === 'paid' ? '' : s.paymentPending;
					} else if ( flags.cancelled && b.status === 'pending_payment' ) {
						state.error = s.paymentCancelled;
					}
					render();
				} )
				.catch( ( err ) => {
					state.error = err.message;
					render();
				} );
		}
		if ( cfg.loggedIn ) {
			return request( 'public/my-bookings' )
				.then( ( r ) => {
					state.list = r.bookings;
					render();
				} )
				.catch( ( err ) => {
					state.error = err.message;
					render();
				} );
		}
		render();
		return null;
	};

	request( 'public/catalog' )
		.then( ( c ) => {
			catalog = c;
		} )
		.catch( () => {} )
		.finally( start );
}
