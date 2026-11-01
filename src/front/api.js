/**
 * Storefront REST client: handles plain permalinks (?rest_route=), sends the nonce for logged-in
 * customers and normalises errors to { code, message }.
 */

/**
 * Builds a URL under dev3bk/v1.
 *
 * @param {string} base  REST base.
 * @param {string} path  Path.
 * @param {Object} query Query params.
 * @return {string} URL.
 */
export function url( base, path, query = {} ) {
	let out = base + String( path ).replace( /^\//, '' );
	const parts = Object.keys( query )
		.filter( ( k ) => query[ k ] !== undefined && query[ k ] !== null && query[ k ] !== '' )
		.map( ( k ) => `${ encodeURIComponent( k ) }=${ encodeURIComponent( query[ k ] ) }` );
	if ( parts.length ) {
		out += ( out.includes( '?' ) ? '&' : '?' ) + parts.join( '&' );
	}
	return out;
}

/**
 * Creates a client.
 *
 * @param {Object} cfg     Boot config (restUrl, nonce, strings).
 * @param {Window} win     Window (fetch).
 * @return {Function} request( path, { method, query, data } ) → Promise.
 */
export function client( cfg, win ) {
	return function request( path, { method = 'GET', query = {}, data } = {} ) {
		const headers = { Accept: 'application/json' };
		if ( data !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		if ( cfg.nonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		return win
			.fetch( url( cfg.restUrl, path, query ), { method, headers, credentials: 'same-origin', body: data !== undefined ? JSON.stringify( data ) : undefined } )
			.then( ( res ) =>
				res
					.json()
					.catch( () => ( {} ) )
					.then( ( body ) => {
						if ( ! res.ok ) {
							throw { code: body.code || 'error', message: body.message || cfg.strings.error, status: res.status };
						}
						return body;
					} )
			)
			.catch( ( e ) => {
				throw e && e.message ? e : { code: 'network', message: cfg.strings.error };
			} );
	};
}
