/*
 * wikEdDiff: inline-style difference engine with block move support
 */

/*
 * Event handler for wikEdDiff.
 * Highlights corresponding block and mark elements on hover and jumps between on click.
 * Code for use in non-jQuery environments and legacy browsers (at least IE 8 compatible).
 *
 * @option Event|undefined event Browser event if available
 * @option element Node DOM node
 * @option type string Event type
 */
window.wikEdDiffBlockHandlerExt = function ( event, element, type ) {

	// IE compatibility
	if ( event === undefined && window.event !== undefined ) {
		event = window.event;
	}

	// Get mark/block elements
	const number = element.id.replace( /\D/g, '' ),
		block = document.getElementById( 'wikEdDiffBlockExt' + number ),
		mark = document.getElementById( 'wikEdDiffMarkExt' + number );
	if ( block === null || mark === null ) {
		return;
	}

	// Highlight corresponding mark/block pairs
	if ( type === 'mouseover' ) {
		element.onmouseover = null;
		element.onmouseout = function ( event ) {
			window.wikEdDiffBlockHandlerExt( event, element, 'mouseout' );
		};
		element.onclick = function ( event ) {
			window.wikEdDiffBlockHandlerExt( event, element, 'click' );
		};
		block.className += ' wikEdDiffBlockHighlight';
		mark.className += ' wikEdDiffMarkHighlight';
	}

	// Remove mark/block highlighting
	if ( type === 'mouseout' || type === 'click' ) {
		element.onmouseout = null;
		element.onmouseover = function ( event ) {
			window.wikEdDiffBlockHandlerExt( event, element, 'mouseover' );
		};

		// Reset, allow outside container (e.g. legend)
		if ( type !== 'click' ) {
			block.className = block.className.replace( / wikEdDiffBlockHighlight/g, '' );
			mark.className = mark.className.replace( / wikEdDiffMarkHighlight/g, '' );

			// GetElementsByClassName
			const container = document.getElementById( 'wikEdDiffContainer' );
			if ( container !== null ) {
				const spans = container.getElementsByTagName( 'span' ),
					spansLength = spans.length;
				for ( let i = 0; i < spansLength; i++ ) {
					if ( spans[ i ] !== block && spans[ i ] !== mark ) {
						if ( spans[ i ].className.includes( ' wikEdDiffBlockHighlight' ) ) {
							spans[ i ].className = spans[ i ].className.replace( / wikEdDiffBlockHighlight/g, '' );
						} else if ( spans[ i ].className.includes( ' wikEdDiffMarkHighlight' ) ) {
							spans[ i ].className = spans[ i ].className.replace( / wikEdDiffMarkHighlight/g, '' );
						}
					}
				}
			}
		}
	}

	// Scroll to corresponding mark/block element
	if ( type === 'click' ) {

		// Get corresponding element
		let corrElement;
		if ( element === block ) {
			corrElement = mark;
		} else {
			corrElement = block;
		}

		// Get element height (getOffsetTop)
		let corrElementPos = 0,
			node = corrElement;
		do {
			corrElementPos += node.offsetTop;
		} while ( ( node = node.offsetParent ) !== null );

		// Get scroll height
		let top;
		if ( window.pageYOffset !== undefined ) {
			top = window.pageYOffset;
		} else {
			top = document.documentElement.scrollTop;
		}

		// Get cursor pos
		let cursor;
		if ( event.pageY !== undefined ) {
			cursor = event.pageY;
		} else if ( event.clientY !== undefined ) {
			cursor = event.clientY + top;
		}

		// Get line height
		let line = 12;
		if ( window.getComputedStyle !== undefined ) {
			line = parseInt( window.getComputedStyle( corrElement ).getPropertyValue( 'line-height' ) );
		}

		// Scroll element under mouse cursor
		window.scroll( 0, corrElementPos + top - cursor + line / 2 );
	}
	return;
};
