<?php namespace WP_CLI\Maintenance;

use WP_CLI;

/**
 * Asks before the maintenance commands change something.
 */
final class Prompt {

	/**
	 * Asks a yes/no question, unlike WP_CLI::confirm() without exiting on no.
	 *
	 * @param string $question Question, without the [y/n] suffix.
	 *
	 * @return bool Whether the answer was yes.
	 */
	public static function confirm( $question ) {
		fwrite( STDOUT, "{$question} [y/n] " );
		$answer = fgets( STDIN );
		if ( false === $answer ) {
			WP_CLI::error( 'No answer received. Run this command in an interactive terminal, or pass --yes.' );
		}
		// A piped answer is not echoed, so end the prompt's line here.
		if ( ! stream_isatty( STDIN ) ) {
			fwrite( STDOUT, "\n" );
		}

		return 'y' === strtolower( trim( $answer ) );
	}
}
