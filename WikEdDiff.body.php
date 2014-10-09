<?php
/**
 * @version 1.2.0
 * @date October 09, 2014
 *
 * wikEdDiff: inline-style difference engine with block move support
 *
 * WikEdDiff.php and the JavaScript library wikEd diff are synced one-to-one ports. Changes and
 * fixes are to be applied to both versions.
 *
 * JavaScript library (mirror): https://en.wikipedia.org/wiki/User:Cacycle/diff
 * JavaScript online tool: http://cacycle.altervista.org/wikEd-diff-tool.html
 * MediaWiki extension: https://www.mediawiki.org/wiki/Extension:wikEdDiff
 *
 * This difference engine applies a word-based algorithm that uses unique words as anchor points to
 * identify matching text and moved blocks (Paul Heckel: A technique for isolating differences
 * between files. Communications of the ACM 21(4):264 (1978)).
 *
 * Additional features:
 *
 * - Visual inline style, changes are shown in a single output text
 * - Block move detection and highlighting
 * - Resolution down to characters level
 * - Unicode and multilingual support
 * - Stepwise split (paragraphs, sentences, words, characters)
 * - Recursive diff
 * - Optimized code for resolving unmatched sequences
 * - Minimization of length of moved blocks
 * - Alignment of ambiguous unmatched sequences to next line break or word border
 * - Clipping of unchanged irrelevant parts from the output (optional)
 * - Fully customizable
 * - Text split optimized for MediaWiki source texts
 * - Well commented and documented code
 *
 * Datastructures (abbreviations from publication):
 *
 * class WikEdDiffText:  diff text object (new or old version)
 * -> text                 text
 * -> $words[]             word count table
 * -> $first               index of first token in tokens list
 * -> $last                index of last token in tokens list
 *
 * -> tokens[]:          token list for new or old string (doubly-linked list) (N and O)
 *   => prev               previous list item
 *   => next               next list item
 *   => token              token string
 *   => link               index of corresponding token in new or old text (OA and NA)
 *   => number             list enumeration number
 *   => unique             token is unique word in text
 *
 * class WikEdDiff:      diff object
 * -> config[]:            configuration settings, see top of code for customization options
 *    => regExp              all regular expressions
 *        => split             regular expressions used for splitting text into tokens
 *    => htmlCode            HTML code fragments used for creating the output
 * -> newText              new text
 * -> oldText              old text
 * -> html                 diff html
 * -> error                  flag: result has not passed unit tests
 *
 * $symbols:             object for symbols table data
 * => token[]              hash table of parsed tokens for passes 1 - 3, points to symbol[i]
 * => symbol[]:            array of objects that hold token counters and pointers:
 *   => newCount             new text token counter (NC)
 *   => oldCount             old text token counter (OC)
 *   => newToken             token index in text.newText.tokens
 *   => oldToken             token index in text.oldText.tokens
 * => linked               flag: at least one unique token pair has been linked
 *
 * -> blocks[]:          array, block data (consecutive text tokens) in new text order
 *   => oldBlock           number of block in old text order
 *   => newBlock           number of block in new text order
 *   => oldNumber          old text token number of first token
 *   => newNumber          new text token number of first token
 *   => oldStart           old text token index of first token
 *   => count              number of tokens
 *   => unique             contains unique matched token
 *   => words              word count
 *   => chars              char length
 *   => type               '=', '-', '+', '|' (same, deletion, insertion, mark)
 *   => section            section number
 *   => group              group number of block
 *   => fixed              belongs to a fixed (not moved) group
 *   => moved              moved block group number corresponding with mark block
 *   => text               text of block tokens
 *
 * -> sections[]:        array, block sections with no block move crosses outside a section
 *   => blockStart         first block in section
 *   => blockEnd           last block in section

 * -> groups[]:          array, section blocks that are consecutive in old text order
 *   => oldNumber          first block oldNumber
 *   => blockStart         first block index
 *   => blockEnd           last block index
 *   => unique             contains unique matched token
 *   => maxWords           word count of longest block
 *   => words              word count
 *   => chars              char count
 *   => fixed              not moved from original position
 *   => movedFrom          group position this group has been moved from
 *   => color              color number of moved group
 *
 * -> fragments[]:       diff fragment list ready for markup, abstraction layer for customization
 *   => text               block or mark text
 *   => color              moved block or mark color number
 *   => type               '=', '-', '+'   same, deletion, insertion
 *                         '<', '>'        mark left, mark right
 *                         '(<', '(>', ')' block start and end
 *                         '~', ' ~', '~ ' omission indicators
 *                         '[', ']', ','   fragment start and end, fragment separator
 *                         '{', '}'        container start and end
 *
 * @file
 * @ingroup DifferenceEngine
 * @ingroup wikEdDiff
 * @author Cacycle (https://en.wikipedia.org/wiki/User:Cacycle)
 */


/**
 * wikEd diff main class
 *
 * @class WikEdDiff
 * @ingroup DifferenceEngine
 * @ingroup wikEdDiff
 */
class WikEdDiff extends DifferenceEngine {


	/**
	 * Integration into DifferenceEngine through new hook CustomDifferenceEngine
	 *
	 * Add the following code to DifferenceEngine.php in function generateTextDiffBody after
	 *   "$ntext = str_replace( "\r\n", "\n", $ntext );":
	 *
	 * @code
	 *
	 * # Custom difference engine hook
	 * wfRunHooks( 'CustomDifferenceEngine', array( &$otext, &$ntext, &$diffText ) );
	 * if ( $diffText !== false ) {
	 *   wfProfileOut( __METHOD__ );
	 *   return $diffText;
	 * }
	 *
	 * @endcode
	 *
	 * @param[in/out] string $newText, $otext New an old text versions
	 * @param[in/out] string|bool $diffText Diff html result or false
	 */
	public static function onCustomDifferenceEngine ( &$otext, &$ntext, &$diffText ) {

		global $wgContLang, $wgOut;

		// load js and css
		$wgOut->addModules( 'ext.wikEdDiff' );

		$wikEdDiff = new WikEdDiff();
		$otext = $wgContLang->segmentForDiff( $otext );
		$ntext = $wgContLang->segmentForDiff( $ntext );
		$diffText = $wgContLang->unsegmentForDiff( $wikEdDiff->diff( $otext, $ntext ) );
	}

	/** @var array $config Configuration and customization settings */
	protected $config = array(

		/** Core diff settings (with default values) */

		/**
		 * @var bool $config['fullDiff']
		 *   Show complete un-clipped diff text (false)
		 */
		'fullDiff' => false,

		/**
		 * @var bool $config['showBlockMoves']
		 *   Enable block move layout with highlighted blocks and marks at their original positions (true)
		 */
		'showBlockMoves' => true,

		/**
		 * @var bool $config['charDiff']
		 *   Enable character-refined diff (true)
		 */
		'charDiff' => true,

		/**
		 * @var bool $config['recursiveDiff']
		 *   Enable recursive diff to resolve problematic sequences (true)
		 */
		'recursiveDiff' => true,

		/**
		 * @var int $config['recursionMax']
		 *   Maximum recursion depth (10)
		 */
		'recursionMax' => 10,

		/**
		 * @var bool $config['unlinkBlocks']
		 *   Reject blocks if they are too short and their words are not unique,
		 *   prevents fragmentated diffs for very different versions (true)
		 */
		'unlinkBlocks' => true,

		/**
		 * @var int $config['unlinkMax']
		 *   Maximum number of rejection cycles (5)
		 */
		'unlinkMax' => 5,

		/**
		 * @var int $config['blockMinLength']
		 *   Reject blocks if shorter than this number of real words (3)
		 */
		'blockMinLength' => 3,

		/**
		 * @var bool $config['coloredBlocks']
		 *   Display blocks in differing colors (rainbow color scheme) (false)
		 */
		'coloredBlocks' => false,

		/**
		 * @var bool $config['coloredBlocks']
		 *   Do not use UniCode block move marks (legacy browsers) (false)
		 */
		'noUnicodeSymbols' => false,

		/**
		 * @var bool $config['stripTrailingNewline']
		 *   Strip trailing newline off of texts (true in .js, false in .php)
		 */
		'stripTrailingNewline' => false,

		/**
		 * @var bool $config['debug']
		 *   Show debug infos and stats (block, group, and fragment data objects) in debug console (false)
		 */
		'debug' => false,

		/**
		 * @var bool $config['timer']
		 *   Show timing results in debug console (false)
		 */
		'timer' => false,

		/**
		 * @var bool $config['unitTesting']
		 *   Run unit tests to prove correct working, display results in debug console (false)
		 */
		'unitTesting' => false,

		/** RegExp character classes */

		// UniCode letters
		'regExpLetters' => '\pL\pN',

		// New line characters without and with \n and \r
		'regExpNewLines' => '\x{0085}\x{2028}',
		'regExpNewLinesAll' => '\n\r\x{0085}\x{2028}',

		// Breaking white space characters without \n, \r, and \f
		'regExpBlanks' => ' \t\x{0b}\x{2000}-\x{200b}\x{202f}\x{205f}\x{3000}',

		// Full stops without '.'
		'regExpFullStops' =>
			'\x{0589}\x{06D4}\x{0701}\x{0702}\x{0964}\x{0df4}\x{1362}\x{166e}\x{1803}\x{1809}\x{2cf9}\x{2cfe}\x{2e3c}\x{3002}\x{a4ff}\x{a60e}\x{a6f3}\x{fe52}\x{ff0e}\x{ff61}',

		// New paragraph characters without \n and \r
		'regExpNewParagraph' => '\f\x{2029}',

		// Exclamation marks without '!'
		'regExpExclamationMarks' =>
			'\x{01c3}\x{01c3}\x{01c3}\x{055c}\x{055c}\x{07f9}\x{1944}\x{1944}\x{203c}\x{203c}\x{2048}\x{2048}\x{fe15}\x{fe57}\x{ff01}',

		// Question marks without '?'
		'regExpQuestionMarks' =>
			'\x{037e}\x{055e}\x{061f}\x{1367}\x{1945}\x{2047}\x{2049}\x{2cfa}\x{2cfb}\x{2e2e}\x{a60f}\x{a6f7}\x{fe56}\x{ff1f}',

		/** Clip settings */

		// Find clip position: characters from right
		// (heading, paragraph, line break, blanks, or characters)
		'clipHeadingLeft'      => 1500,
		'clipParagraphLeftMax' => 1500,
		'clipParagraphLeftMin' =>  500,
		'clipLineLeftMax'      => 1000,
		'clipLineLeftMin'      =>  500,
		'clipBlankLeftMax'     => 1000,
		'clipBlankLeftMin'     =>  500,
		'clipCharsLeft'        =>  500,

		// Find clip position: characters from right
		// (heading, paragraph, line break, blanks, or characters)
		'clipHeadingRight'      => 1500,
		'clipParagraphRightMax' => 1500,
		'clipParagraphRightMin' =>  500,
		'clipLineRightMax'      => 1000,
		'clipLineRightMin'      =>  500,
		'clipBlankRightMax'     => 1000,
		'clipBlankRightMin'     =>  500,
		'clipCharsRight'        =>  500,

		// Maximum number of lines to search for clip position
		'clipLinesRightMax' => 10,
		'clipLinesLeftMax' => 10,

		// Skip clipping if ranges are too close
		'clipSkipLines' => 5,
		'clipSkipChars' => 1000,
	);

	/** Internal data structures */

	/** @var WikEdDiffText $newText New text version object with text and token list */
	protected $newText = array();

	/** @var WikEdDiffText $oldText Old text version object with text and token list */
	protected $oldText = array();

	/** @var array $blocks Block data (consecutive text tokens) in new text order */
	protected $blocks = array();

	/** @var array $groups Section blocks that are consecutive in old text order */
	protected $groups = array();

	/** @var array $sections Block sections with no block move crosses outside a section */
	protected $sections = array();

	/** @var array $timer Debug timer array: string 'label' => float PHP microtime */
	protected $timer = array();

	/** @var array $recursionTimer Debug timer for time spent in recursion levels in milliseconds */
	protected $recursionTimer = array();

	/** Output data */

	/** @var bool $error Unit tests have detected a diff error */
	public $error = false;

	/** @var array $fragments Diff fragment list for markup, abstraction layer for customization */
	public $fragments = array();

	/** @var string $html Html code of diff */
	public $html = null;


	/**
	 * Constructor, initialize settings
	 * @param[in] mixed $wgWikEd... LocalSettings configuration variables
	 * @param[out] array $config Settings
	 */
	public function __construct() {

		global $wgWikEdDiffFullDiff,
			$wgWikEdDiffShowBlockMoves,
			$wgWikEdDiffCharDiff,
			$wgWikEdDiffRecursiveDiff,
			$wgWikEdDiffRecursionMax,
			$wgWikEdDiffUnlinkBlocks,
			$wgWikEdDiffUnlinkMax,
			$wgWikEdDiffBlockMinLength,
			$wgWikEdDiffColoredBlocks,
			$wgWikEdDiffNoUnicodeSymbols,
			$wgWikEdDiffStripTrailingNewline,
			$wgWikEdDiffDebug,
			$wgWikEdDiffTimer,
			$wgWikEdDiffUnitTesting;

		/** Add $wgWikEd... settings to configuration settings */
		if ( $wgWikEdDiffFullDiff !== null ) {
			$this->config['fullDiff'] = $wgWikEdDiffFullDiff;
		}
		if ( $wgWikEdDiffShowBlockMoves !== null ) {
			$this->config['showBlockMoves'] = $wgWikEdDiffShowBlockMoves;
		}
		if ( $wgWikEdDiffCharDiff !== null ) {
			$this->config['charDiff'] = $wgWikEdDiffCharDiff ;
		}
		if ( $wgWikEdDiffRecursiveDiff !== null ) {
			$this->config['recursiveDiff'] = $wgWikEdDiffRecursiveDiff;
		}
		if ( $wgWikEdDiffRecursionMax !== null ) {
			$this->config['recursionMax'] = $wgWikEdDiffRecursionMax;
		}
		if ( $wgWikEdDiffUnlinkBlocks !== null ) {
			$this->config['unlinkBlocks'] = $wgWikEdDiffUnlinkBlocks;
		}
		if ( $wgWikEdDiffUnlinkMax !== null ) {
			$this->config['unlinkMax'] = $wgWikEdDiffUnlinkMax;
		}
		if ( $wgWikEdDiffBlockMinLength !== null ) {
			$this->config['blockMinLength'] = $wgWikEdDiffBlockMinLength;
		}
		if ( $wgWikEdDiffColoredBlocks !== null ) {
			$this->config['coloredBlocks'] = $wgWikEdDiffColoredBlocks;
		}
		if ( $wgWikEdDiffNoUnicodeSymbols !== null ) {
			$this->config['noUnicodeSymbols'] = $wgWikEdDiffNoUnicodeSymbols;
		}
		if ( $wgWikEdDiffStripTrailingNewline !== null ) {
			$this->config['stripTrailingNewline'] = $wgWikEdDiffStripTrailingNewline;
		}
		if ( $wgWikEdDiffDebug !== null ) {
			$this->config['debug'] = $wgWikEdDiffDebug;
		}
		if ( $wgWikEdDiffTimer !== null ) {
			$this->config['timer'] = $wgWikEdDiffTimer;
		}
		if ( $wgWikEdDiffUnitTesting !== null ) {
			$this->config['unitTesting'] = $wgWikEdDiffUnitTesting;
		}

		/** Add regular expressions to configuration settings */

		$this->config['regExp'] = array(

			// RegExps for splitting text
			'split' => array(

				// Split into paragraphs, after double newlines
				'paragraph' => '/.*?((\r\n|\n|\r){2,}|[' . $this->config['regExpNewParagraph'] . '])+/su',

				// Split into sentences, after .space and newlines
				'sentence' =>
					'/[^' .
					$this->config['regExpNewLinesAll'] .
					']*?([.!?;' .
					$this->config['regExpFullStops'] .
					$this->config['regExpExclamationMarks'] .
					$this->config['regExpQuestionMarks'] .
					']+[' . $this->config['regExpBlanks'] .
					']+)?([' . $this->config['regExpNewLines'] .
					']|\r\n|\n|\r)/u',

				// Split into inline chunks
				'chunk' =>
					'/\[\[[^\[\]\n]+\]\]|' .    // [[wiki link]]
					'\{\{[^\{\}\n]+\}\}|' .     // {{template}}
					'\[[^\[\]\n]+\]|' .         // [ext. link]
					'<\/?[^<>\[\]\{\}\n]+>|' .  // <html>
					'\[\[[^\[\]\|\n]+\]\]\||' . // [[wiki link|
					'\{\{[^\{\}\|\n]+\||' .     // {{template|
					'\b((https?:|)\/\/)[^\x{00}-\x{20}\s\"\[\]\x{7f}]+/u', // url

				// Split into words, multi-char markup, and chars
				'word' => '/[' .
					$this->config['regExpLetters'] .
					']+([\'’_]?[' .
					$this->config['regExpLetters'] .
					']+)*|\[\[|\]\]|\{\{|\}\}|&\w+;|\'\'\'|\'\'|==+|\{\||\|\}|\|-|./u',

				// Split into chars
				'character' => '/./u',
			),

			// RegExps for sliding gaps: newlines and space/word breaks
			'slideStop' =>
				'/[' .
				$this->config['regExpBlanks'] .
				$this->config['regExpNewLinesAll'] .
				$this->config['regExpNewParagraph'] .
				']$/u',
			'slideBorder' =>
				'/[ \t' .
				$this->config['regExpNewLinesAll'] .
				$this->config['regExpNewParagraph'] .
				'\x{0C}\x{0b}]$/u',

			// RegExps for counting words
			'countWords' =>
				'/[' .
				$this->config['regExpLetters'] .
				']+([\'’_]?[' .
				$this->config['regExpLetters'] .
				']+)*/u',
			'countChunks' =>
				'/\[\[[^\[\]\n]+\]\]|' .    // [[wiki link]]
				'\{\{[^\{\}\n]+\}\}|' .     // {{template}}
				'\[[^\[\]\n]+\]|' .         // [ext. link]
				'<\/?[^<>\[\]\{\}\n]+>|' .  // <html>
				'\[\[[^\[\]\|\n]+\]\]\||' . // [[wiki link|
				'\{\{[^\{\}\|\n]+\||' .     // {{template|
				'\b((https?:|)\/\/)[^\x{00}-\x{20}\s\"\[\]\x{7f}]+/u', // url

			// RegExp detecting blank-only and single-char blocks
			'blankBlock' => '/^([^\t\S]+|[^\t])$/u',

			// RegExps for clipping
			'clipLine' =>
				'/[' .
				$this->config['regExpNewLinesAll'] .
				$this->config['regExpNewParagraph'] .
				']+/u',
			'clipHeading' => '/(^|\n)(==+.+?==+|\{\||\|\}).*?(?=\n|$)/u',
			'clipParagraph' => '/((\r\n|\n|\r){2,}|[' .
				$this->config['regExpNewParagraph'] .
				'])/u',
			'clipBlank' => '/[' . $this->config['regExpBlanks'] . ']+/u',
			'clipTrimNewLinesLeft' =>
				'/[' .
				$this->config['regExpNewLinesAll'] .
				$this->config['regExpNewParagraph'] .
				']+$/u',
			'clipTrimNewLinesRight' =>
				'/^[' .
				$this->config['regExpNewLinesAll'] .
				$this->config['regExpNewParagraph'] .
				']+/u',
			'clipTrimBlanksLeft' =>
				'/[' .
				$this->config['regExpBlanks'] .
				$this->config['regExpNewLinesAll'] .
				$this->config['regExpNewParagraph'] .
				']+$/u',
			'clipTrimBlanksRight' =>
				'/^[' .
				$this->config['regExpBlanks'] .
				$this->config['regExpNewLinesAll'] .
				$this->config['regExpNewParagraph'] .
				']+/u'
		);

	/**
	 * Add output html fragments to configuration settings
	 *   Dynamic replacements:
	 *     {number}: class/color/block/mark/id number
	 *     {title}: title attribute (popup)
	 *     {nounicode}: noUnicodeSymbols fallback
	 */
		$this->config['htmlCode'] = array(
			'noChangeStart' =>
				'<div class="wikEdDiffNoChange" title="' .
				wfMessage( 'wiked-diff-same' )->escaped() .
				'">',
			'noChangeEnd' => '</div>',

			'mediaWikiTableStart' =>
				'<tr class="wikEdDiffTableRow">' .
				'<td class="wikEdDiffTableCell" colspan="4">',
			'mediaWikiTableEnd' => '</td></tr>',

			'containerStart' => '<div class="wikEdDiffContainer" id="wikEdDiffContainer">',
			'containerEnd' => '</div>',

			'fragmentStart' => '<div class="wikEdDiffFragment" style="white-space: pre-wrap;">',
			'fragmentEnd' => '</div>',
			'separator' => '<div class="wikEdDiffSeparator"></div>',

			'insertStart' =>
				'<span class="wikEdDiffInsert" title="' .
				wfMessage( 'wiked-diff-ins' )->escaped() .
				'">',
			'insertStartBlank' =>
				'<span class="wikEdDiffInsert wikEdDiffInsertBlank" title="' .
				wfMessage( 'wiked-diff-ins' )->escaped() .
				'">',
			'insertEnd' => '</span>',

			'deleteStart' =>
				'<span class="wikEdDiffDelete" title="' .
				wfMessage( 'wiked-diff-del' )->escaped() .
				'">',
			'deleteStartBlank' =>
				'<span class="wikEdDiffDelete wikEdDiffDeleteBlank" title="' .
				wfMessage( 'wiked-diff-del' )->escaped() .
				'">',
			'deleteEnd' => '</span>',

			'blockStart' =>
				'<span class="wikEdDiffBlock" title="{title}" id="wikEdDiffBlock{number}"' .
				'onmouseover="wikEdDiffBlockHandler(undefined, this, \'mouseover\');">',
			'blockColoredStart' =>
				'<span class="wikEdDiffBlock wikEdDiffBlock wikEdDiffBlock{number}"' .
				'title="{title}" id="wikEdDiffBlock{number}"' .
				'onmouseover="wikEdDiffBlockHandler(undefined, this, \'mouseover\');">',
			'blockEnd' => '</span>',

			'markLeft' =>
				'<span class="wikEdDiffMarkLeft{nounicode}" title="{title}" id="wikEdDiffMark{number}"' .
				'onmouseover="wikEdDiffBlockHandler(undefined, this, \'mouseover\');"></span>',
			'markLeftColored' =>
				'<span class="wikEdDiffMarkLeft{nounicode} wikEdDiffMark wikEdDiffMark{number}"' .
				'title="{title}" id="wikEdDiffMark{number}"' .
				'onmouseover="wikEdDiffBlockHandler(undefined, this, \'mouseover\');"></span>',

			'markRight' =>
				'<span class="wikEdDiffMarkRight{nounicode}" title="{title}" id="wikEdDiffMark{number}"' .
				'onmouseover="wikEdDiffBlockHandler(undefined, this, \'mouseover\');"></span>',
			'markRightColored' =>
				'<span class="wikEdDiffMarkRight{nounicode} wikEdDiffMark wikEdDiffMark{number}"
				title="{title}" id="wikEdDiffMark{number}"' .
				'onmouseover="wikEdDiffBlockHandler(undefined, this, \'mouseover\');"></span>',

			'newline' => "<span class=\"wikEdDiffNewline\">\n</span>",
			'tab' => "<span class=\"wikEdDiffTab\"><span class=\"wikEdDiffTabSymbol\"></span>\t</span>",
			'space' => '<span class="wikEdDiffSpace"><span class="wikEdDiffSpaceSymbol"></span> </span>',

			'omittedChars' => '<span class="wikEdDiffOmittedChars">…</span>',

			'errorStart' =>
				'<div class="wikEdDiffError" title="' . wfMessage( 'wiked-diff-error' )->escaped() . '">',
			'errorEnd' => '</div>'
		);
	}


	/**
	 * Main diff method
	 *
	 * @param string $oldString Old text version
	 * @param string $newString New text version
	 * @param[out] array $fragment Diff fragment list ready for markup, abstraction layer for customized diffs
	 * @param[out] string $html Html code of diff
	 * @return string Html code of diff
	 */
	public function diff ( &$oldString, &$newString ) {

		// Start total timer
		if ( $this->config['timer'] === true ) {
			$this->time( 'total' );
		}

		// Start diff timer
		if ( $this->config['timer'] === true ) {
			$this->time( 'diff' );
		}

		// Reset error flag
		$this->error = false;

		// Load version strings into WikEdDiffText objects
		$this->newText = new WikEdDiffText( $newString, $this );
		$this->oldText = new WikEdDiffText( $oldString, $this );

		// Trap trivial changes: no change
		if ( $this->newText->text == $this->oldText->text ) {
			$this->html =
				$this->config['htmlCode']['containerStart'] .
				$this->config['htmlCode']['fragmentStart'] .
				$this->config['htmlCode']['noChangeStart'] .
				wfMessage( 'wiked-diff-empty' )->escaped() .
				$this->config['htmlCode']['noChangeEnd'] .
				$this->config['htmlCode']['fragmentEnd'] .
				$this->config['htmlCode']['containerEnd'];

			// Pack into MediaWiki diff table row
			$this->tableDiffFormatter();
			return $this->html;
		}

		// Trap trivial changes: old text deleted
		if (
			$this->oldText->text === '' || (
				$this->oldText->text == "\n" &&
				substr( $this->newText->text, strlen( $this->newText->text ) - 1 ) == "\n"
			)
		) {
			$this->html =
				$this->config['htmlCode']['containerStart'] .
				$this->config['htmlCode']['fragmentStart'] .
				$this->config['htmlCode']['insertStart'] .
				$this->htmlEscape( $this->newText->text ) .
				$this->config['htmlCode']['insertEnd'] .
				$this->config['htmlCode']['fragmentEnd'] .
				$this->config['htmlCode']['containerEnd'];

			// Pack into MediaWiki diff table row
			$this->tableDiffFormatter();
			return $this->html;
		}

		// Trap trivial changes: new text deleted
		if (
			$this->newText->text === '' || (
				$this->newText->text == "\n" &&
				substr( $this->oldText->text, strlen( $this->oldText->text ) - 1 ) == "\n"
			)
		) {
			$this->html =
				$this->config['htmlCode']['containerStart'] .
				$this->config['htmlCode']['fragmentStart'] .
				$this->config['htmlCode']['deleteStart'] .
				$this->htmlEscape( $this->oldText->text ) .
				$this->config['htmlCode']['deleteEnd'] .
				$this->config['htmlCode']['fragmentEnd'] .
				$this->config['htmlCode']['containerEnd'];

			// Pack into MediaWiki diff table row
			$this->tableDiffFormatter();
			return $this->html;
		}

		// New symbols object
		$symbols = array(
			'token' => array(),
			'hashTable' => array(),
			'linked' => false
		);

		// Split new and old text into paragraps
		$this->newText->splitText( 'paragraph' );
		$this->oldText->splitText( 'paragraph' );

		// Calculate diff
		$this->calculateDiff( $symbols, 'paragraph' );

		// Refine different paragraphs into sentences
		$this->newText->splitRefine( 'sentence' );
		$this->oldText->splitRefine( 'sentence' );

		// Calculate refined diff
		$this->calculateDiff( $symbols, 'sentence' );

		// Refine different paragraphs into chunks
		if ( $this->config['timer'] === true ) {
			$this->time( 'chunk split' );
		}
		$this->newText->splitRefine( 'chunk' );
		$this->oldText->splitRefine( 'chunk' );
		if ( $this->config['timer'] === true ) {
			$this->timeEnd( 'chunk split' );
		}

		// Calculate refined diff
		$this->calculateDiff( $symbols, 'chunk' );

		// Refine different sentences into words
		if ( $this->config['timer'] === true ) {
			$this->time( 'word split' );
		}
		$this->newText->splitRefine( 'word' );
		$this->oldText->splitRefine( 'word' );
		if ( $this->config['timer'] === true ) {
			$this->timeEnd( 'word split' );
		}

		// Calculate refined diff information with recursion for unresolved gaps
		$this->calculateDiff( $symbols, 'word', false, true );

		// Slide gaps
		if ( $this->config['timer'] === true ) {
			$this->time( 'word slide' );
		}
		$this->slideGaps( $this->newText, $this->oldText );
		$this->slideGaps( $this->oldText, $this->newText );
		if ( $this->config['timer'] === true ) {
			$this->timeEnd( 'word slide' );
		}

		// Split tokens
		if ( $this->config['charDiff'] === true ) {

			// Split tokens into chars in selected unresolved gaps
			if ( $this->config['timer'] === true ) {
				$this->time( 'character split' );
			}
			$this->splitRefineChars();
			if ( $this->config['timer'] === true ) {
				$this->timeEnd( 'character split' );
			}

			// Calculate refined diff information with recursion for unresolved gaps
			$this->calculateDiff( $symbols, 'character', false, true );

			// Slide gaps
			if ( $this->config['timer'] === true ) {
				$this->time( 'character slide' );
			}
			$this->slideGaps( $this->newText, $this->oldText );
			$this->slideGaps( $this->oldText, $this->newText );
			if ( $this->config['timer'] === true ) {
				$this->timeEnd( 'character slide' );
			}
		}

		// Enumerate token lists
		$this->newText->enumerateTokens();
		$this->oldText->enumerateTokens();

		// Detect moved blocks
		if ( $this->config['timer'] === true ) {
			$this->time( 'blocks' );
		}
		$this->detectBlocks();
		if ( $this->config['timer'] === true ) {
			$this->timeEnd( 'blocks' );
		}

		// Assemble blocks into fragment table
		$this->getDiffFragments();

		// Stop diff timer
		if ( $this->config['timer'] === true ) {
			$this->timeEnd( 'diff' );
		}

		// Unit tests
		if ( $this->config['unitTesting'] === true ) {

			// Test diff to test consistency between input and output
			if ( $this->config['timer'] === true ) {
				$this->time( 'unit tests' );
			}
			$this->unitTests();
			if ( $this->config['timer'] === true ) {
				$this->timeEnd( 'unit tests' );
			}
		}

		// Clipping
		if ( $this->config['fullDiff'] === false ) {

			// Clipping unchanged sections from unmoved block text
			if ( $this->config['timer'] === true ) {
				$this->time( 'clip' );
			}
			$this->clipDiffFragments();
			if ( $this->config['timer'] === true ) {
				$this->timeEnd( 'clip' );
			}
		}

		// Create html formatted diff code from diff fragments
		if ( $this->config['timer'] === true ) {
			$this->time( 'html' );
		}
		$this->getDiffHtml();
		if ( $this->config['timer'] === true ) {
			$this->timeEnd( 'html' );
		}

		// No change
		if ( $this->html === '' ) {
			$this->html =
				$this->config['htmlCode']['containerStart'] .
				$this->config['htmlCode']['fragmentStart'] .
				$this->config['htmlCode']['noChangeStart'] .
				wfMessage( 'wiked-diff-same' )->escaped() .
				$this->config['htmlCode']['noChangeEnd'] .
				$this->config['htmlCode']['fragmentEnd'] .
				$this->config['htmlCode']['containerEnd'];
		}

		// Add error indicator
		if ( $this->error === true ) {
			$this->html = $this->config['htmlCode']['errorStart'] . $this->html . $this->config['htmlCode']['errorEnd'];
		}

		// Pack into MediaWiki diff table row
		$this->tableDiffFormatter();

		// Stop total timer
		if ( $this->config['timer'] === true ) {
			$this->timeEnd( 'total' );
		}

		// Debug log
		if ( $this->config['debug'] === true ) {
			$this->debug( 'HTML', $this->html );
		}

		return $this->html;
	}


	/**
	 * Pack diff html code into MediaWiki diff table row
	 *
	 * @param[in/out] string $html Html code of diff
	 */
	private function tableDiffFormatter() {

		$this->html =
			$this->config['htmlCode']['mediaWikiTableStart'] .
			$this->html .
			$this->config['htmlCode']['mediaWikiTableEnd'];
	}


	/**
	 * Split tokens into chars in the following unresolved regions (gaps):
	 *   - One token became connected or separated by space or dash (or any token)
	 *   - Same number of tokens in gap and strong similarity of all tokens:
	 *     - Addition or deletion of flanking strings in tokens
	 *     - Addition or deletion of internal string in tokens
	 *     - Same length and at least 50 % identity
	 *     - Same start or end, same text longer than different text
	 * Identical tokens including space separators will be linked,
	 *   resulting in word-wise char-level diffs
	 *
	 * @param[in/out] WikEdDiffText $newText, $oldText Text object tokens list
	 */
	private function splitRefineChars() {

		/** Find corresponding gaps */

		// Cycle trough new text tokens list
		$gaps = array();
		$gap = null;
		$i = $this->newText->first;
		$j = $this->oldText->first;
		while ( $i !== null && isset( $this->newText->tokens[$i] ) ) {

			// Get token links
			$newLink = $this->newText->tokens[$i]['link'];
			$oldLink = null;
			if ( $j !== null ) {
				$oldLink = $this->oldText->tokens[$j]['link'];
			}

			// Start of gap in new and old
			if ( $gap === null && $newLink === null && $oldLink === null ) {
				$gap = count( $gaps );
				array_push( $gaps, array(
					'newFirst'  => $i,
					'newLast'   => $i,
					'newTokens' => 1,
					'oldFirst'  => $j,
					'oldLast'   => $j,
					'oldTokens' => null,
					'charSplit' => null
				) );
			}

			// Count chars and tokens in gap
			elseif ( $gap !== null && $newLink === null ) {
				$gaps[$gap]['newLast'] = $i;
				$gaps[$gap]['newTokens'] ++;
			}

			// Gap ended
			elseif ( $gap !== null && $newLink !== null ) {
				$gap = null;
			}

			// Next list elements
			if ( $newLink !== null ) {
				$j = $this->oldText->tokens[$newLink]['next'];
			}
			$i = $this->newText->tokens[$i]['next'];
		}

		// Cycle trough gaps and add old text gap data
		for ( $gap = 0; $gap < count( $gaps ); $gap ++ ) {

			// Cycle trough old text tokens list
			$j = $gaps[$gap]['oldFirst'];
			while (
				$j !== null && $this->oldText->tokens[$j] !== null &&
				$this->oldText->tokens[$j]['link'] === null
			) {

				// Count old chars and tokens in gap
				$gaps[$gap]['oldLast'] = $j;
				$gaps[$gap]['oldTokens'] ++;

				$j = $this->oldText->tokens[$j]['next'];
			}
		}

		/** Select gaps of identical token number and strong similarity of all tokens */

		for ( $gap = 0; $gap < count( $gaps ); $gap ++ ) {
			$charSplit = true;

			// Not same gap length
			if ( $gaps[$gap]['newTokens'] != $gaps[$gap]['oldTokens'] ) {

				// One word became separated by space, dash, or any string
				if ( $gaps[$gap]['newTokens'] == 1 && $gaps[$gap]['oldTokens'] == 3 ) {
					$token = $this->newText->tokens[ $gaps[$gap]['newFirst'] ]['token'];
					$tokenFirst = $this->oldText->tokens[ $gaps[$gap]['oldFirst'] ]['token'];
					$tokenLast = $this->oldText->tokens[ $gaps[$gap]['oldLast'] ]['token'];
					if (
						strpos( $token, $tokenFirst ) !== 0 ||
						strpos( $token, $tokenLast ) !== strlen( $token ) - strlen( $tokenLast )
					) {
						continue;
					}
				} elseif ( $gaps[$gap]['oldTokens'] == 1 && $gaps[$gap]['newTokens'] == 3 ) {
					$token = $this->oldText->tokens[ $gaps[$gap]['oldFirst'] ]['token'];
					$tokenFirst = $this->newText->tokens[ $gaps[$gap]['newFirst'] ]['token'];
					$tokenLast = $this->newText->tokens[ $gaps[$gap]['newLast'] ]['token'];
					if (
						strpos( $token, $tokenFirst ) !== 0 ||
						strpos( $token, $tokenLast ) !== strlen( $token ) - strlen( $tokenLast )
					) {
						continue;
					}
				} else {
					continue;
				}
				$gaps[$gap]['charSplit'] = true;
			}

			// Cycle trough new text tokens list and set charSplit
			else {
				$i = $gaps[$gap]['newFirst'];
				$j = $gaps[$gap]['oldFirst'];
				while ( $i !== null ) {
					$newToken = $this->newText->tokens[$i]['token'];
					$oldToken = $this->oldText->tokens[$j]['token'];

					// Get shorter and longer token
					if ( strlen( $newToken ) < strlen( $oldToken ) ) {
						$shorterToken = $newToken;
						$longerToken = $oldToken;
					} else {
						$shorterToken = $oldToken;
						$longerToken = $newToken;
					}

					// Not same token length
					if ( strlen( $newToken ) != strlen( $oldToken ) ) {

						// Test for addition or deletion of internal string in tokens

						// Find number of identical chars from left
						$left = 0;
						while ( $left < strlen( $shorterToken ) ) {
							if ( $newToken[$left] != $oldToken[$left] ) {
								break;
							}
							$left ++;
						}

						// Find number of identical chars from right
						$right = 0;
						while ( $right < strlen( $shorterToken ) ) {
							if (
								$newToken[strlen( $newToken ) - 1 - $right] != $oldToken[strlen( $oldToken ) - 1 - $right]
							) {
								break;
							}
							$right ++;
						}

						// No simple insertion or deletion of internal string
						if ( $left + $right != strlen( $shorterToken ) ) {

							// Not addition or deletion of flanking strings in tokens (smaller token not part of larger token)
							if ( strpos( $longerToken, $shorterToken ) === false ) {

								// Same text at start or end shorter than different text
								if ( $left < strlen( $shorterToken ) / 2 && $right < strlen( $shorterToken ) / 2 ) {

									// Do not split into chars this gap
									$charSplit = false;
									break;
								}
							}
						}
					}

					// Same token length
					elseif ( $newToken != $oldToken ) {

						// Tokens less than 50 % identical
						$ident = 0;
						for ( $pos = 0; $pos < strlen( $shorterToken ); $pos ++ ) {
							if ( $shorterToken[$pos] == $longerToken[$pos] ) {
								$ident ++;
							}
						}
						if ( $ident / strlen( $shorterToken ) < 0.49 ) {

							// Do not split into chars this gap
							$charSplit = false;
							break;
						}
					}

					// Next list elements
					if ( $i == $gaps[$gap]['newLast'] ) {
						break;
					}
					$i = $this->newText->tokens[$i]['next'];
					$j = $this->oldText->tokens[$j]['next'];
				}
				$gaps[$gap]['charSplit'] = $charSplit;
			}
		}

		/** Refine words into chars in selected gaps */

		for ( $gap = 0; $gap < count( $gaps ); $gap ++ ) {
			if ( $gaps[$gap]['charSplit'] === true ) {

				// Cycle trough new text tokens list, link spaces, and split into chars
				$i = $gaps[$gap]['newFirst'];
				$j = $gaps[$gap]['oldFirst'];
				$newGapLength = $i - $gaps[$gap]['newLast'];
				$oldGapLength = $j - $gaps[$gap]['oldLast'];
				while ( $i !== null || $j !== null ) {

					// Link identical tokens (spaces) to keep char refinement to words
					if (
						$newGapLength == $oldGapLength &&
						$this->newText->tokens[$i]['token'] == $this->oldText->tokens[$j]['token']
					) {
						$this->newText->tokens[$i]['link'] = $j;
						$this->oldText->tokens[$j]['link'] = $i;
					}

					// Refine words into chars
					else {
						if ( $i !== null ) {
							$this->newText->splitText( 'character', $i );
						}
						if ( $j !== null ) {
							$this->oldText->splitText( 'character', $j );
						}
					}

					// Next list elements
					if ( $i == $gaps[$gap]['newLast'] ) {
						$i = null;
					}
					if ( $j == $gaps[$gap]['oldLast'] ) {
						$j = null;
					}
					if ( $i !== null ) {
						$i = $this->newText->tokens[$i]['next'];
					}
					if ( $j !== null ) {
						$j = $this->oldText->tokens[$j]['next'];
					}
				}
			}
		}
	}


	/**
	 * Move gaps with ambiguous identical fronts to last newline border or otherwise last word border
	 *
	 * @param[in/out] wikEdDiffText $text, $textLinked These two are $newText and $oldText
	 */
	private function slideGaps( &$text, &$textLinked ) {

		// Cycle through tokens list
		$i = $text->first;
		$gapStart = null;
		while ( $i !== null && $text->tokens[$i] !== null ) {

			// Remember gap start
			if ( $gapStart === null && $text->tokens[$i]['link'] === null ) {
				$gapStart = $i;
			}

			// Find gap end
			elseif ( $gapStart !== null && $text->tokens[$i]['link'] !== null ) {
				$gapFront = $gapStart;
				$gapBack = $text->tokens[$i]['prev'];

				// Slide down as deep as possible
				$front = $gapFront;
				$back = $text->tokens[$gapBack]['next'];
				if (
					( $front !== null && $back !== null ) &&
					( $text->tokens[$front]['link'] === null && $text->tokens[$back]['link'] !== null ) &&
					( $text->tokens[$front]['token'] === $text->tokens[$back]['token'] )
				) {
					$text->tokens[$front]['link'] = $text->tokens[$back]['link'];
					$textLinked->tokens[ $text->tokens[$front]['link'] ]['link'] = $front;
					$text->tokens[$back]['link'] = null;

					$gapFront = $text->tokens[$gapFront]['next'];
					$gapBack = $text->tokens[$gapBack]['next'];

					$front = $text->tokens[$front]['next'];
					$back = $text->tokens[$back]['next'];
				}

				// Test slide up, remember last line break or word border
				$front = $text->tokens[$gapFront]['prev'];
				$back = $gapBack;
				$gapFrontBlankTest = preg_match( $this->config['regExp']['slideBorder'], $text->tokens[$gapFront]['token'] );
				$frontStop = $front;
				if  ( $text->tokens[$back]['link'] === null ) {
					while (
						$front !== null && $back !== null &&
						$text->tokens[$front]['link'] !== null &&
						$text->tokens[$front]['token'] == $text->tokens[$back]['token']
					) {
						$front = $text->tokens[$front]['prev'];
						$back = $text->tokens[$back]['prev'];

						// Stop at line break
						if ( $front !== null ) {
							if ( preg_match( $this->config['regExp']['slideStop'], $text->tokens[$front]['token'] ) === 1 ) {
								$frontStop = $front;
								break;
							}

							// Stop at first word border (blank/word or word/blank)
							if (
								preg_match( $this->config['regExp']['slideBorder'], $text->tokens[$front]['token'] ) !== $gapFrontBlankTest
							) {
								$frontStop = $front;
							}
						}
					}
				}

				// Actually slide up to stop
				$front = $text->tokens[$gapFront]['prev'];
				$back = $gapBack;
				while (
					$front !== null && $back !== null && $front !== $frontStop &&
					$text->tokens[$front]['link'] !== null && $text->tokens[$back]['link'] === null &&
					$text->tokens[$front]['token'] == $text->tokens[$back]['token']
				) {
					$text->tokens[$back]['link'] = $text->tokens[$front]['link'];
					$textLinked->tokens[ $text->tokens[$back]['link'] ]['link'] = $back;
					$text->tokens[$front]['link'] = null;

					$front = $text->tokens[$front]['prev'];
					$back = $text->tokens[$back]['prev'];
				}
				$gapStart = null;
			}
			$i = $text->tokens[$i]['next'];
		}
	}


	/**
	 * Calculate diff information, can be called repeatedly during refining
	 * Links corresponding tokens from old and new text.
	 * Steps:
	 *   Pass 1: parse new text into symbol table
	 *   Pass 2: parse old text into symbol table
	 *   Pass 3: connect unique matched tokens
	 *   Pass 4: connect adjacent identical tokens downwards
	 *   Pass 5: connect adjacent identical tokens upwards
	 *   Repeat with empty symbol table (against crossed-over gaps)
	 *   Recursively diff still unresolved regions downwards with empty symbol table
	 *   Recursively diff still unresolved regions upwards with empty symbol table
	 *
	 * @param array $symbols Symbol table object
	 * @param string level Split level: 'paragraph', 'sentence', 'chunk', 'word', or 'character'
	 *
	 * Optionally for recursive or repeated calls:
	 * @param bool $repeat Repeat with empty symbol table
	 * @param bool $recurse Enable recursion
	 * @param int $newStart, $newEnd, $oldStart, $oldEnd Text object tokens indices
	 * @param int $recursionLevel Recursion level
	 * @param[in/out] WikEdDiffText $newText, $oldText Text object, tokens list link property
	 */
	private function calculateDiff (
		&$symbols,
		$level,
		$repeat = false,
		$recurse = false,
		$newStart = null,
		$newEnd = null,
		$oldStart = null,
		$oldEnd = null,
		$recursionLevel = 0
	) {

		// Set defaults
		if ( $newStart === null ) { $newStart = $this->newText->first; }
		if ( $newEnd   === null ) { $newEnd   = $this->newText->last;  }
		if ( $oldStart === null ) { $oldStart = $this->oldText->first; }
		if ( $oldEnd   === null ) { $oldEnd   = $this->oldText->last;  }

		// Start timers
		if ( $this->config['timer'] === true && $repeat === false && $recursionLevel === 0 ) {
			$this->time( $level );
		}
		if ( $this->config['timer'] === true && $repeat === false ) {
			$this->time( $level . ( $recursionLevel ) );
		}

		// Limit recursion depth
		if ( $recursionLevel > $this->config['recursionMax'] ) {
			return;
		}

		/**
		 * Pass 1: parse new text into symbol table.
		 */

		// Cycle trough new text tokens list
		$i = $newStart;
		while ( $i !== null && $this->newText->tokens[$i] !== null ) {
			if ( $this->newText->tokens[$i]['link'] === null ) {

				// Add new entry to symbol table
				$token = $this->newText->tokens[$i]['token'];
				if ( !isset( $symbols['hashTable'][$token] ) ) {
					$current = count( $symbols['token'] );
					$symbols['hashTable'][$token] = $current;
					$symbols['token'][$current] = array(
						'newCount' => 1,
						'oldCount' => 0,
						'newToken' => $i,
						'oldToken' => null
					);
				}
				// Or update existing entry

				else {

					// Increment token counter for new text
					$hashToArray = $symbols['hashTable'][$token];
					$symbols['token'][$hashToArray]['newCount'] ++;
				}
			}

			// Next list element
			if ( $i == $newEnd ) {
				break;
			}
			$i = $this->newText->tokens[$i]['next'];
		}

		/**
		 * Pass 2: parse old text into symbol table.
		 */

		// Cycle trough old text tokens list
		$j = $oldStart;
		while ( $j !== null && $this->oldText->tokens[$j] !== null ) {
			if ( $this->oldText->tokens[$j]['link'] === null ) {

				// Add new entry to symbol table
				$token = $this->oldText->tokens[$j]['token'];
				if ( !isset( $symbols['hashTable'][$token] ) ) {
					$current = count( $symbols['token'] );
					$symbols['hashTable'][$token] = $current;
					$symbols['token'][$current] = array(
						'newCount' => 0,
						'oldCount' => 1,
						'newToken' => null,
						'oldToken' => $j
					);
				}

				// Or update existing entry
				else {

					// Increment token counter for old text
					$hashToArray = $symbols['hashTable'][$token];
					$symbols['token'][$hashToArray]['oldCount'] ++;

					// Add token number for old text
					$symbols['token'][$hashToArray]['oldToken'] = $j;
				}
			}

			// Next list element
			if ( $j === $oldEnd ) {
				break;
			}
			$j = $this->oldText->tokens[$j]['next'];
		}

		/**
		 * Pass 3: connect unique tokens.
		 */

		// Cycle trough symbol array
		for ( $i = 0; $i < count( $symbols['token'] ); $i ++ ) {

			// Find tokens in the symbol table that occur only once in both versions
			if ( $symbols['token'][$i]['newCount'] == 1 && $symbols['token'][$i]['oldCount'] == 1 ) {
				$newToken = $symbols['token'][$i]['newToken'];
				$oldToken = $symbols['token'][$i]['oldToken'];

				// Connect from new to old and from old to new
				if ( $this->newText->tokens[$newToken]['link'] === null ) {

					// Do not use spaces as unique markers
					if ( preg_match( '/^\s+$/u', $this->newText->tokens[$newToken]['token'] ) === 0 ) {
						$this->newText->tokens[$newToken]['link'] = $oldToken;
						$this->oldText->tokens[$oldToken]['link'] = $newToken;
						$symbols['linked'] = true;

						// Check if token contains unique word
						if ( $recursionLevel === 0 ) {
							$unique = false;
							if ( $level == 'character' ) {
								$unique = true;
							} else {
								$token = $this->newText->tokens[$newToken]['token'];
								preg_match_all( $this->config['regExp']['countWords'], $token, $regExpMatchWord );
								preg_match_all( $this->config['regExp']['countChunks'], $token, $regExpMatchChunk );
								$words = array_merge( $regExpMatchWord[0], $regExpMatchChunk[0] );

								// Unique if longer than min block length
								if ( count( $words ) >= $this->config['blockMinLength'] ) {
									$unique = true;
								}

								// Unique if it contains at least one unique word
								else {
									for ( $word = 0; $word < count( $words ); $word ++ ) {
										if (
											isset( $this->oldText->words[ $words[$word] ] ) &&
											isset( $this->newText->words[ $words[$word] ] )
										) {
											if (
												$this->oldText->words[ $words[$word] ] == 1 &&
												$this->newText->words[ $words[$word] ] == 1
											) {
												$unique = true;
												break;
											}
										}
									}
								}
							}

							// Set unique
							if ( $unique === true ) {
								$this->newText->tokens[$newToken]['unique'] = true;
								$this->oldText->tokens[$oldToken]['unique'] = true;
							}
						}
					}
				}
			}
		}

		// Continue passes only if unique tokens have been linked previously
		if ( $symbols['linked'] === true ) {

			/**
			 * Pass 4: connect adjacent identical tokens downwards.
			 */

			// Get surrounding connected tokens
			$i = $newStart;
			if ( $this->newText->tokens[$i]['prev'] !== null ) {
				$i = $this->newText->tokens[$i]['prev'];
			}
			$iStop = $newEnd;
			if ( $this->newText->tokens[$iStop]['next'] !== null ) {
				$iStop = $this->newText->tokens[$iStop]['next'];
			}
			$j = null;

			// Cycle trough new text tokens list down
			do {

				// Connected pair
				$link = $this->newText->tokens[$i]['link'];
				if ( $link !== null ) {
					$j = $this->oldText->tokens[$link]['next'];
				}

				// Connect if tokens are the same
				elseif (
					$j !== null &&
					$this->oldText->tokens[$j]['link'] === null &&
					$this->newText->tokens[$i]['token'] == $this->oldText->tokens[$j]['token']
				) {
					$this->newText->tokens[$i]['link'] = $j;
					$this->oldText->tokens[$j]['link'] = $i;
					$j = $this->oldText->tokens[$j]['next'];
				}

				// Not same
				else {
					$j = null;
				}
				$i = $this->newText->tokens[$i]['next'];
			} while ( $i !== $iStop );

			/**
			 * Pass 5: connect adjacent identical tokens upwards.
			 */

			// Get surrounding connected tokens
			$i = $newEnd;
			if ( $this->newText->tokens[$i]['next'] !== null ) {
				$i = $this->newText->tokens[$i]['next'];
			}
			$iStop = $newStart;
			if ( $this->newText->tokens[$iStop]['prev'] !== null ) {
				$iStop = $this->newText->tokens[$iStop]['prev'];
			}
			$j = null;

			// Cycle trough new text tokens list up
			do {

				// Connected pair
				$link = $this->newText->tokens[$i]['link'];
				if ( $link !== null ) {
					$j = $this->oldText->tokens[$link]['prev'];
				}

				// Connect if tokens are the same
				elseif (
					$j !== null &&
					$this->oldText->tokens[$j]['link'] === null &&
					$this->newText->tokens[$i]['token'] == $this->oldText->tokens[$j]['token']
				) {
					$this->newText->tokens[$i]['link'] = $j;
					$this->oldText->tokens[$j]['link'] = $i;
					$j = $this->oldText->tokens[$j]['prev'];
				}

				// Not same
				else {
					$j = null;
				}
				$i = $this->newText->tokens[$i]['prev'];
			} while ( $i !== $iStop );

			/**
			 * Connect adjacent identical tokens downwards from text start,
			 * treat boundary as connected, stop after first connected token.
			 */

			// Only for full text diff
			if ( $newStart == $this->newText->first && $newEnd == $this->newText->last ) {

				// From start
				$i = $this->newText->first;
				$j = $this->oldText->first;

				// Cycle trough new text tokens list down, connect identical tokens, stop after first connected token
				while (
					$i !== null && $j !== null &&
					$this->newText->tokens[$i]['link'] === null &&
					$this->oldText->tokens[$j]['link'] === null &&
					$this->newText->tokens[$i]['token'] == $this->oldText->tokens[$j]['token']
				) {
					$this->newText->tokens[$i]['link'] = $j;
					$this->oldText->tokens[$j]['link'] = $i;
					$i = $this->newText->tokens[$i]['next'];
					$j = $this->oldText->tokens[$j]['next'];
				}

				// From end
				$i = $this->newText->last;
				$j = $this->oldText->last;

				// Cycle trough old text tokens list up, connect identical tokens, stop after first connected token
				while (
					$i !== null && $j !== null &&
					$this->newText->tokens[$i]['link'] === null &&
					$this->oldText->tokens[$j]['link'] === null &&
					$this->newText->tokens[$i]['token'] == $this->oldText->tokens[$j]['token']
				) {
					$this->newText->tokens[$i]['link'] = $j;
					$this->oldText->tokens[$j]['link'] = $i;
					$i = $this->newText->tokens[$i]['prev'];
					$j = $this->oldText->tokens[$j]['prev'];
				}
			}

			/**
			 * Repeat with empty symbol table to link hidden unresolved common tokens in cross-overs
			 * ("and" in "and this a and b that" -> "and this a and b that").
			 */

			// New empty symbols object
			if ( $repeat === false ) {
				$symbolsRepeat = array(
					'token' => array(),
					'hashTable' => array(),
					'linked' => false
				);
				$this->calculateDiff(
					$symbolsRepeat, $level, true, false,
					$newStart, $newEnd, $oldStart, $oldEnd
				);
			}

			/**
			 * Refine by recursively diffing unresolved regions with empty symbol table at word level
			 * Helps against gaps caused by addition of common tokens around sequences of common tokens
			 */

			if ( $recurse === true && $this->config['recursiveDiff'] === true ) {

				/**
				 * Recursively diff still unresolved regions downwards.
				 */

				// Cycle trough new text tokens list
				$i = $newStart;
				$j = $oldStart;

				while ( $i !== null && $this->newText->tokens[$i] !== null ) {

					// Get j from previous tokens match
					$iPrev = $this->newText->tokens[$i]['prev'];
					if ( $iPrev !== null ) {
						$jPrev = $this->newText->tokens[$iPrev]['link'];
						if ( $jPrev !== null ) {
							$j = $this->oldText->tokens[$jPrev]['next'];
						}
					}

					// Check for the start of an unresolved sequence
					if (
						$j !== null &&
						$this->oldText->tokens[$j] !== null &&
						$this->newText->tokens[$i]['link'] === null &&
						$this->oldText->tokens[$j]['link'] === null
					) {

						// Determine the limits of the unresolved new sequence
						$iStart = $i;
						$iEnd = null;
						$iLength = 0;
						$iNext = $i;
						while ( $iNext !== null && $this->newText->tokens[$iNext]['link'] === null ) {
							$iEnd = $iNext;
							$iLength ++;
							if ( $iEnd == $newEnd ) {
								break;
							}
							$iNext = $this->newText->tokens[$iNext]['next'];
						}

						// Determine the limits of the unresolved old sequence
						$jStart = $j;
						$jEnd = null;
						$jLength = 0;
						$jNext = $j;
						while ( $jNext !== null && $this->oldText->tokens[$jNext]['link'] === null ) {
							$jEnd = $jNext;
							$jLength ++;
							if ( $jEnd == $oldEnd ) {
								break;
							}
							$jNext = $this->oldText->tokens[$jNext]['next'];
						}

						// Recursively diff the unresolved sequence
						if ( $iLength > 1 || $jLength > 1 ) {

							// New empty symbols object for sub-region
							$symbolsRecurse = array(
								'token' => array(),
								'hashTable' => array(),
								'linked' => false
							);
							$this->calculateDiff(
								$symbolsRecurse, $level, false, true,
								$iStart, $iEnd, $jStart, $jEnd, $recursionLevel + 1
							);
						}
						$i = $iEnd;
					}

					// Next list element
					if ( $i == $newEnd ) {
						break;
					}
					$i = $this->newText->tokens[$i]['next'];
				}

				/**
				 * Recursively diff still unresolved regions upwards.
				 */

				// Cycle trough new text tokens list
				$i = $newEnd;
				$j = $oldEnd;
				while ( $i !== null && $this->newText->tokens[$i] !== null ) {

					// Get j from next matched tokens
					$iPrev = $this->newText->tokens[$i]['next'];
					if ( $iPrev !== null ) {
						$jPrev = $this->newText->tokens[$iPrev]['link'];
						if ( $jPrev !== null ) {
							$j = $this->oldText->tokens[$jPrev]['prev'];
						}
					}

					// Check for the start of an unresolved sequence
					if (
						$j !== null &&
						$this->oldText->tokens[$j] !== null &&
						$this->newText->tokens[$i]['link'] === null &&
						$this->oldText->tokens[$j]['link'] === null
					) {

						// Determine the limits of the unresolved new sequence
						$iStart = null;
						$iEnd = $i;
						$iLength = 0;
						$iNext = $i;
						while ( $iNext !== null && $this->newText->tokens[$iNext]['link'] === null ) {
							$iStart = $iNext;
							$iLength ++;
							if ( $iStart == $newStart ) {
								break;
							}
							$iNext = $this->newText->tokens[$iNext]['prev'];
						}

						// Determine the limits of the unresolved old sequence
						$jStart = null;
						$jEnd = $j;
						$jLength = 0;
						$jNext = $j;
						while ( $jNext !== null && $this->oldText->tokens[$jNext]['link'] === null ) {
							$jStart = $jNext;
							$jLength ++;
							if ( $jStart == $oldStart ) {
								break;
							}
							$jNext = $this->oldText->tokens[$jNext]['prev'];
						}

						// Recursively diff the unresolved sequence
						if ( $iLength > 1 || $jLength > 1 ) {

							// New empty symbols object for sub-region
							$symbolsRecurse = array(
								'token' => array(),
								'hashTable' => array(),
								'linked' => false
							);
							$this->calculateDiff(
								$symbolsRecurse, $level, false, true,
								$iStart, $iEnd, $jStart, $jEnd, $recursionLevel + 1
							);
						}
						$i = $iStart;
					}

					// Next list element
					if ( $i == $newStart ) {
						break;
					}
					$i = $this->newText->tokens[$i]['prev'];
				}
			}
		}

		// Stop timers
		if ( $this->config['timer'] === true && $repeat === false ) {
			if ( !isset( $this->recursionTimer[$recursionLevel] ) ) {
				$this->recursionTimer[$recursionLevel] = 0;
			}
			$this->recursionTimer[$recursionLevel] += $this->timeEnd( $level . ( $recursionLevel ), true );
		}
		if ( $this->config['timer'] === true && $repeat === false && $recursionLevel === 0 ) {
			$this->timeRecursionEnd( $level );
			$this->timeEnd( $level );
		}
	}


	/**
	 * Main method for processing raw diff data, extracting deleted, inserted, and moved blocks
	 *
	 * Scheme of blocks, sections, and groups (old block numbers):
	 *   Old:      1    2 3D4   5E6    7   8 9 10  11
	 *             |    ‾/-/_    X     |    >|<     |
	 *   New:      1  I 3D4 2  E6 5  N 7  10 9  8  11
	 *   Section:       0 0 0   1 1       2 2  2
	 *   Group:    0 10 111 2  33 4 11 5   6 7  8   9
	 *   Fixed:    .    +++ -  ++ -    .   . -  -   +
	 *   Type:     =  . =-= =  -= =  . =   = =  =   =
	 *
	 * @param[out] array $groups Groups table object
	 * @param[out] array $blocks Blocks table object
	 * @param[in/out] WikEdDiffText $newText, $oldText Text object tokens list
	 */
	private function detectBlocks() {

		// Debug log
		if ( $this->config['debug'] === true ) {
			$this->oldText->debugText( 'Old text' );
			$this->newText->debugText( 'New text' );
		}

		// Start with empty blocks array
		$this->blocks = array();

		// Collect identical corresponding ('=') blocks from old text and sort by new text
		$this->getSameBlocks();

		// Collect independent block sections with no block move crosses outside a section
		//  for per-section determination of non-moving fixed groups
		$this->getSections();

		// Start with empty groups array
		$this->groups = array();

		// Find groups of continuous old text blocks
		$this->getGroups();

		// Set longest sequence of increasing groups in sections as fixed (not moved)
		if ( $this->config['timer'] === true ) {
			$this->time( 'setFixed' );
		}
		$this->setFixed();
		if ( $this->config['timer'] === true ) {
			$this->timeEnd( 'setFixed' );
		}

		// Convert groups to insertions/deletions if maximum block length is too short
		$unlinkCount = 0;
		if ( $this->config['unlinkBlocks'] === true && $this->config['blockMinLength'] > 0 ) {
			if ( $this->config['timer'] === true ) {
				$this->time( 'unlink' );
			}

			// Repeat as long as unlinking is possible
			$unlinked = true;
			while ( $unlinked === true && $unlinkCount < $this->config['unlinkMax'] ) {

				// Convert '=' to '+'/'-' pairs
				$unlinked = $this->unlinkBlocks();

				// Start over after conversion
				if ( $unlinked === true ) {
					$unlinkCount ++;
					$this->slideGaps( $this->newText, $this->oldText );
					$this->slideGaps( $this->oldText, $this->newText );

					// Repeat block detection from start
					$this->blocks = array();
					$this->getSameBlocks();
					$this->getSections();
					$this->groups = array();
					$this->getGroups();
					$this->setFixed();
				}
			}
			if ( $this->config['timer'] === true ) {
				$this->timeEnd( 'unlink' );
			}
		}

		// Collect deletion ('-') blocks from old text
		$this->getDelBlocks();

		// Position '-' blocks into new text order
		$this->positionDelBlocks();

		// Collect insertion ('+') blocks from new text
		$this->getInsBlocks();

		// Set group numbers of '+' blocks
		$this->setInsGroups();

		// Mark original positions of moved groups
		$this->insertMarks();

		// Debug log
		if ( $this->config['debug'] === true || $this->config['timer'] === true ) {
			$this->debug( 'Unlinke count', $unlinkCount );
		}
		if ( $this->config['debug'] === true ) {
			$this->debugGroups( 'Groups' );
			$this->debugBlocks( 'Blocks' );
		}
	}


	/**
	 * Collect identical corresponding matching ('=') blocks from old text and sort by new text
	 *
	 * @param[in] WikEdDiffText $newText, $oldText Text objects
	 * @param[in/out] array $blocks Blocks table object
	 */
	private function getSameBlocks() {

		$blocks = &$this->blocks;

		// Cycle through old text to find matched (linked) blocks
		$j = $this->oldText->first;
		$i = null;
		while ( $j !== null ) {

			// Skip '-' blocks
			while ( $j !== null && !isset( $this->oldText->tokens[$j]['link'] ) ) {
				$j = $this->oldText->tokens[$j]['next'];
			}

			// Get '=' block
			if ( $j !== null ) {
				$i = $this->oldText->tokens[$j]['link'];
				$iStart = $i;
				$jStart = $j;

				// Detect matching blocks ('=')
				$count = 0;
				$unique = false;
				$text = '';
				while ( $i !== null && $j !== null && $this->oldText->tokens[$j]['link'] == $i ) {
					$token = $this->oldText->tokens[$j]['token'];
					$count ++;
					if ( $this->newText->tokens[$i]['unique'] === true ) {
						$unique = true;
					}
					$text .= $token;
					$i = $this->newText->tokens[$i]['next'];
					$j = $this->oldText->tokens[$j]['next'];
				}

				// Save old text '=' block
				array_push( $blocks, array(
					'oldBlock'  => count( $blocks ),
					'newBlock'  => null,
					'oldNumber' => $this->oldText->tokens[$jStart]['number'],
					'newNumber' => $this->newText->tokens[$iStart]['number'],
					'oldStart' => $jStart,
					'count'     => $count,
					'unique'    => $unique,
					'words'     => $this->wordCount( $text ),
					'chars'     => mb_strlen( $text ),
					'type'      => '=',
					'section'   => null,
					'group'     => null,
					'fixed'     => null,
					'moved'     => null,
					'text'      => $text
				) );
			}
		}

		// Sort blocks by new text token number
		usort( $blocks, function( $a, $b ) {
			return $a['newNumber'] - $b['newNumber'];
		} );

		// Number blocks in new text order
		for ( $block = 0; $block < count( $blocks ); $block ++ ) {
			$blocks[$block]['newBlock'] = $block;
		}
	}


	/**
	 * Collect independent block sections with no block move crosses
	 *   outside a section for per-section determination of non-moving fixed groups
	 *
	 * @param[out] array $sections Sections table object
	 * @param[in/out] array $blocks Blocks table object, section property
	 */
	private function getSections() {

		$blocks = &$this->blocks;
		$sections = &$this->sections;

		// Clear sections array
		$sections = array();

		// Cycle through blocks
		for ( $block = 0; $block < count( $blocks ); $block ++ ) {

			$sectionStart = $block;
			$sectionEnd = $block;

			$oldMax = $blocks[$sectionStart]['oldNumber'];
			$sectionOldMax = $oldMax;

			// Check right
			for ( $j = $sectionStart + 1; $j < count( $blocks ); $j ++ ) {

				// Check for crossing over to the left
				if ( $blocks[$j]['oldNumber'] > $oldMax ) {
					$oldMax = $blocks[$j]['oldNumber'];
				} elseif ( $blocks[$j]['oldNumber'] < $sectionOldMax ) {
					$sectionEnd = $j;
					$sectionOldMax = $oldMax;
				}
			}

			// Save crossing sections
			if ( $sectionEnd > $sectionStart ) {

				// Save section to block
				for ( $j = $sectionStart; $j <= $sectionEnd; $j ++ ) {
					$blocks[$j]['section'] = count( $sections );
				}

				// Save section
				array_push( $sections, array(
					'blockStart' => $sectionStart,
					'blockEnd'   => $sectionEnd
				) );
				$block = $sectionEnd;
			}
		}
	}


	/**
	 * Find groups of continuous old text blocks
	 *
	 * @param[out] array $groups Groups table object
	 * @param[in/out] array $blocks Blocks table object, group property
	 */
	private function getGroups() {

		$blocks = &$this->blocks;
		$groups = &$this->groups;

		// Cycle through blocks
		for ( $block = 0; $block < count( $blocks ); $block ++ ) {
			$groupStart = $block;
			$groupEnd = $block;
			$oldBlock = $blocks[$groupStart]['oldBlock'];

			// Get word and char count of block
			$words = $this->wordCount( $blocks[$block]['text'] );
			$maxWords = $words;
			$unique = $blocks[$block]['unique'];
			$chars = $blocks[$block]['chars'];

			// Check right
			for ( $i = $groupEnd + 1; $i < count( $blocks ); $i ++ ) {

				// Check for crossing over to the left
				if ( $blocks[$i]['oldBlock'] != $oldBlock + 1 ) {
					break;
				}
				$oldBlock = $blocks[$i]['oldBlock'];

				// Get word and char count of block
				if ( $blocks[$i]['words'] > $maxWords ) {
					$maxWords = $blocks[$i]['words'];
				}
				if ( $blocks[$i]['unique'] === true ) {
					$unique = true;
				}
				$words += $blocks[$i]['words'];
				$chars += $blocks[$i]['chars'];
				$groupEnd = $i;
			}

			// Save crossing group
			if ( $groupEnd >= $groupStart ) {

				// Set groups outside sections as fixed
				$fixed = false;
				if ( $blocks[$groupStart]['section'] === null ) {
					$fixed = true;
				}

				// Save group to block
				for ( $i = $groupStart; $i <= $groupEnd; $i ++ ) {
					$blocks[$i]['group'] = count( $groups );
					$blocks[$i]['fixed'] = $fixed;
				}

				// Save group
				array_push( $groups, array(
					'oldNumber'  => $blocks[$groupStart]['oldNumber'],
					'blockStart' => $groupStart,
					'blockEnd'   => $groupEnd,
					'unique'     => $unique,
					'maxWords'   => $maxWords,
					'words'      => $words,
					'chars'      => $chars,
					'fixed'      => $fixed,
					'movedFrom'  => null,
					'color'      => null
				) );
				$block = $groupEnd;
			}
		}
	}


	/**
	 * Set longest sequence of increasing groups in sections as fixed (not moved)
	 *
	 * @param[in] array $sections Sections table object
	 * @param[in/out] array $groups Groups table object, fixed property
	 * @param[in/out] array $blocks Blocks table object, fixed property
	 */
	private function setFixed() {

		$blocks = &$this->blocks;
		$groups = &$this->groups;
		$sections = &$this->sections;

		// Cycle through sections
		for ( $section = 0; $section < count( $sections ); $section ++ ) {
			$blockStart = $sections[$section]['blockStart'];
			$blockEnd = $sections[$section]['blockEnd'];

			$groupStart = $blocks[$blockStart]['group'];
			$groupEnd = $blocks[$blockEnd]['group'];

			// Recusively find path of groups in increasing old group order with longest char length
			$cache = array();
			$maxChars = 0;
			$maxPath = null;

			// Start at each group of section
			for ( $i = $groupStart; $i <= $groupEnd; $i ++ ) {
				$pathObj = $this->findMaxPath( $i, $groupEnd, $cache );
				if ( $pathObj['chars'] > $maxChars ) {
					$maxPath = $pathObj['path'];
					$maxChars = $pathObj['chars'];
				}
			}

			// Mark fixed groups
			for ( $i = 0; $i < count( $maxPath ); $i ++ ) {
				$group = $maxPath[$i];
				$groups[$group]['fixed'] = true;

				// Mark fixed blocks
				for ( $block = $groups[$group]['blockStart']; $block <= $groups[$group]['blockEnd']; $block ++ ) {
					$blocks[$block]['fixed'] = true;
				}
			}
		}
	}


	/**
	 * Recusively find path of groups in increasing old group order with longest char length
	 *
	 * @param int $start Path start group
	 * @param int $groupEnd Path last group
	 * @param array $cache Cache object, contains $returnObj for $start
	 * @return array $returnObj Contains path and char length
	 */
	private function findMaxPath( $start, &$groupEnd, &$cache ) {

		$groups = &$this->groups;

		// Find longest sub-path
		$maxChars = 0;
		$oldNumber = $groups[$start]['oldNumber'];
		$returnObj = array( 'path' => array(), 'chars' => 0 );
		for ( $i = $start + 1; $i <= $groupEnd; $i ++ ) {

			// Only in increasing old group order
			if ( $groups[$i]['oldNumber'] < $oldNumber ) {
				continue;
			}

			// Get longest sub-path from cache (deep copy)
			if ( isset( $cache[$i] ) ) {
				$pathObj = $cache[$i];
			}

			// Get longest sub-path by recursion
			else {
				$pathObj = $this->findMaxPath( $i, $groupEnd, $cache );
			}

			// Select longest sub-path
			if ( $pathObj['chars'] > $maxChars ) {
				$maxChars = $pathObj['chars'];
				$returnObj = $pathObj;
			}
		}

		// Add current start to path
		array_unshift( $returnObj['path'], $start );
		$returnObj['chars'] += $groups[$start]['chars'];

		// Save path to cache (deep copy)
		if ( !isset( $cache[$start] ) ) {
			$cache[$start] = $returnObj;
		}

		return $returnObj;
	}


	/**
	 * Collect deletion ('-') blocks from old text
	 *
	 * @param[in] WikEdDiffText $oldText Old Text object
	 * @param[out] array $blocks Blocks table object
	 */
	private function getDelBlocks() {

		$blocks = &$this->blocks;

		// Cycle through old text to find matched (linked) blocks
		$j = $this->oldText->first;
		$i = null;
		while ( $j !== null ) {

			// Collect '-' blocks
			$oldStart = $j;
			$count = 0;
			$text = '';
			while ( $j !== null && !isset( $this->oldText->tokens[$j]['link'] ) ) {
				$count ++;
				$text .= $this->oldText->tokens[$j]['token'];
				$j = $this->oldText->tokens[$j]['next'];
			}

			// Save old text '-' block
			if ( $count !== 0 ) {
				array_push( $blocks, array(
					'oldBlock'  => null,
					'newBlock'  => null,
					'oldNumber' => $this->oldText->tokens[$oldStart]['number'],
					'newNumber' => null,
					'oldStart'  => $oldStart,
					'count'     => $count,
					'unique'    => false,
					'words'     => null,
					'chars'     => mb_strlen( $text ),
					'type'      => '-',
					'section'   => null,
					'group'     => null,
					'fixed'     => null,
					'moved'     => null,
					'text'      => $text
				) );
			}

			// Skip '=' blocks
			if ( $j !== null ) {
				$i = $this->oldText->tokens[$j]['link'];
				while ( $i !== null && $j !== null && $this->oldText->tokens[$j]['link'] == $i ) {
					$i = $this->newText->tokens[$i]['next'];
					$j = $this->oldText->tokens[$j]['next'];
				}
			}
		}
	}


	/**
	 * Position deletion '-' blocks into new text order
	 * Deletion blocks move with fixed reference:
	 *   Old:          1 D 2      1 D 2
	 *                /     \    /   \ \
	 *   New:        1 D     2  1     D 2
	 *   Fixed:      *                  *
	 *   newNumber:  1 1              2 2
	 * Marks '|' and deletions '-' get newNumber of reference block
	 *   and are sorted around it by old text number
	 *
	 * @param[in/out] array $blocks Blocks table, newNumber, section, group, and fixed properties
	 *
	 */
	private function positionDelBlocks() {

		$blocks = &$this->blocks;
		$groups = &$this->groups;

		// Sort shallow copy of blocks by oldNumber
		$blocksOld = array();
		for ( $block = 0; $block < count( $blocks ); $block ++ ) {
			$blocksOld[$block] = &$blocks[$block];
		}

		usort( $blocksOld, function( $a, $b ) {
			return $a['oldNumber'] - $b['oldNumber'];
		} );

		// Cycle through blocks in old text order
		for ( $block = 0; $block < count( $blocksOld ); $block ++ ) {
			$delBlock = &$blocksOld[$block];

			// '-' block only
			if ( $delBlock['type'] != '-' ) {
				continue;
			}

			// Find fixed '=' reference block from original block position to position '-' block, similar to position '|' code

			// Get old text prev block
			$prevBlockNumber = null;
			$prevBlock = null;
			if ( $block > 0 ) {
				$prevBlockNumber = $blocksOld[$block - 1]['newBlock'];
				$prevBlock = $blocks[$prevBlockNumber];
			}

			// Get old text next block
			$nextBlockNumber = null;
			$nextBlock = null;
			if ( $block < count( $blocksOld ) - 1 ) {
				$nextBlockNumber = $blocksOld[$block + 1]['newBlock'];
				$nextBlock = $blocks[$nextBlockNumber];
			}

			// Move after prev block if fixed
			$refBlock = null;
			if ( $prevBlock !== null && $prevBlock['type'] == '=' && $prevBlock['fixed'] === true ) {
				$refBlock = $prevBlock;
			}

			// Move before next block if fixed
			elseif ( $nextBlock !== null && $nextBlock['type'] == '=' && $nextBlock['fixed'] === true ) {
				$refBlock = $nextBlock;
			}

			// Move after prev block if not start of group
			elseif (
				$prevBlock !== null &&
				$prevBlock['type'] == '=' &&
				$prevBlockNumber != $groups[ $prevBlock['group'] ]['blockEnd']
			) {
				$refBlock = $prevBlock;
			}

			// Move before next block if not start of group
			elseif (
				$nextBlock !== null &&
				$nextBlock['type'] == '=' &&
				$nextBlockNumber != $groups[ $nextBlock['group'] ]['blockStart']
			) {
				$refBlock = $nextBlock;
			}

			// Move after closest previous fixed block
			else {
				for ( $fixed = $block; $fixed >= 0; $fixed -- ) {
					if ( $blocksOld[$fixed]['type'] == '=' && $blocksOld[$fixed]['fixed'] === true ) {
						$refBlock = $blocksOld[$fixed];
						break;
					}
				}
			}

			// Move before first block
			if ( $refBlock === null ) {
				$delBlock['newNumber'] = -1;
			}

			// Update '-' block data
			else {
				$delBlock['newNumber'] = $refBlock['newNumber'];
				$delBlock['section'] = $refBlock['section'];
				$delBlock['group'] = $refBlock['group'];
				$delBlock['fixed'] = $refBlock['fixed'];
			}
		}

		// Sort '-' blocks in and update groups
		$this->sortBlocks();
	}


	/**
	 * Convert matching '=' blocks in groups into insertion/deletion ('+'/'-') pairs
	 *   if too short and too common
	 * Prevents fragmentated diffs for very different versions
	 *
	 * @param[in] array $blocks Blocks table object
	 * @param[in/out] WikEdDiffText $newText, $oldText Text object, linked property
	 * @param[in/out] array $groups Groups table object
	 * @return bool True if text tokens were unlinked
	 */
	private function unlinkBlocks() {

		$blocks = &$this->blocks;
		$groups = &$this->groups;

		// Cycle through groups
		$unlinked = false;
		for ( $group = 0; $group < count( $groups ); $group ++ ) {
			$blockStart = $groups[$group]['blockStart'];
			$blockEnd = $groups[$group]['blockEnd'];

			// Unlink whole group if no block is at least blockMinLength words long and unique
			if (
				$groups[$group]['maxWords'] < $this->config['blockMinLength'] &&
				$groups[$group]['unique'] === false
			) {
				for ( $block = $blockStart; $block <= $blockEnd; $block ++ ) {
					if ( $blocks[$block]['type'] == '=' ) {
						$this->unlinkSingleBlock( $blocks[$block] );
						$unlinked = true;
					}
				}
			}

			// Otherwise unlink block flanks
			else {

				// Unlink blocks from start
				for ( $block = $blockStart; $block <= $blockEnd; $block ++ ) {
					if ( $blocks[$block]['type'] == '=' ) {

						// Stop unlinking if more than one word or a unique word
						if ( $blocks[$block]['words'] > 1 || $blocks[$block]['unique'] === true ) {
							break;
						}
						$this->unlinkSingleBlock( $blocks[$block] );
						$unlinked = true;
						$blockStart = $block;
					}
				}

				// Unlink blocks from end
				for ( $block = $blockEnd; $block > $blockStart; $block -- ) {
					if ( $blocks[$block]['type'] == '=' ) {

						// Stop unlinking if more than one word or a unique word
						if (
							$blocks[$block]['words'] > 1 || (
								$blocks[$block]['words'] == 1 && $blocks[$block]['unique'] === true
							)
						) {
							break;
						}
						$this->unlinkSingleBlock( $blocks[$block] );
						$unlinked = true;
					}
				}
			}
		}
		return $unlinked;
	}


	/**
	 * Unlink text tokens of single block, convert them into into insertion/deletion ('+'/'-') pairs
	 *
	 * @param[in] array $blocks Blocks table object
	 * @param[out] WikEdDiffText $newText, $oldText Text objects, link property
	 */
	private function unlinkSingleBlock( &$block ) {

		// Cycle through old text
		$j = $block['oldStart'];
		for ( $count = 0; $count < $block['count']; $count ++ ) {

			// Unlink tokens
			$this->newText->tokens[ $this->oldText->tokens[$j]['link'] ]['link'] = null;
			$this->oldText->tokens[$j]['link'] = null;
			$j = $this->oldText->tokens[$j]['next'];
		}
	}


	/**
	 * Collect insertion ('+') blocks from new text
	 *
	 * @param[in] WikEdDiffText $newText New Text object
	 * @param[out] array $blocks Blocks table object
	 */
	private function getInsBlocks() {

		$blocks = &$this->blocks;

		// Cycle through new text to find insertion blocks
		$i = $this->newText->first;
		while ( $i !== null ) {

			// Jump over linked (matched) block
			while ( $i !== null && $this->newText->tokens[$i]['link'] !== null ) {
				$i = $this->newText->tokens[$i]['next'];
			}

			// Detect insertion blocks ('+')
			if ( $i !== null ) {
				$iStart = $i;
				$count = 0;
				$text = '';
				while ( $i !== null && $this->newText->tokens[$i]['link'] === null ) {
					$count ++;
					$text .= $this->newText->tokens[$i]['token'];
					$i = $this->newText->tokens[$i]['next'];
				}

				// Save new text '+' block
				array_push( $blocks, array(
					'oldBlock'  => null,
					'newBlock'  => null,
					'oldNumber' => null,
					'newNumber' => $this->newText->tokens[$iStart]['number'],
					'oldStart'  => null,
					'count'     => $count,
					'unique'    => false,
					'words'     => null,
					'chars'     => mb_strlen( $text ),
					'type'      => '+',
					'section'   => null,
					'group'     => null,
					'fixed'     => null,
					'moved'     => null,
					'text'      => $text
				) );
			}
		}

		// Sort '+' blocks in and update groups
		$this->sortBlocks();
	}


	/**
	 * Sort blocks by new text token number and update groups
	 *
	 * @param[in/out] array $groups Groups table object
	 * @param[in/out] array $blocks Blocks table object
	 */
	private function sortBlocks() {

		$blocks = &$this->blocks;
		$groups = &$this->groups;

		// Sort by newNumber, then by old number
		usort( $blocks, function( $a, $b ) {
			$comp = $a['newNumber'] - $b['newNumber'];
			if ( $comp === 0 ) {
				$comp = $a['oldNumber'] - $b['oldNumber'];
			}
			return $comp;
		} );

		// Cycle through blocks and update groups with new block numbers
		$group = null;
		for ( $block = 0; $block < count( $blocks ); $block ++ ) {
			$blockGroup = $blocks[$block]['group'];
			if ( $blockGroup !== null ) {
				if ( $blockGroup !== $group ) {
					$group = $blocks[$block]['group'];
					$groups[$group]['blockStart'] = $block;
					$groups[$group]['oldNumber'] = $blocks[$block]['oldNumber'];
				}
				$groups[$blockGroup]['blockEnd'] = $block;
			}
		}
	}


	/**
	 * Set group numbers of insertion '+' blocks
	 *
	 * @param[in/out] array $groups Groups table object
	 * @param[in/out] array $blocks Blocks table object, fixed and group properties
	 */
	private function setInsGroups() {

		$blocks = &$this->blocks;
		$groups = &$this->groups;

		// Set group numbers of '+' blocks inside existing groups
		for ( $group = 0; $group < count( $groups ); $group ++ ) {
			$fixed = $groups[$group]['fixed'];
			for ( $block = $groups[$group]['blockStart']; $block <= $groups[$group]['blockEnd']; $block ++ ) {
				if ( $blocks[$block]['group'] === null ) {
					$blocks[$block]['group'] = $group;
					$blocks[$block]['fixed'] = $fixed;
				}
			}
		}

		// Add remaining '+' blocks to new groups

		// Cycle through blocks
		for ( $block = 0; $block < count( $blocks ); $block ++ ) {

			// Skip existing groups
			if ( $blocks[$block]['group'] === null ) {
				$blocks[$block]['group'] = count( $groups );

				// Save new single-block group
				array_push( $groups, array(
					'oldNumber'  => $blocks[$block]['oldNumber'],
					'blockStart' => $block,
					'blockEnd'   => $block,
					'unique'     => $blocks[$block]['unique'],
					'maxWords'   => $blocks[$block]['words'],
					'words'      => $blocks[$block]['words'],
					'chars'      => $blocks[$block]['chars'],
					'fixed'      => $blocks[$block]['fixed'],
					'movedFrom'  => null,
					'color'      => null
				) );
			}
		}
	}


	/**
	 * Mark original positions of moved groups
	 * Scheme: moved block marks at original positions relative to fixed groups:
	 *   Groups:    3       7
	 *           1 <|       |     (no next smaller fixed)
	 *           5  |<      |
	 *              |>  5   |
	 *              |   5  <|
	 *              |      >|   5
	 *              |       |>  9 (no next larger fixed)
	 *   Fixed:     *       *
	 * Mark direction: $groups[$movedGroup]['blockStart'] < $groups[$group]['blockStart']
	 * Group side:     $groups[$movedGroup]['oldNumber']  < $groups[$group]['oldNumber']
	 * Marks '|' and deletions '-' get newNumber of reference block
	 *   and are sorted around it by old text number
	 *
	 * @param[in/out] array $groups Groups table object, movedFrom property
	 * @param[in/out] array $blocks Blocks table object
	 */
	private function insertMarks() {

		$blocks = &$this->blocks;
		$groups = &$this->groups;
		$moved = array();
		$color = 1;

		// Make shallow copy of blocks
		$blocksOld = $blocks;

		// Enumerate copy
		for ( $i = 0; $i < count( $blocksOld ); $i ++ ) {
			$blocksOld[$i]['number'] = $i;
		}

		// Sort copy by oldNumber
		usort( $blocksOld, function( $a, $b ) {
			$comp = $a['oldNumber'] - $b['oldNumber'];
			if ( $comp === 0 ) {
				$comp = $a['newNumber'] - $b['newNumber'];
			}
			return $comp;
		} );

		// Create lookup table: original to sorted
		$lookupSorted = array();
		for ( $i = 0; $i < count( $blocksOld ); $i ++ ) {
			$lookupSorted[ $blocksOld[$i]['number'] ] = $i;
		}

		// Cycle through groups (moved group)
		for ( $moved = 0; $moved < count( $groups ); $moved ++ ) {
			$movedGroup = &$groups[$moved];
			if ( $movedGroup['fixed'] !== false ) {
				continue;
			}
			$movedOldNumber = $movedGroup['oldNumber'];

			// Find fixed '=' reference block from original block position to position '|' block, similar to position '-' code

			// Get old text prev block
			$prevBlock = null;
			$block = $lookupSorted[ $movedGroup['blockStart'] ];
			if ( $block > 0 ) {
				$prevBlock = $blocksOld[$block - 1];
			}

			// Get old text next block
			$nextBlock = null;
			$block = $lookupSorted[ $movedGroup['blockEnd'] ];
			if ( $block < count( $blocksOld ) - 1 ) {
				$nextBlock = $blocksOld[$block + 1];
			}

			// Move after prev block if fixed
			$refBlock = null;
			if ( $prevBlock !== null && $prevBlock['type'] == '=' && $prevBlock['fixed'] === true ) {
				$refBlock = $prevBlock;
			}

			// Move before next block if fixed
			elseif ( $nextBlock !== null && $nextBlock['type'] == '=' && $nextBlock['fixed'] === true ) {
				$refBlock = $nextBlock;
			}

			// Find closest fixed block to the left
			else {
				for ( $fixed = $lookupSorted[ $movedGroup['blockStart'] ] - 1; $fixed >= 0; $fixed -- ) {
					if ( $blocksOld[$fixed]['type'] == '=' && $blocksOld[$fixed]['fixed'] === true ) {
						$refBlock = $blocksOld[$fixed];
						break;
					}
				}
			}

			// Get position of new mark block

			// No smaller fixed block, moved right from before first block
			if ( $refBlock === null ) {
				$newNumber = -1;
				$markGroup = count( $groups );

				// Save new single-mark-block group
				array_push( $groups, array(
					'oldNumber'  => 0,
					'blockStart' => count( $blocks ),
					'blockEnd'   => count( $blocks ),
					'unique'     => false,
					'maxWords'   => null,
					'words'      => null,
					'chars'      => 0,
					'fixed'      => null,
					'movedFrom'  => null,
					'color'      => null
				) );
			} else {
				$newNumber = $refBlock['newNumber'];
				$markGroup = $refBlock['group'];
			}

			// Insert '|' block
			array_push( $blocks, array(
				'oldBlock'  => null,
				'newBlock'  => null,
				'oldNumber' => $movedOldNumber,
				'newNumber' => $newNumber,
				'oldStart'  => null,
				'count'     => null,
				'unique'    => null,
				'words'     => null,
				'chars'     => 0,
				'type'      => '|',
				'section'   => null,
				'group'     => $markGroup,
				'fixed'     => true,
				'moved'     => $moved,
				'text'      => ''
			) );

			// Set group color
			$movedGroup['color'] = $color;
			$movedGroup['movedFrom'] = $markGroup;
			$color ++;
		}

		// Sort '|' blocks in and update groups
		$this->sortBlocks();

	}


	/**
	 * Collect diff fragment list for markup, create abstraction layer for customized diffs
	 * Adds the following fagment types:
	 *   '=', '-', '+'   same, deletion, insertion
	 *   '<', '>'        mark left, mark right
	 *   '(<', '(>', ')' block start and end
	 *   '[', ']'        fragment start and end
	 *   '{', '}'        container start and end
	 *
	 * @param[in] array $groups Groups table object
	 * @param[in] array $blocks Blocks table object
	 * @param[out] array $fragments Fragments array, abstraction layer for diff code
	 */
	private function getDiffFragments () {

		$blocks = &$this->blocks;
		$groups = &$this->groups;
		$fragments = &$this->fragments;

		// Make shallow copy of groups and sort by blockStart
		$groupsSort = $groups;
		usort( $groupsSort, function( $a, $b ) {
			return $a['blockStart'] - $b['blockStart'];
		} );

		// Cycle through groups
		$htmlFragments = array();
		for ( $group = 0; $group < count( $groupsSort ); $group ++ ) {
			$blockStart = $groupsSort[$group]['blockStart'];
			$blockEnd = $groupsSort[$group]['blockEnd'];

			// Add moved block start
			$color = $groupsSort[$group]['color'];
			if ( $color !== null ) {
				if ( $groupsSort[$group]['movedFrom'] < $blocks[ $blockStart ]['group'] ) {
					$type = '(<';
				} else {
					$type = '(>';
				}
				array_push( $fragments, array(
					'text'  => '',
					'type'  => $type,
					'color' => $color
				) );
			}

			// Cycle through blocks
			for ( $block = $blockStart; $block <= $blockEnd; $block ++ ) {
				$type = $blocks[$block]['type'];

				// Add '=' unchanged text and moved block
				if ( $type == '=' || $type == '-' || $type == '+' ) {
					array_push( $fragments, array(
						'text'  => $blocks[$block]['text'],
						'type'  => $type,
						'color' => $color
					) );
				}

				// Add '<' and '>' marks
				elseif ( $type == '|' ) {
					$movedGroup = $groups[ $blocks[$block]['moved'] ];

					// Get mark text
					$markText = '';
					for ( $movedBlock = $movedGroup['blockStart']; $movedBlock <= $movedGroup['blockEnd']; $movedBlock ++ ) {
						if ( $blocks[$movedBlock]['type'] == '=' || $blocks[$movedBlock]['type'] == '-' ) {
							$markText .= $blocks[$movedBlock]['text'];
						}
					}

					// Get mark direction
					if ( $movedGroup['blockStart'] < $blockStart ) {
						$markType = '<';
					} else {
						$markType = '>';
					}

					// Add mark
						array_push( $fragments, array(
						'text'  => $markText,
						'type'  => $markType,
						'color' => $movedGroup['color']
					) );
				}
			}

			// Add moved block end
			if ( $color !== null ) {
				array_push( $fragments, array(
					'text'  => '',
					'type'  => ')',
					'color' => $color
				) );
			}
		}

		// Cycle through fragments, join consecutive fragments of same type (i.e. '-' blocks)
		for ( $fragment = 1; $fragment < count ( $fragments ); $fragment ++ ) {

			// Check if joinable
			if (
				$fragments[$fragment]['type'] == $fragments[$fragment - 1]['type'] &&
				$fragments[$fragment]['color'] == $fragments[$fragment - 1]['color'] &&
				$fragments[$fragment]['text'] !== '' && $fragments[$fragment - 1]['text'] !== ''
			) {

				// Join and splice
				$fragments[$fragment - 1]['text'] .= $fragments[$fragment]['text'];
				array_splice( $fragments, $fragment, 1 );
				$fragment --;
			}
		}

		// Enclose in containers
		array_unshift( $fragments, array( 'text' => '', 'type' => '[', 'color' => null ) );
		array_unshift( $fragments, array( 'text' => '', 'type' => '{', 'color' => null ) );
		array_push(    $fragments, array( 'text' => '', 'type' => ']', 'color' => null ) );
		array_push(    $fragments, array( 'text' => '', 'type' => '}', 'color' => null ) );

		return;
	}


	/**
	 * Clip unchanged sections from unmoved block text
	 * Adds the following fagment types:
	 *   '~', ' ~', '~ ' omission indicators
	 *   '[', ']', ','   fragment start and end, fragment separator
	 *
	 * @param[in/out] array $fragments Fragments array, abstraction layer for diff code
	 */
	private function clipDiffFragments () {

		$fragments = &$this->fragments;

		// Skip if only one fragment in containers, no change
		if ( count( $fragments ) == 5 ) {
			return;
		}

		// Min length for clipping right
		$minRight = $this->config['clipHeadingRight'];
		if ( $this->config['clipParagraphRightMin'] < $minRight ) {
			$minRight = $this->config['clipParagraphRightMin'];
		}
		if ( $this->config['clipLineRightMin'] < $minRight ) {
			$minRight = $this->config['clipLineRightMin'];
		}
		if ( $this->config['clipBlankRightMin'] < $minRight ) {
			$minRight = $this->config['clipBlankRightMin'];
		}
		if ( $this->config['clipCharsRight'] < $minRight ) {
			$minRight = $this->config['clipCharsRight'];
		}

		// Min length for clipping left
		$minLeft = $this->config['clipHeadingLeft'];
		if ( $this->config['clipParagraphLeftMin'] < $minLeft ) {
			$minLeft = $this->config['clipParagraphLeftMin'];
		}
		if ( $this->config['clipLineLeftMin'] < $minLeft ) {
			$minLeft = $this->config['clipLineLeftMin'];
		}
		if ( $this->config['clipBlankLeftMin'] < $minLeft ) {
			$minLeft = $this->config['clipBlankLeftMin'];
		}
		if ( $this->config['clipCharsLeft'] < $minLeft ) {
			$minLeft = $this->config['clipCharsLeft'];
		}

		// Cycle through fragments
		for ( $fragment = 0; $fragment < count( $fragments ); $fragment ++ ) {

			// Skip if not an unmoved and unchanged block
			$type = $fragments[$fragment]['type'];
			$color = $fragments[$fragment]['color'];
			if ( $type !== '=' || $color !== null ) {
				continue;
			}

			// Skip if too short for clipping
			$text = $fragments[$fragment]['text'];
			if ( mb_strlen( $text ) < $minRight && mb_strlen( $text ) < $minLeft ) {
				continue;
			}

			// Get line positions including start and end
			$lines = array();
			$lastIndex = null;
			preg_match_all( $this->config['regExp']['clipLine'], $text, $regExpMatch, PREG_OFFSET_CAPTURE );
			for ( $i = 0; $i < count( $regExpMatch[0] ); $i ++ ) {
				array_push( $lines, $regExpMatch[0][$i][1] );
				$lastIndex = $regExpMatch[0][$i][1] + strlen( $regExpMatch[0][$i][0] );
			}
			if ( !isset( $lines[0] ) || $lines[0] !== 0 ) {
				array_unshift( $lines, 0 );
			}
			if ( $lastIndex !== strlen( $text ) ) {
				array_push( $lines, strlen( $text ) );
			}
// $this->debug( '$text', $text);
// $this->debug( 'strlen( $text )', strlen( $text ) );
// $this->debug( '$lines', $lines);

			// Get heading positions
			$headings = array();
			$headingsEnd = array();
			preg_match_all( $this->config['regExp']['clipHeading'], $text, $regExpMatch, PREG_OFFSET_CAPTURE );
			for ( $i = 0; $i < count( $regExpMatch[0] ); $i ++ ) {
				array_push( $headings, $regExpMatch[0][$i][1] );
				array_push( $headingsEnd, $regExpMatch[0][$i][1] + strlen( $regExpMatch[0][$i][0] ) );
			}
// $this->debug( '$headings', $headings);

			// Get paragraph positions including start and end
			$paragraphs = array();
			$lastIndex = null;
			preg_match_all( $this->config['regExp']['clipParagraph'], $text, $regExpMatch, PREG_OFFSET_CAPTURE );
			for ( $i = 0; $i < count( $regExpMatch[0] ); $i ++ ) {
				array_push( $paragraphs, $regExpMatch[0][$i][1] );
				$lastIndex = $regExpMatch[0][$i][1] + strlen( $regExpMatch[0][$i][0] );
			}
			if ( !isset( $paragraphs[0] ) || $paragraphs[0] !== 0 ) {
				array_unshift( $paragraphs, 0 );
			}
			if ( $lastIndex !== strlen( $text ) ) {
				array_push( $paragraphs, strlen( $text ) );
			}
// $this->debug( '$paragraphs', $paragraphs);

			// Determine ranges to keep on left and right side
			$rangeRight = null;
			$rangeLeft = null;
			$rangeRightType = '';
			$rangeLeftType = '';

			// Find clip pos from left, skip for first non-container block
			if ( $fragment != 2 ) {

				// Maximum lines to search from left
				$rangeLeftMax = strlen( $text );
				if ( $this->config['clipLinesLeftMax'] < count( $lines ) ) {
					$rangeLeftMax = $lines[$this->config['clipLinesLeftMax']];
				}

				// Find first heading from left
				if ( $rangeLeft === null ) {
					for ( $j = 0; $j < count( $headingsEnd ); $j ++ ) {
						if ( $headingsEnd[$j] > $this->config['clipHeadingLeft'] || $headingsEnd[$j] > $rangeLeftMax ) {
							break;
						}
						$rangeLeft = $headingsEnd[$j];
						$rangeLeftType = 'heading';
						break;
					}
				}

				// Find first paragraph from left
				if ( $rangeLeft === null ) {
					for ( $j = 0; $j < count( $paragraphs ); $j ++ ) {
						if ( $paragraphs[$j] > $this->config['clipParagraphLeftMax'] || $paragraphs[$j] > $rangeLeftMax ) {
							break;
						}
						if ( $paragraphs[$j] > $this->config['clipParagraphLeftMin'] ) {
							$rangeLeft = $paragraphs[$j];
							$rangeLeftType = 'paragraph';
							break;
						}
					}
				}

				// Find first line break from left
				if ( $rangeLeft === null ) {
					for ( $j = 0; $j < count( $lines ); $j ++ ) {
						if ( $lines[$j] > $this->config['clipLineLeftMax'] || $lines[$j] > $rangeLeftMax ) {
							break;
						}
						if ( $lines[$j] > $this->config['clipLineLeftMin'] ) {
							$rangeLeft = $lines[$j];
							$rangeLeftType = 'line';
							break;
						}
					}
				}

				// Find blank from left
				if ( $rangeLeft === null ) {
					if ( preg_match( $this->config['regExp']['clipBlank'], $text, $regExpMatch, PREG_OFFSET_CAPTURE, $this->config['clipBlankLeftMin'] ) === 1 ) {
						if ( $regExpMatch[0][1] < $this->config['clipBlankLeftMax'] && $regExpMatch[0][1] < $rangeLeftMax ) {
							$rangeLeft = $regExpMatch[0][1];
							$rangeLeftType = 'blank';
						}
					}
				}

				// Fixed number of chars from left
				if ( $rangeLeft === null ) {
					if ( $this->config['clipCharsLeft'] < $rangeLeftMax ) {

						// Get byte length from UniCode length
						$rangeLeft = strlen( mb_substr( $text, 0, $this->config['clipCharsLeft'] ) );
						$rangeLeftType = 'chars';
					}
				}

				// Fixed number of lines from left
				if ( $rangeLeft === null ) {
					$rangeLeft = $rangeLeftMax;
					$rangeLeftType = 'fixed';
				}
			}

			// Find clip pos from right, skip for last non-container block
			if ( $fragment != count( $fragments ) - 3 ) {

				// Maximum lines to search from right
				$rangeRightMin = 0;
				if ( count( $lines ) >= $this->config['clipLinesRightMax'] ) {
					$rangeRightMin = $lines[count( $lines ) - $this->config['clipLinesRightMax']];
				}

				// Find last heading from right
				if ( $rangeRight === null ) {
					for ( $j = count( $headings ) - 1; $j >= 0; $j -- ) {
						if (
							$headings[$j] < strlen( $text ) - $this->config['clipHeadingRight'] ||
							$headings[$j] < $rangeRightMin
						) {
							break;
						}
						$rangeRight = $headings[$j];
						$rangeRightType = 'heading';
						break;
					}
				}

				// Find last paragraph from right
				if ( $rangeRight === null ) {
					for ( $j = count( $paragraphs ) - 1; $j >= 0 ; $j -- ) {
						if (
							$paragraphs[$j] < strlen( $text ) - $this->config['clipParagraphRightMax'] ||
							$paragraphs[$j] < $rangeRightMin
						) {
							break;
						}
						if ( $paragraphs[$j] < strlen( $text ) - $this->config['clipParagraphRightMin'] ) {
							$rangeRight = $paragraphs[$j];
							$rangeRightType = 'paragraph';
							break;
						}
					}
				}

				// Find last line break from right
				if ( $rangeRight === null ) {
					for ( $j = count( $lines ) - 1; $j >= 0; $j -- ) {
						if (
							$lines[$j] < strlen( $text ) - $this->config['clipLineRightMax'] ||
							$lines[$j] < $rangeRightMin
						) {
							break;
						}
						if ( $lines[$j] < strlen( $text ) - $this->config['clipLineRightMin'] ) {
							$rangeRight = $lines[$j];
							$rangeRightType = 'line';
							break;
						}
					}
				}

				// Find last blank from right
				if ( $rangeRight === null ) {
					$startPos = strlen( $text ) - $this->config['clipBlankRightMax'];
					if ( $startPos < $rangeRightMin ) {
						$startPos = $rangeRightMin;
					}
					$lastPos = $startPos;
					while ( preg_match( $this->config['regExp']['clipBlank'], $text, $regExpMatch, PREG_OFFSET_CAPTURE, $lastPos ) === 1 ) {
						if ( $regExpMatch[0][1] > strlen( $text ) - $this->config['clipBlankRightMin'] ) {
							if ( $lastPos !== null ) {
								$rangeRight = $lastPos;
								$rangeRightType = 'blank';
							}
							break;
						}
						$lastPos = $regExpMatch[0][1] + strlen( $regExpMatch[0][0] );
					}
				}

				// Fixed number of chars from right
				if ( $rangeRight === null ) {
					if ( strlen( $text ) - $this->config['clipCharsRight'] > $rangeRightMin ) {

						// Get byte length from UniCode length
						$rangeRight = strlen( mb_substr( $text, mb_strlen( $text ) - $this->config['clipCharsRight'] ) );
						$rangeRightType = 'chars';
					}
				}

				// Fixed number of lines from right
				if ( $rangeRight === null ) {
					$rangeRight = $rangeRightMin;
					$rangeRightType = 'fixed';
				}
			}

			// Check if we skip clipping if ranges are close together
			if ( $rangeLeft !== null && $rangeRight !== null ) {

				// Skip if overlapping ranges
				if ( $rangeLeft > $rangeRight ) {
					continue;
				}

				// Skip if chars too close
				$skipChars = $rangeRight - $rangeLeft;
				if ( $skipChars < $this->config['clipSkipChars'] ) {
					continue;
				}

				// Skip if lines too close
				$skipLines = 0;
				for ( $j = 0; $j < count( $lines ); $j ++ ) {
					if ( $lines[$j] > $rangeRight || $skipLines > $this->config['clipSkipLines'] ) {
						break;
					}
					if ( $lines[$j] > $rangeLeft ) {
						$skipLines ++;
					}
				}
				if ( $skipLines < $this->config['clipSkipLines'] ) {
					continue;
				}
			}

			// Skip if nothing to clip
			if ( $rangeLeft === null && $rangeRight === null ) {
				continue;
			}

			// Split left text
			$textLeft = null;
			$omittedLeft = null;
			if ( $rangeLeft !== null ) {
				$textLeft = substr( $text, 0, $rangeLeft );

				// Remove trailing empty lines
				$textLeft = preg_replace( $this->config['regExp']['clipTrimNewLinesLeft'], '', $textLeft );

				// Get omission indicators, remove trailing blanks
				if ( $rangeLeftType == 'chars' ) {
					$omittedLeft = '~';
					$textLeft = preg_replace( $this->config['regExp']['clipTrimBlanksLeft'], '', $textLeft );
				} elseif ( $rangeLeftType == 'blank' ) {
					$omittedLeft = ' ~';
					$textLeft = preg_replace( $this->config['regExp']['clipTrimBlanksLeft'], '', $textLeft );
				}
			}

			// Split right text,
			$textRight = null;
			$omittedRight = null;
			if ( $rangeRight !== null ) {
				$textRight = substr( $text, strlen( $text ) - $rangeRight );

				// Remove leading empty lines
				$textRight = preg_replace( $this->config['regExp']['clipTrimNewLinesRight'], '', $textRight );

				// Get omission indicators, remove leading blanks
				if ( $rangeRightType == 'chars' ) {
					$omittedRight = '~';
					$textRight = preg_replace( $this->config['regExp']['clipTrimBlanksRight'], '', $textRight );
				} elseif ( $rangeRightType == 'blank' ) {
					$omittedRight = '~ ';
					$textRight = preg_replace( $this->config['regExp']['clipTrimBlanksRight'], '', $textRight );
				}
			}

			// Remove split element
			array_splice( $fragments, $fragment, 1 );

			// Add left text to fragments list
			if ( $rangeLeft !== null ) {
				array_splice( $fragments, $fragment ++, 0, array( array( 'text' => $textLeft, 'type' => '=', 'color' => null ) ) );
				if ( $omittedLeft !== null ) {
					array_splice( $fragments, $fragment ++, 0, array( array( 'text' => '', 'type' => $omittedLeft, 'color' => null ) ) );
				}
			}

			// Add fragment container and separator to list
			if ( $rangeLeft !== null && $rangeRight !== null ) {
				array_splice( $fragments, $fragment ++, 0, array( array( 'text' => '', 'type' => ']', 'color' => null ) ) );
				array_splice( $fragments, $fragment ++, 0, array( array( 'text' => '', 'type' => ',', 'color' => null ) ) );
				array_splice( $fragments, $fragment ++, 0, array( array( 'text' => '', 'type' => '[', 'color' => null ) ) );
			}

			// Add right text to fragments list
			if ( $rangeRight !== null ) {
				if ( $omittedRight !== null ) {
					array_splice( $fragments, $fragment ++, 0, array( array( 'text' => '', 'type' => $omittedRight, 'color' => null ) ) );
				}
				array_splice( $fragments, $fragment ++, 0, array( array( 'text' => $textRight, 'type' => '=', 'color' => null ) ) );
			}
		}

		// Debug log
		if ( $this->config['debug'] === true ) {
			$this->debugFragments( 'Fragments' );
		}
	}


	/**
	 * Create html formatted diff code from diff fragments
	 *
	 * @param[in] array $fragments Fragments array, abstraction layer for diff code
	 * @param string|null $version
	 *   Output version: 'new' or 'old': only text from new or old version, used for unit tests
	 * @param[out] string $html Html code of diff
	 */
	private function getDiffHtml ( $version = null ) {

		$fragments = &$this->fragments;

		// No change, only one unchanged block in containers
		if ( count( $fragments ) == 5 && $fragments[2]['type'] == '=' ) {
			$this->html = '';
			return;
		}

		// Cycle through fragments
		$htmlFragments = array();
		for ( $fragment = 0; $fragment < count( $fragments ); $fragment ++ ) {
			$text = $fragments[$fragment]['text'];
			$type = $fragments[$fragment]['type'];
			$color = $fragments[$fragment]['color'];
			$html = '';

			// Test if text is blanks-only or a single character
			$blank = false;
			if ( $text !== '' ) {
				$blank = ( preg_match( $this->config['regExp']['blankBlock'], $text ) === 1 );
			}

			// Add container start markup
			if ( $type == '{' ) {
				$html = $this->config['htmlCode']['containerStart'];
			}

			// Add container end markup
			elseif ( $type == '}' ) {
				$html = $this->config['htmlCode']['containerEnd'];
			}

			// Add fragment start markup
			if ( $type == '[' ) {
				$html = $this->config['htmlCode']['fragmentStart'];
			}

			// Add fragment end markup
			elseif ( $type == ']' ) {
				$html = $this->config['htmlCode']['fragmentEnd'];
			}

			// Add fragment separator markup
			elseif ( $type == ',' ) {
				$html = $this->config['htmlCode']['separator'];
			}

			// Add omission markup
			if ( $type == '~' ) {
				$html = $this->config['htmlCode']['omittedChars'];
			}

			// Add omission markup
			if ( $type == ' ~' ) {
				$html = ' ' . $this->config['htmlCode']['omittedChars'];
			}

			// Add omission markup
			if ( $type == '~ ' ) {
				$html = $this->config['htmlCode']['omittedChars'] . ' ';
			}

			// Add colored left-pointing block start markup
			elseif ( $type == '(<' ) {
				if ( $version != 'old' ) {

					// Get title
					if ( $this->config['noUnicodeSymbols'] === true ) {
						$title = wfMessage( 'wiked-diff-block-left-nounicode' )->plain();
					} else {
						$title = wfMessage( 'wiked-diff-block-left' )->plain();
					}

					// Get html
					if ( $this->config['coloredBlocks'] === true ) {
						$html = $this->config['htmlCode']['blockColoredStart'];
					} else {
						$html = $this->config['htmlCode']['blockStart'];
					}
					$html = $this->htmlCustomize( $html, $color, $title );
				}
			}

			// Add colored right-pointing block start markup
			elseif ( $type == '(>' ) {
				if ( $version != 'old' ) {

					// Get title
					if ( $this->config['noUnicodeSymbols'] === true ) {
						$title = wfMessage( 'wiked-diff-block-right-nounicode' )->plain();
					} else {
						$title = wfMessage( 'wiked-diff-block-right' )->plain();
					}

					// Get html
					if ( $this->config['coloredBlocks'] === true ) {
						$html = $this->config['htmlCode']['blockColoredStart'];
					} else {
						$html = $this->config['htmlCode']['blockStart'];
					}
					$html = $this->htmlCustomize( $html, $color, $title );
				}
			}

			// Add colored block end markup
			elseif ( $type == ')' ) {
				if ( $version != 'old' ) {
					$html = $this->config['htmlCode']['blockEnd'];
				}
			}

			// Add '=' (unchanged) text and moved block
			if ( $type == '=' ) {
				$text = $this->htmlEscape( $text );
				if ( $color !== null ) {
					if ( $version != 'old' ) {
						$html = $this->markupBlanks( $text, true );
					}
				} else {
					$html = $this->markupBlanks( $text );
				}
			}

			// Add '-' text
			elseif ( $type == '-' ) {
				if ( $version != 'new' ) {

					// For old version skip '-' inside moved group
					if ( $version != 'old' || $color === null ) {
						$text = $this->htmlEscape( $text );
						$text = $this->markupBlanks( $text, true );
						if ( $blank === true ) {
							$html = $this->config['htmlCode']['deleteStartBlank'];
						} else {
							$html = $this->config['htmlCode']['deleteStart'];
						}
						$html .= $text . $this->config['htmlCode']['deleteEnd'];
					}
				}
			}

			// Add '+' text
			elseif ( $type == '+' ) {
				if ( $version != 'old' ) {
					$text = $this->htmlEscape( $text );
					$text = $this->markupBlanks( $text, true );
					if ( $blank === true ) {
						$html = $this->config['htmlCode']['insertStartBlank'];
					} else {
						$html = $this->config['htmlCode']['insertStart'];
					}
					$html .= $text . $this->config['htmlCode']['insertEnd'];
				}
			}

			// Add '<' and '>' code
			elseif ( $type == '<' || $type == '>' ) {
				if ( $version != 'new' ) {

					// Display as deletion at original position
					if ( $this->config['showBlockMoves'] === false || $version == 'old' ) {
						$text = $this->htmlEscape( $text );
						$text = $this->markupBlanks( $text, true );
						if ( $version == 'old' ) {
							if ( $this->config['coloredBlocks'] === true ) {
								$html = $this->htmlCustomize( $this->config['htmlCode']['blockColoredStart'], $color ) . $text . $this->config['htmlCode']['blockEnd'];
							} else {
								$html = $this->htmlCustomize( $this->config['htmlCode']['blockStart'], $color ) . $text . $this->config['htmlCode']['blockEnd'];
							}
						} else {
							if ( $blank === true ) {
								$html = $this->config['htmlCode']['deleteStartBlank'] . $text . $this->config['htmlCode']['deleteEnd'];
							} else {
								$html = $this->config['htmlCode']['deleteStart'] . $text . $this->config['htmlCode']['deleteEnd'];
							}
						}
					}

					// Display as mark
					else {
						if ( $type == '<' ) {
							if ( $this->config['coloredBlocks'] === true ) {
								$html = $this->htmlCustomize( $this->config['htmlCode']['markLeftColored'], $color, $text );
							} else {
								$html = $this->htmlCustomize( $this->config['htmlCode']['markLeft'], $color, $text );
							}
						} else {
							if ( $this->config['coloredBlocks'] === true ) {
								$html = $this->htmlCustomize( $this->config['htmlCode']['markRightColored'], $color, $text );
							} else {
								$html = $this->htmlCustomize( $this->config['htmlCode']['markRight'], $color, $text );
							}
						}
					}
				}
			}
			array_push( $htmlFragments, $html );
		}

		// Join fragments
		$this->html = implode( $htmlFragments );
	}


	/**
	 * Customize html code fragments
	 * Replaces:
	 *   {number}:    class/color/block/mark/id number
	 *   {title}:     title attribute (popup)
	 *   {nounicode}: noUnicodeSymbols fallback
	 *   input: html, number: block number, title: title attribute (popup) text
	 *
	 * @param string $html Html code to be customized
	 * @return string Customized html code
	 */
	private function htmlCustomize( $html, $number, $title = null ) {

		// Replace {number} with class/color/block/mark/id number
		$html = str_replace( '{number}', $number, $html );

		// Replace {nounicode} with wikEdDiffNoUnicode class name
		if ( $this->config['noUnicodeSymbols'] === true ) {
			$html = str_replace( '{nounicode}', ' wikEdDiffNoUnicode', $html );
		} else {
			$html = str_replace( '{nounicode}', '', $html );
		}

		// Shorten title text, replace {title}
		if ( $title !== null ) {
			$max = 512;
			$end = 128;
			$gapMark = ' [...] ';
			$length = strlen( $title );
			if ( $length > $max ) {
				$title = substr( $title, 0, $max - strlen( $gapMark ) - $end ) . $gapMark . substr( $title, $length - $end );
			}
			$title = $this->htmlEscape( $title );
			$title = str_replace( "\t", '&nbsp;&nbsp;', $title );
			$title = str_replace( '  ', '&nbsp;&nbsp;', $title );
			$html = str_replace( '{title}', $title, $html );
		}
		return $html;
	}


	/**
	 * Replace html-sensitive characters in output text with character entities
	 *
	 * @param string $html Html code to be escaped
	 * @return string Escaped html code
	 */
	private function htmlEscape( $html ) {

		return htmlspecialchars( $html );
	}


	/**
	 * Markup tabs, newlines, and spaces in diff fragment text
	 *
	 * @param bool $highlight Highlight newlines and tabs in addition to spaces
	 * @param string $html Text code to be marked-up
	 * @return string Marked-up text
	 */
	private function markupBlanks( $html, $highlight = false ) {

		$html = str_replace( " ", $this->config['htmlCode']['space'], $html );
		if ( $highlight === true ) {
			$html = str_replace( "\n", $this->config['htmlCode']['newline'], $html );
			$html = str_replace( "\t", $this->config['htmlCode']['tab'], $html );
		}
		return $html;
	}


	/**
	 * Count real words in text
	 *
	 * @param string $text Text for word counting
	 * @return int Number of words in text
	 */
	private function wordCount( &$text ) {

		return preg_match_all( $this->config['regExp']['countWords'], $text );
	}

	/**
	 * Test diff code for consistency with input versions
	 * Prints results to debug console
	 *
	 * @param[in] WikEdDiffText $newText, $oldText Text objects
	 */
	private function unitTests () {

		// Check if output is consistent with new text
		$this->getDiffHtml( 'new' );
		$diff = preg_replace( '/<[^>]*>/', '', $this->html );
		$text = $this->htmlEscape( $this->newText->text );
		if ( $diff != $text ) {
			$this->debug( 'Error: wikEdDiff unit test failure: diff not consistent with new text version!' );
			$this->error = true;
			$this->debug( 'new text', $text );
			$this->debug( 'new diff', $diff );
		} else {
			$this->debug( 'OK: wikEdDiff unit test passed: diff consistent with new text.' );
		}

		// Check if output is consistent with old text
		$this->getDiffHtml( 'old' );
		$diff = preg_replace( '/<[^>]*>/', '', $this->html );
		$text = $this->htmlEscape( $this->oldText->text );
		if ( $diff != $text ) {
			$this->debug( 'Error: wikEdDiff unit test failure: diff not consistent with old text version!' );
			$this->error = true;
			$this->debug( 'old text', $text );
			$this->debug( 'old diff', $diff );
		} else {
			$this->debug( 'OK: wikEdDiff unit test passed: diff consistent with old text.' );
		}
	}


	/**
	 * Debug functions, add the following css to common.css to see tab-separated tables in console:
	 *   .mw-debug table td { white-space: pre; font-family: monospace; font-size: 10px; }
	 *   .mw-debug-pane { height: 700px; }
	 */


	/**
	 * Dump blocks object to debug console
	 *
	 * @param string $name Block name
	 * @param[in] array $blocks Blocks table object
	 */
	private function debugBlocks( $name, &$blocks = null ) {

		if ( $blocks === null ) {
			$blocks = &$this->blocks;
		}
		$dump = "\ni \toldBl \tnewBl \toldNm \tnewNm \toldSt \tcount \tuniq \twords \tchars \ttype \tsect \tgroup \tfixed \tmoved \tname\n";
		for ( $i = 0; $i < count( $blocks ); $i ++ ) {
			$dump .=
				$i . " \t" .
				$blocks[$i]['oldBlock'] . " \t" .
				$blocks[$i]['newBlock'] . " \t" .
				$blocks[$i]['oldNumber'] . " \t" .
				$blocks[$i]['newNumber'] . " \t" .
				$blocks[$i]['oldStart'] . " \t" .
				$blocks[$i]['count'] . " \t" .
				$blocks[$i]['unique'] . " \t" .
				$blocks[$i]['words'] . " \t" .
				$blocks[$i]['chars'] . " \t" .
				$blocks[$i]['type'] . " \t" .
				$blocks[$i]['section'] . " \t" .
				$blocks[$i]['group'] . " \t" .
				$blocks[$i]['fixed'] . " \t" .
				$blocks[$i]['moved'] . " \t" .
				$this->debugShortenText( $blocks[$i]['text'] ) . "\n";
		}
		$this->debug( $name, $dump );
	}


	/**
	 * Dump groups object to debug console
	 *
	 * @param string $name Group name
	 * @param[in] array $groups Groups table object
	 */
	private function debugGroups( $name, &$groups = null ) {

		if ( $groups === null ) {
			$groups = &$this->groups;
		}
		$dump = "\ni \toldNm \tblSta \tblEnd \tuniq \tmaxWo \twords \tchars \tfixed \toldNm \tmFrom \tcolor\n";
		for ( $i = 0; $i < count( $groups ); $i ++ ) {
			$dump .=
				$i . " \t" .
				$groups[$i]['oldNumber'] . " \t" .
				$groups[$i]['blockStart'] . " \t" .
				$groups[$i]['blockEnd'] . " \t" .
				$groups[$i]['unique'] . " \t" .
				$groups[$i]['maxWords'] . " \t" .
				$groups[$i]['words'] . " \t" .
				$groups[$i]['chars'] . " \t" .
				$groups[$i]['fixed'] . " \t" .
				$groups[$i]['oldNumber'] . " \t" .
				$groups[$i]['movedFrom'] . " \t" .
				$groups[$i]['color'] . "\n";
		}
		$this->debug( $name, $dump );
	}


	/**
	 * Dump fragments array to debug console
	 *
	 * @param string $name Fragments name
	 * @param[in] array $fragments Fragments array
	 */
	private function debugFragments ( $name ) {

		$fragments = &$this->fragments;
		$dump = "\ni \ttype \tcolor \tname\n";
		for ( $i = 0; $i < count( $fragments ); $i ++ ) {
			$dump .=
				$i . " \t\"" .
				$fragments[$i]['type'] . "\" \t" .
				$fragments[$i]['color'] . " \t" .
				$this->debugShortenText( $fragments[$i]['text'], 120, 40 ) . "\n";
		}
		$this->debug( $name, $dump );
	}


	/**
	 * Shorten text for dumping
	 *
	 * @param string $text Text to be shortened
	 * @param int $max Max length of (shortened) text
	 * @param int $end Length of trailing fragment of shortened text
	 * @return string Shortened text
	 */
	public function debugShortenText ( $text, $max = 50, $end = 15 ) {

		$text = preg_replace( "/\n/", "\\n", $text );
		$text = preg_replace( "/\t/", "  ", $text );
		if ( mb_strlen( $text ) > $max ) {
			$text = mb_substr( $text, 0, $max - 1 - $end ) . '…' . mb_substr( $text, mb_strlen( $text ) - $end );
		}
		return "\"$text\"";
	}


	/**
	 * Start timer 'label', analogous to JavaScript console timer
	 * Usage: $this->time( 'label' );
	 *
	 * @param string $label Timer label
	 * @param[out] array $timer
	 */
	protected function time( $label ) {

		$this->timer[$label] = microtime( true );
	}


	/**
	 * Stop timer 'label', analogous to JavaScript console timer
	 * Logs time in milliseconds since start to debug console
	 * Usage: $this->timeEnd( 'label' );
	 *
	 * @param string $label Timer label.
	 * @param bool $noLog Do not log result.
	 * @return float Time in milliseconds, rounded to two decimal digits.
	 */
	protected function timeEnd( $label, $noLog = false ) {

		$diff = 0;
		if ( isset( $this->timer[$label] ) ) {
			$start = $this->timer[$label];
			$stop = microtime( true );
			$diff = round( ( $stop - $start ) * 1000 * 100 ) / 100;
			unset( $this->timer[$label] );
			if ( $noLog === false ) {
				$this->debug( $label . ': ' . $diff . ' ms' );
			}
		}
		return $diff;
	}


	/**
	 * Log recursion timer results to debug console
	 * Usage: $this->timeRecursionEnd();
	 *
	 * @param string $text Text label for output
	 * @param[in] array $recursionTimer Accumulated recursion times
	 */
	protected function timeRecursionEnd( $text ) {

		if ( count( $this->recursionTimer ) > 1 ) {

			// Subtract times spent in deeper recursions
			for ( $i = 0; $i < count( $this->recursionTimer ) - 1; $i ++ ) {
				$this->recursionTimer[$i] -= $this->recursionTimer[$i + 1];
			}

			// Log recursion times
			for ( $i = 0; $i < count( $this->recursionTimer ); $i ++ ) {
				$this->debug( "$text recursion $i: " . $this->recursionTimer[$i] . " ms" );
			}
		}
		$this->recursionTimer = array();
	}


	/**
	 * Log variable values to debug console
	 * Usage: $this->debug( '$var', $var );
	 *
	 * @param string $name Object identifier
	 * @param mixed|null $name Object to be logged
	 */
	protected function debug( $name = '', $object = null ) {

		if ( $object === null ) {
			MWDebug::log( "$name\n" );
		} else {
			MWDebug::log( "$name: " . print_r( $object, true ) . "\n" );
		}
	}
}


/**
 * Data and methods for single text version (old or new one)
 *
 * @class WikEdDiffText
 * @ingroup DifferenceEngine
 * @ingroup wikEdDiff
 */
class WikEdDiffText extends WikEdDiff {

	/** @var WikEdDiff $parent Parent object for configuration settings and debugging methods */
	public $parent;

	/** @var string $text Text of this version */
	public $text;

	/** @var array $tokens Tokens list */
	public $tokens = array();

	/** @var int $first, $last First and last index of tokens list */
	public $first = null;
	public $last = null;

	/** @var array $words Word counts for version text */
	public $words = array();


	/**
	 * Constructor, initialize text object
	 *
	 * @param string $text Text of version
	 * @param WikEdDiff $parent Parent, for configuration settings and debugging methods
	 */
	public function __construct ( &$text, &$parent ) {

		$this->text = $text;
		$this->parent = $parent;

		// parse and count words and chunks for identification of unique real words
		if ( $this->config['timer'] === true ) {
			$this->time( 'wordParse' );
		}
		$this->wordParse( $parent->config['regExp']['countWords'] );
		$this->wordParse( $parent->config['regExp']['countChunks'] );
		if ( $this->config['timer'] === true ) {
			$this->timeEnd( 'wordParse' );
		}
	}


	/**
	 * Parse and count words and chunks for identification of unique words
	 *
	 * @param string $regExp Regular expression for counting words
	 * @param[in] string $text Text of version
	 * @param[out] array $words Number of word occurrences
	 */
	protected function wordParse( &$regExp ) {

		preg_match_all( $regExp, $this->text, $regExpMatch );
		for ( $i = 0; $i < count( $regExpMatch[0] ); $i ++ ) {
			$word = $regExpMatch[0][$i];
			if ( !isset( $this->words[$word] ) ) {
				$this->words[$word] = 1;
			} else {
				$this->words[$word] ++;
			}
		}
		return;
	}

	/**
	 * Split text into paragraph, sentence, chunk, word, or character tokens
	 *
	 * @param string $level Level of splitting: paragraph, sentence, chunk, word, or character
	 * @param int|null $token Index of token to be split, otherwise uses full text
	 * @param[in] string $text Full text to be split
	 * @param[out] array $tokens Tokens list
	 * @param[out] int $first, $last First and last index of tokens list
	 */
	protected function splitText( $level, &$token = null ) {

		$prev = null;
		$next = null;
		$current = count( $this->tokens );
		$first = $current;

		// Split full text or specified token
		if ( $token === null ) {
			$text = &$this->text;
		} else {
			$prev = $this->tokens[$token]['prev'];
			$next = $this->tokens[$token]['next'];
			$text = &$this->tokens[$token]['token'];
		}

		// Split text into tokens, regExp match as separator
		$number = 0;
		$split = array();
		preg_match_all( $this->parent->config['regExp']['split'][$level], $text, $regExpMatch, PREG_OFFSET_CAPTURE );
		$lastIndex = 0;
		for ( $i = 0; $i < count( $regExpMatch[0] ); $i ++ ) {
			$regExpMatchIndex = $regExpMatch[0][$i][1];
			if ( $regExpMatchIndex > $lastIndex ) {
				array_push( $split, substr( $text, $lastIndex, $regExpMatchIndex - $lastIndex ) );
			}
			$lastIndex = $regExpMatchIndex + strlen( $regExpMatch[0][$i][0] );
			array_push( $split, $regExpMatch[0][$i][0] );
		}
		if ( $lastIndex < strlen( $text ) ) {
			array_push( $split, substr( $text, $lastIndex ) );
		}

		// Cycle trough new tokens
		for ( $i = 0, $c = count( $split ); $i < $c; $i ++ ) {

			// Insert current item, link to previous
			$this->tokens[$current] = array(
				'token'  => $split[$i],
				'prev'   => $prev,
				'next'   => null,
				'link'   => null,
				'number' => null,
				'unique' => false
			);
			$number ++;

			// Link previous item to current
			if ( $prev !== null ) {
				$this->tokens[$prev]['next'] = $current;
			}
			$prev = $current;
			$current ++;
		}

		// Connect last new item and existing next item
		if ( $number > 0 && $token !== null ) {
			if ( $prev !== null ) {
				$this->tokens[$prev]['next'] = $next;
			}
			if ( $next !== null ) {
				$this->tokens[$next]['prev'] = $prev;
			}
		}

		// Set text first and last token index
		if ( $number > 0 ) {

			// Initial text split
			if ( $token === null ) {
				$this->first = 0;
				$this->last = $prev;
			}

			// First or last token has been split
			else {
				if ( $token == $this->first ) {
					$this->first = $first;
				}
				if ( $token == $this->last ) {
					$this->last = $prev;
				}
			}
		}
	}

	/**
	 * Split unique unmatched tokens into smaller tokens
	 *
	 * @param string $level Level of splitting: sentence, chunk, or word
	 * @param[in] array $tokens Tokens list
	 */
	protected function splitRefine( $level ) {

		// Cycle through tokens list
		$i = $this->first;
		while ( $i !== null && $this->tokens[$i] !== null ) {

			// Refine unique unmatched tokens into smaller tokens
			if ( $this->tokens[$i]['link'] === null ) {
				$this->splitText( $level, $i );
			}
			$i = $this->tokens[$i]['next'];
		}
	}


	/**
	 * Enumerate text token list before detecting blocks
	 *
	 * @param[out] array $tokens Tokens list
	 */
	protected function enumerateTokens() {

		// Enumerate tokens list
		$number = 0;
		$i = $this->first;
		while ( $i !== null && $this->tokens[$i] !== null ) {
			$this->tokens[$i]['number'] = $number;
			$number ++;
			$i = $this->tokens[$i]['next'];
		}
	}


	/**
	 * Dump tokens object to debug console
	 *
	 * @param string $name Text name
	 * @param[in] int $first, $last First and last index of tokens list
	 * @param[in] array $tokens Tokens list
	 */
	protected function debugText( $name ) {

		$tokens = $this->tokens;
		$dump =
			"first: " .
			$this->first . "\tlast: " .
			$this->last . "\n";
		$dump .= "\ni \tlink \t(prev \tnext) \tuniq \t#num \t\"token\"\n";
		$i = $this->first;
		while ( $i !== null && isset( $this->tokens[$i] ) ) {
			$dump .=
				$i . " \t" .
				$tokens[$i]['link'] . " \t(" .
				$tokens[$i]['prev'] . " \t" .
				$tokens[$i]['next'] . ") \t" .
				$tokens[$i]['unique'] . " \t#" .
				$tokens[$i]['number'] . " \t" .
				$this->parent->debugShortenText( $tokens[$i]['token'] ) . "\n";
			$i = $tokens[$i]['next'];
		}
		$this->debug( $name, $dump );
	}
}