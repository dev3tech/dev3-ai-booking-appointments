import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Checkbox, EmptyState, IconButton, Select, TextField, Toggle, useToast } from '../core3/ui';
import { DAYS, dayName, fmtDay } from './util';

/**
 * Weekly hours editor: day → [ [ start, end ], … ].
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.value    Week map.
 * @param {Function} props.onChange Change.
 * @param {string}   props.kind     hours|breaks (labels).
 * @return {Element} Editor.
 */
export function WeekEditor( { value, onChange, kind = 'hours' } ) {
	const week = value || {};
	const setDay = ( day, list ) => onChange( { ...week, [ day ]: list } );
	const isBreaks = kind === 'breaks';
	return (
		<div className="dev3bk-week">
			{ DAYS.map( ( day ) => {
				const list = week[ day ] || [];
				const open = list.length > 0;
				return (
					<div key={ day } className={ `dev3bk-week__day${ open ? '' : ' is-closed' }` }>
						<div className="dev3bk-week__name">
							{ isBreaks ? (
								<strong>{ dayName( day ) }</strong>
							) : (
								<Toggle label={ dayName( day ) } checked={ open } onChange={ ( on ) => setDay( day, on ? [ [ '09:00', '17:00' ] ] : [] ) } />
							) }
						</div>
						<div className="dev3bk-week__ranges">
							{ ! open && ! isBreaks && <span className="dev3bk-muted">{ __( 'Closed', 'dev3-ai-booking-appointments' ) }</span> }
							{ list.map( ( iv, i ) => (
								<div key={ i } className="dev3bk-week__range">
									<TextField label={ `${ dayName( day ) } ${ isBreaks ? __( 'break start', 'dev3-ai-booking-appointments' ) : __( 'from', 'dev3-ai-booking-appointments' ) }` } hideLabel type="time" value={ iv[ 0 ] } onChange={ ( v ) => setDay( day, list.map( ( x, j ) => ( j === i ? [ v, x[ 1 ] ] : x ) ) ) } />
									<span aria-hidden="true">–</span>
									<TextField label={ `${ dayName( day ) } ${ isBreaks ? __( 'break end', 'dev3-ai-booking-appointments' ) : __( 'to', 'dev3-ai-booking-appointments' ) }` } hideLabel type="time" value={ iv[ 1 ] === '24:00' ? '23:59' : iv[ 1 ] } onChange={ ( v ) => setDay( day, list.map( ( x, j ) => ( j === i ? [ x[ 0 ], v ] : x ) ) ) } />
									<IconButton icon="x" label={ __( 'Remove', 'dev3-ai-booking-appointments' ) } onClick={ () => setDay( day, list.filter( ( _, j ) => j !== i ) ) } />
								</div>
							) ) }
							{ ( open || isBreaks ) && (
								<Button size="sm" variant="ghost" icon="plus" onClick={ () => setDay( day, [ ...list, isBreaks ? [ '12:00', '13:00' ] : [ list.length ? list[ list.length - 1 ][ 1 ] : '09:00', '18:00' ] ] ) }>
									{ isBreaks ? __( 'Add break', 'dev3-ai-booking-appointments' ) : __( 'Add hours', 'dev3-ai-booking-appointments' ) }
								</Button>
							) }
						</div>
					</div>
				);
			} ) }
			<div className="dev3bk-week__copy">
				<Button
					size="sm"
					variant="link"
					onClick={ () => {
						const mon = week.mon || [];
						onChange( { ...week, tue: mon, wed: mon, thu: mon, fri: mon } );
					} }
				>
					{ __( 'Copy Monday to Tuesday–Friday', 'dev3-ai-booking-appointments' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Time off / closures / special hours list + add form.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.staffId  Staff (0 = business).
 * @param {Array}    props.items    Exceptions.
 * @param {Function} props.onChange Reload.
 * @return {Element} Editor.
 */
export function ExceptionsEditor( { staffId = 0, items, onChange } ) {
	const toast = useToast();
	const blank = { type: 'off', date_from: '', date_to: '', all_day: true, start_time: '09:00', end_time: '13:00', label: '', yearly: false };
	const [ form, setForm ] = useState( blank );
	const [ busy, setBusy ] = useState( false );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );

	const add = () => {
		setBusy( true );
		const data = { ...form, staff_id: staffId };
		if ( form.type === 'off' && form.all_day ) {
			data.start_time = '';
			data.end_time = '';
		}
		request( 'exceptions', { method: 'POST', data } )
			.then( () => {
				toast.success( __( 'Added.', 'dev3-ai-booking-appointments' ) );
				setForm( blank );
				onChange();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};
	const remove = ( id ) =>
		request( `exceptions/${ id }`, { method: 'DELETE' } )
			.then( onChange )
			.catch( ( e ) => toast.error( e ) );

	const describe = ( ex ) => {
		const range = ex.date_from === ex.date_to ? fmtDay( ex.date_from, { weekday: 'short', day: 'numeric', month: 'short', year: ex.yearly ? undefined : 'numeric' } ) : `${ fmtDay( ex.date_from, { day: 'numeric', month: 'short' } ) } – ${ fmtDay( ex.date_to, { day: 'numeric', month: 'short', year: ex.yearly ? undefined : 'numeric' } ) }`;
		const time = ex.start_time ? ` · ${ ex.start_time }–${ ex.end_time }` : ` · ${ __( 'all day', 'dev3-ai-booking-appointments' ) }`;
		return range + time;
	};

	return (
		<div className="dev3bk-exc">
			{ items.length === 0 ? (
				<EmptyState compact icon="clock" title={ staffId ? __( 'No time off or special days', 'dev3-ai-booking-appointments' ) : __( 'No closures or holidays', 'dev3-ai-booking-appointments' ) } />
			) : (
				<ul className="dev3bk-exc__list">
					{ items.map( ( ex ) => (
						<li key={ ex.id }>
							<Badge tone={ ex.type === 'hours' ? 'info' : 'warning' }>{ ex.type === 'hours' ? __( 'Special hours', 'dev3-ai-booking-appointments' ) : __( 'Off', 'dev3-ai-booking-appointments' ) }</Badge>
							<span className="dev3bk-exc__text">
								<strong>{ ex.label || ( ex.type === 'hours' ? __( 'Special hours', 'dev3-ai-booking-appointments' ) : __( 'Time off', 'dev3-ai-booking-appointments' ) ) }</strong>
								<span className="dev3bk-muted">
									{ describe( ex ) }
									{ ex.yearly ? ` · ${ __( 'every year', 'dev3-ai-booking-appointments' ) }` : '' }
								</span>
							</span>
							<IconButton icon="trash" label={ __( 'Remove', 'dev3-ai-booking-appointments' ) } onClick={ () => remove( ex.id ) } />
						</li>
					) ) }
				</ul>
			) }
			<div className="dev3bk-exc__form">
				<div className="dev3bk-formrow">
					<Select
						label={ __( 'Type', 'dev3-ai-booking-appointments' ) }
						value={ form.type }
						onChange={ set( 'type' ) }
						options={ [
							{ value: 'off', label: staffId ? __( 'Time off', 'dev3-ai-booking-appointments' ) : __( 'Closed / holiday', 'dev3-ai-booking-appointments' ) },
							{ value: 'hours', label: __( 'Special working hours', 'dev3-ai-booking-appointments' ) },
						] }
					/>
					<TextField label={ __( 'From', 'dev3-ai-booking-appointments' ) } type="date" value={ form.date_from } onChange={ set( 'date_from' ) } required />
					<TextField label={ __( 'To', 'dev3-ai-booking-appointments' ) } type="date" value={ form.date_to } onChange={ set( 'date_to' ) } help={ __( 'Leave empty for one day.', 'dev3-ai-booking-appointments' ) } />
				</div>
				<div className="dev3bk-formrow">
					{ form.type === 'off' && <Checkbox label={ __( 'All day', 'dev3-ai-booking-appointments' ) } checked={ form.all_day } onChange={ set( 'all_day' ) } /> }
					{ ( form.type === 'hours' || ! form.all_day ) && (
						<>
							<TextField label={ __( 'Start', 'dev3-ai-booking-appointments' ) } type="time" value={ form.start_time } onChange={ set( 'start_time' ) } />
							<TextField label={ __( 'End', 'dev3-ai-booking-appointments' ) } type="time" value={ form.end_time } onChange={ set( 'end_time' ) } />
						</>
					) }
				</div>
				<div className="dev3bk-formrow">
					<TextField label={ __( 'Label', 'dev3-ai-booking-appointments' ) } value={ form.label } onChange={ set( 'label' ) } placeholder={ __( 'e.g. Christmas, Training, Holiday', 'dev3-ai-booking-appointments' ) } />
					<Checkbox label={ __( 'Repeat every year', 'dev3-ai-booking-appointments' ) } checked={ form.yearly } onChange={ set( 'yearly' ) } />
				</div>
				<Button variant="secondary" icon="plus" disabled={ ! form.date_from } loading={ busy } onClick={ add }>
					{ form.type === 'hours' ? __( 'Add special hours', 'dev3-ai-booking-appointments' ) : __( 'Add time off', 'dev3-ai-booking-appointments' ) }
				</Button>
			</div>
		</div>
	);
}
