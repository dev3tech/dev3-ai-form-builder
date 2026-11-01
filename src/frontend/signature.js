/**
 * Minimal signature pad (pointer events, HiDPI, touch). Writes a PNG data URL into the hidden input.
 *
 * @param {HTMLElement} wrap .dev3fb-signature element.
 * @param {Function}    onChange Called after each stroke / clear.
 * @return {Object} { clear, isEmpty }
 */
export function signaturePad( wrap, onChange = () => {} ) {
	const canvas = wrap.querySelector( 'canvas' );
	const input = wrap.querySelector( 'input[type="hidden"]' );
	const ctx = canvas.getContext ? canvas.getContext( '2d' ) : null;
	let drawing = false;
	let empty = true;
	let last = null;

	const resize = () => {
		if ( ! ctx ) {
			return;
		}
		const ratio = Math.max( 1, window.devicePixelRatio || 1 );
		const width = canvas.clientWidth || 600;
		const height = canvas.clientHeight || 180;
		const snapshot = ! empty ? canvas.toDataURL() : null;
		canvas.width = Math.round( width * ratio );
		canvas.height = Math.round( height * ratio );
		ctx.setTransform( ratio, 0, 0, ratio, 0, 0 );
		ctx.lineWidth = 2.2;
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		ctx.strokeStyle = getComputedStyle( wrap ).color || '#111';
		if ( snapshot ) {
			const img = new window.Image();
			img.onload = () => ctx.drawImage( img, 0, 0, width, height );
			img.src = snapshot;
		}
	};

	const point = ( e ) => {
		const rect = canvas.getBoundingClientRect();
		return { x: e.clientX - rect.left, y: e.clientY - rect.top };
	};

	const save = () => {
		input.value = empty ? '' : canvas.toDataURL( 'image/png' );
		onChange( ! empty );
	};

	const down = ( e ) => {
		if ( ! ctx ) {
			return;
		}
		e.preventDefault();
		drawing = true;
		last = point( e );
		if ( canvas.setPointerCapture && e.pointerId !== undefined ) {
			canvas.setPointerCapture( e.pointerId );
		}
		ctx.beginPath();
		ctx.arc( last.x, last.y, 1, 0, Math.PI * 2 );
		ctx.fill();
		empty = false;
	};
	const move = ( e ) => {
		if ( ! drawing ) {
			return;
		}
		e.preventDefault();
		const p = point( e );
		ctx.beginPath();
		ctx.moveTo( last.x, last.y );
		ctx.lineTo( p.x, p.y );
		ctx.stroke();
		last = p;
	};
	const up = () => {
		if ( drawing ) {
			drawing = false;
			save();
		}
	};

	canvas.addEventListener( 'pointerdown', down );
	canvas.addEventListener( 'pointermove', move );
	canvas.addEventListener( 'pointerup', up );
	canvas.addEventListener( 'pointercancel', up );
	canvas.addEventListener( 'pointerleave', up );
	canvas.style.touchAction = 'none';

	const clear = () => {
		if ( ctx ) {
			ctx.clearRect( 0, 0, canvas.width, canvas.height );
		}
		empty = true;
		save();
	};
	const btn = wrap.querySelector( '.dev3fb-signature-clear' );
	if ( btn ) {
		btn.addEventListener( 'click', clear );
	}
	resize();
	if ( window.ResizeObserver ) {
		let w = canvas.clientWidth;
		new window.ResizeObserver( () => {
			if ( canvas.clientWidth !== w ) {
				w = canvas.clientWidth;
				resize();
			}
		} ).observe( canvas );
	}
	return { clear, isEmpty: () => empty };
}
