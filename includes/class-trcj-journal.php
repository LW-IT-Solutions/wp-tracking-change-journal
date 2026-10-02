<?php
/**
 * Checks, compares and stores the tracking state of the site.
 *
 * @package TrackingChangeJournal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the configured pages of this site, compares tags and consent defaults with the previous check, notes which
 * plugins, themes or core versions changed in between, and keeps the result as a journal.
 */
class TRCJ_Journal {

	const SETTINGS    = 'trcj_settings';
	const STATE       = 'trcj_state';
	const JOURNAL     = 'trcj_journal';
	const PENDING     = 'trcj_pending';
	const MAX_ENTRIES = 200;
	const MAX_PAGES   = 10;
	const DELAY       = 60;
	const TIME_LIMIT  = 60;

	/** Activation: default settings, daily check, first check. */
	public static function activate() {
		if ( false === get_option( self::SETTINGS ) ) {
			add_option(
				self::SETTINGS,
				array(
					'pages' => self::default_paths(),
					'email' => false,
				),
				'',
				false
			);
		}
		if ( ! wp_next_scheduled( 'trcj_daily' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'trcj_daily' );
		}
		self::schedule( 'activation', 5 );
	}

	/** Deactivation: remove scheduled checks. Data stays until uninstall. */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'trcj_daily' );
		wp_clear_scheduled_hook( 'trcj_check' );
		delete_option( self::PENDING );
	}

	/**
	 * Plan a check shortly after a change. Several changes in a row lead to one check.
	 *
	 * @param string $reason  activation or change.
	 * @param int    $delay   Seconds from now.
	 * @param int    $attempt Retry counter for pages in maintenance mode.
	 */
	public static function schedule( $reason, $delay = self::DELAY, $attempt = 0 ) {
		$pending = get_option( self::PENDING );
		if ( $attempt || ! is_array( $pending ) || 'activation' !== $pending['reason'] ) {
			update_option(
				self::PENDING,
				array(
					'reason'  => $reason,
					'attempt' => (int) $attempt,
				),
				false
			);
		}
		if ( ! wp_next_scheduled( 'trcj_check' ) ) {
			wp_schedule_single_event( time() + $delay, 'trcj_check' );
		}
	}

	/** Cron: the planned check after a change. */
	public static function run_scheduled() {
		$pending = get_option( self::PENDING );
		delete_option( self::PENDING );
		$reason  = is_array( $pending ) && 'activation' === $pending['reason'] ? 'activation' : 'change';
		$attempt = is_array( $pending ) ? (int) $pending['attempt'] : 0;
		self::check( $reason, $attempt );
	}

	/** Cron: the daily check. */
	public static function run_daily() {
		self::check( 'daily' );
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array pages (paths), email (bool).
	 */
	public static function settings() {
		$s = get_option( self::SETTINGS );
		$s = is_array( $s ) ? $s : array();
		return array(
			'pages' => ! empty( $s['pages'] ) && is_array( $s['pages'] ) ? array_slice( array_values( $s['pages'] ), 0, self::MAX_PAGES ) : array( self::home_path() ),
			'email' => ! empty( $s['email'] ),
		);
	}

	/**
	 * Store settings.
	 *
	 * @param array $pages Clean paths.
	 * @param bool  $email Send an email on changes.
	 */
	public static function save_settings( $pages, $email ) {
		update_option(
			self::SETTINGS,
			array(
				'pages' => $pages ? $pages : array( self::home_path() ),
				'email' => (bool) $email,
			),
			false
		);
	}

	/**
	 * State of the previous check.
	 *
	 * @return array time, pages, components.
	 */
	public static function state() {
		$s = get_option( self::STATE );
		return is_array( $s ) ? $s : array(
			'time'       => 0,
			'pages'      => array(),
			'components' => array(),
		);
	}

	/**
	 * Journal entries, newest first.
	 *
	 * @return array
	 */
	public static function journal() {
		$j = get_option( self::JOURNAL );
		return is_array( $j ) ? $j : array();
	}

	/** Delete the journal. The last state stays, so the next check compares against it. */
	public static function clear_journal() {
		delete_option( self::JOURNAL );
	}

	/**
	 * Origin of the home URL: scheme, host and port.
	 *
	 * @return string
	 */
	public static function origin() {
		$p = wp_parse_url( home_url( '/' ) );
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	/**
	 * Path of the home page.
	 *
	 * @return string
	 */
	public static function home_path() {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return $path ? $path : '/';
	}

	/**
	 * Path and query of an address on this site.
	 *
	 * @param string $url Absolute address.
	 * @return string Path, or '' for addresses on other hosts.
	 */
	public static function path_of( $url ) {
		$p    = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( empty( $p['host'] ) || strtolower( $p['host'] ) !== strtolower( $home['host'] ) || ( $p['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			return '';
		}
		$path = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
		return $path . ( isset( $p['query'] ) && '' !== $p['query'] ? '?' . $p['query'] : '' );
	}

	/**
	 * Default pages: the home page and the latest post.
	 *
	 * @return array Paths.
	 */
	public static function default_paths() {
		$paths  = array( self::home_path() );
		$latest = get_posts(
			array(
				'numberposts'      => 1,
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'has_password'     => false,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		if ( $latest ) {
			$path = self::path_of( get_permalink( $latest[0] ) );
			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * Turn user input into paths on this site.
	 *
	 * @param string $text One address or path per line.
	 * @return array List of paths and the number of rejected lines.
	 */
	public static function clean_paths( $text ) {
		$paths    = array();
		$rejected = 0;
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$line = strtok( $line, '#' );
			if ( preg_match( '~^https?://~i', $line ) ) {
				$path = self::path_of( esc_url_raw( $line ) );
			} elseif ( '/' === $line[0] && '/' !== substr( $line, 1, 1 ) && '\\' !== substr( $line, 1, 1 ) ) {
				$path = self::path_of( esc_url_raw( self::origin() . $line ) );
			} else {
				$path = '';
			}
			if ( '' === $path ) {
				++$rejected;
				continue;
			}
			$paths[] = $path;
		}
		$paths = array_values( array_unique( $paths ) );
		if ( count( $paths ) > self::MAX_PAGES ) {
			$rejected += count( $paths ) - self::MAX_PAGES;
			$paths     = array_slice( $paths, 0, self::MAX_PAGES );
		}
		return array( $paths, $rejected );
	}

	/**
	 * Load one page of this site without cookies and scan it.
	 *
	 * @param string $path Path on this site.
	 * @return array status, error, tags, consent.
	 */
	public static function fetch( $path ) {
		$page = array(
			'status'  => 0,
			'error'   => '',
			'tags'    => array(),
			'consent' => array(),
		);
		// The extra query argument passes page caches, so the check sees what WordPress renders now.
		$url      = add_query_arg( 'trcj-check', time(), self::origin() . $path );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => 3 * MB_IN_BYTES,
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$page['error'] = substr( sanitize_text_field( $response->get_error_message() ), 0, 200 );
			return $page;
		}
		$page['status'] = (int) wp_remote_retrieve_response_code( $response );
		$type           = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( self::ok( $page['status'] ) && ( '' === $type || false !== stripos( $type, 'html' ) ) ) {
			$page = array_merge( $page, TRCJ_Detector::scan( wp_remote_retrieve_body( $response ) ) );
		}
		return $page;
	}

	/**
	 * Run a check, compare it with the previous one and write a journal entry.
	 *
	 * @param string $reason  manual, change, daily or activation.
	 * @param int    $attempt Retry counter.
	 * @return array|null The entry, or null when the check was postponed.
	 */
	public static function check( $reason, $attempt = 0 ) {
		$settings    = self::settings();
		$state       = self::state();
		$pages       = array();
		$skipped     = array();
		$unreachable = '';
		$start       = microtime( true );
		foreach ( $settings['pages'] as $i => $path ) {
			if ( '' !== $unreachable ) {
				// The first page could not be reached at all: the site cannot load itself, so the others fail too.
				$pages[ $path ] = array(
					'status'  => 0,
					'error'   => $unreachable,
					'tags'    => array(),
					'consent' => array(),
				);
				continue;
			}
			if ( microtime( true ) - $start > self::TIME_LIMIT && isset( $state['pages'][ $path ] ) ) {
				// Keep the previous result instead of running into the PHP time limit.
				$pages[ $path ] = $state['pages'][ $path ];
				$skipped[]      = $path;
				continue;
			}
			$pages[ $path ] = self::fetch( $path );
			if ( 0 === $i && 0 === $pages[ $path ]['status'] ) {
				$unreachable = $pages[ $path ]['error'];
			}
		}
		// Right after an update the site can still be in maintenance mode. Try again later.
		if ( 'change' === $reason && $attempt < 2 && in_array( 503, wp_list_pluck( $pages, 'status' ), true ) ) {
			self::schedule( 'change', 300, $attempt + 1 );
			return null;
		}
		$components = self::components();
		$entry      = array(
			'time'       => time(),
			'reason'     => $reason,
			'components' => empty( $state['components'] ) ? array() : self::component_changes( $state['components'], $components ),
			'pages'      => array(),
			'changed'    => false,
		);
		foreach ( $pages as $path => $page ) {
			if ( in_array( $path, $skipped, true ) ) {
				$entry['pages'][ $path ] = array( array( 'k' => 'skipped' ) );
				continue;
			}
			if ( ! isset( $state['pages'][ $path ] ) ) {
				$entry['pages'][ $path ] = array( array( 'k' => 'first' ) );
				continue;
			}
			$changes = self::page_changes( $state['pages'][ $path ], $page );
			if ( $changes ) {
				$entry['pages'][ $path ] = $changes;
				$entry['changed']        = true;
			}
		}
		update_option(
			self::STATE,
			array(
				'time'       => $entry['time'],
				'pages'      => $pages,
				'components' => $components,
			),
			false
		);
		if ( 'daily' !== $reason || $entry['changed'] || $entry['components'] ) {
			$journal = self::journal();
			array_unshift( $journal, $entry );
			update_option( self::JOURNAL, array_slice( $journal, 0, self::MAX_ENTRIES ), false );
		}
		if ( $entry['changed'] && $settings['email'] ) {
			self::mail( $entry );
		}
		return $entry;
	}

	/**
	 * Is this a successful HTTP status?
	 *
	 * @param int $status HTTP status.
	 * @return bool
	 */
	public static function ok( $status ) {
		return $status >= 200 && $status < 300;
	}

	/**
	 * Installed plugins, must-use plugins, the active theme and the WordPress version.
	 *
	 * @return array key => n (name), v (version), a (active).
	 */
	public static function components() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array(
			'core' => array(
				'n' => 'WordPress',
				'v' => (string) get_bloginfo( 'version' ),
				'a' => true,
			),
		);
		foreach ( get_plugins() as $file => $data ) {
			$out[ 'plugin:' . $file ] = array(
				'n' => self::name( $data['Name'], $file ),
				'v' => (string) $data['Version'],
				'a' => in_array( $file, $active, true ),
			);
		}
		foreach ( get_mu_plugins() as $file => $data ) {
			$out[ 'mu:' . $file ] = array(
				'n' => self::name( $data['Name'], $file ),
				'v' => (string) $data['Version'],
				'a' => true,
			);
		}
		$theme  = wp_get_theme();
		$themes = array( $theme );
		if ( $theme->parent() ) {
			$themes[] = $theme->parent();
		}
		foreach ( $themes as $t ) {
			$out[ 'theme:' . $t->get_stylesheet() ] = array(
				'n' => self::name( $t->get( 'Name' ), $t->get_stylesheet() ),
				'v' => (string) $t->get( 'Version' ),
				'a' => true,
			);
		}
		return $out;
	}

	/**
	 * Clean component name.
	 *
	 * @param string $name     Name from the header.
	 * @param string $fallback File or folder name.
	 * @return string
	 */
	private static function name( $name, $fallback ) {
		$name = substr( sanitize_text_field( (string) $name ), 0, 100 );
		return '' !== $name ? $name : $fallback;
	}

	/**
	 * What changed among plugins, themes and core.
	 *
	 * @param array $old Previous components.
	 * @param array $new Current components.
	 * @return array Changes.
	 */
	public static function component_changes( $old, $new ) {
		$changes = array();
		foreach ( $new as $key => $n ) {
			$kind = explode( ':', $key )[0];
			if ( ! isset( $old[ $key ] ) ) {
				$changes[] = array(
					'k'  => 'theme' === $kind ? 'theme_on' : 'installed',
					't'  => $kind,
					'n'  => $n['n'],
					'to' => $n['v'],
				);
				continue;
			}
			$o = $old[ $key ];
			if ( $o['v'] !== $n['v'] ) {
				$changes[] = array(
					'k'    => 'updated',
					't'    => $kind,
					'n'    => $n['n'],
					'from' => $o['v'],
					'to'   => $n['v'],
				);
			}
			if ( $o['a'] !== $n['a'] ) {
				$changes[] = array(
					'k' => $n['a'] ? 'activated' : 'deactivated',
					't' => $kind,
					'n' => $n['n'],
				);
			}
		}
		foreach ( $old as $key => $o ) {
			if ( ! isset( $new[ $key ] ) ) {
				$kind      = explode( ':', $key )[0];
				$changes[] = array(
					'k'    => 'theme' === $kind ? 'theme_off' : 'deleted',
					't'    => $kind,
					'n'    => $o['n'],
					'from' => $o['v'],
				);
			}
		}
		return $changes;
	}

	/**
	 * What changed on one page.
	 *
	 * @param array $old Previous result.
	 * @param array $new Current result.
	 * @return array Changes.
	 */
	public static function page_changes( $old, $new ) {
		$changes = array();
		$from    = (int) $old['status'];
		$to      = (int) $new['status'];
		if ( $from !== $to ) {
			$changes[] = $to ? array(
				'k'    => 'status',
				'from' => $from,
				'to'   => $to,
			) : array(
				'k'  => 'error',
				'to' => $new['error'],
			);
		}
		// Tags are compared only between two pages that loaded; a failed load is no removal.
		if ( ! self::ok( $from ) || ! self::ok( $to ) ) {
			return $changes;
		}
		foreach ( $new['tags'] as $key => $t ) {
			if ( ! isset( $old['tags'][ $key ] ) ) {
				$changes[] = self::tag_change( 'added', $t ) + array(
					'held' => ! TRCJ_Detector::active( $t ),
					'dup'  => TRCJ_Detector::duplicate( $t ),
				);
				continue;
			}
			$o = $old['tags'][ $key ];
			if ( TRCJ_Detector::active( $o ) !== TRCJ_Detector::active( $t ) ) {
				$changes[] = self::tag_change( TRCJ_Detector::active( $t ) ? 'released' : 'held', $t );
			}
			if ( TRCJ_Detector::duplicate( $o ) !== TRCJ_Detector::duplicate( $t ) ) {
				$changes[] = self::tag_change( TRCJ_Detector::duplicate( $t ) ? 'duplicate' : 'single', $t );
			}
			if ( $o['s'] && $t['s'] && $o['s'] !== $t['s'] ) {
				$changes[] = self::tag_change( 'host', $t ) + array(
					'from' => implode( ', ', $o['s'] ),
					'to'   => implode( ', ', $t['s'] ),
				);
			}
		}
		foreach ( $old['tags'] as $key => $t ) {
			if ( ! isset( $new['tags'][ $key ] ) ) {
				$changes[] = self::tag_change( 'removed', $t );
			}
		}
		if ( $old['consent'] !== $new['consent'] ) {
			$changes[] = array(
				'k'    => 'consent',
				'from' => implode( ' | ', $old['consent'] ),
				'to'   => implode( ' | ', $new['consent'] ),
			);
		}
		return $changes;
	}

	/**
	 * Group pages with the same result, so identical changes or tags are listed once.
	 *
	 * @param array $pages Path => value.
	 * @return array List of [paths, value].
	 */
	public static function group_pages( $pages ) {
		$groups = array();
		foreach ( $pages as $path => $value ) {
			$key = md5( (string) wp_json_encode( $value ) );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( array(), $value );
			}
			$groups[ $key ][0][] = (string) $path;
		}
		return array_values( $groups );
	}

	/**
	 * Change record for one tag.
	 *
	 * @param string $kind Change kind.
	 * @param array  $t    Tag entry.
	 * @return array
	 */
	private static function tag_change( $kind, $t ) {
		return array(
			'k' => $kind,
			'l' => $t['l'],
			'i' => $t['i'],
		);
	}

	/**
	 * Name of a check reason.
	 *
	 * @param string $reason Reason key.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		switch ( $reason ) {
			case 'manual':
				return __( 'Manual check', 'tracking-change-journal' );
			case 'change':
				return __( 'Check after a plugin, theme or core change', 'tracking-change-journal' );
			case 'activation':
				return __( 'First check after activation', 'tracking-change-journal' );
			case 'settings':
				return __( 'Check after the page list changed', 'tracking-change-journal' );
			default:
				return __( 'Daily check', 'tracking-change-journal' );
		}
	}

	/**
	 * Sentence for a component change.
	 *
	 * @param array $c Change.
	 * @return string
	 */
	public static function describe_component( $c ) {
		$name = 'theme' === $c['t'] ? sprintf(
			/* translators: %s: theme name. */
			__( 'Theme %s', 'tracking-change-journal' ),
			$c['n']
		) : $c['n'];
		switch ( $c['k'] ) {
			case 'updated':
				/* translators: 1: plugin, theme or WordPress, 2: old version, 3: new version. */
				return sprintf( __( '%1$s updated from %2$s to %3$s', 'tracking-change-journal' ), $name, $c['from'], $c['to'] );
			case 'installed':
				if ( '' === $c['to'] ) {
					/* translators: %s: plugin name. */
					return sprintf( __( '%s installed', 'tracking-change-journal' ), $name );
				}
				/* translators: 1: plugin name, 2: version. */
				return sprintf( __( '%1$s %2$s installed', 'tracking-change-journal' ), $name, $c['to'] );
			case 'deleted':
				/* translators: %s: plugin name. */
				return sprintf( __( '%s deleted', 'tracking-change-journal' ), $name );
			case 'activated':
				/* translators: %s: plugin name. */
				return sprintf( __( '%s activated', 'tracking-change-journal' ), $name );
			case 'deactivated':
				/* translators: %s: plugin name. */
				return sprintf( __( '%s deactivated', 'tracking-change-journal' ), $name );
			case 'theme_on':
				/* translators: %s: theme name. */
				return sprintf( __( '%s is now in use', 'tracking-change-journal' ), $name );
			default:
				/* translators: %s: theme name. */
				return sprintf( __( '%s is no longer in use', 'tracking-change-journal' ), $name );
		}
	}

	/**
	 * Sentence for a page change.
	 *
	 * @param array $c Change.
	 * @return string
	 */
	public static function describe_page( $c ) {
		$tag = isset( $c['l'] ) ? $c['l'] . ' ' . $c['i'] : '';
		switch ( $c['k'] ) {
			case 'first':
				return __( 'First check of this page.', 'tracking-change-journal' );
			case 'skipped':
				return __( 'Not checked this time: the check reached its time limit. The previous result is kept.', 'tracking-change-journal' );
			case 'status':
				/* translators: 1: new HTTP status, 2: previous HTTP status. */
				return sprintf( __( 'The page now answers with HTTP status %1$d (before: %2$d).', 'tracking-change-journal' ), $c['to'], $c['from'] );
			case 'error':
				/* translators: %s: error message. */
				return sprintf( __( 'The page could not be loaded: %s', 'tracking-change-journal' ), $c['to'] );
			case 'added':
				if ( ! empty( $c['held'] ) ) {
					/* translators: %s: tag name and ID. */
					return sprintf( __( '%s added. It waits for consent or a loader script.', 'tracking-change-journal' ), $tag );
				}
				if ( ! empty( $c['dup'] ) ) {
					/* translators: %s: tag name and ID. */
					return sprintf( __( '%s added. It is loaded or configured more than once.', 'tracking-change-journal' ), $tag );
				}
				/* translators: %s: tag name and ID. */
				return sprintf( __( '%s added.', 'tracking-change-journal' ), $tag );
			case 'removed':
				/* translators: %s: tag name and ID. */
				return sprintf( __( '%s removed.', 'tracking-change-journal' ), $tag );
			case 'held':
				/* translators: %s: tag name and ID. */
				return sprintf( __( '%s now waits for consent or a loader script.', 'tracking-change-journal' ), $tag );
			case 'released':
				/* translators: %s: tag name and ID. */
				return sprintf( __( '%s now loads directly, without waiting for consent.', 'tracking-change-journal' ), $tag );
			case 'duplicate':
				/* translators: %s: tag name and ID. */
				return sprintf( __( '%s is now loaded or configured more than once.', 'tracking-change-journal' ), $tag );
			case 'single':
				/* translators: %s: tag name and ID. */
				return sprintf( __( '%s is no longer loaded more than once.', 'tracking-change-journal' ), $tag );
			case 'host':
				/* translators: 1: tag name and ID, 2: new host, 3: previous host. */
				return sprintf( __( '%1$s now loads from %2$s (before: %3$s).', 'tracking-change-journal' ), $tag, $c['to'], $c['from'] );
			default:
				if ( '' === $c['from'] ) {
					/* translators: %s: consent mode defaults. */
					return sprintf( __( 'Consent mode defaults added: %s', 'tracking-change-journal' ), self::consent_text( $c['to'] ) );
				}
				if ( '' === $c['to'] ) {
					/* translators: %s: previous consent mode defaults. */
					return sprintf( __( 'Consent mode defaults removed (before: %s).', 'tracking-change-journal' ), self::consent_text( $c['from'] ) );
				}
				$diff = self::consent_diff( $c['from'], $c['to'] );
				if ( '' !== $diff ) {
					/* translators: %s: changed values, for example "wait_for_update 500 → 2000". */
					return sprintf( __( 'Consent mode defaults changed: %s.', 'tracking-change-journal' ), $diff );
				}
				/* translators: 1: new consent mode defaults, 2: previous consent mode defaults. */
				return sprintf( __( 'Consent mode defaults changed to %1$s (before: %2$s).', 'tracking-change-journal' ), self::consent_text( $c['to'] ), self::consent_text( $c['from'] ) );
		}
	}

	/**
	 * Changed values between two single sets of consent defaults.
	 *
	 * @param string $from Previous defaults.
	 * @param string $to   New defaults.
	 * @return string Changes as "key old → new", or '' when the sets cannot be compared value by value.
	 */
	public static function consent_diff( $from, $to ) {
		if ( false !== strpos( $from . $to, '|' ) || false !== strpos( $from . $to, '?' ) ) {
			return '';
		}
		$parse = function ( $text ) {
			$out = array();
			foreach ( explode( ', ', $text ) as $pair ) {
				$parts = explode( '=', $pair, 2 );
				if ( 2 === count( $parts ) ) {
					$out[ $parts[0] ] = $parts[1];
				}
			}
			return $out;
		};
		$old  = $parse( $from );
		$new  = $parse( $to );
		$diff = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
			$a = $old[ $key ] ?? '–';
			$b = $new[ $key ] ?? '–';
			if ( $a !== $b ) {
				$diff[] = $key . ' ' . $a . ' → ' . $b;
			}
		}
		return implode( ', ', $diff );
	}

	/**
	 * Readable consent defaults.
	 *
	 * @param string $text Stored defaults.
	 * @return string
	 */
	public static function consent_text( $text ) {
		return str_replace( '?', __( 'set from a variable, values not readable', 'tracking-change-journal' ), $text );
	}

	/**
	 * Tell the site administrator about tracking changes.
	 *
	 * @param array $entry Journal entry.
	 */
	private static function mail( $entry ) {
		$lines = array();
		foreach ( $entry['components'] as $c ) {
			$lines[] = '- ' . self::describe_component( $c );
		}
		foreach ( self::group_pages( $entry['pages'] ) as list( $paths, $changes ) ) {
			$lines[] = '';
			$lines[] = implode( ', ', $paths );
			foreach ( $changes as $c ) {
				$lines[] = '- ' . self::describe_page( $c );
			}
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Tracking changed', 'tracking-change-journal' ), $site );
		$body    = __( 'Tracking Change Journal found changes in the tracking tags of your public pages.', 'tracking-change-journal' ) . "\n\n"
			. self::reason_label( $entry['reason'] ) . "\n" . implode( "\n", $lines ) . "\n\n"
			. admin_url( 'tools.php?page=tracking-change-journal' ) . "\n";
		wp_mail( get_option( 'admin_email' ), $subject, $body );
	}
}
