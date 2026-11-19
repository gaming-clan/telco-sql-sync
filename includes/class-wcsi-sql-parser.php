<?php
/**
 * Streaming SQL dump parser.
 *
 * Reads a .sql file statement by statement and parses INSERT / CREATE TABLE
 * statements into PHP arrays. The SQL is NEVER executed against the database.
 *
 * @package Telco_SQL_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCSI_SQL_Parser {

	/** @var string */
	private $path;

	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Yield every statement in the file starting at $offset (a statement boundary).
	 *
	 * @param int $offset Byte offset to start from.
	 * @return Generator yields [ string $statement, int $start_offset, int $end_offset ].
	 */
	public function statements( $offset = 0 ) {
		$fh = fopen( $this->path, 'rb' );
		if ( ! $fh ) {
			throw new RuntimeException( 'Cannot open SQL file.' );
		}
		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}

		$pos        = (int) $offset; // Byte position of the start of the current line.
		$buf        = '';
		$stmt_start = $pos;
		$state      = 'normal'; // normal | sq | dq | bt | block

		while ( ( $line = fgets( $fh ) ) !== false ) {
			$len = strlen( $line );
			$i   = 0;
			$seg = 0; // Start of the not-yet-buffered part of this line.

			while ( $i < $len ) {
				switch ( $state ) {
					case 'normal':
						$i += strcspn( $line, "'\"`;-#/", $i );
						if ( $i >= $len ) {
							break 2;
						}
						$c = $line[ $i ];
						if ( ';' === $c ) {
							$buf .= substr( $line, $seg, $i - $seg );
							$stmt = trim( $buf );
							$end  = $pos + $i + 1;
							if ( '' !== $stmt ) {
								yield array( $stmt, $stmt_start, $end );
							}
							$buf        = '';
							$stmt_start = $end;
							$i++;
							$seg = $i;
						} elseif ( "'" === $c ) {
							$state = 'sq';
							$i++;
						} elseif ( '"' === $c ) {
							$state = 'dq';
							$i++;
						} elseif ( '`' === $c ) {
							$state = 'bt';
							$i++;
						} elseif ( '#' === $c || ( '-' === $c && $i + 1 < $len && '-' === $line[ $i + 1 ] && ( $i + 2 >= $len || ctype_space( $line[ $i + 2 ] ) || ord( $line[ $i + 2 ] ) < 32 ) ) ) {
							// Line comment: drop the rest of the line.
							$buf .= substr( $line, $seg, $i - $seg ) . "\n";
							$i    = $len;
							$seg  = $len;
						} elseif ( '/' === $c && $i + 1 < $len && '*' === $line[ $i + 1 ] ) {
							$buf  .= substr( $line, $seg, $i - $seg ) . ' ';
							$state = 'block';
							$i    += 2;
							$seg   = $i;
						} else {
							$i++;
						}
						break;

					case 'sq':
					case 'dq':
						$quote = ( 'sq' === $state ) ? "'" : '"';
						$i    += strcspn( $line, $quote . '\\', $i );
						if ( $i >= $len ) {
							break 2;
						}
						if ( '\\' === $line[ $i ] ) {
							$i += 2; // Skip the escaped character.
						} elseif ( $i + 1 < $len && $line[ $i + 1 ] === $quote ) {
							$i += 2; // Doubled quote.
						} else {
							$i++;
							$state = 'normal';
						}
						break;

					case 'bt':
						$p = strpos( $line, '`', $i );
						if ( false === $p ) {
							$i = $len;
						} else {
							$i     = $p + 1;
							$state = 'normal';
						}
						break;

					case 'block':
						$p = strpos( $line, '*/', $i );
						if ( false === $p ) {
							$i   = $len;
							$seg = $len;
						} else {
							$i     = $p + 2;
							$seg   = $i;
							$state = 'normal';
						}
						break;
				}
			}

			if ( 'block' !== $state && $seg < $len ) {
				$buf .= substr( $line, $seg );
			}
			$pos += $len;
		}
		fclose( $fh );

		$stmt = trim( $buf );
		if ( '' !== $stmt ) {
			yield array( $stmt, $stmt_start, $pos );
		}
	}

	/**
	 * Parse a CREATE TABLE statement.
	 *
	 * @return array|null [ 'table' => string, 'columns' => string[] ]
	 */
	public static function parse_create_table( $stmt ) {
		if ( ! preg_match( '/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[\w$]+`?\.)?`?([\w$]+)`?\s*\(/i', $stmt, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$table = $m[1][0];
		$start = $m[0][1] + strlen( $m[0][0] );
		$end   = strrpos( $stmt, ')' );
		if ( false === $end || $end < $start ) {
			return null;
		}
		$body  = substr( $stmt, $start, $end - $start );
		$parts = self::split_top_level( $body );
		$cols  = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part || preg_match( '/^(PRIMARY|KEY|UNIQUE|CONSTRAINT|INDEX|FULLTEXT|SPATIAL|FOREIGN|CHECK)\b/i', $part ) ) {
				continue;
			}
			if ( preg_match( '/^`([^`]+)`/', $part, $cm ) || preg_match( '/^([\w$]+)/', $part, $cm ) ) {
				$cols[] = $cm[1];
			}
		}
		return array(
			'table'   => $table,
			'columns' => $cols,
		);
	}

	/**
	 * Parse the header of an INSERT/REPLACE statement and return a lazy row generator.
	 *
	 * @return array|null [ 'table' => string, 'columns' => string[]|null, 'rows' => Generator ]
	 */
	public static function parse_insert( $stmt ) {
		if ( ! preg_match( '/^(?:INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?(?:`?[\w$]+`?\.)?`?([\w$]+)`?\s*(\(([^)]*)\))?\s*VALUES?\b/i', $stmt, $m ) ) {
			return null;
		}
		$columns = null;
		if ( ! empty( $m[3] ) ) {
			$columns = array_map(
				function ( $c ) {
					return trim( trim( $c ), "` \t\r\n" );
				},
				explode( ',', $m[3] )
			);
		}
		return array(
			'table'   => $m[1],
			'columns' => $columns,
			'rows'    => self::parse_tuples( $stmt, strlen( $m[0] ) ),
		);
	}

	/**
	 * Cheap table-name sniff for an INSERT without parsing its rows.
	 */
	public static function sniff_insert_table( $stmt ) {
		if ( preg_match( '/^(?:INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?(?:`?[\w$]+`?\.)?`?([\w$]+)`?/i', substr( $stmt, 0, 300 ), $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Generator over the (v1, v2, ...) tuples that follow VALUES.
	 */
	private static function parse_tuples( $s, $i ) {
		$n = strlen( $s );
		while ( $i < $n ) {
			// Seek next "(".
			$i += strcspn( $s, '(', $i );
			if ( $i >= $n ) {
				return;
			}
			$i++;
			$row = array();
			while ( $i < $n ) {
				$i += strspn( $s, " \t\r\n", $i );
				if ( $i >= $n ) {
					return;
				}
				$c = $s[ $i ];
				if ( "'" === $c || '"' === $c ) {
					$row[] = self::read_string( $s, $i, $c );
				} elseif ( ( 'N' === $c || 'n' === $c ) && $i + 1 < $n && "'" === $s[ $i + 1 ] ) {
					$i++;
					$row[] = self::read_string( $s, $i, "'" );
				} elseif ( '_' === $c && preg_match( '/\G_[a-z0-9]+\s*(?=[\'"])/i', $s, $im, 0, $i ) ) {
					$i    += strlen( $im[0] );
					$row[] = self::read_string( $s, $i, $s[ $i ] );
				} else {
					$raw = self::read_raw( $s, $i );
					if ( 0 === strcasecmp( $raw, 'NULL' ) ) {
						$row[] = null;
					} elseif ( preg_match( '/^0x([0-9a-f]*)$/i', $raw, $hm ) && '' !== $hm[1] && 0 === strlen( $hm[1] ) % 2 ) {
						$row[] = hex2bin( $hm[1] );
					} else {
						$row[] = $raw;
					}
				}
				$i += strspn( $s, " \t\r\n", $i );
				if ( $i >= $n ) {
					return;
				}
				if ( ',' === $s[ $i ] ) {
					$i++;
					continue;
				}
				if ( ')' === $s[ $i ] ) {
					$i++;
					break;
				}
				$i++; // Unexpected char; skip it.
			}
			yield $row;
		}
	}

	/** Read a quoted string starting at $i (which points at the opening quote). */
	private static function read_string( $s, &$i, $quote ) {
		$n   = strlen( $s );
		$i++;
		$out = '';
		while ( $i < $n ) {
			$j = $i + strcspn( $s, $quote . '\\', $i );
			$out .= substr( $s, $i, $j - $i );
			if ( $j >= $n ) {
				$i = $n;
				break;
			}
			if ( '\\' === $s[ $j ] ) {
				$e = $j + 1 < $n ? $s[ $j + 1 ] : '';
				switch ( $e ) {
					case '0':
						$out .= "\0";
						break;
					case 'n':
						$out .= "\n";
						break;
					case 'r':
						$out .= "\r";
						break;
					case 't':
						$out .= "\t";
						break;
					case 'b':
						$out .= "\x08";
						break;
					case 'Z':
						$out .= "\x1a";
						break;
					case '%':
					case '_':
						$out .= '\\' . $e;
						break;
					default:
						$out .= $e;
				}
				$i = $j + 2;
			} elseif ( $j + 1 < $n && $s[ $j + 1 ] === $quote ) {
				$out .= $quote;
				$i    = $j + 2;
			} else {
				$i = $j + 1;
				break;
			}
		}
		return $out;
	}

	/** Read an unquoted token (number, NULL, function call...) up to "," or ")" at depth 0. */
	private static function read_raw( $s, &$i ) {
		$n     = strlen( $s );
		$start = $i;
		$depth = 0;
		while ( $i < $n ) {
			$c = $s[ $i ];
			if ( "'" === $c || '"' === $c ) {
				self::read_string( $s, $i, $c );
				continue;
			}
			if ( '(' === $c ) {
				$depth++;
			} elseif ( ')' === $c ) {
				if ( 0 === $depth ) {
					break;
				}
				$depth--;
			} elseif ( ',' === $c && 0 === $depth ) {
				break;
			}
			$i++;
		}
		return trim( substr( $s, $start, $i - $start ) );
	}

	/** Split on commas that are not inside parentheses or quotes. */
	private static function split_top_level( $s ) {
		$parts = array();
		$depth = 0;
		$cur   = '';
		$q     = '';
		$n     = strlen( $s );
		for ( $i = 0; $i < $n; $i++ ) {
			$c = $s[ $i ];
			if ( '' !== $q ) {
				$cur .= $c;
				if ( '\\' === $c && $i + 1 < $n ) {
					$cur .= $s[ ++$i ];
				} elseif ( $c === $q ) {
					$q = '';
				}
				continue;
			}
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$q    = $c;
				$cur .= $c;
			} elseif ( '(' === $c ) {
				$depth++;
				$cur .= $c;
			} elseif ( ')' === $c ) {
				$depth--;
				$cur .= $c;
			} elseif ( ',' === $c && 0 === $depth ) {
				$parts[] = $cur;
				$cur     = '';
			} else {
				$cur .= $c;
			}
		}
		if ( '' !== trim( $cur ) ) {
			$parts[] = $cur;
		}
		return $parts;
	}
}
