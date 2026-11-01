import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Checkbox, ErrorNotice, Skeleton, TextField, cx } from '../core3/ui';
import { fmtDay, qs } from './util';

/**
 * Admin slot picker: real free slots for a service/staff/day (notice and advance rules ignored),
 * plus an optional forced time.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.service  Service id.
 * @param {number}   props.staff    Staff id (0 = any).
 * @param {string}   props.date     Business-local day.
 * @param {number}   props.exclude  Booking id to ignore (reschedule).
 * @param {Object}   props.value    { start, staff, force, time }.
 * @param {Function} props.onChange Change.
 * @return {Element} Picker.
 */
export default function SlotPicker( { service, staff, date, exclude = 0, value, onChange } ) {
	const [ slots, setSlots ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( ! service || ! date ) {
			setSlots( null );
			return;
		}
		setSlots( null );
		setError( null );
		request( `availability${ qs( { service, staff, date, exclude } ) }` )
			.then( ( d ) => setSlots( d.days[ date ] || [] ) )
			.catch( setError );
	}, [ service, staff, date, exclude ] );

	if ( ! service || ! date ) {
		return <p className="dev3bk-muted">{ __( 'Choose a service and a day to see free times.', 'dev3-ai-booking-appointments' ) }</p>;
	}
	return (
		<div className="dev3bk-slotpick">
			{ error && <ErrorNotice error={ error } /> }
			{ ! slots && ! error && <Skeleton lines={ 2 } /> }
			{ slots && ! value.force && (
				<>
					<p className="dev3bk-slotpick__label">{ fmtDay( date, { weekday: 'long', day: 'numeric', month: 'long' } ) }</p>
					{ slots.length === 0 ? (
						<p className="dev3bk-muted">{ __( 'No free times this day. Pick another day or override below.', 'dev3-ai-booking-appointments' ) }</p>
					) : (
						<div className="dev3bk-slotpick__grid" role="listbox" aria-label={ __( 'Free times', 'dev3-ai-booking-appointments' ) }>
							{ slots.map( ( s ) => (
								<button
									key={ s.start }
									type="button"
									role="option"
									aria-selected={ value.start === s.start }
									className={ cx( 'dev3bk-slotpick__slot', value.start === s.start && 'is-selected' ) }
									onClick={ () => onChange( { ...value, start: s.start, staff: staff || s.staff[ 0 ] } ) }
								>
									{ s.label }
								</button>
							) ) }
						</div>
					) }
				</>
			) }
			<div className="dev3bk-slotpick__force">
				<Checkbox
					label={ __( 'Override availability (double-booking allowed)', 'dev3-ai-booking-appointments' ) }
					checked={ !! value.force }
					onChange={ ( force ) => onChange( { ...value, force, start: force ? '' : value.start } ) }
				/>
				{ value.force && (
					<TextField
						label={ __( 'Start time', 'dev3-ai-booking-appointments' ) }
						type="time"
						value={ value.time || '' }
						onChange={ ( time ) => onChange( { ...value, time } ) }
						help={ __( 'In the business time zone. Staff is required.', 'dev3-ai-booking-appointments' ) }
					/>
				) }
			</div>
		</div>
	);
}
