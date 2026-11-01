/**
 * Saves text as a file in the browser.
 *
 * @param {string} filename File name.
 * @param {string} text     Content.
 * @param {string} type     MIME type.
 */
export function download( filename, text, type = 'text/csv;charset=utf-8' ) {
	const blob = new window.Blob( [ text ], { type } );
	const url = window.URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();
	setTimeout( () => {
		window.URL.revokeObjectURL( url );
		a.remove();
	}, 0 );
}
