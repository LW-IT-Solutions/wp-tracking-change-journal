<?php
/**
 * Finds tracking tags and consent defaults in the HTML of a page.
 *
 * @package TrackingChangeJournal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads script, iframe and img elements with the HTML API of WordPress. Text in the page body, code examples
 * and comments are never matched, only elements that load or configure a tag.
 */
class TRCJ_Detector {

	/** Script types a browser runs. */
	const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'application/x-javascript', 'text/ecmascript', 'application/ecmascript', 'text/jscript', 'module' );

	/** Script types that hold data or markup, never a tag. */
	const DATA_TYPES = array( 'application/json', 'application/ld+json', 'importmap', 'speculationrules', 'text/template', 'text/html', 'text/x-template' );

	/**
	 * Detection rules, read from tags.json.
	 *
	 * @var array|null
	 */
	private static $rules = null;

	/**
	 * Detection rules.
	 *
	 * @return array Tag type => label and rules.
	 */
	public static function rules() {
		if ( null === self::$rules ) {
			$data        = wp_json_file_decode( __DIR__ . '/tags.json', array( 'associative' => true ) );
			self::$rules = is_array( $data ) ? $data : array();
		}
		return self::$rules;
	}

	/**
	 * Scan one page.
	 *
	 * @param string $html Page HTML.
	 * @return array Tags (type:id => counts) and consent defaults.
	 */
	public static function scan( $html ) {
		$found = array(
			'tags'    => array(),
			'consent' => array(),
		);
		if ( ! is_string( $html ) || '' === $html ) {
			return $found;
		}
		$p = new WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			$tag = $p->get_tag();
			if ( 'SCRIPT' === $tag ) {
				$type = strtolower( trim( explode( ';', (string) $p->get_attribute( 'type' ) )[0] ) );
				if ( in_array( $type, self::DATA_TYPES, true ) ) {
					continue;
				}
				$held = ! in_array( $type, self::JS_TYPES, true );
				foreach ( self::sources( $p, $held ) as $src ) {
					self::match( 'src', $src[0], $src[1], $p, $found );
				}
				$text = $p->get_modifiable_text();
				if ( '' !== trim( $text ) ) {
					self::match( 'inline', $text, $held, $p, $found );
					if ( ! $held ) {
						self::consent( $text, $found );
					}
				}
			} elseif ( 'LINK' === $tag ) {
				// A preload hint for a tag loader: the loader itself is often added by a script.
				$rel  = strtolower( (string) $p->get_attribute( 'rel' ) );
				$href = $p->get_attribute( 'href' );
				if ( is_string( $href ) && preg_match( '/(?:^|\s)(?:preload|modulepreload)(?:\s|$)/', $rel ) ) {
					self::match( 'src', trim( $href ), false, $p, $found, 'preload' );
				}
			} elseif ( 'IFRAME' === $tag || 'IMG' === $tag ) {
				foreach ( self::sources( $p, false ) as $src ) {
					self::match( strtolower( $tag ), $src[0], $src[1], $p, $found );
				}
			}
		}
		ksort( $found['tags'] );
		$found['consent'] = array_values( array_unique( $found['consent'] ) );
		sort( $found['consent'] );
		return $found;
	}

	/**
	 * Is a tag loaded or configured directly, not only waiting for consent or a loader script? A noscript
	 * fallback counts only when no held code exists, because browsers with JavaScript ignore it.
	 *
	 * @param array $t Tag entry.
	 * @return bool
	 */
	public static function active( $t ) {
		return ( $t['a'] + $t['c'] + ( $t['p'] ?? 0 ) ) > 0 || ( $t['n'] > 0 && 0 === $t['h'] );
	}

	/**
	 * Is a tag loaded or configured more than once on the page?
	 *
	 * @param array $t Tag entry.
	 * @return bool
	 */
	public static function duplicate( $t ) {
		return $t['a'] > 1 || $t['c'] > 1;
	}

	/**
	 * Addresses an element loads. A src attribute loads now; data-*src attributes are what consent tools and
	 * script delayers swap in later.
	 *
	 * @param WP_HTML_Tag_Processor $p    Processor on the element.
	 * @param bool                  $held Whether the element itself does not run yet.
	 * @return array List of [address, held].
	 */
	private static function sources( $p, $held ) {
		$out = array();
		$src = $p->get_attribute( 'src' );
		if ( is_string( $src ) && '' !== trim( $src ) ) {
			$out[] = array( trim( $src ), $held );
		}
		foreach ( (array) $p->get_attribute_names_with_prefix( 'data-' ) as $name ) {
			if ( 'src' === substr( $name, -3 ) ) {
				$value = $p->get_attribute( $name );
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$out[] = array( trim( $value ), true );
				}
			}
		}
		return $out;
	}

	/**
	 * Apply the rules for one kind of input.
	 *
	 * @param string                $in    src, inline, iframe or img.
	 * @param string                $text  Address or script text.
	 * @param bool                  $held  Whether this code waits for consent or a loader script.
	 * @param WP_HTML_Tag_Processor $p     Processor on the element.
	 * @param array                 $found Results so far.
	 * @param string                $as    Role to record instead of the rule's role, for preload hints.
	 */
	private static function match( $in, $text, $held, $p, &$found, $as = '' ) {
		foreach ( self::rules() as $type => $def ) {
			if ( empty( $def['rules'] ) || ! is_array( $def['rules'] ) ) {
				continue;
			}
			foreach ( $def['rules'] as $rule ) {
				if ( $rule['in'] !== $in || ( ! empty( $rule['requires'] ) && false === stripos( $text, $rule['requires'] ) ) ) {
					continue;
				}
				$ids = array();
				if ( ! empty( $rule['id_attr'] ) ) {
					$value = $p->get_attribute( $rule['id_attr'] );
					if ( is_string( $value ) && preg_match( '~' . $rule['re'] . '~', $text ) ) {
						$ids[] = $value;
					}
				} elseif ( preg_match_all( '~' . $rule['re'] . '~', $text, $m ) ) {
					$ids = $m[1];
					if ( ! empty( $rule['list'] ) ) {
						// The group holds a JSON list of IDs, as plugins pass them to their own script.
						$ids = array();
						foreach ( $m[1] as $list ) {
							preg_match_all( '/[A-Za-z0-9-]+/', $list, $parts );
							$ids = array_merge( $ids, $parts[0] );
						}
					}
				}
				foreach ( $ids as $id ) {
					$id = substr( preg_replace( '/[^A-Za-z0-9._-]/', '', $id ), 0, 64 );
					if ( '' === $id ) {
						continue;
					}
					if ( '' !== $as && 'loader' !== $rule['role'] ) {
						continue;
					}
					$role = $held ? 'held' : ( '' !== $as ? $as : $rule['role'] );
					$host = '';
					if ( 'loader' === $role || 'preload' === $role ) {
						$host = self::host( $in, $text, $rule );
					}
					self::add( $found, $type, (string) $def['label'], $id, $role, $host );
				}
			}
		}
	}

	/**
	 * Host a loader comes from.
	 *
	 * @param string $in   Kind of input.
	 * @param string $text Address or script text.
	 * @param array  $rule Rule that matched.
	 * @return string Host name or ''.
	 */
	private static function host( $in, $text, $rule ) {
		if ( 'inline' === $in ) {
			return ! empty( $rule['host'] ) && preg_match( '~' . $rule['host'] . '~i', $text, $m ) ? strtolower( $m[1] ) : '';
		}
		$host = wp_parse_url( $text, PHP_URL_HOST );
		if ( ! $host ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
		}
		// Only characters a host name can contain; anything else in a crafted address is dropped.
		return substr( preg_replace( '/[^a-z0-9.:\[\]-]/', '', strtolower( (string) $host ) ), 0, 100 );
	}

	/**
	 * Count one occurrence.
	 *
	 * @param array  $found Results so far.
	 * @param string $type  Tag type.
	 * @param string $label Display name.
	 * @param string $id    Tag ID.
	 * @param string $role  loader, config, noscript, preload or held.
	 * @param string $host  Loader host or ''.
	 */
	private static function add( &$found, $type, $label, $id, $role, $host ) {
		$key = $type . ':' . $id;
		if ( ! isset( $found['tags'][ $key ] ) ) {
			$found['tags'][ $key ] = array(
				'l' => $label,
				'i' => $id,
				'a' => 0,
				'c' => 0,
				'n' => 0,
				'p' => 0,
				'h' => 0,
				's' => array(),
			);
		}
		$field = array(
			'loader'   => 'a',
			'config'   => 'c',
			'noscript' => 'n',
			'preload'  => 'p',
			'held'     => 'h',
		);
		++$found['tags'][ $key ][ $field[ $role ] ];
		if ( '' !== $host && ! in_array( $host, $found['tags'][ $key ]['s'], true ) ) {
			$found['tags'][ $key ]['s'][] = $host;
			sort( $found['tags'][ $key ]['s'] );
		}
	}

	/**
	 * Read Google consent mode defaults set in an inline script.
	 *
	 * @param string $text  Script text.
	 * @param array  $found Results so far.
	 */
	private static function consent( $text, &$found ) {
		if ( ! preg_match_all( '~gtag\(\s*[\'"]consent[\'"]\s*,\s*[\'"]default[\'"]\s*,\s*(\{[^{}]*\})?~', $text, $m ) ) {
			return;
		}
		foreach ( $m[1] as $object ) {
			if ( '' === $object ) {
				// Values come from a variable and cannot be read without running the script.
				$found['consent'][] = '?';
				continue;
			}
			preg_match_all( '~[\'"]?([a-z_]{2,40})[\'"]?\s*:\s*(\[[^\]]*\]|\'[^\']*\'|"[^"]*"|[A-Za-z0-9_.]+)~', $object, $pairs, PREG_SET_ORDER );
			$values = array();
			foreach ( $pairs as $pair ) {
				$value = preg_replace( '/[^A-Za-z0-9_.,-]/', '', $pair[2] );
				if ( '[' === $pair[2][0] ) {
					// Long region lists are kept as count and fingerprint, so any change still shows.
					$list = array_filter( explode( ',', $value ) );
					sort( $list );
					$value = count( $list ) > 10 ? '[' . count( $list ) . ' regions #' . substr( md5( implode( ',', $list ) ), 0, 6 ) . ']' : '[' . implode( ',', $list ) . ']';
				} else {
					$value = substr( $value, 0, 100 );
				}
				$values[ $pair[1] ] = $pair[1] . '=' . $value;
			}
			ksort( $values );
			$found['consent'][] = $values ? implode( ', ', $values ) : '?';
		}
	}
}
