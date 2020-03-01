<?php

/**
 * Data and methods for single text version (old or new one).
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
	 * Constructor, initialize text object.
	 *
	 * @param string $text Text of version
	 * @param WikEdDiff $parent Parent, for configuration settings and debugging methods
	 */
	public function __construct ( $text, $parent ) {

		$this->text = $text;
		$this->parent = $parent;

		// Parse and count words and chunks for identification of unique real words
		if ( $parent->config['timer'] === true ) {
			$parent->time( 'wordParse' );
		}
		$this->wordParse( $parent->config['regExp']['countWords'] );
		$this->wordParse( $parent->config['regExp']['countChunks'] );
		if ( $parent->config['timer'] === true ) {
			$parent->timeEnd( 'wordParse' );
		}
	}


	/**
	 * Parse and count words and chunks for identification of unique words.
	 *
	 * @param string $regExp Regular expression for counting words
	 * @param[in] string $text Text of version
	 * @param[out] array $words Number of word occurrences
	 */
	protected function wordParse( $regExp ) {

		preg_match_all( $regExp, $this->text, $regExpMatch );
		for ( $i = 0, $matchLength = count( $regExpMatch[0] ); $i < $matchLength; $i ++ ) {
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
	 * Split text into paragraph, line, sentence, chunk, word, or character tokens.
	 *
	 * @param string $level Level of splitting: paragraph, line, sentence, chunk, word, or character
	 * @param int|null $token Index of token to be split, otherwise uses full text
	 * @param[in] string $text Full text to be split
	 * @param[out] array $tokens Tokens list
	 * @param[out] int $first, $last First and last index of tokens list
	 */
	protected function splitText( $level, $token = null ) {

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
			$text = $this->tokens[$token]['token'];
		}

		// Split text into tokens, regExp match as separator
		$number = 0;
		$split = array();
		preg_match_all(
			$this->parent->config['regExp']['split'][$level],
			$text,
			$regExpMatch,
			PREG_OFFSET_CAPTURE
		);
		$lastIndex = 0;
		for ( $i = 0, $matchLength = count( $regExpMatch[0] ); $i < $matchLength; $i ++ ) {
			$regExpMatchIndex = $regExpMatch[0][$i][1];
			if ( $regExpMatchIndex > $lastIndex ) {
				array_push( $split, substr( $text, $lastIndex, $regExpMatchIndex - $lastIndex ) );
			}
			array_push( $split, $regExpMatch[0][$i][0] );
			$lastIndex = $regExpMatchIndex + strlen( $regExpMatch[0][$i][0] );
		}
		if ( $lastIndex < strlen( $text ) ) {
			array_push( $split, substr( $text, $lastIndex ) );
		}

		// Cycle through new tokens
		for ( $i = 0, $splitLength = count( $split ); $i < $splitLength; $i ++ ) {

			// Insert current item, link to previous
			array_push( $this->tokens, array(
				'token'  => $split[$i],
				'prev'   => $prev,
				'next'   => null,
				'link'   => null,
				'number' => null,
				'unique' => false
			) );
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
				if ( $token === $this->first ) {
					$this->first = $first;
				}
				if ( $token === $this->last ) {
					$this->last = $prev;
				}
			}
		}
	}

	/**
	 * Split unique unmatched tokens into smaller tokens.
	 *
	 * @param string $level Level of splitting: line, sentence, chunk, or word
	 * @param[in] array $tokens Tokens list
	 */
	protected function splitRefine( $level ) {

		// Cycle through tokens list
		$i = $this->first;
		while ( $i !== null ) {

			// Refine unique unmatched tokens into smaller tokens
			if ( $this->tokens[$i]['link'] === null ) {
				$this->splitText( $level, $i );
			}
			$i = $this->tokens[$i]['next'];
		}
	}


	/**
	 * Enumerate text token list before detecting blocks.
	 *
	 * @param[out] array $tokens Tokens list
	 */
	protected function enumerateTokens() {

		// Enumerate tokens list
		$number = 0;
		$i = $this->first;
		while ( $i !== null ) {
			$this->tokens[$i]['number'] = $number;
			$number ++;
			$i = $this->tokens[$i]['next'];
		}
	}


	/**
	 * Dump tokens object to debug console.
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
		while ( $i !== null ) {
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
