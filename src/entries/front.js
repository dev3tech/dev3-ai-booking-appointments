import { boot } from '../front/app';
import '../front/front.css';

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => boot( window ) );
} else {
	boot( window );
}
