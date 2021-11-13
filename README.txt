wikEdDiff - inline-style difference engine with block move support


System requirements:

This extension requires PHP 5.3+ and MediaWiki 1.17+.


Installation:

1. MediaWiki versions before 1.25 require a patch to core: replace the content of
  function createDifferenceEngine in includes/content/ContentHandler.php
  with the following code:

	// hook: get diff engine class name
	$diffEngineClass = '';
	if ( Hooks::run( 'GetDiffEngineClass', array( $context, &$diffEngineClass ) ) ) {
		// use default diff engine
		$diffEngineClass = $this->getDiffEngineClass();
	}
	return new $diffEngineClass( $context, $old, $new, $rcid, $refreshCache, $unhide );

2. Add the following code to /LocalSettings.php:

	# wikEdDiff: inline-style difference engine with block move support
	wfLoadExtension( 'WikEdDiff' );

3. Optionally add the following customization options to /LocalSettings.php (set to defaults):

	# Show complete un-clipped diff text (false)
	$wgWikEdDiffFullDiff = false;

	# Enable block move layout with highlighted blocks and marks at their original positions (true)
	$wgWikEdDiffShowBlockMoves = true;

	# Enable character-refined diff (true)
	$wgWikEdDiffCharDiff = true;

	# Enable repeated diff to resolve problematic sequences (true)
	$wgWikEdDiffRepeatedDiff = true;

	# Enable recursive diff to resolve problematic sequences (true)
	$wgWikEdDiffRecursiveDiff = true;

	# Maximum recursion depth (10)
	$wgWikEdDiffRecursionMax = 10;

	# Reject blocks if they are too short and their words are not unique,
	# prevents fragmentated diffs for very different versions (true)
	$wgWikEdDiffUnlinkBlocks = true;

	# Maximum number of rejection cycles (5)
	$wgWikEdDiffUnlinkMax = 5;

	# Reject blocks if shorter than this number of real words (3)
	$wgWikEdDiffBlockMinLength = 3;

	# Display blocks in differing colors (rainbow color scheme) (false)
	$wgWikEdDiffColoredBlocks = false;

	# Do not use UniCode block move marks (legacy browsers) (false)
	$wgWikEdDiffNoUnicodeSymbols = false;

	# Strip trailing newline off of texts (false)
	$wgWikEdDiffStripTrailingNewline = true;

	# Show debug infos and stats (block, group, and fragment data objects) in debug console (false)
	$wgWikEdDiffDebug = false;

	# Show timing results in debug console (false)
	$wgWikEdDiffTimer = false;

	# Run unit tests to prove correct working, display results in debug console (false)
	$wgWikEdDiffUnitTesting = false;


Change log:
____________________________________________________________________________________________________

Version 1.2.5 (July 20, 2014)

Bug fix:

- Patch to core is no longer required after integration of custom diff engines into MediaWiki core
  with new hook "GetDifferenceEngine", see:
  "Add hook for custom difference engine (WikEdDiff)" https://gerrit.wikimedia.org/r/#/c/166143/10

____________________________________________________________________________________________________

Version 1.2.4 (October 23, 2014)

Bug fix:

- Fixed word/chunk counts for unique token detection

Improvements:

- Disabled unlinking for simple texts with only short linked block lengths (i.e. test cases)

____________________________________________________________________________________________________

Version 1.2.3 (October 21, 2014)

Bug fix:

- Fixed no clipping at end of text

____________________________________________________________________________________________________

Version 1.2.2 (October 16, 2014)

Bug fixes:

- Fixed slide gaps
- Fixed rounding of timer results

Improvements:

- Added new line split for better code diffs and easier sentence split
- Added timers to block detection routines
- Freed memory for word count arrays

Various:

- Cleaned-up code

____________________________________________________________________________________________________

Version 1.2.1 (October 14, 2014)

Performance optimizations:

- Sped-up calculateDiff() with linked region borders array instead of cycling through tokens
- New option $wgWikEdDiffRepeatedDiff to turn off repeated diff refinement
- Freed memory by undefining arrays
- Optimized 'for' loops by precalculating termination values

Bug fixes:

- Fixed space highlighting breaking diff html
- Fixed 'sentence' split regExp
- Fixed sliding regExps
- Removed double container from 'No change' message
- Added UniCode support to clipDiffFragments() calculations

Various:

- Updated hook and hook handler to 'GenerateTextDiffBody'
- Fragment container from <div> to <pre> for newline copying
- Changed all comparison operators to strict
- Added new regExp 'blankOnlyToken' for testing unique tokens
- Removed HTML debug log
- Made JavaScript highlight handler compatibel with wikEd diff JavaScript library

____________________________________________________________________________________________________

Version 1.2.0 (October 09, 2014)

Initial release.

____________________________________________________________________________________________________
