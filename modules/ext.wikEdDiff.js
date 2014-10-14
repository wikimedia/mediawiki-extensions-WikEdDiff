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
	var number = element.id.replace( /\D/g, '' );
	var block = document.getElementById( 'wikEdDiffBlockExt' + number );
	var mark = document.getElementById( 'wikEdDiffMarkExt' + number );
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
			var container = document.getElementById( 'wikEdDiffContainer' );
			if ( container !== null ) {
				var spans = container.getElementsByTagName( 'span' );
				var spansLength = spans.length;
				for ( var i = 0; i < spansLength; i ++ ) {
					if ( spans[i] !== block && spans[i] !== mark ) {
						if ( spans[i].className.indexOf( ' wikEdDiffBlockHighlight' ) !== -1 ) {
							spans[i].className = spans[i].className.replace( / wikEdDiffBlockHighlight/g, '' );
						}
						else if ( spans[i].className.indexOf( ' wikEdDiffMarkHighlight') !== -1 ) {
							spans[i].className = spans[i].className.replace( / wikEdDiffMarkHighlight/g, '' );
						}
					}
				}
			}
		}
	}

	// Scroll to corresponding mark/block element
	if ( type === 'click' ) {

		// Get corresponding element
		var corrElement;
		if ( element === block ) {
			corrElement = mark;
		}
		else {
			corrElement = block;
		}

		// Get element height (getOffsetTop)
		var corrElementPos = 0;
		var node = corrElement;
		do {
			corrElementPos += node.offsetTop;
		} while ( ( node = node.offsetParent ) !== null );

		// Get scroll height
		var top;
		if ( window.pageYOffset !== undefined ) {
			top = window.pageYOffset;
		}
		else {
			top = document.documentElement.scrollTop;
		}

		// Get cursor pos
		var cursor;
		if ( event.pageY !== undefined ) {
			cursor = event.pageY;
		}
		else if ( event.clientY !== undefined ) {
			cursor = event.clientY + top;
		}

		// Get line height
		var line = 12;
		if ( window.getComputedStyle !== undefined ) {
			line = parseInt( window.getComputedStyle( corrElement ).getPropertyValue( 'line-height' ) );
		}

		// Scroll element under mouse cursor
		window.scroll( 0, corrElementPos + top - cursor + line / 2 );
	}
	return;
};
