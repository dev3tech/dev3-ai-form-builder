import { initAll } from '../frontend/form';
import '../frontend/frontend.css';

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initAll );
} else {
	initAll();
}
// Forms injected later (popups, page builders' live preview).
window.dev3fbInit = initAll;
