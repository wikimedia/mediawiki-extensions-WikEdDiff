wikEdDiff: inline-style difference engine with block move support

This extension requires PHP 5.3+ and MediaWiki 1.17+.

Installation:

1. WikEdDiff requires a patch to the MediaWiki core to add the new hook "CustomDifferenceEngine".
Add the following code to /includes/diff/DifferenceEngine.php in function generateTextDiffBody
after "$ntext = str_replace( "\r\n", "\n", $ntext );":

	# Custom difference engine hook
	wfRunHooks( 'CustomDifferenceEngine', array( &$otext, &$ntext, &$diffText ) );
	if ( $diffText !== false ) {
		wfProfileOut( __METHOD__ );
		return $diffText;
	}

2. Add the following code to /LocalSettings.php:

	# wikEdDiff: inline-style difference engine with block move support
	require_once "$IP/extensions/WikEdDiff/WikEdDiff.php";

3. Optionally add the following customization options to /LocalSettings.php (set to defaults):

	# Show complete un-clipped diff text (false)
	$wgWikEdDiffFullDiff = false;

	# Enable block move layout with highlighted blocks and marks at their original positions (true)
	$wgWikEdDiffShowBlockMoves = true;

	# Enable character-refined diff (true)
	$wgWikEdDiffCharDiff = true;

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
