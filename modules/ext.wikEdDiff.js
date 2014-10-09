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
window.wikEdDiffBlockHandler = function (event, element, type) {

	// IE compatibility
	if ( ( event === undefined ) && ( window.event !== undefined ) ) {
		event = window.event;
	}

	// get mark/block elements
	var number = element.id.replace( /\D/g, '' );
	var block = document.getElementById( 'wikEdDiffBlock' + number );
	var mark = document.getElementById( 'wikEdDiffMark' + number );
	if ( mark === null ) {
		return;
	}

	// highlight corresponding mark/block pairs
	if ( type == 'mouseover' ) {
		element.onmouseover = null;
		element.onmouseout = function ( event ) {
			window.wikEdDiffBlockHandler( event, element, 'mouseout' );
		};
		element.onclick = function ( event ) {
			window.wikEdDiffBlockHandler( event, element, 'click' );
		};
		block.className += ' wikEdDiffBlockHighlight';
		mark.className += ' wikEdDiffMarkHighlight';
	}

	// remove mark/block highlighting
	if ( ( type == 'mouseout' ) || ( type == 'click' ) ) {
		element.onmouseout = null;
		element.onmouseover = function ( event ) {
			window.wikEdDiffBlockHandler( event, element, 'mouseover' );
		};

		// reset, allow outside container (e.g. legend)
		if ( type != 'click' ) {
			block.className = block.className.replace( /wikEdDiffBlockHighlight/g, '' );
			mark.className = mark.className.replace( /wikEdDiffMarkHighlight/g, '' );

			// getElementsByClassName
			var container = document.getElementById( 'wikEdDiffContainer' );
			if ( container !== null ) {
				var spans = container.getElementsByTagName( 'span' );
				for ( var i = 0; i < spans.length; i ++ ) {
					if ( ( spans[i] != block ) && ( spans[i] != mark ) ) {
						if ( spans[i].className.indexOf( ' wikEdDiffBlockHighlight' ) != -1 ) {
							spans[i].className = spans[i].className.replace( / wikEdDiffBlockHighlight/g, '' );
						}
						else if ( spans[i].className.indexOf( ' wikEdDiffMarkHighlight' ) != -1 ) {
							spans[i].className = spans[i].className.replace( / wikEdDiffMarkHighlight/g, '' );
						}
					}
				}
			}
		}
	}

	// scroll to corresponding mark/block element
	if ( type == 'click' ) {

		// get corresponding element
		var corrElement;
		if ( element == block ) {
			corrElement = mark;
		}
		else {
			corrElement = block;
		}

		// get element height (getOffsetTop )
		var corrElementPos = 0;
		var node = corrElement;
		do {
			corrElementPos += node.offsetTop;
		} while ( ( node = node.offsetParent ) !== null );

		// get scroll height
		var top;
		if ( window.pageYOffset !== undefined ) {
			top = window.pageYOffset;
		}
		else {
			top = document.documentElement.scrollTop;
		}

		// get cursor pos
		var cursor;
		if ( event.pageY !== undefined ) {
			cursor = event.pageY;
		}
		else if ( event.clientY !== undefined ) {
			cursor = event.clientY + top;
		}

		// get line height
		var line = 12;
		if ( window.getComputedStyle !== undefined ) {
			line = parseInt( window.getComputedStyle( corrElement ).getPropertyValue( 'line-height' ) );
		}

		// scroll element under mouse cursor
		window.scroll( 0, corrElementPos + top - cursor + line / 2 );
	}
	return;
};
