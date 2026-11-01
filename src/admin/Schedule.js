import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, Columns, ErrorNotice, PageHeader, SkeletonCard, useToast } from '../core3/ui';
import { ExceptionsEditor, WeekEditor } from './ScheduleParts';

export default function Schedule( { changed } ) {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ dirty, setDirty ] = useState( false );

	const load = () =>
		request( 'schedule' )
			.then( ( d ) => {
				setData( d );
				setDirty( false );
			} )
			.catch( setError );
	useEffect( () => {
		load();
	}, [] );

	const save = () => {
		setBusy( true );
		request( 'schedule', { method: 'PUT', data: { hours: data.hours, breaks: data.breaks } } )
			.then( ( d ) => {
				setData( d );
				setDirty( false );
				changed();
				toast.success( __( 'Business hours saved.', 'dev3-ai-booking-appointments' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return <SkeletonCard lines={ 7 } />;
	}
	return (
		<>
			<PageHeader
				title={ __( 'Schedule', 'dev3-ai-booking-appointments' ) }
				description={ sprintf( /* translators: %s: timezone. */ __( 'Default opening hours, breaks, closures and holidays (%s). Staff who use the business hours follow these.', 'dev3-ai-booking-appointments' ), data.timezone ) }
				actions={
					<Button variant="primary" icon="check" disabled={ ! dirty } loading={ busy } onClick={ save }>
						{ __( 'Save hours', 'dev3-ai-booking-appointments' ) }
					</Button>
				}
			/>
			<Columns min={ 360 }>
				<Card title={ __( 'Business hours', 'dev3-ai-booking-appointments' ) }>
					<WeekEditor
						value={ data.hours }
						onChange={ ( hours ) => {
							setData( { ...data, hours } );
							setDirty( true );
						} }
					/>
				</Card>
				<Card title={ __( 'Breaks', 'dev3-ai-booking-appointments' ) } description={ __( 'Applied every week, e.g. a lunch break.', 'dev3-ai-booking-appointments' ) }>
					<WeekEditor
						kind="breaks"
						value={ data.breaks }
						onChange={ ( breaks ) => {
							setData( { ...data, breaks } );
							setDirty( true );
						} }
					/>
				</Card>
			</Columns>
			<Card style={ { marginTop: 16 } } title={ __( 'Closures, holidays and special days', 'dev3-ai-booking-appointments' ) } description={ __( 'Apply to every staff member. Special hours replace the weekly hours for those dates.', 'dev3-ai-booking-appointments' ) }>
				<ExceptionsEditor staffId={ 0 } items={ data.exceptions } onChange={ () => request( 'schedule' ).then( ( d ) => { setData( ( cur ) => ( { ...cur, exceptions: d.exceptions } ) ); changed(); } ) } />
			</Card>
		</>
	);
}
