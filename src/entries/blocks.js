import { registerBlockType } from '@wordpress/blocks';
import { createElement, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const box = { border: '1px dashed #c3c4c7', borderRadius: 8, padding: 24, textAlign: 'center', background: '#fff' };

function useCatalog() {
	const [ data, setData ] = useState( { services: [], staff: [] } );
	useEffect( () => {
		apiFetch( { path: '/dev3bk/v1/public/catalog' } )
			.then( setData )
			.catch( () => {} );
	}, [] );
	return data;
}

registerBlockType( 'dev3bk/booking-form', {
	apiVersion: 2,
	title: __( 'Booking form', 'dev3-ai-booking-appointments' ),
	description: __( 'A mobile-friendly, multi-step appointment booking form.', 'dev3-ai-booking-appointments' ),
	category: 'widgets',
	icon: 'calendar-alt',
	keywords: [ __( 'appointment', 'dev3-ai-booking-appointments' ), __( 'booking', 'dev3-ai-booking-appointments' ), __( 'schedule', 'dev3-ai-booking-appointments' ) ],
	attributes: {
		service: { type: 'number', default: 0 },
		staff: { type: 'number', default: 0 },
		category: { type: 'string', default: '' },
	},
	supports: { html: false, align: [ 'wide', 'full' ] },
	edit: function Edit( { attributes, setAttributes } ) {
		const props = useBlockProps();
		const catalog = useCatalog();
		const service = catalog.services.find( ( s ) => s.id === attributes.service );
		return createElement(
			'div',
			props,
			createElement(
				InspectorControls,
				null,
				createElement(
					PanelBody,
					{ title: __( 'Booking options', 'dev3-ai-booking-appointments' ) },
					createElement( SelectControl, {
						label: __( 'Service', 'dev3-ai-booking-appointments' ),
						value: String( attributes.service ),
						options: [ { value: '0', label: __( 'Let the customer choose', 'dev3-ai-booking-appointments' ) }, ...catalog.services.map( ( s ) => ( { value: String( s.id ), label: s.name } ) ) ],
						onChange: ( v ) => setAttributes( { service: Number( v ) } ),
					} ),
					createElement( SelectControl, {
						label: __( 'Staff', 'dev3-ai-booking-appointments' ),
						value: String( attributes.staff ),
						options: [ { value: '0', label: __( 'Let the customer choose', 'dev3-ai-booking-appointments' ) }, ...catalog.staff.map( ( s ) => ( { value: String( s.id ), label: s.name } ) ) ],
						onChange: ( v ) => setAttributes( { staff: Number( v ) } ),
					} ),
					createElement( TextControl, {
						label: __( 'Only this category', 'dev3-ai-booking-appointments' ),
						value: attributes.category,
						onChange: ( v ) => setAttributes( { category: v } ),
					} )
				)
			),
			createElement(
				'div',
				{ style: box },
				createElement( 'strong', null, __( 'Booking form', 'dev3-ai-booking-appointments' ) ),
				createElement(
					'p',
					{ style: { margin: '8px 0 0' } },
					service ? service.name : __( 'Customers pick a service, staff member, date and time, then confirm. Preview the page to try it.', 'dev3-ai-booking-appointments' )
				)
			)
		);
	},
	save: () => null,
} );

registerBlockType( 'dev3bk/my-bookings', {
	apiVersion: 2,
	title: __( 'My bookings', 'dev3-ai-booking-appointments' ),
	description: __( 'Customers view, reschedule and cancel their appointments.', 'dev3-ai-booking-appointments' ),
	category: 'widgets',
	icon: 'id',
	supports: { html: false, align: [ 'wide' ] },
	edit: function Edit() {
		const props = useBlockProps();
		return createElement(
			'div',
			props,
			createElement(
				'div',
				{ style: box },
				createElement( 'strong', null, __( 'My bookings', 'dev3-ai-booking-appointments' ) ),
				createElement( 'p', { style: { margin: '8px 0 0' } }, __( 'Logged-in customers see their bookings; emailed links open a single booking.', 'dev3-ai-booking-appointments' ) )
			)
		);
	},
	save: () => null,
} );
