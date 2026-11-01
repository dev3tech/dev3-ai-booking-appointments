import { fill, h, icon, id } from './dom';
import { addDays, fmtDay, zones } from './time';

const WINDOW = 14;

/**
 * Translates with %s / %d / %1$s placeholders.
 *
 * @param {Object} strings Strings.
 * @param {string} key     Key.
 * @param {...*}   args    Values.
 * @return {string} Text.
 */
export function t( strings, key, ...args ) {
	let i = 0;
	return String( strings[ key ] || key ).replace( /%(\d+\$)?[sd]/g, ( m, pos ) => {
		const v = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
		return v === undefined ? '' : String( v );
	} );
}

/**
 * Date + time picker with natural-language search and a time-zone switcher.
 *
 * @param {Object}   o           Options.
 * @param {Function} o.request   API client.
 * @param {Object}   o.strings   Strings.
 * @param {string}   o.locale    Locale.
 * @param {number}   o.service   Service id.
 * @param {number}   o.staff     Staff id (0 = any).
 * @param {string}   o.tz        Customer zone.
 * @param {string}   o.today     Today (customer zone).
 * @param {boolean}  o.nl        Show the "Tell us when suits you" box.
 * @param {Object}   o.selected  Selected slot { start }.
 * @param {Function} o.onPick    ( slot ) => void; slot = { start, label, date, staff[], seats_left, ai }.
 * @param {Function} o.onTz      ( tz ) => void.
 * @return {Object} { el, destroy }
 */
export function createPicker( o ) {
	const s = o.strings;
	const state = { from: o.today, days: {}, loaded: false, day: '', tz: o.tz, selected: o.selected ? o.selected.start : '', next: '', last: '', loading: false, nl: null, error: '' };
	const root = h( 'div', { class: 'dev3bk-picker' } );
	const live = h( 'p', { class: 'dev3bk-sr', 'aria-live': 'polite' } );
	const nlBox = h( 'div', { class: 'dev3bk-nl' } );
	const strip = h( 'div', { class: 'dev3bk-strip' } );
	const slotsEl = h( 'div', { class: 'dev3bk-slots' } );
	const tzEl = h( 'div', { class: 'dev3bk-tz' } );
	let seq = 0;

	// Natural-language box.
	if ( o.nl ) {
		const inputId = id( 'dev3bk-nl' );
		const input = h( 'input', { id: inputId, type: 'text', class: 'dev3bk-input', placeholder: s.nlPlaceholder, autocomplete: 'off', maxlength: '300', enterkeyhint: 'search' } );
		const results = h( 'div', { class: 'dev3bk-nl__res', 'aria-live': 'polite' } );
		const button = h( 'button', { type: 'submit', class: 'dev3bk-btn dev3bk-btn--soft' }, icon( 'spark' ), h( 'span', { text: s.nlButton } ) );
		const form = h(
			'form',
			{
				class: 'dev3bk-nl__form',
				onSubmit: ( e ) => {
					e.preventDefault();
					const text = input.value.trim();
					if ( ! text ) {
						input.focus();
						return;
					}
					button.disabled = true;
					fill( results, h( 'p', { class: 'dev3bk-hint', text: s.nlSearching } ) );
					o.request( 'public/nl', { method: 'POST', data: { text, service: o.service, staff: o.staff, tz: state.tz } } )
						.then( ( r ) => renderNl( results, r ) )
						.catch( ( err ) => fill( results, h( 'p', { class: 'dev3bk-error', role: 'alert', text: err.message } ) ) )
						.finally( () => {
							button.disabled = false;
						} );
				},
			},
			h( 'label', { for: inputId, class: 'dev3bk-label', text: s.nlLabel } ),
			h( 'div', { class: 'dev3bk-nl__row' }, input, button )
		);
		fill( nlBox, form, results, h( 'p', { class: 'dev3bk-or' }, h( 'span', { text: s.or } ) ) );
	}

	function renderNl( box, r ) {
		state.nl = { box, r };
		if ( ! r.slots || ! r.slots.length ) {
			fill( box, h( 'p', { class: 'dev3bk-hint', text: s.nlNone } ) );
			return;
		}
		const byDate = {};
		r.slots.forEach( ( slot ) => {
			( byDate[ slot.date ] = byDate[ slot.date ] || [] ).push( slot );
		} );
		const groups = Object.keys( byDate ).map( ( date ) => {
			const gid = id( 'dev3bk-ng' );
			return h(
				'div',
				{ class: 'dev3bk-slotgroup', role: 'group', 'aria-labelledby': gid },
				h( 'p', { class: 'dev3bk-slotgroup__label', id: gid, text: fmtDay( date, { weekday: 'long', day: 'numeric', month: 'long' }, o.locale ) } ),
				h(
					'div',
					{ class: 'dev3bk-slotgrid' },
					byDate[ date ].map( ( slot ) => {
						const on = state.selected === slot.start;
						return h( 'button', {
							type: 'button',
							class: `dev3bk-slot${ on ? ' is-on' : '' }`,
							'aria-pressed': on ? 'true' : 'false',
							text: slot.label,
							onClick: () => {
								state.day = slot.date;
								pick( { ...slot, ai: true } );
								renderNl( box, r );
								if ( slot.date < state.from || slot.date >= addDays( state.from, WINDOW ) ) {
									state.from = slot.date;
									load();
								} else {
									render();
								}
							},
						} );
					} )
				)
			);
		} );
		fill( box, h( 'p', { class: 'dev3bk-hint', text: r.relaxed ? s.nlNearest : t( s, 'nlResults', r.summary || '' ) } ), ...groups );
	}

	function pick( slot ) {
		state.selected = slot.start;
		o.onPick( slot );
	}

	function load() {
		const my = ++seq;
		state.loading = true;
		state.error = '';
		render();
		o.request( 'public/availability', { query: { service: o.service, staff: o.staff, from: state.from, to: addDays( state.from, WINDOW - 1 ), tz: state.tz } } )
			.then( ( d ) => {
				if ( my !== seq ) {
					return;
				}
				state.days = d.days || {};
				state.next = d.next || '';
				state.last = d.last || '';
				state.loaded = true;
				state.loading = false;
				const dates = Object.keys( state.days );
				if ( ! state.day || ! state.days[ state.day ] ) {
					state.day = dates.length ? dates[ 0 ] : '';
				}
				render();
				live.textContent = dates.length ? '' : s.noSlots;
			} )
			.catch( ( err ) => {
				if ( my !== seq ) {
					return;
				}
				state.loading = false;
				state.error = err.message;
				render();
			} );
	}

	function renderStrip() {
		const days = Array.from( { length: WINDOW }, ( _, i ) => addDays( state.from, i ) );
		const month = fmtDay( days[ 0 ], { month: 'long', year: 'numeric' }, o.locale );
		const canBack = state.from > o.today;
		const canFwd = ! state.last || addDays( state.from, WINDOW ) <= state.last;
		const head = h(
			'div',
			{ class: 'dev3bk-strip__head' },
			h( 'span', { class: 'dev3bk-strip__month', text: month } ),
			h(
				'span',
				{ class: 'dev3bk-strip__nav' },
				h(
					'button',
					{
						type: 'button',
						class: 'dev3bk-iconbtn',
						'aria-label': s.earlier,
						disabled: ! canBack,
						onClick: () => {
							const back = addDays( state.from, -WINDOW );
							state.from = back < o.today ? o.today : back;
							state.day = '';
							load();
						},
					},
					icon( 'left' )
				),
				h(
					'button',
					{
						type: 'button',
						class: 'dev3bk-iconbtn',
						'aria-label': s.later,
						disabled: ! canFwd,
						onClick: () => {
							state.from = addDays( state.from, WINDOW );
							state.day = '';
							load();
						},
					},
					icon( 'right' )
				)
			)
		);
		const row = h( 'div', { class: 'dev3bk-strip__days', role: 'listbox', 'aria-label': s.pickTime } );
		days.forEach( ( d ) => {
			const has = !! ( state.days[ d ] && state.days[ d ].length );
			const on = d === state.day;
			row.appendChild(
				h(
					'button',
					{
						type: 'button',
						role: 'option',
						class: `dev3bk-day${ on ? ' is-on' : '' }${ has ? ' has-slots' : '' }`,
						'aria-selected': on ? 'true' : 'false',
						'aria-label': fmtDay( d, { weekday: 'long', day: 'numeric', month: 'long' }, o.locale ),
						disabled: state.loaded && ! has,
						onClick: () => {
							state.day = d;
							render();
							const first = slotsEl.querySelector( '.dev3bk-slot' );
							if ( first ) {
								first.focus( { preventScroll: true } );
							}
						},
					},
					h( 'span', { class: 'dev3bk-day__wd', text: d === o.today ? s.today : fmtDay( d, { weekday: 'short' }, o.locale ) } ),
					h( 'span', { class: 'dev3bk-day__num', text: String( Number( d.slice( 8 ) ) ) } ),
					h( 'span', { class: 'dev3bk-day__dot', 'aria-hidden': 'true' } )
				)
			);
		} );
		fill( strip, head, row );
		const active = row.querySelector( '.is-on' );
		if ( active && active.scrollIntoView && row.scrollWidth > row.clientWidth ) {
			row.scrollLeft = Math.max( 0, active.offsetLeft - row.clientWidth / 2 + active.clientWidth / 2 );
		}
	}

	function renderSlots() {
		if ( state.error ) {
			fill( slotsEl, h( 'p', { class: 'dev3bk-error', role: 'alert', text: state.error } ), h( 'button', { type: 'button', class: 'dev3bk-btn dev3bk-btn--ghost', text: s.retry, onClick: load } ) );
			return;
		}
		if ( state.loading && ! state.loaded ) {
			fill( slotsEl, h( 'div', { class: 'dev3bk-skel', 'aria-hidden': 'true' }, h( 'i' ), h( 'i' ), h( 'i' ), h( 'i' ), h( 'i' ), h( 'i' ) ) );
			return;
		}
		const list = state.days[ state.day ] || [];
		if ( ! list.length ) {
			const children = [ h( 'p', { class: 'dev3bk-hint', text: Object.keys( state.days ).length ? s.noSlots : s.noAvailability } ) ];
			if ( state.next ) {
				children.push(
					h( 'button', {
						type: 'button',
						class: 'dev3bk-btn dev3bk-btn--soft',
						text: t( s, 'nextAvailable', fmtDay( state.next, { weekday: 'long', day: 'numeric', month: 'long' }, o.locale ) ),
						onClick: () => {
							state.from = state.next;
							state.day = state.next;
							load();
						},
					} )
				);
			}
			fill( slotsEl, ...children );
			return;
		}
		const groups = [
			[ 'morning', ( x ) => x.hour < 12 ],
			[ 'afternoon', ( x ) => x.hour >= 12 && x.hour < 17 ],
			[ 'evening', ( x ) => x.hour >= 17 ],
		];
		const out = [];
		groups.forEach( ( [ key, test ] ) => {
			const items = list.filter( test );
			if ( ! items.length ) {
				return;
			}
			const gid = id( 'dev3bk-g' );
			out.push(
				h(
					'div',
					{ class: 'dev3bk-slotgroup', role: 'group', 'aria-labelledby': gid },
					h( 'p', { class: 'dev3bk-slotgroup__label', id: gid, text: s[ key ] } ),
					h(
						'div',
						{ class: 'dev3bk-slotgrid' },
						items.map( ( slot ) => {
							const on = state.selected === slot.start;
							return h(
								'button',
								{
									type: 'button',
									class: `dev3bk-slot${ on ? ' is-on' : '' }`,
									'aria-pressed': on ? 'true' : 'false',
									onClick: () => {
										pick( { ...slot, date: state.day } );
										renderSlots();
										if ( state.nl ) {
											renderNl( state.nl.box, state.nl.r );
										}
									},
								},
								h( 'span', { text: slot.label } ),
								slot.seats_left > 1 && o.group ? h( 'small', { text: t( s, 'spotsLeft', slot.seats_left ) } ) : null
							);
						} )
					)
				)
			);
		} );
		fill( slotsEl, ...out );
	}

	function renderTz() {
		const selId = id( 'dev3bk-tz' );
		const select = h(
			'select',
			{
				id: selId,
				class: 'dev3bk-input dev3bk-tz__select',
				onChange: ( e ) => {
					state.tz = e.target.value;
					state.selected = '';
					o.onTz( state.tz );
					load();
				},
			},
			zones( [ state.tz ] ).map( ( z ) => h( 'option', { value: z, selected: z === state.tz, text: z.replace( /_/g, ' ' ) } ) )
		);
		const wrap = h( 'div', { class: 'dev3bk-tz__pick', hidden: true }, h( 'label', { for: selId, class: 'dev3bk-label', text: s.timezone } ), select );
		fill(
			tzEl,
			icon( 'globe' ),
			h( 'span', { text: t( s, 'timesIn', state.tz.replace( /_/g, ' ' ) ) } ),
			h( 'button', {
				type: 'button',
				class: 'dev3bk-link',
				text: s.changeTz,
				'aria-expanded': 'false',
				onClick: ( e ) => {
					wrap.hidden = ! wrap.hidden;
					e.currentTarget.setAttribute( 'aria-expanded', wrap.hidden ? 'false' : 'true' );
					if ( ! wrap.hidden ) {
						select.focus();
					}
				},
			} ),
			wrap
		);
	}

	function render() {
		renderStrip();
		renderSlots();
	}

	renderTz();
	fill( root, nlBox, strip, slotsEl, tzEl, live );
	load();
	return { el: root };
}
