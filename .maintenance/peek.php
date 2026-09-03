#!/usr/bin/env php
<?php
/**
 * peek - live peek windows for parallel jobs you schedule yourself.
 *
 * peek owns the terminal; a thin `peek.php run` wrapper around each job feeds
 * it. Concurrency is somebody else's problem: use xargs -P, parallel, make -j,
 * or &.
 *
 *   php peek.php -- xargs -P4 -I% php peek.php run -n % -- gcc -c %.c
 *   producer | php peek.php pipe -n feed | sink
 *
 * Exits with the status of the command after --. With no display running,
 * `peek.php run` becomes its command unchanged, so scripts work either way.
 *
 * Degrades to a plain passthrough when the platform cannot support the
 * display (Windows, no unix domain datagram sockets, PHP < 7.4) or when
 * NO_PEEK is set in the environment.
 */

const PEEK_SEP     = "\x1f";
const PEEK_MAXLINE = 4000;
const PEEK_ANSI_RE = '#\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b\[[0-9;?]*[ -/]*[@-~]|\x1b[@-Z\\\\-_]#';
const PEEK_CTRL_RE = '#[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]#';

const PEEK_RESET = "\033[0m";
const PEEK_BOLD  = "\033[1m";
const PEEK_DIM   = "\033[2m";
const PEEK_RED   = "\033[31m";
const PEEK_GREEN = "\033[32m";
const PEEK_CYAN  = "\033[36m";
const PEEK_GREY  = "\033[90m";

function peek_supported() {
	if ( PHP_VERSION_ID < 70400 ) {
		return false;
	}
	if ( getenv( 'NO_PEEK' ) ) {
		return false;
	}
	if ( DIRECTORY_SEPARATOR === '\\' ) {
		return false;
	}
	if ( ! function_exists( 'stream_socket_server' ) ) {
		return false;
	}
	return in_array( 'udg', stream_get_transports(), true );
}

function peek_clean( $raw ) {
	$parts = explode( "\r", $raw );
	$text  = (string) preg_replace( PEEK_ANSI_RE, '', (string) end( $parts ) );
	$text  = (string) preg_replace( PEEK_CTRL_RE, '', $text );
	if ( 1 !== @preg_match( '##u', $text ) ) {
		$text = (string) preg_replace( '#[\x80-\xFF]+#', "\xEF\xBF\xBD", $text );
	}
	return str_replace( "\t", '    ', $text );
}

function peek_len( $text ) {
	$count = @preg_match_all( '#.#us', $text );
	return false === $count ? strlen( $text ) : $count;
}

function peek_fit( $text, $width ) {
	if ( $width <= 0 ) {
		return '';
	}
	if ( peek_len( $text ) <= $width ) {
		return $text;
	}
	if ( @preg_match( '#^.{0,' . max( 0, $width - 1 ) . '}#us', $text, $m ) ) {
		return $m[0] . '…';
	}
	return substr( $text, 0, max( 0, $width - 1 ) ) . '…';
}

function peek_dur( $seconds ) {
	if ( $seconds < 60 ) {
		return sprintf( '%.1fs', $seconds );
	}
	return sprintf( '%dm%02ds', (int) ( $seconds / 60 ), ( (int) $seconds ) % 60 );
}

function peek_spinner() {
	static $frames = null;
	if ( null === $frames ) {
		$frames = preg_split( '//u', '⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏', -1, PREG_SPLIT_NO_EMPTY );
	}
	return $frames;
}

function peek_term_size() {
	static $size = array( 80, 24 );
	static $at   = 0.0;
	$now         = microtime( true );
	if ( $at > 0 && ( $now - $at ) < 2.0 ) {
		return $size;
	}
	$at   = $now;
	$cols = (int) getenv( 'COLUMNS' );
	$rows = (int) getenv( 'LINES' );
	if ( $cols <= 0 || $rows <= 0 ) {
		$out = @shell_exec( 'stty size < /dev/tty 2>/dev/null' );
		if ( $out && preg_match( '#^(\d+)\s+(\d+)#', trim( $out ), $m ) ) {
			$rows = (int) $m[1];
			$cols = (int) $m[2];
		}
	}
	$size = array( $cols > 0 ? $cols : 80, $rows > 0 ? $rows : 24 );
	return $size;
}

function peek_which( $bin ) {
	if ( false !== strpos( $bin, '/' ) ) {
		return is_executable( $bin ) ? $bin : null;
	}
	foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $dir ) {
		if ( '' === $dir ) {
			continue;
		}
		$candidate = $dir . '/' . $bin;
		if ( is_file( $candidate ) && is_executable( $candidate ) ) {
			return $candidate;
		}
	}
	return null;
}

/**
 * Run a command unchanged, inheriting stdio, and return its exit code.
 * Used whenever the display is unavailable so behavior stays identical.
 */
function peek_passthrough( array $cmd ) {
	if ( function_exists( 'pcntl_exec' ) ) {
		$bin = peek_which( $cmd[0] );
		if ( null !== $bin ) {
			@pcntl_exec( $bin, array_slice( $cmd, 1 ) );
			// Falls through only if exec itself failed.
		}
	}
	$spec = array(
		0 => STDIN,
		1 => STDOUT,
		2 => STDERR,
	);
	if ( PHP_VERSION_ID >= 70400 ) {
		$proc = @proc_open( $cmd, $spec, $pipes );
	} else {
		$proc = @proc_open( implode( ' ', array_map( 'escapeshellarg', $cmd ) ), $spec, $pipes );
	}
	if ( ! is_resource( $proc ) ) {
		fwrite( STDERR, "peek: failed to run: {$cmd[0]}\n" );
		return 127;
	}
	return proc_close( $proc );
}

// --------------------------------------------------------------------------
// client side: peek.php run / peek.php pipe
// --------------------------------------------------------------------------

/**
 * Write side of the protocol. Never fatal: if the display is gone, we
 * silently stop reporting rather than killing the job.
 */
class PeekFeed {

	private $sock = null;
	private $lane;

	public function __construct( $lane, $name ) {
		$this->lane = (string) $lane;
		$path       = getenv( 'PEEK_SOCK' );
		if ( $path ) {
			$this->sock = @stream_socket_client( 'udg://' . $path, $errno, $errstr, 1 );
			if ( $this->sock ) {
				$this->send( 'OPEN', $name );
			}
		}
	}

	public function send( $kind, $payload ) {
		if ( ! $this->sock ) {
			return;
		}
		$data = $kind . PEEK_SEP . $this->lane . PEEK_SEP . substr( $payload, 0, PEEK_MAXLINE );
		$sent = @stream_socket_sendto( $this->sock, $data );
		if ( false === $sent || $sent < 0 ) {
			$this->sock = null;
		}
	}

	public function line( $raw ) {
		$this->send( 'LINE', $raw );
	}

	public function close( $rc ) {
		$this->send( 'EXIT', (string) $rc );
	}
}

function peek_cmd_run( array $argv ) {
	$name = null;
	$rest = array();
	$help = false;
	while ( $argv ) {
		$arg = array_shift( $argv );
		if ( '-n' === $arg || '--name' === $arg ) {
			$name = array_shift( $argv );
		} elseif ( '-h' === $arg || '--help' === $arg ) {
			$help = true;
		} elseif ( '--' === $arg ) {
			$rest = $argv;
			break;
		} else {
			$rest = array_merge( array( $arg ), $argv );
			break;
		}
	}
	if ( $help || ! $rest ) {
		fwrite( STDERR, "usage: peek.php run [-n NAME] -- COMMAND [ARGS...]\n" );
		return $help ? 0 : 2;
	}

	// No display: become the command. This is what makes peek droppable
	// into scripts that also run standalone.
	if ( ! getenv( 'PEEK_SOCK' ) || ! peek_supported() ) {
		return peek_passthrough( $rest );
	}

	$feed = new PeekFeed( getmypid(), null !== $name ? $name : implode( ' ', $rest ) );
	$spec = array(
		0 => STDIN,
		1 => array( 'pipe', 'w' ),
		2 => array( 'redirect', 1 ),
	);
	$proc = @proc_open( $rest, $spec, $pipes );
	if ( ! is_resource( $proc ) ) {
		$feed->close( 127 );
		fwrite( STDERR, "peek: failed to run: {$rest[0]}\n" );
		return 127;
	}
	while ( false !== ( $line = fgets( $pipes[1] ) ) ) {
		$feed->line( rtrim( $line, "\n" ) );
	}
	fclose( $pipes[1] );
	$rc = proc_close( $proc );
	$feed->close( $rc );
	return $rc;
}

function peek_cmd_pipe( array $argv ) {
	$name = 'stdin';
	while ( $argv ) {
		$arg = array_shift( $argv );
		if ( '-n' === $arg || '--name' === $arg ) {
			$name = array_shift( $argv );
		}
	}
	$feed = new PeekFeed( getmypid(), $name );
	while ( false !== ( $line = fgets( STDIN ) ) ) {
		fwrite( STDOUT, $line ); // Stay a tee, so pipe mode drops into a pipeline.
		fflush( STDOUT );
		$feed->line( rtrim( $line, "\n" ) );
	}
	$feed->close( 0 );
	return 0;
}

// --------------------------------------------------------------------------
// server side: the display
// --------------------------------------------------------------------------

class PeekLane {

	public $name;
	public $tail = array();
	public $start;
	public $end       = null;
	public $rc        = null;
	public $committed = false;

	private $max;

	public function __construct( $name, $peek_lines ) {
		$this->name  = $name;
		$this->max   = max( 1, $peek_lines );
		$this->start = microtime( true );
	}

	public function add_line( $text ) {
		$this->tail[] = $text;
		if ( count( $this->tail ) > $this->max ) {
			array_shift( $this->tail );
		}
	}

	public function is_running() {
		return null === $this->rc;
	}

	public function header( $frame, $width, $color = true ) {
		$spin = peek_spinner();
		if ( null === $this->rc ) {
			$glyph      = $spin[ $frame % count( $spin ) ];
			$tint       = PEEK_CYAN;
			$right      = peek_dur( microtime( true ) - $this->start );
			$right_tint = PEEK_GREY;
		} elseif ( 0 === $this->rc ) {
			$glyph      = '✔';
			$tint       = PEEK_GREEN;
			$right      = peek_dur( $this->end - $this->start );
			$right_tint = PEEK_GREY;
		} else {
			$glyph      = '✘';
			$tint       = PEEK_RED;
			$right      = 'exit ' . $this->rc . ' · ' . peek_dur( $this->end - $this->start );
			$right_tint = PEEK_RED;
		}
		$right_len = peek_len( $right );
		$name_fit  = peek_fit( $this->name, max( 0, $width - $right_len - 4 ) );
		$plain     = $glyph . ' ' . $name_fit;
		$pad       = str_repeat( ' ', max( 1, $width - peek_len( $plain ) - $right_len ) );
		if ( ! $color ) {
			return $plain . $pad . $right;
		}
		if ( null === $this->rc ) {
			$styled_name = PEEK_BOLD . $name_fit . PEEK_RESET;
		} elseif ( 0 === $this->rc ) {
			$styled_name = $name_fit;
		} else {
			$styled_name = PEEK_RED . PEEK_BOLD . $name_fit . PEEK_RESET;
		}
		return $tint . PEEK_BOLD . $glyph . PEEK_RESET . ' ' . $styled_name
			. $pad . $right_tint . $right . PEEK_RESET;
	}
}

class PeekDisplay {

	public $lanes = array();
	public $order = array();

	private $peek_lines;
	private $prev  = 0;
	private $frame = 0;
	private $start;

	public function __construct( $peek_lines ) {
		$this->peek_lines = $peek_lines;
		$this->start      = microtime( true );
	}

	public function event( $kind, $lane, $payload ) {
		if ( 'OPEN' === $kind ) {
			if ( ! isset( $this->lanes[ $lane ] ) ) {
				$this->lanes[ $lane ] = new PeekLane( peek_clean( $payload ), $this->peek_lines );
				$this->order[]        = $lane;
			}
		} elseif ( isset( $this->lanes[ $lane ] ) ) {
			$ln = $this->lanes[ $lane ];
			if ( 'LINE' === $kind ) {
				$text = peek_clean( $payload );
				if ( '' !== trim( $text ) ) {
					$ln->add_line( $text );
				}
			} elseif ( 'EXIT' === $kind ) {
				$ln->rc  = (int) $payload;
				$ln->end = microtime( true );
			}
		}
	}

	public function counts() {
		$running = 0;
		$ok      = 0;
		$failed  = 0;
		foreach ( $this->order as $key ) {
			$rc = $this->lanes[ $key ]->rc;
			if ( null === $rc ) {
				++$running;
			} elseif ( 0 === $rc ) {
				++$ok;
			} else {
				++$failed;
			}
		}
		return array( $running, $ok, $failed );
	}

	public function footer( $color = true ) {
		list( $running, $ok, $failed ) = $this->counts();
		$elapsed                       = peek_dur( microtime( true ) - $this->start );
		if ( ! $color ) {
			$bits = array();
			if ( $running ) {
				$bits[] = $running . ' running';
			}
			$bits[] = 'ok ' . $ok;
			if ( $failed ) {
				$bits[] = 'failed ' . $failed;
			}
			$bits[] = $elapsed;
			return implode( ' · ', $bits );
		}
		$sep  = PEEK_GREY . ' · ' . PEEK_RESET;
		$bits = array();
		if ( $running ) {
			$bits[] = PEEK_CYAN . $running . ' running' . PEEK_RESET;
		}
		$bits[] = PEEK_GREEN . '✔ ' . $ok . PEEK_RESET;
		if ( $failed ) {
			$bits[] = PEEK_RED . PEEK_BOLD . '✘ ' . $failed . PEEK_RESET;
		}
		$bits[] = PEEK_GREY . $elapsed . PEEK_RESET;
		return '  ' . PEEK_GREY . '─' . PEEK_RESET . ' ' . implode( $sep, $bits );
	}

	public function compose( $rows, $cols ) {
		$commit = array();
		$live   = array();
		$lanes  = array();
		foreach ( $this->order as $key ) {
			$lanes[] = $this->lanes[ $key ];
		}
		foreach ( $lanes as $ln ) {
			if ( null !== $ln->rc && ! $ln->committed ) {
				$ln->committed = true;
				$commit[]      = $ln->header( $this->frame, $cols );
				// Keep the captured tail of a failed job on screen: it scrolls
				// into history with the header, so the error context survives.
				if ( 0 !== $ln->rc ) {
					foreach ( $ln->tail as $text ) {
						$commit[] = '  ' . PEEK_RED . '│' . PEEK_RESET . ' '
							. PEEK_GREY . peek_fit( $text, $cols - 4 ) . PEEK_RESET;
					}
				}
			}
		}
		$pending = array();
		foreach ( $lanes as $ln ) {
			if ( ! $ln->committed ) {
				$pending[] = $ln;
			}
		}
		$budget = max( 0, $rows - 3 ) - count( $pending );
		$active = array();
		foreach ( $pending as $ln ) {
			if ( $ln->is_running() ) {
				$active[] = $ln;
			}
		}
		$share = ( $active && $budget > 0 )
			? min( $this->peek_lines, intdiv( $budget, count( $active ) ) )
			: 0;
		foreach ( $pending as $ln ) {
			$live[] = $ln->header( $this->frame, $cols );
			if ( $ln->is_running() && $share ) {
				foreach ( array_slice( $ln->tail, -$share ) as $text ) {
					$live[] = '  ' . PEEK_GREY . '│' . PEEK_RESET . ' '
						. PEEK_DIM . peek_fit( $text, $cols - 4 ) . PEEK_RESET;
				}
			}
		}
		$live[] = $this->footer();
		return array( $commit, $live );
	}

	public function draw( $out ) {
		list( $cols, $rows )   = peek_term_size();
		list( $commit, $live ) = $this->compose( $rows, $cols );
		$buf                   = $this->prev ? "\033[" . $this->prev . 'A' : '';
		foreach ( array_merge( $commit, $live ) as $line ) {
			$buf .= "\033[2K" . $line . "\n";
		}
		$buf .= "\033[J";
		fwrite( $out, $buf );
		fflush( $out );
		$this->prev = count( $live );
		++$this->frame;
	}

	public function summary() {
		$lines = array();
		foreach ( $this->order as $key ) {
			$lines[] = $this->lanes[ $key ]->header( 0, 80, false );
		}
		$lines[] = $this->footer( false );
		return $lines;
	}
}

function peek_drain( $srv, PeekDisplay $disp ) {
	while ( true ) {
		$data = @stream_socket_recvfrom( $srv, 131072 );
		if ( false === $data || '' === $data || null === $data ) {
			return;
		}
		$parts = explode( PEEK_SEP, $data, 3 );
		if ( 3 === count( $parts ) ) {
			$disp->event( $parts[0], $parts[1], $parts[2] );
		}
	}
}

function peek_cmd_serve( array $argv ) {
	$peek_lines = 6;
	$fps        = 12.5;
	$help       = false;
	$rest       = array();
	while ( $argv ) {
		$arg = array_shift( $argv );
		if ( '--peek' === $arg ) {
			$peek_lines = (int) array_shift( $argv );
		} elseif ( 0 === strpos( $arg, '--peek=' ) ) {
			$peek_lines = (int) substr( $arg, 7 );
		} elseif ( '--fps' === $arg ) {
			$fps = (float) array_shift( $argv );
		} elseif ( 0 === strpos( $arg, '--fps=' ) ) {
			$fps = (float) substr( $arg, 6 );
		} elseif ( '-h' === $arg || '--help' === $arg ) {
			$help = true;
		} elseif ( '--' === $arg ) {
			$rest = $argv;
			break;
		} else {
			$rest = array_merge( array( $arg ), $argv );
			break;
		}
	}
	if ( $help || ! $rest ) {
		fwrite( STDERR, "usage: peek.php [--peek N] [--fps F] -- COMMAND [ARGS...]\n" );
		fwrite( STDERR, "       peek.php run [-n NAME] -- COMMAND [ARGS...]\n" );
		fwrite( STDERR, "       peek.php pipe [-n NAME]\n" );
		return $help ? 0 : 2;
	}

	if ( ! peek_supported() ) {
		return peek_passthrough( $rest );
	}

	$tmp = sys_get_temp_dir() . '/peek.' . getmypid() . '.' . substr( md5( uniqid( '', true ) ), 0, 6 );
	if ( ! @mkdir( $tmp, 0700, true ) ) {
		return peek_passthrough( $rest );
	}
	$sock_path = $tmp . '/sock';
	$srv       = @stream_socket_server( 'udg://' . $sock_path, $errno, $errstr, STREAM_SERVER_BIND );
	if ( ! $srv ) {
		@rmdir( $tmp );
		return peek_passthrough( $rest );
	}
	stream_set_blocking( $srv, false );

	$env              = getenv();
	$env['PEEK_SOCK'] = $sock_path;

	$tty = function_exists( 'stream_isatty' ) && @stream_isatty( STDOUT );

	// The driver's own output would fight the live region, so hold it back
	// and replay it once the display tears down.
	$spec = $tty
		? array(
			0 => STDIN,
			1 => array( 'pipe', 'w' ),
			2 => array( 'redirect', 1 ),
		)
		: array(
			0 => STDIN,
			1 => STDOUT,
			2 => STDERR,
		);
	$proc = @proc_open( $rest, $spec, $pipes, null, $env );
	if ( ! is_resource( $proc ) ) {
		fclose( $srv );
		@unlink( $sock_path );
		@rmdir( $tmp );
		fwrite( STDERR, "peek: failed to run: {$rest[0]}\n" );
		return 127;
	}

	$disp    = new PeekDisplay( $peek_lines );
	$held    = '';
	$restore = function () use ( $tty ) {
		if ( $tty ) {
			fwrite( STDOUT, "\033[?25h" );
			fflush( STDOUT );
		}
	};
	if ( $tty ) {
		fwrite( STDOUT, "\033[?25l" );
		register_shutdown_function( $restore );
		if ( function_exists( 'pcntl_async_signals' ) ) {
			pcntl_async_signals( true );
			$on_signal = function () use ( $restore ) {
				$restore();
				exit( 130 );
			};
			pcntl_signal( SIGINT, $on_signal );
			pcntl_signal( SIGTERM, $on_signal );
		}
		stream_set_blocking( $pipes[1], false );
	}

	$frame_us = (int) ( 1000000 / max( $fps, 1 ) );
	$exit     = null;
	while ( true ) {
		$status = proc_get_status( $proc );
		if ( ! $status['running'] && null === $exit ) {
			$exit = $status['exitcode'];
		}
		$read = array( $srv );
		if ( $tty && is_resource( $pipes[1] ) && ! feof( $pipes[1] ) ) {
			$read[] = $pipes[1];
		}
		$write  = null;
		$except = null;
		@stream_select( $read, $write, $except, 0, $frame_us );
		peek_drain( $srv, $disp );
		if ( $tty && is_resource( $pipes[1] ) ) {
			while ( false !== ( $chunk = fread( $pipes[1], 65536 ) ) && '' !== $chunk ) {
				$held .= $chunk;
			}
		}
		if ( $tty ) {
			$disp->draw( STDOUT );
		}
		if ( null !== $exit ) {
			break;
		}
	}

	usleep( 250000 ); // Drain late EXIT datagrams.
	peek_drain( $srv, $disp );
	if ( $tty ) {
		while ( is_resource( $pipes[1] ) && false !== ( $chunk = fread( $pipes[1], 65536 ) ) && '' !== $chunk ) {
			$held .= $chunk;
		}
		$disp->draw( STDOUT );
		fclose( $pipes[1] );
	}
	proc_close( $proc );
	fclose( $srv );
	@unlink( $sock_path );
	@rmdir( $tmp );
	$restore();

	if ( $tty ) {
		if ( '' !== $held ) {
			fwrite( STDOUT, $held );
			fflush( STDOUT );
		}
	} else {
		foreach ( $disp->summary() as $line ) {
			fwrite( STDOUT, $line . "\n" );
		}
	}
	return null === $exit ? 0 : $exit;
}

function peek_main( array $argv ) {
	array_shift( $argv );
	if ( isset( $argv[0] ) && 'run' === $argv[0] ) {
		return peek_cmd_run( array_slice( $argv, 1 ) );
	}
	if ( isset( $argv[0] ) && 'pipe' === $argv[0] ) {
		return peek_cmd_pipe( array_slice( $argv, 1 ) );
	}
	return peek_cmd_serve( $argv );
}

exit( peek_main( $argv ) );
