import { fill, h, icon, id } from './dom';
import { createPicker, t } from './picker';
import { browserTz, fmtDay, todayIn } from './time';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Initials for avatars.
 *
 * @param {string} name Name.
 * @return {string} Initials.
 */
const initials = ( name ) =>
	String( name || '' )
		.trim()
		.split( /\s+/ )
		.slice( 0, 2 )
		.map( ( p ) => p.charAt( 0 ).toUpperCase() )
		.join( '' );

/**
 * Mounts the multi-step booking widget.
 *
 * @param {HTMLElement} root    Mount (.dev3bk-widget).
 * @param {Object}      cfg     Boot config.
 * @param {Function}    request API client.
 * @param {Window}      win     Window.
 */
export function mountWidget( root, cfg, request, win ) {
	const s = cfg.strings;
	let opts = {};
	try {
		opts = JSON.parse( root.getAttribute( 'data-dev3bk' ) || '{}' );
	} catch ( e ) {
		opts = {};
	}
	const state = {
		catalog: null,
		step: 'service',
		service: null,
		staff: null, // { id } or 0 (any)
		slot: null,
		details: { name: '', email: '', phone: '', notes: '', website: '' },
		payment: '',
		booking: null,
		busy: false,
		error: '',
		tz: '',
		category: '',
	};
	const app = h( 'div', { class: 'dev3bk-app' } );
	const head = h( 'div', { class: 'dev3bk-head' } );
	const body = h( 'div', { class: 'dev3bk-body' } );
	const bar = h( 'div', { class: 'dev3bk-bar' } );
	const live = h( 'p', { class: 'dev3bk-sr', 'aria-live': 'polite' } );
	app.append( head, body, bar, live );

	const services = () => ( state.catalog ? state.catalog.services.filter( ( x ) => ! opts.category || x.category === opts.category ) : [] );
	const staffFor = ( svc ) => ( state.catalog ? state.catalog.staff.filter( ( p ) => svc && svc.staff.includes( p.id ) ) : [] );
	const payments = () => ( state.service ? state.service.payments : [ 'none' ] );
	const needsPayStep = () => {
		if ( ! state.service ) {
			return services().some( ( x ) => x.payments.length > 1 || ( x.payments[ 0 ] !== 'none' && x.payments[ 0 ] !== 'later' ) );
		}
		const p = payments();
		return p.length > 1 || ( p[ 0 ] !== 'none' && p[ 0 ] !== 'later' );
	};

	function steps() {
		const list = [];
		if ( ! opts.service && services().length > 1 ) {
			list.push( 'service' );
		}
		if ( ! opts.staff && state.service && staffFor( state.service ).length > 1 ) {
			list.push( 'staff' );
		} else if ( ! opts.staff && ! state.service ) {
			list.push( 'staff' );
		}
		list.push( 'time', 'details' );
		if ( needsPayStep() ) {
			list.push( 'pay' );
		}
		return list;
	}

	function go( step, focus = true ) {
		state.step = step;
		state.error = '';
		render();
		if ( focus ) {
			const title = head.querySelector( '.dev3bk-title' );
			if ( title ) {
				title.focus( { preventScroll: true } );
				const top = root.getBoundingClientRect().top;
				if ( top < 0 && win.scrollBy ) {
					win.scrollBy( { top: top - 16, behavior: 'smooth' } );
				}
			}
		}
	}

	function next() {
		const list = steps();
		const i = list.indexOf( state.step );
		go( list[ i + 1 ] || 'details' );
	}
	function back() {
		const list = steps();
		const i = list.indexOf( state.step );
		if ( i > 0 ) {
			go( list[ i - 1 ] );
		}
	}

	function title( text ) {
		return h( 'h3', { class: 'dev3bk-title', tabindex: '-1', text } );
	}

	function progress() {
		const list = steps();
		const i = list.indexOf( state.step );
		if ( i < 0 ) {
			return null;
		}
		return h(
			'div',
			{ class: 'dev3bk-progress' },
			h( 'span', { class: 'dev3bk-progress__text', text: t( s, 'stepOf', i + 1, list.length ) } ),
			h( 'span', { class: 'dev3bk-progress__bar', 'aria-hidden': 'true' }, h( 'i', { style: { width: `${ ( ( i + 1 ) / list.length ) * 100 }%` } } ) )
		);
	}

	function summary() {
		const rows = [];
		if ( state.service ) {
			rows.push( [ s.service, `${ state.service.name } · ${ t( s, 'minutes', state.service.duration ) }` ] );
		}
		const person = state.slot && state.staff === 0 && state.slot.staffName ? state.slot.staffName : state.staff && state.staff.name;
		if ( person ) {
			rows.push( [ s.with, person ] );
		}
		if ( state.slot ) {
			rows.push( [ s.when, `${ fmtDay( state.slot.date, { weekday: 'long', day: 'numeric', month: 'long' }, cfg.locale ) } · ${ state.slot.label }` ] );
		}
		if ( state.service ) {
			rows.push( [ s.price, state.service.price_label + ( state.service.deposit ? ` · ${ t( s, 'dueNow', state.service.deposit ) }` : '' ) ] );
		}
		return h(
			'dl',
			{ class: 'dev3bk-sum' },
			rows.map( ( [ k, v ] ) => h( 'div', { class: 'dev3bk-sum__row' }, h( 'dt', { text: k } ), h( 'dd', { text: v } ) ) )
		);
	}

	function stepService() {
		const list = services();
		if ( ! list.length ) {
			return [ h( 'p', { class: 'dev3bk-hint', text: s.noServices } ) ];
		}
		const cats = [ ...new Set( list.map( ( x ) => x.category ).filter( Boolean ) ) ];
		const out = [];
		if ( cats.length > 1 ) {
			out.push(
				h(
					'div',
					{ class: 'dev3bk-cats', role: 'group', 'aria-label': s.chooseService },
					[ '', ...cats ].map( ( c ) =>
						h( 'button', {
							type: 'button',
							class: `dev3bk-pill${ state.category === c ? ' is-on' : '' }`,
							'aria-pressed': state.category === c ? 'true' : 'false',
							text: c || s.allCategories,
							onClick: () => {
								state.category = c;
								render();
							},
						} )
					)
				)
			);
		}
		out.push(
			h(
				'div',
				{ class: 'dev3bk-tiles' },
				list
					.filter( ( x ) => ! state.category || x.category === state.category )
					.map( ( svc ) =>
						h(
							'button',
							{
								type: 'button',
								class: `dev3bk-tile${ state.service && state.service.id === svc.id ? ' is-on' : '' }`,
								style: { '--dev3bk-tile': svc.color },
								onClick: () => {
									state.service = svc;
									state.slot = null;
									const people = staffFor( svc );
									if ( opts.staff ) {
										state.staff = people.find( ( p ) => p.id === opts.staff ) || 0;
									} else if ( people.length === 1 ) {
										state.staff = people[ 0 ];
									} else if ( state.staff && ! svc.staff.includes( state.staff.id ) ) {
										state.staff = null;
									}
									next();
								},
							},
							h( 'span', { class: 'dev3bk-tile__name', text: svc.name } ),
							svc.description ? h( 'span', { class: 'dev3bk-tile__desc', text: svc.description } ) : null,
							h(
								'span',
								{ class: 'dev3bk-tile__meta' },
								h( 'span', {}, icon( 'clock' ), t( s, 'minutes', svc.duration ) ),
								h( 'strong', { text: svc.price_label } )
							)
						)
					)
			)
		);
		return out;
	}

	function stepStaff() {
		const people = staffFor( state.service );
		const tile = ( p ) => {
			const on = p === 0 ? state.staff === 0 : state.staff && state.staff.id === p.id;
			return h(
				'button',
				{
					type: 'button',
					class: `dev3bk-tile dev3bk-tile--person${ on ? ' is-on' : '' }`,
					onClick: () => {
						state.staff = p;
						state.slot = null;
						next();
					},
				},
				h( 'span', { class: 'dev3bk-avatar', style: { background: p ? p.color : 'var(--dev3bk-accent)' }, 'aria-hidden': 'true' }, p ? h( 'span', { text: initials( p.name ) } ) : icon( 'user' ) ),
				h( 'span', { class: 'dev3bk-tile__body' }, h( 'span', { class: 'dev3bk-tile__name', text: p ? p.name : s.anyStaff } ), h( 'span', { class: 'dev3bk-tile__desc', text: p ? p.bio || '' : s.anyStaffHint } ) )
			);
		};
		return [ h( 'div', { class: 'dev3bk-tiles dev3bk-tiles--people' }, people.length > 1 ? tile( 0 ) : null, people.map( tile ) ) ];
	}

	function stepTime() {
		const picker = createPicker( {
			request,
			strings: s,
			locale: cfg.locale,
			service: state.service.id,
			staff: state.staff ? state.staff.id : 0,
			tz: state.tz,
			today: todayIn( state.tz ),
			nl: state.catalog.ai,
			group: state.service.capacity > 1,
			selected: state.slot,
			onPick: ( slot ) => {
				const person = slot.staff && slot.staff.length === 1 && state.staff === 0 ? state.catalog.staff.find( ( p ) => p.id === slot.staff[ 0 ] ) : null;
				state.slot = { ...slot, staffName: person ? person.name : '', pinStaff: slot.ai && person ? person.id : 0 };
				live.textContent = `${ s.selected }: ${ fmtDay( slot.date, { weekday: 'long', day: 'numeric', month: 'long' }, cfg.locale ) } ${ slot.label }`;
				renderBar();
			},
			onTz: ( tz ) => {
				state.tz = tz;
				state.slot = null;
				renderBar();
			},
		} );
		return [ picker.el ];
	}

	function field( key, label, type, opt = {} ) {
		const fid = id( 'dev3bk-f' );
		const err = id( 'dev3bk-e' );
		const input = h( opt.textarea ? 'textarea' : 'input', {
			id: fid,
			class: 'dev3bk-input',
			type: opt.textarea ? null : type,
			name: key,
			value: state.details[ key ],
			autocomplete: opt.autocomplete || 'off',
			inputmode: opt.inputmode || null,
			required: opt.required || null,
			rows: opt.textarea ? '3' : null,
			maxlength: opt.max || null,
			'aria-describedby': err,
			onInput: ( e ) => {
				state.details[ key ] = e.target.value;
				e.target.removeAttribute( 'aria-invalid' );
				const m = root.querySelector( `#${ err }` );
				if ( m ) {
					m.textContent = '';
				}
			},
		} );
		return h(
			'div',
			{ class: 'dev3bk-field' },
			h( 'label', { for: fid, class: 'dev3bk-label' }, label, opt.required ? null : h( 'span', { class: 'dev3bk-opt', text: ` ${ s.optional }` } ) ),
			input,
			h( 'p', { class: 'dev3bk-ferr', id: err, role: 'alert' } )
		);
	}

	function stepDetails() {
		const phone = state.catalog.phone_field;
		const form = h(
			'form',
			{
				class: 'dev3bk-form',
				novalidate: true,
				onSubmit: ( e ) => {
					e.preventDefault();
					primaryAction();
				},
			},
			field( 'name', s.name, 'text', { required: true, autocomplete: 'name', max: '190' } ),
			field( 'email', s.email, 'email', { required: true, autocomplete: 'email', inputmode: 'email', max: '190' } ),
			phone !== 'hidden' ? field( 'phone', s.phone, 'tel', { required: phone === 'required', autocomplete: 'tel', inputmode: 'tel', max: '40' } ) : null,
			state.catalog.notes_field ? field( 'notes', s.notes, 'text', { textarea: true, max: '1000' } ) : null,
			h(
				'div',
				{ class: 'dev3bk-hp', 'aria-hidden': 'true' },
				h( 'label', { text: 'Website' }, h( 'input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', onInput: ( e ) => ( state.details.website = e.target.value ) } ) )
			),
			h( 'button', { type: 'submit', class: 'dev3bk-hidden-submit', tabindex: '-1', 'aria-hidden': 'true' } )
		);
		return [ summary(), form ];
	}

	function validate() {
		const d = state.details;
		const errors = [];
		if ( ! d.name.trim() ) {
			errors.push( [ 'name', s.enterName ] );
		}
		if ( ! EMAIL.test( d.email.trim() ) ) {
			errors.push( [ 'email', s.enterEmail ] );
		}
		if ( state.catalog.phone_field === 'required' && ! d.phone.trim() ) {
			errors.push( [ 'phone', s.enterPhone ] );
		}
		errors.forEach( ( [ key, msg ] ) => {
			const input = body.querySelector( `[name="${ key }"]` );
			if ( input ) {
				input.setAttribute( 'aria-invalid', 'true' );
				const m = root.querySelector( `#${ input.getAttribute( 'aria-describedby' ) }` );
				if ( m ) {
					m.textContent = msg;
				}
			}
		} );
		if ( errors.length ) {
			const first = body.querySelector( `[name="${ errors[ 0 ][ 0 ] }"]` );
			if ( first ) {
				first.focus();
			}
		}
		return ! errors.length;
	}

	function stepPay() {
		const methods = payments();
		if ( ! state.payment || ! methods.includes( state.payment ) ) {
			state.payment = methods[ 0 ];
		}
		const name = id( 'dev3bk-pay' );
		return [
			summary(),
			h(
				'fieldset',
				{ class: 'dev3bk-pay' },
				h( 'legend', { class: 'dev3bk-label', text: s.payment } ),
				methods.map( ( m ) => {
					const rid = id( 'dev3bk-r' );
					return h(
						'label',
						{ class: `dev3bk-radio${ state.payment === m ? ' is-on' : '' }`, for: rid },
						h( 'input', {
							type: 'radio',
							id: rid,
							name,
							value: m,
							checked: state.payment === m,
							onChange: () => {
								state.payment = m;
								render();
							},
						} ),
						h( 'span', { text: state.catalog.payment_label[ m ] || m } )
					);
				} )
			),
			state.catalog.policy ? h( 'p', { class: 'dev3bk-policy' }, h( 'strong', { text: `${ s.policy }: ` } ), state.catalog.policy ) : null,
		];
	}

	function stepDone() {
		const b = state.booking;
		const pending = b.status === 'pending';
		return [
			h( 'div', { class: 'dev3bk-done__icon', 'aria-hidden': 'true' }, icon( 'check' ) ),
			h( 'p', { class: 'dev3bk-done__text', text: t( s, pending ? 'donePendingText' : 'doneText', state.details.email ) } ),
			h(
				'dl',
				{ class: 'dev3bk-sum' },
				[
					[ s.service, b.service ],
					[ s.with, b.staff ],
					[ s.when, `${ b.day_label }, ${ b.date_label } · ${ b.time_label }` ],
					[ s.reference, b.ref ],
				].map( ( [ k, v ] ) => h( 'div', { class: 'dev3bk-sum__row' }, h( 'dt', { text: k } ), h( 'dd', { text: v } ) ) )
			),
			h(
				'div',
				{ class: 'dev3bk-actions' },
				h( 'a', { class: 'dev3bk-btn dev3bk-btn--soft', href: b.google_url, target: '_blank', rel: 'noopener' }, icon( 'cal' ), h( 'span', { text: s.addGoogle } ) ),
				h( 'a', { class: 'dev3bk-btn dev3bk-btn--soft', href: b.ics_url, download: `booking-${ b.ref }.ics` }, icon( 'cal' ), h( 'span', { text: s.downloadIcs } ) ),
				h( 'a', { class: 'dev3bk-btn dev3bk-btn--ghost', href: b.manage_url }, h( 'span', { text: s.manage } ) )
			),
			state.catalog.policy ? h( 'p', { class: 'dev3bk-policy', text: state.catalog.policy } ) : null,
		];
	}

	function primaryLabel() {
		if ( state.step === 'details' ) {
			return needsPayStep() ? s.continue : s.confirm;
		}
		if ( state.step === 'pay' ) {
			return state.payment === 'stripe' || state.payment === 'woocommerce' ? s.toPayment : s.confirm;
		}
		return s.continue;
	}

	function submit() {
		state.busy = true;
		state.error = '';
		renderBar();
		const d = state.details;
		const staffId = state.staff ? state.staff.id : state.slot.pinStaff || 0;
		request( 'public/bookings', {
			method: 'POST',
			data: {
				service: state.service.id,
				staff: staffId,
				start: state.slot.start,
				tz: state.tz,
				name: d.name.trim(),
				email: d.email.trim(),
				phone: d.phone.trim(),
				notes: d.notes.trim(),
				payment: needsPayStep() ? state.payment : payments()[ 0 ],
				website: d.website,
				ai: !! state.slot.ai,
			},
		} )
			.then( ( r ) => {
				if ( r.next && r.next.type === 'redirect' && r.next.url ) {
					live.textContent = s.redirecting;
					fill( body, h( 'p', { class: 'dev3bk-hint', text: s.redirecting } ) );
					win.location.assign( r.next.url );
					return;
				}
				state.booking = r.booking;
				state.busy = false;
				go( 'done' );
			} )
			.catch( ( err ) => {
				state.busy = false;
				state.error = err.message;
				if ( err.code === 'dev3bk_slot_taken' ) {
					state.slot = null;
					go( 'time' );
					state.error = err.message;
					renderError();
					return;
				}
				renderError();
				renderBar();
			} );
	}

	function primaryAction() {
		if ( state.step === 'time' && state.slot ) {
			next();
		} else if ( state.step === 'details' ) {
			if ( ! validate() ) {
				return;
			}
			if ( needsPayStep() ) {
				next();
			} else {
				submit();
			}
		} else if ( state.step === 'pay' ) {
			submit();
		}
	}

	function renderError() {
		const old = body.querySelector( '.dev3bk-alert' );
		if ( old ) {
			old.remove();
		}
		if ( state.error ) {
			body.prepend( h( 'p', { class: 'dev3bk-alert', role: 'alert', text: state.error } ) );
		}
	}

	function renderBar() {
		const list = steps();
		const i = list.indexOf( state.step );
		if ( state.step === 'done' ) {
			fill(
				bar,
				h( 'button', {
					type: 'button',
					class: 'dev3bk-btn dev3bk-btn--ghost',
					text: s.bookAnother,
					onClick: () => {
						state.booking = null;
						state.slot = null;
						go( steps()[ 0 ] );
					},
				} )
			);
			return;
		}
		const showPrimary = [ 'time', 'details', 'pay' ].includes( state.step );
		const backBtn = i > 0 ? h( 'button', { type: 'button', class: 'dev3bk-btn dev3bk-btn--ghost', onClick: back }, icon( 'left' ), h( 'span', { text: s.back } ) ) : h( 'span' );
		let note = null;
		if ( state.step === 'time' && state.slot ) {
			note = h( 'span', { class: 'dev3bk-bar__note', text: `${ fmtDay( state.slot.date, { weekday: 'short', day: 'numeric', month: 'short' }, cfg.locale ) } · ${ state.slot.label }` } );
		}
		const primary = showPrimary
			? h(
					'button',
					{
						type: 'button',
						class: 'dev3bk-btn dev3bk-btn--primary',
						disabled: state.busy || ( state.step === 'time' && ! state.slot ),
						'aria-busy': state.busy ? 'true' : null,
						onClick: primaryAction,
					},
					state.busy ? h( 'span', { class: 'dev3bk-spin', 'aria-hidden': 'true' } ) : null,
					h( 'span', { text: state.busy ? s.booking : primaryLabel() } )
			  )
			: null;
		fill( bar, backBtn, h( 'span', { class: 'dev3bk-bar__end' }, note, primary ) );
	}

	function render() {
		const titles = { service: s.chooseService, staff: s.chooseStaff, time: s.pickTime, details: s.yourDetails, pay: s.review };
		if ( state.step === 'done' ) {
			fill( head, title( state.booking.status === 'pending' ? s.donePending : s.doneTitle ) );
			fill( body, ...stepDone() );
		} else {
			fill( head, progress(), title( titles[ state.step ] ) );
			const parts = { service: stepService, staff: stepStaff, time: stepTime, details: stepDetails, pay: stepPay }[ state.step ]();
			fill( body, ...parts );
			renderError();
		}
		root.classList.toggle( 'is-done', state.step === 'done' );
		renderBar();
	}

	request( 'public/catalog' )
		.then( ( c ) => {
			state.catalog = c;
			state.tz = browserTz( c.tz );
			if ( c.user ) {
				state.details.name = c.user.name || '';
				state.details.email = c.user.email || '';
			}
			const list = services();
			if ( opts.service ) {
				state.service = list.find( ( x ) => x.id === opts.service ) || null;
			} else if ( list.length === 1 ) {
				state.service = list[ 0 ];
			}
			if ( state.service ) {
				const people = staffFor( state.service );
				if ( opts.staff ) {
					state.staff = people.find( ( p ) => p.id === opts.staff ) || 0;
				} else if ( people.length === 1 ) {
					state.staff = people[ 0 ];
				}
			}
			fill( root, app );
			root.removeAttribute( 'aria-busy' );
			state.step = steps()[ 0 ];
			if ( state.step === 'staff' && ! state.service ) {
				state.step = 'service';
			}
			render();
		} )
		.catch( ( err ) => {
			fill( root, h( 'p', { class: 'dev3bk-alert', role: 'alert', text: err.message } ) );
		} );
}
