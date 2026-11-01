import { client } from './api';
import { mountWidget } from './widget';
import { mountAccount } from './account';

/**
 * Mounts every booking widget and customer area on the page.
 *
 * @param {Window} win Window.
 */
export function boot( win ) {
	const cfg = win.dev3bkFront;
	if ( ! cfg || ! cfg.restUrl ) {
		return;
	}
	const request = client( cfg, win );
	win.document.querySelectorAll( '.dev3bk-widget:not([data-dev3bk-ready])' ).forEach( ( el ) => {
		el.setAttribute( 'data-dev3bk-ready', '1' );
		mountWidget( el, cfg, request, win );
	} );
	win.document.querySelectorAll( '.dev3bk-account:not([data-dev3bk-ready])' ).forEach( ( el ) => {
		el.setAttribute( 'data-dev3bk-ready', '1' );
		mountAccount( el, cfg, request, win );
	} );
}
