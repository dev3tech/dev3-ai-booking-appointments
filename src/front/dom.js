/**
 * Tiny DOM helpers for the storefront (no framework, small bundle). Text is always set with
 * textContent, never innerHTML, so shopper and store text cannot inject markup.
 */

/**
 * Creates an element.
 *
 * @param {string} tag      Tag.
 * @param {Object} attrs    Attributes: class, text, on* handlers, booleans, style (object).
 * @param {...*}   children Nodes, strings, arrays; null/false skipped.
 * @return {HTMLElement} Element.
 */
export function h( tag, attrs = {}, ...children ) {
	const el = document.createElement( tag );
	Object.keys( attrs || {} ).forEach( ( key ) => {
		const value = attrs[ key ];
		if ( value === null || value === undefined || value === false ) {
			return;
		}
		if ( key === 'class' ) {
			el.className = value;
		} else if ( key === 'text' ) {
			el.textContent = value;
		} else if ( key === 'value' ) {
			el.value = value;
		} else if ( key === 'style' && typeof value === 'object' ) {
			Object.keys( value ).forEach( ( p ) => el.style.setProperty( p, value[ p ] ) );
		} else if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
			el.addEventListener( key.slice( 2 ).toLowerCase(), value );
		} else if ( value === true ) {
			el.setAttribute( key, '' );
		} else {
			el.setAttribute( key, String( value ) );
		}
	} );
	append( el, children );
	return el;
}

/**
 * Appends children.
 *
 * @param {HTMLElement} el       Parent.
 * @param {Array}       children Children.
 * @return {HTMLElement} Parent.
 */
export function append( el, children ) {
	children.flat( 3 ).forEach( ( child ) => {
		if ( child === null || child === undefined || child === false || child === '' ) {
			return;
		}
		el.appendChild( typeof child === 'string' || typeof child === 'number' ? document.createTextNode( String( child ) ) : child );
	} );
	return el;
}

/**
 * Replaces an element's content.
 *
 * @param {HTMLElement} el       Element.
 * @param {...*}        children Children.
 * @return {HTMLElement} Element.
 */
export function fill( el, ...children ) {
	while ( el.firstChild ) {
		el.removeChild( el.firstChild );
	}
	return append( el, children );
}

let uid = 0;
/**
 * Unique id.
 *
 * @param {string} base Base.
 * @return {string} Id.
 */
export const id = ( base = 'dev3bk' ) => `${ base }-${ ++uid }`;

/**
 * Inline SVG icon (decorative).
 *
 * @param {string} name check|left|right|clock|cal|user|spark|x|pin.
 * @return {SVGElement} Icon.
 */
export function icon( name ) {
	const paths = {
		check: 'M20 6 9 17l-5-5',
		left: 'm15 18-6-6 6-6',
		right: 'm9 18 6-6-6-6',
		clock: 'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
		cal: 'M8 3v4M16 3v4M3 10h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z',
		user: 'M20 21a8 8 0 1 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10z',
		spark: 'M12 3l1.9 5.6L19.5 10.5l-5.6 1.9L12 18l-1.9-5.6L4.5 10.5l5.6-1.9z',
		x: 'M18 6 6 18M6 6l12 12',
		pin: 'M12 21s-7-6.2-7-12a7 7 0 1 1 14 0c0 5.8-7 12-7 12zM12 11a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
		globe: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
	};
	const ns = 'http://www.w3.org/2000/svg';
	const svg = document.createElementNS( ns, 'svg' );
	svg.setAttribute( 'viewBox', '0 0 24 24' );
	svg.setAttribute( 'width', '20' );
	svg.setAttribute( 'height', '20' );
	svg.setAttribute( 'aria-hidden', 'true' );
	svg.setAttribute( 'focusable', 'false' );
	svg.setAttribute( 'class', 'dev3bk-ico' );
	const p = document.createElementNS( ns, 'path' );
	p.setAttribute( 'd', paths[ name ] || paths.check );
	p.setAttribute( 'fill', name === 'spark' ? 'currentColor' : 'none' );
	p.setAttribute( 'stroke', 'currentColor' );
	p.setAttribute( 'stroke-width', '2' );
	p.setAttribute( 'stroke-linecap', 'round' );
	p.setAttribute( 'stroke-linejoin', 'round' );
	svg.appendChild( p );
	return svg;
}
