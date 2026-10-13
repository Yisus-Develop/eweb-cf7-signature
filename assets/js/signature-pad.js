/**
 * EWEB CF7 Signature - dependency free signature pad.
 *
 * A modern, responsive HTML5 canvas signature pad written in vanilla ES6+
 * (no jQuery, no external library, no `var`). Pointers, pens, fingers and
 * styluses are supported through the Pointer Events API, and the strokes are
 * re-rendered from their data whenever the canvas is resized, so signatures
 * never get blurry or distorted on mobile devices.
 *
 * Public API:
 *   window.EwebSignaturePad.init(root)          -> (re)initialises a subtree.
 *   window.EwebSignaturePad.get(canvas)         -> SignaturePad instance.
 *   window.EwebSignaturePad.getByField(name)    -> SignaturePad instance.
 *   SignaturePad#clear() / #isEmpty() / #toDataURL() / #toJSON() / #fromJSON().
 *
 * @package EWEB_CF7_Signature
 */

( () => {
	'use strict';

	const FIELD_SELECTOR = '.eweb-sig__canvas';
	const WRAPPER_SELECTOR = '.eweb-sig';
	const STORAGE_KEY = '__ewebSignaturePad';

	const DEFAULTS = {
		minWidth: 0.7,
		maxWidth: 2.8,
		velocityFilterWeight: 0.7,
		penColor: '#111827',
		backgroundColor: '#ffffff',
		trim: true,
		padding: 12,
	};

	/**
	 * Returns a safe, finite number.
	 *
	 * @param {*} value     Raw value.
	 * @param {number} fallback Value used when the input is not usable.
	 * @return {number} Normalised number.
	 */
	const number = ( value, fallback ) => {
		const parsed = typeof value === 'number' ? value : Number.parseFloat( value );

		return Number.isFinite( parsed ) ? parsed : fallback;
	};

	/**
	 * Reads a CSS colour, falling back to a sane default.
	 *
	 * @param {string} value    Raw value coming from the DOM.
	 * @param {string} fallback Colour used when the input is not usable.
	 * @return {string} CSS colour.
	 */
	const colour = ( value, fallback ) => {
		if ( typeof value !== 'string' ) {
			return fallback;
		}

		const trimmed = value.trim();

		if ( trimmed === '' ) {
			return fallback;
		}

		return /^(#[0-9a-f]{3,8}|rgb[a]?\(|hsl[a]?\()/i.test( trimmed ) || trimmed === 'transparent'
			? trimmed
			: fallback;
	};

	/**
	 * Multiplies every coordinate of a stroke, keeping the drawing consistent
	 * with the backing store after a device pixel ratio change.
	 *
	 * @param {Array<Object>} points Stroke points.
	 * @param {number}        scale  Scale factor.
	 * @return {void}
	 */
	const scaleStroke = ( points, scale ) => {
		points.forEach( ( point ) => {
			point.x *= scale;
			point.y *= scale;
			point.w *= scale;
		} );
	};

	/**
	 * A single signature pad attached to one canvas element.
	 */
	class SignaturePad {
		/**
		 * @param {HTMLCanvasElement} canvas  Target canvas.
		 * @param {Object}            options Rendering options.
		 */
		constructor( canvas, options ) {
			this.canvas = canvas;
			this.wrapper = canvas.closest( WRAPPER_SELECTOR );
			this.options = Object.assign( {}, DEFAULTS, options );

			this.strokes = [];
			this.activeStroke = null;
			this.activeCursor = null;
			this.activePointer = null;
			this.lastVelocity = 0;
			this.lastWidth = number( this.options.minWidth, DEFAULTS.minWidth ) / 2;
			this.ratio = 1;
			this.listeners = {};

			this.baseWidth = Math.max( 1, number( canvas.getAttribute( 'width' ), 600 ) );
			this.baseHeight = Math.max( 1, number( canvas.getAttribute( 'height' ), 240 ) );

			this.context = canvas.getContext( '2d' );

			const host = this.wrapper || this.canvas.parentElement;

			this.input = host ? host.querySelector( '.eweb-sig__value' ) : null;

			this.clearButton = host
				? host.querySelector( '[data-eweb-sig="clear"]' )
				: null;

			if ( ! this.context ) {
				return;
			}

			this.bind();
			this.fit();
		}

		/**
		 * Registers every DOM listener of the pad.
		 *
		 * @return {void}
		 */
		bind() {
			this.onPointerDown = this.onPointerDown.bind( this );
			this.onPointerMove = this.onPointerMove.bind( this );
			this.onPointerUp = this.onPointerUp.bind( this );
			this.onContextMenu = ( event ) => event.preventDefault();
			this.onClear = () => this.clear();

			this.canvas.addEventListener( 'pointerdown', this.onPointerDown );
			this.canvas.addEventListener( 'pointermove', this.onPointerMove );
			this.canvas.addEventListener( 'pointerup', this.onPointerUp );
			this.canvas.addEventListener( 'pointercancel', this.onPointerUp );
			this.canvas.addEventListener( 'lostpointercapture', this.onPointerUp );
			this.canvas.addEventListener( 'contextmenu', this.onContextMenu );

			if ( this.clearButton ) {
				this.clearButton.addEventListener( 'click', this.onClear );
			}

			if ( typeof ResizeObserver !== 'undefined' ) {
				this.observer = new ResizeObserver( () => this.fit() );
				this.observer.observe( this.wrapper || this.canvas );
			} else {
				this.onWindowResize = () => this.fit();
				window.addEventListener( 'resize', this.onWindowResize, { passive: true } );
			}
		}

		/**
		 * Removes every listener so the pad can be garbage collected.
		 *
		 * @return {void}
		 */
		destroy() {
			this.canvas.removeEventListener( 'pointerdown', this.onPointerDown );
			this.canvas.removeEventListener( 'pointermove', this.onPointerMove );
			this.canvas.removeEventListener( 'pointerup', this.onPointerUp );
			this.canvas.removeEventListener( 'pointercancel', this.onPointerUp );
			this.canvas.removeEventListener( 'lostpointercapture', this.onPointerUp );
			this.canvas.removeEventListener( 'contextmenu', this.onContextMenu );

			if ( this.clearButton ) {
				this.clearButton.removeEventListener( 'click', this.onClear );
			}

			if ( this.observer ) {
				this.observer.disconnect();
			}

			if ( this.onWindowResize ) {
				window.removeEventListener( 'resize', this.onWindowResize );
			}
		}

		/**
		 * Matches the backing store to the displayed size, whatever the device
		 * pixel ratio, keeping the signature legible and crisp.
		 *
		 * @return {void}
		 */
		fit() {
			const bounds = this.canvas.getBoundingClientRect();
			const cssWidth = bounds.width > 0 ? bounds.width : this.baseWidth;
			const aspect = this.baseWidth / this.baseHeight;
			const deviceRatio = Math.min( 3, Math.max( 1, window.devicePixelRatio || 1 ) );
			const budget = ( this.baseWidth * 2 ) / cssWidth;
			const ratio = Math.max( 0.5, Math.min( deviceRatio, budget ) );

			let width = Math.max( 32, Math.round( cssWidth * ratio ) );
			let height = Math.max( 32, Math.round( width / aspect ) );

			const previousWidth = this.canvas.width || width;
			const scale = width / previousWidth;

			this.ratio = width / cssWidth;

			if ( width === this.canvas.width && height === this.canvas.height ) {
				return;
			}

			this.canvas.width = width;
			this.canvas.height = height;

			if ( scale !== 1 ) {
				this.strokes.forEach( ( stroke ) => scaleStroke( stroke, scale ) );
			}

			this.configure();
			this.redraw();
		}

		/**
		 * Applies the pen settings to the 2D context.
		 *
		 * @return {void}
		 */
		configure() {
			this.context.lineCap = 'round';
			this.context.lineJoin = 'round';
			this.context.strokeStyle = this.options.penColor;
			this.context.fillStyle = this.options.penColor;
		}

		/**
		 * Returns the position of an event in canvas coordinates.
		 *
		 * @param {PointerEvent} event Pointer event.
		 * @return {{x: number, y: number, time: number}} Point.
		 */
		pointFrom( event ) {
			const bounds = this.canvas.getBoundingClientRect();
			const scaleX = bounds.width > 0 ? this.canvas.width / bounds.width : 1;
			const scaleY = bounds.height > 0 ? this.canvas.height / bounds.height : 1;

			return {
				x: ( event.clientX - bounds.left ) * scaleX,
				y: ( event.clientY - bounds.top ) * scaleY,
				time: event.timeStamp,
			};
		}

		/**
		 * Starts a new stroke.
		 *
		 * @param {PointerEvent} event Pointer event.
		 * @return {void}
		 */
		onPointerDown( event ) {
			if ( this.activePointer !== null || ( event.button !== undefined && event.button !== 0 ) ) {
				return;
			}

			event.preventDefault();

			this.activePointer = event.pointerId;

			if ( typeof this.canvas.setPointerCapture === 'function' ) {
				this.canvas.setPointerCapture( event.pointerId );
			}

			const point = this.pointFrom( event );

			point.w = this.widthForPoint( point, null );

			this.activeStroke = [ point ];
			this.strokes.push( this.activeStroke );
			this.activeCursor = { x: point.x, y: point.y };
			this.lastVelocity = 0;
			this.lastWidth = point.w;

			this.drawDot( point );
			this.updateEmptyState();
			this.emit( 'begin', { stroke: this.activeStroke } );
		}

		/**
		 * Extends the current stroke.
		 *
		 * @param {PointerEvent} event Pointer event.
		 * @return {void}
		 */
		onPointerMove( event ) {
			if ( this.activePointer !== event.pointerId || ! this.activeStroke ) {
				return;
			}

			event.preventDefault();

			const point = this.pointFrom( event );
			const previous = this.activeStroke[ this.activeStroke.length - 1 ];

			// Ignore duplicates and micro movements, they only add noise.
			if ( Math.abs( point.x - previous.x ) < 0.7 && Math.abs( point.y - previous.y ) < 0.7 ) {
				return;
			}

			point.w = this.widthForPoint( point, previous );

			this.activeStroke.push( point );
			this.advanceCursor( previous, point );
		}

		/**
		 * Ends the current stroke and publishes the result.
		 *
		 * @param {PointerEvent} event Pointer event.
		 * @return {void}
		 */
		onPointerUp( event ) {
			if ( this.activePointer !== event.pointerId || ! this.activeStroke ) {
				return;
			}

			const stroke = this.activeStroke;
			const last = stroke[ stroke.length - 1 ];

			// A duplicated closing point makes the stroke reach the exact spot
			// where the pointer was released, even after a redraw.
			if ( stroke.length > 1 ) {
				const closing = { x: last.x, y: last.y, time: last.time + 1, w: last.w };

				stroke.push( closing );
				this.advanceCursor( last, closing );
			}

			this.activePointer = null;
			this.activeStroke = null;
			this.activeCursor = null;

			this.syncInput();
			this.emit( 'end', { stroke: stroke } );
		}

		/**
		 * Computes the pen width for a point, using a filtered velocity so the
		 * stroke thins out on fast movements and stays steady on slow ones.
		 *
		 * @param {{x: number, y: number, time: number}} point    Current point.
		 * @param {{x: number, y: number, time: number}|null} previous Previous point.
		 * @return {number} Width in canvas pixels.
		 */
		widthForPoint( point, previous ) {
			const minWidth = number( this.options.minWidth, DEFAULTS.minWidth );
			const maxWidth = number( this.options.maxWidth, DEFAULTS.maxWidth );
			const weight = number( this.options.velocityFilterWeight, DEFAULTS.velocityFilterWeight );

			let velocity = 0;

			if ( previous ) {
				const ratio = this.ratio > 0 ? this.ratio : 1;
				const distance = Math.sqrt( ( point.x - previous.x ) ** 2 + ( point.y - previous.y ) ** 2 ) / ratio;
				const elapsed = Math.max( 1, point.time - previous.time );

				velocity = distance / elapsed;
			}

			this.lastVelocity = weight * velocity + ( 1 - weight ) * this.lastVelocity;

			const target = maxWidth / ( this.lastVelocity + 1 );

			this.lastWidth = weight * target + ( 1 - weight ) * this.lastWidth;

			return Math.max( minWidth, Math.min( maxWidth, this.lastWidth ) );
		}

		/**
		 * Draws the segment that links two points of the current stroke.
		 *
		 * @param {Object} previous Previous point.
		 * @param {Object} point    Current point.
		 * @return {void}
		 */
		advanceCursor( previous, point ) {
			const context = this.context;
			const cursor = this.activeCursor || { x: previous.x, y: previous.y };
			const middle = {
				x: ( previous.x + point.x ) / 2,
				y: ( previous.y + point.y ) / 2,
			};

			context.beginPath();
			context.lineWidth = point.w;
			context.moveTo( cursor.x, cursor.y );
			context.quadraticCurveTo( previous.x, previous.y, middle.x, middle.y );
			context.stroke();

			this.activeCursor = middle;
		}

		/**
		 * Draws an isolated point as a filled dot.
		 *
		 * @param {Object} point Point to draw.
		 * @return {void}
		 */
		drawDot( point ) {
			this.context.beginPath();
			this.context.arc( point.x, point.y, Math.max( 0.5, point.w / 2 ), 0, Math.PI * 2, false );
			this.context.fill();
		}

		/**
		 * Replays every stored stroke, producing exactly the same drawing as the
		 * incremental rendering.
		 *
		 * @return {void}
		 */
		redraw() {
			this.context.clearRect( 0, 0, this.canvas.width, this.canvas.height );
			this.configure();

			this.strokes.forEach( ( stroke ) => {
				const savedCursor = this.activeCursor;

				this.activeCursor = null;

				if ( stroke.length === 1 ) {
					this.drawDot( stroke[ 0 ] );
				}

				for ( let index = 1; index < stroke.length; index++ ) {
					this.advanceCursor( stroke[ index - 1 ], stroke[ index ] );
				}

				this.activeCursor = savedCursor;
			} );

			this.updateEmptyState();
		}

		/**
		 * Tells whether the pad is empty.
		 *
		 * @return {boolean} True when nothing has been drawn.
		 */
		isEmpty() {
			return this.strokes.length === 0;
		}

		/**
		 * Removes every stroke and resets the hidden input.
		 *
		 * @return {void}
		 */
		clear() {
			this.strokes = [];
			this.activeStroke = null;
			this.activeCursor = null;
			this.activePointer = null;

			this.context.clearRect( 0, 0, this.canvas.width, this.canvas.height );

			this.syncInput();
			this.updateEmptyState();
			this.emit( 'clear', {} );
		}

		/**
		 * Exports the signature as a PNG data URI, cropping the empty margins and
		 * painting the background so the image stays readable on dark e-mails.
		 *
		 * @return {string} `data:image/png;base64,...`
		 */
		toDataURL() {
			return this.exportCanvas().toDataURL( 'image/png' );
		}

		/**
		 * Serialises the strokes, so a signature can be restored later.
		 *
		 * @return {Object} Serialisable state.
		 */
		toJSON() {
			return {
				ratio: this.ratio,
				width: this.canvas.width,
				height: this.canvas.height,
				strokes: this.strokes.map( ( stroke ) => stroke.map( ( point ) => ( {
					x: point.x,
					y: point.y,
					time: point.time,
					w: point.w,
				} ) ) ),
			};
		}

		/**
		 * Restores a serialised signature.
		 *
		 * @param {Object} state State produced by `toJSON()`.
		 * @return {void}
		 */
		fromJSON( state ) {
			if ( ! state || ! Array.isArray( state.strokes ) ) {
				return;
			}

			const scale = state.width > 0 ? this.canvas.width / state.width : 1;

			this.strokes = state.strokes
				.filter( ( stroke ) => Array.isArray( stroke ) && stroke.length > 0 )
				.map( ( stroke ) => stroke.map( ( point ) => ( {
					x: number( point.x, 0 ) * scale,
					y: number( point.y, 0 ) * scale,
					time: number( point.time, 0 ),
					w: Math.max( 0.1, number( point.w, 1 ) * scale ),
				} ) ) );

			this.redraw();
			this.syncInput();
		}

		/**
		 * Builds the canvas that will be exported.
		 *
		 * @return {HTMLCanvasElement} Export canvas.
		 */
		exportCanvas() {
			const source = this.options.trim ? this.trimmedCanvas() : this.canvas;
			const output = document.createElement( 'canvas' );
			const background = colour( this.options.backgroundColor, DEFAULTS.backgroundColor );

			output.width = source.width;
			output.height = source.height;

			const context = output.getContext( '2d' );

			if ( context ) {
				if ( background !== 'transparent' ) {
					context.fillStyle = background;
					context.fillRect( 0, 0, output.width, output.height );
				}

				context.drawImage( source, 0, 0 );
			}

			return output;
		}

		/**
		 * Crops the transparent margins of the canvas.
		 *
		 * @return {HTMLCanvasElement} Cropped canvas, or the original one.
		 */
		trimmedCanvas() {
			const width = this.canvas.width;
			const height = this.canvas.height;

			if ( width < 1 || height < 1 ) {
				return this.canvas;
			}

			let pixels;

			try {
				pixels = this.context.getImageData( 0, 0, width, height ).data;
			} catch ( error ) {
				return this.canvas;
			}

			let top = -1;
			let bottom = -1;
			let left = width;
			let right = -1;

			for ( let y = 0; y < height; y++ ) {
				const row = y * width;

				for ( let x = 0; x < width; x++ ) {
					if ( pixels[ ( row + x ) * 4 + 3 ] === 0 ) {
						continue;
					}

					if ( top === -1 ) {
						top = y;
					}

					bottom = y;
					left = Math.min( left, x );
					right = Math.max( right, x );
				}
			}

			if ( top === -1 || right === -1 ) {
				return this.canvas;
			}

			const padding = Math.round( number( this.options.padding, DEFAULTS.padding ) * this.ratio );
			const startX = Math.max( 0, left - padding );
			const startY = Math.max( 0, top - padding );
			const endX = Math.min( width, right + padding + 1 );
			const endY = Math.min( height, bottom + padding + 1 );
			const cropped = document.createElement( 'canvas' );

			cropped.width = Math.max( 1, endX - startX );
			cropped.height = Math.max( 1, endY - startY );

			const context = cropped.getContext( '2d' );

			if ( context ) {
				context.drawImage(
					this.canvas,
					startX,
					startY,
					cropped.width,
					cropped.height,
					0,
					0,
					cropped.width,
					cropped.height
				);
			}

			return cropped;
		}

		/**
		 * Copies the current signature into the hidden input CF7 submits.
		 *
		 * @return {void}
		 */
		syncInput() {
			if ( ! this.input ) {
				return;
			}

			const value = this.isEmpty() ? '' : this.toDataURL();

			if ( this.input.value === value ) {
				return;
			}

			this.input.value = value;
			this.input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		/**
		 * Shows or hides the "sign here" placeholder.
		 *
		 * @return {void}
		 */
		updateEmptyState() {
			if ( this.wrapper ) {
				this.wrapper.classList.toggle( 'is-empty', this.isEmpty() );
			}
		}

		/**
		 * Registers a listener.
		 *
		 * @param {string}   name     Event name.
		 * @param {Function} callback Callback.
		 * @return {void}
		 */
		on( name, callback ) {
			if ( typeof callback !== 'function' ) {
				return;
			}

			if ( ! this.listeners[ name ] ) {
				this.listeners[ name ] = [];
			}

			this.listeners[ name ].push( callback );
		}

		/**
		 * Dispatches an event.
		 *
		 * @param {string} name  Event name.
		 * @param {Object} detail Event payload.
		 * @return {void}
		 */
		emit( name, detail ) {
			( this.listeners[ name ] || [] ).forEach( ( callback ) => callback( detail, this ) );
		}
	}

	/**
	 * Instances created so far, keyed by canvas element.
	 *
	 * @type {WeakMap<HTMLCanvasElement, SignaturePad>}
	 */
	const instances = new WeakMap();

	/**
	 * Initialises every pad that is not initialised yet.
	 *
	 * @param {Document|Element} root Subtree to scan.
	 * @return {Array<SignaturePad>} Created instances.
	 */
	const init = ( root ) => {
		const scope = root || document;
		const created = [];

		scope.querySelectorAll( FIELD_SELECTOR ).forEach( ( canvas ) => {
			if ( instances.has( canvas ) ) {
				return;
			}

			const options = {
				penColor: colour( canvas.dataset.ewebPen, DEFAULTS.penColor ),
				backgroundColor: colour( canvas.dataset.ewebBg, DEFAULTS.backgroundColor ),
				maxWidth: number( canvas.dataset.ewebPenwidth, DEFAULTS.maxWidth ),
				minWidth: number( canvas.dataset.ewebPenwidth, DEFAULTS.maxWidth ) * 0.35,
				trim: canvas.dataset.ewebTrim !== 'false',
			};

			const pad = new SignaturePad( canvas, options );

			instances.set( canvas, pad );
			created.push( pad );

			canvas[ STORAGE_KEY ] = pad;
		} );

		return created;
	};

	/**
	 * Returns the pad attached to a canvas.
	 *
	 * @param {HTMLCanvasElement} canvas Canvas element.
	 * @return {SignaturePad|undefined} Instance, if any.
	 */
	const get = ( canvas ) => instances.get( canvas );

	/**
	 * Returns the pad of a form field.
	 *
	 * @param {string} name Field name.
	 * @param {Document|Element} root Subtree to scan.
	 * @return {SignaturePad|undefined} Instance, if any.
	 */
	const getByField = ( name, root ) => {
		const scope = root || document;
		const canvas = scope.querySelector( FIELD_SELECTOR + '[data-eweb-field="' + name + '"]' );

		return canvas ? instances.get( canvas ) : undefined;
	};

	/**
	 * Clears the pads of a form.
	 *
	 * @param {Element} form Form element.
	 * @return {void}
	 */
	const reset = ( form ) => {
		if ( ! form || typeof form.querySelectorAll !== 'function' ) {
			return;
		}

		form.querySelectorAll( FIELD_SELECTOR ).forEach( ( canvas ) => {
			const pad = instances.get( canvas );

			if ( pad ) {
				pad.clear();
			}
		} );
	};

	window.EwebSignaturePad = { SignaturePad, init, get, getByField, reset };

	// Initialise as soon as the DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => init( document ), { once: true } );
	} else {
		init( document );
	}

	// Contact Form 7 replaces form markup on submit and after a successful send.
	[ 'wpcf7submit', 'wpcf7mailsent', 'wpcf7mailfailed', 'wpcf7invalid' ].forEach( ( eventName ) => {
		document.addEventListener(
			eventName,
			( event ) => {
				init( document );

				if ( eventName === 'wpcf7mailsent' ) {
					reset( event.target );
				}
			},
			false
		);
	} );
} )();
