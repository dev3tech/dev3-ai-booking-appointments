import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, Drawer, ErrorNotice, NumberField, PageHeader, Select, SkeletonCard, Stack, Tabs, Textarea, TextField, Toggle, useToast } from '../core3/ui';

const GROUPS = {
	customer: __( 'Customer emails', 'dev3-ai-booking-appointments' ),
	reminder: __( 'Reminders & follow-up', 'dev3-ai-booking-appointments' ),
	admin: __( 'Admin & staff emails', 'dev3-ai-booking-appointments' ),
};

function TemplateEditor( { tpl, placeholders, onClose, onSaved, onUsage } ) {
	const toast = useToast();
	const [ subject, setSubject ] = useState( tpl.subject );
	const [ body, setBody ] = useState( tpl.body );
	const [ tab, setTab ] = useState( 'edit' );
	const [ preview, setPreview ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ ai, setAi ] = useState( { tone: 'friendly', length: 'short', language: '', instructions: '' } );
	const [ draft, setDraft ] = useState( null );
	const [ to, setTo ] = useState( '' );

	useEffect( () => {
		if ( tab !== 'preview' ) {
			return;
		}
		request( `templates/${ tpl.id }/preview`, { method: 'POST', data: { subject, body } } )
			.then( setPreview )
			.catch( setError );
	}, [ tab ] );

	const insert = ( ph ) => setBody( `${ body }${ body.endsWith( ' ' ) || body.endsWith( '\n' ) ? '' : ' ' }{${ ph }}` );

	const save = ( values, source = 'manual' ) => {
		setBusy( 'save' );
		setError( null );
		return request( `templates/${ tpl.id }`, { method: 'PUT', data: { ...values, source } } )
			.then( ( t ) => {
				toast.success( source === 'ai' ? __( 'DEV3 AI copy approved and saved.', 'dev3-ai-booking-appointments' ) : __( 'Template saved.', 'dev3-ai-booking-appointments' ) );
				onSaved( t );
				setSubject( t.subject );
				setBody( t.body );
				setDraft( null );
			} )
			.catch( ( e ) => {
				setError( e );
				throw e;
			} )
			.finally( () => setBusy( '' ) );
	};

	const write = () => {
		setBusy( 'ai' );
		setError( null );
		request( `templates/${ tpl.id }/ai`, { method: 'POST', data: ai } )
			.then( ( r ) => {
				setDraft( r );
				onUsage();
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	return (
		<Drawer
			open
			onClose={ onClose }
			size="lg"
			title={ tpl.label }
			description={ tpl.audience === 'admin' ? __( 'Sent to the business email and the staff member.', 'dev3-ai-booking-appointments' ) : __( 'Sent to the customer.', 'dev3-ai-booking-appointments' ) }
			footer={
				<>
					{ tpl.customized && (
						<Button variant="ghost" onClick={ () => save( { reset: true } ).catch( () => {} ) }>
							{ __( 'Reset to default', 'dev3-ai-booking-appointments' ) }
						</Button>
					) }
					<Button variant="primary" loading={ busy === 'save' } onClick={ () => save( { subject, body } ).catch( () => {} ) }>
						{ __( 'Save template', 'dev3-ai-booking-appointments' ) }
					</Button>
				</>
			}
		>
			<Stack gap={ 16 }>
				{ error && <ErrorNotice error={ error } onDismiss={ () => setError( null ) } /> }
				<Tabs
					value={ tab }
					onChange={ setTab }
					label={ __( 'Template', 'dev3-ai-booking-appointments' ) }
					tabs={ [
						{ id: 'edit', label: __( 'Edit', 'dev3-ai-booking-appointments' ) },
						{ id: 'preview', label: __( 'Preview', 'dev3-ai-booking-appointments' ) },
					] }
				/>
				{ tab === 'edit' && (
					<>
						<TextField label={ __( 'Subject', 'dev3-ai-booking-appointments' ) } value={ subject } onChange={ setSubject } />
						<Textarea label={ __( 'Message', 'dev3-ai-booking-appointments' ) } value={ body } onChange={ setBody } rows={ 9 } maxLength={ 5000 } help={ tpl.required.length ? sprintf( /* translators: %s: placeholders. */ __( 'Required: %s', 'dev3-ai-booking-appointments' ), tpl.required.map( ( r ) => `{${ r }}` ).join( ', ' ) ) : '' } />
						<div className="dev3bk-chips" aria-label={ __( 'Insert a placeholder', 'dev3-ai-booking-appointments' ) } role="group">
							{ placeholders.map( ( ph ) => (
								<button key={ ph } type="button" className="dev3bk-chip" onClick={ () => insert( ph ) }>
									{ `{${ ph }}` }
								</button>
							) ) }
						</div>
						<Card tone="ai" title={ __( 'Write with DEV3 AI', 'dev3-ai-booking-appointments' ) } description={ __( 'Get fresh copy that keeps the placeholders. You review it; nothing is sent until you approve it.', 'dev3-ai-booking-appointments' ) }>
							<div className="dev3bk-formrow">
								<Select
									label={ __( 'Tone', 'dev3-ai-booking-appointments' ) }
									value={ ai.tone }
									onChange={ ( tone ) => setAi( { ...ai, tone } ) }
									options={ [
										{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-booking-appointments' ) },
										{ value: 'professional', label: __( 'Professional', 'dev3-ai-booking-appointments' ) },
										{ value: 'warm', label: __( 'Warm', 'dev3-ai-booking-appointments' ) },
										{ value: 'concise', label: __( 'Concise', 'dev3-ai-booking-appointments' ) },
										{ value: 'playful', label: __( 'Playful', 'dev3-ai-booking-appointments' ) },
										{ value: 'luxury', label: __( 'Luxury', 'dev3-ai-booking-appointments' ) },
									] }
								/>
								<Select
									label={ __( 'Length', 'dev3-ai-booking-appointments' ) }
									value={ ai.length }
									onChange={ ( length ) => setAi( { ...ai, length } ) }
									options={ [
										{ value: 'short', label: __( 'Short', 'dev3-ai-booking-appointments' ) },
										{ value: 'medium', label: __( 'Medium', 'dev3-ai-booking-appointments' ) },
										{ value: 'long', label: __( 'Long', 'dev3-ai-booking-appointments' ) },
									] }
								/>
								<TextField label={ __( 'Language', 'dev3-ai-booking-appointments' ) } value={ ai.language } onChange={ ( language ) => setAi( { ...ai, language } ) } placeholder={ __( 'Site language', 'dev3-ai-booking-appointments' ) } />
							</div>
							<TextField label={ __( 'Extra instructions (optional)', 'dev3-ai-booking-appointments' ) } value={ ai.instructions } onChange={ ( instructions ) => setAi( { ...ai, instructions } ) } placeholder={ __( 'e.g. mention free parking, ask to arrive 5 minutes early', 'dev3-ai-booking-appointments' ) } />
							<div style={ { marginTop: 12 } }>
								<AiButton loading={ busy === 'ai' } onClick={ write } />
							</div>
							{ draft && (
								<div style={ { marginTop: 16 } }>
									{ draft.missing.length > 0 && <ErrorNotice error={ { code: 'missing', message: sprintf( /* translators: %s: placeholders. */ __( 'The draft is missing %s. Add it before approving.', 'dev3-ai-booking-appointments' ), draft.missing.map( ( m ) => `{${ m }}` ).join( ', ' ) ) } } /> }
									<AiDraftReview
										fields={ [
											{ key: 'subject', label: __( 'Subject', 'dev3-ai-booking-appointments' ), maxLength: 200 },
											{ key: 'body', label: __( 'Message', 'dev3-ai-booking-appointments' ), multiline: true, maxLength: 5000 },
										] }
										original={ { subject, body } }
										draft={ draft.draft }
										meta={ { model: draft.model, tokens: draft.tokens, cached: draft.cached } }
										acceptLabel={ __( 'Approve & save', 'dev3-ai-booking-appointments' ) }
										accepting={ busy === 'save' }
										regenerating={ busy === 'ai' }
										onRegenerate={ write }
										onReject={ () => setDraft( null ) }
										onAccept={ ( v ) => save( v, 'ai' ).catch( () => {} ) }
									/>
								</div>
							) }
						</Card>
					</>
				) }
				{ tab === 'preview' && (
					<>
						{ ! preview && <SkeletonCard lines={ 4 } /> }
						{ preview && (
							<>
								<p>
									<strong>{ __( 'Subject:', 'dev3-ai-booking-appointments' ) }</strong> { preview.subject }
								</p>
								<iframe className="dev3bk-preview" title={ __( 'Email preview', 'dev3-ai-booking-appointments' ) } srcDoc={ preview.html } sandbox="" />
							</>
						) }
						<div className="dev3bk-formrow">
							<TextField label={ __( 'Send a test to', 'dev3-ai-booking-appointments' ) } type="email" value={ to } onChange={ setTo } placeholder={ __( 'Your email', 'dev3-ai-booking-appointments' ) } help={ __( 'Sends the saved version with sample values.', 'dev3-ai-booking-appointments' ) } />
						</div>
						<Button
							variant="secondary"
							loading={ busy === 'test' }
							onClick={ () => {
								setBusy( 'test' );
								request( `templates/${ tpl.id }/test`, { method: 'POST', data: { to } } )
									.then( ( r ) => toast.success( sprintf( /* translators: %s: email. */ __( 'Test sent to %s.', 'dev3-ai-booking-appointments' ), r.sent ) ) )
									.catch( ( e ) => toast.error( e ) )
									.finally( () => setBusy( '' ) );
							} }
						>
							{ __( 'Send test email', 'dev3-ai-booking-appointments' ) }
						</Button>
					</>
				) }
			</Stack>
		</Drawer>
	);
}

export default function Notifications( { settings, save, onUsage } ) {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ timing, setTiming ] = useState( { reminder1_hours: settings.reminder1_hours, reminder2_hours: settings.reminder2_hours, followup_hours: settings.followup_hours, admin_notify: settings.admin_notify, staff_notify: settings.staff_notify } );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		request( 'templates' )
			.then( setData )
			.catch( setError );
	useEffect( () => {
		load();
	}, [] );

	const toggle = ( tpl, enabled ) =>
		request( `templates/${ tpl.id }`, { method: 'PUT', data: { enabled } } )
			.then( load )
			.catch( ( e ) => toast.error( e ) );

	const saveTiming = () => {
		setBusy( true );
		save( timing )
			.then( () => toast.success( __( 'Reminder settings saved.', 'dev3-ai-booking-appointments' ) ) )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<>
			<PageHeader title={ __( 'Notifications', 'dev3-ai-booking-appointments' ) } description={ __( 'Emails and reminders. Only the approved text you see here is ever sent.', 'dev3-ai-booking-appointments' ) } />
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			<Card
				title={ __( 'Reminder timing', 'dev3-ai-booking-appointments' ) }
				description={ __( 'Reminders go out once per booking. 0 turns one off. Bookings made inside a window skip that reminder.', 'dev3-ai-booking-appointments' ) }
				footer={
					<Button variant="primary" loading={ busy } onClick={ saveTiming }>
						{ __( 'Save timing', 'dev3-ai-booking-appointments' ) }
					</Button>
				}
			>
				<div className="dev3bk-formrow">
					<NumberField label={ __( 'Reminder 1 (hours before)', 'dev3-ai-booking-appointments' ) } value={ timing.reminder1_hours } onChange={ ( v ) => setTiming( { ...timing, reminder1_hours: v } ) } min={ 0 } max={ 336 } />
					<NumberField label={ __( 'Reminder 2 (hours before)', 'dev3-ai-booking-appointments' ) } value={ timing.reminder2_hours } onChange={ ( v ) => setTiming( { ...timing, reminder2_hours: v } ) } min={ 0 } max={ 336 } />
					<NumberField label={ __( 'Follow-up (hours after)', 'dev3-ai-booking-appointments' ) } value={ timing.followup_hours } onChange={ ( v ) => setTiming( { ...timing, followup_hours: v } ) } min={ 0 } max={ 336 } help={ __( 'Also turn on the follow-up email below.', 'dev3-ai-booking-appointments' ) } />
				</div>
				<div className="dev3bk-formrow">
					<Toggle label={ __( 'Email the business on new bookings', 'dev3-ai-booking-appointments' ) } checked={ timing.admin_notify } onChange={ ( v ) => setTiming( { ...timing, admin_notify: v } ) } />
					<Toggle label={ __( 'Email the staff member', 'dev3-ai-booking-appointments' ) } checked={ timing.staff_notify } onChange={ ( v ) => setTiming( { ...timing, staff_notify: v } ) } />
				</div>
			</Card>
			{ ! data && ! error && <SkeletonCard lines={ 6 } /> }
			{ data &&
				Object.keys( GROUPS ).map( ( g ) => (
					<Card key={ g } title={ GROUPS[ g ] } flush style={ { marginTop: 16 } }>
						<ul className="dev3bk-tpls">
							{ data.templates
								.filter( ( t ) => t.group === g )
								.map( ( t ) => (
									<li key={ t.id } className="dev3bk-tpls__row">
										<div className="dev3bk-tpls__main">
											<strong>{ t.label }</strong>
											<span className="dev3bk-muted">{ t.subject }</span>
										</div>
										<div className="dev3bk-tpls__side">
											{ t.source === 'ai' && <Badge tone="brand">{ __( 'DEV3 AI copy', 'dev3-ai-booking-appointments' ) }</Badge> }
											{ t.customized && t.source !== 'ai' && <Badge tone="info">{ __( 'Edited', 'dev3-ai-booking-appointments' ) }</Badge> }
											<Toggle label={ sprintf( /* translators: %s: email name. */ __( 'Send "%s"', 'dev3-ai-booking-appointments' ), t.label ) } checked={ t.enabled } onChange={ ( v ) => toggle( t, v ) } />
											<Button size="sm" variant="secondary" icon="edit" onClick={ () => setEditing( t ) }>
												{ __( 'Edit', 'dev3-ai-booking-appointments' ) }
											</Button>
										</div>
									</li>
								) ) }
						</ul>
					</Card>
				) ) }
			{ editing && (
				<TemplateEditor
					tpl={ editing }
					placeholders={ data.placeholders }
					onClose={ () => setEditing( null ) }
					onSaved={ ( t ) => {
						setEditing( t );
						load();
					} }
					onUsage={ onUsage }
				/>
			) }
		</>
	);
}
