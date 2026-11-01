<?php
/**
 * Deterministic natural-language parser (English) for booking requests. It is the non-AI fallback
 * for DEV3 AI natural-language booking and produces the same constraint shape the AI returns.
 *
 * Understands: today / tomorrow / day after tomorrow, weekdays ("tue", "next friday"), this/next
 * week, (this) weekend, next month, "in 3 days", dates ("28 oct", "October 28", "10/28", ISO),
 * after/before/from/until/at/around/between times (5, 5pm, 17:30, noon), morning/afternoon/
 * evening/lunchtime, "with <staff>" and service names.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Rule parser.
 */
class Nl_Parser {

	const WEEKDAYS = array(
		'monday'    => 1,
		'mon'       => 1,
		'tuesday'   => 2,
		'tues'      => 2,
		'tue'       => 2,
		'wednesday' => 3,
		'wed'       => 3,
		'thursday'  => 4,
		'thurs'     => 4,
		'thur'      => 4,
		'thu'       => 4,
		'friday'    => 5,
		'fri'       => 5,
		'saturday'  => 6,
		'sat'       => 6,
		'sunday'    => 7,
		'sun'       => 7,
	);

	const MONTHS = array(
		'january'   => 1,
		'jan'       => 1,
		'february'  => 2,
		'feb'       => 2,
		'march'     => 3,
		'mar'       => 3,
		'april'     => 4,
		'apr'       => 4,
		'may'       => 5,
		'june'      => 6,
		'jun'       => 6,
		'july'      => 7,
		'jul'       => 7,
		'august'    => 8,
		'aug'       => 8,
		'september' => 9,
		'sept'      => 9,
		'sep'       => 9,
		'october'   => 10,
		'oct'       => 10,
		'november'  => 11,
		'nov'       => 11,
		'december'  => 12,
		'dec'       => 12,
	);

	/**
	 * Parses a request.
	 *
	 * @param string $text Request.
	 * @param array  $ctx  today (Y-m-d), services [{id,name}], staff [{id,name}], window (days, default 14).
	 * @return array { service_id, staff_id, date_from, date_to, weekdays[], time_from, time_to, summary }
	 */
	public static function parse( $text, array $ctx ) {
		$raw                          = (string) $text;
		$t                            = ' ' . self::normalise( $raw ) . ' ';
		$today                        = new \DateTimeImmutable( (string) $ctx['today'] );
		$out                          = array(
			'service_id' => self::match_entity( $t, (array) ( $ctx['services'] ?? array() ), false ),
			'staff_id'   => self::match_entity( $t, (array) ( $ctx['staff'] ?? array() ), true ),
			'date_from'  => '',
			'date_to'    => '',
			'weekdays'   => array(),
			'time_from'  => '',
			'time_to'    => '',
			'summary'    => '',
		);
		list( $from, $to, $weekdays ) = self::dates( $t, $today, (int) ( $ctx['window'] ?? 14 ) );
		$out['date_from']             = $from->format( 'Y-m-d' );
		$out['date_to']               = $to->format( 'Y-m-d' );
		$out['weekdays']              = $weekdays;
		list( $out['time_from'], $out['time_to'] ) = self::times( $t );
		$out['summary']                            = self::summary( $out, $ctx );
		return $out;
	}

	/**
	 * Lower-case, accents removed, punctuation spaced.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalise( $text ) {
		$text = strtolower( remove_accents( (string) $text ) );
		$text = preg_replace( '/[^a-z0-9:\/\-\s\.]/', ' ', $text );
		$text = preg_replace( '/(\d)\.(\d{2})\b/', '$1:$2', $text ); // 17.30 → 17:30.
		$text = str_replace( '.', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Finds a service/staff id by name ("with Anna", "Anna", or a significant word of the name).
	 *
	 * @param string $t        Normalised text (padded).
	 * @param array  $entities [{id,name}].
	 * @param bool   $people   Match first names.
	 * @return int
	 */
	private static function match_entity( $t, array $entities, $people ) {
		$best  = 0;
		$score = 0;
		$stop  = array( 'with', 'and', 'the', 'for', 'appointment', 'session', 'booking', 'service', 'minutes', 'mins', 'hour', 'hours' );
		foreach ( $entities as $e ) {
			$name = self::normalise( (string) ( $e['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$s = 0;
			if ( false !== strpos( $t, ' ' . $name . ' ' ) ) {
				$s = 100 + strlen( $name );
			} else {
				$words = array_filter( explode( ' ', $name ), static fn( $w ) => strlen( $w ) >= ( $people ? 2 : 4 ) && ! in_array( $w, $stop, true ) && ! is_numeric( $w ) );
				foreach ( $words as $i => $w ) {
					$variants = array( $w );
					if ( ! $people ) {
						$variants[] = rtrim( $w, 's' ); // "haircuts" ↔ "haircut".
						$variants[] = $w . 's';
					}
					foreach ( array_unique( $variants ) as $v ) {
						if ( strlen( $v ) >= 3 && preg_match( '/\b' . preg_quote( $v, '/' ) . '\b/', $t ) ) {
							$s = max( $s, ( $people && 0 === $i ? 60 : 40 ) + strlen( $v ) );
						}
					}
				}
			}
			if ( $people && $s > 0 && preg_match( '/\b(with|by|from)\s+' . preg_quote( explode( ' ', $name )[0], '/' ) . '\b/', $t ) ) {
				$s += 50;
			}
			if ( $s > $score ) {
				$score = $s;
				$best  = (int) $e['id'];
			}
		}
		return $best;
	}

	/**
	 * Date range + weekday filter.
	 *
	 * @param string             $t      Text.
	 * @param \DateTimeImmutable $today  Today.
	 * @param int                $window Default window days.
	 * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable,2:int[]}
	 */
	private static function dates( $t, \DateTimeImmutable $today, $window ) {
		$dow = (int) $today->format( 'N' );
		if ( preg_match( '/\bday after tomorrow\b/', $t ) ) {
			$d = $today->modify( '+2 day' );
			return array( $d, $d, array() );
		}
		if ( preg_match( '/\b(tomorrow|tmrw|tmr)\b/', $t ) ) {
			$d = $today->modify( '+1 day' );
			return array( $d, $d, array() );
		}
		if ( preg_match( '/\b(today|tonight|this (morning|afternoon|evening))\b/', $t ) ) {
			return array( $today, $today, array() );
		}
		if ( preg_match( '/\bin (\d{1,2}) (day|days|week|weeks)\b/', $t, $m ) ) {
			$n = (int) $m[1] * ( 0 === strpos( $m[2], 'week' ) ? 7 : 1 );
			$d = $today->modify( '+' . $n . ' day' );
			return array( $d, $d, array() );
		}
		$explicit = self::explicit_date( $t, $today );
		if ( $explicit ) {
			return array( $explicit, $explicit, array() );
		}
		// Weekdays (with "next" meaning the first occurrence after today; also "next week tuesday").
		$found = array();
		$next  = false;
		foreach ( self::WEEKDAYS as $word => $n ) {
			if ( preg_match( '/\b(next |this |on |coming )?' . $word . 's?\b/', $t, $m ) ) {
				$found[ $n ] = true;
				if ( isset( $m[1] ) && 'next ' === $m[1] ) {
					$next = true;
				}
			}
		}
		$next_week = (bool) preg_match( '/\bnext week\b/', $t );
		if ( $found ) {
			$days = array_keys( $found );
			sort( $days );
			if ( $next_week ) {
				$monday = $today->modify( '+' . ( 8 - $dow ) . ' day' );
				return array( $monday, $monday->modify( '+6 day' ), $days );
			}
			if ( 1 === count( $days ) ) {
				$diff = ( $days[0] - $dow + 7 ) % 7;
				if ( 0 === $diff && $next ) {
					$diff = 7;
				}
				$d = $today->modify( '+' . $diff . ' day' );
				return array( $d, $d, array() );
			}
			return array( $today, $today->modify( '+6 day' ), $days );
		}
		if ( $next_week ) {
			$monday = $today->modify( '+' . ( 8 - $dow ) . ' day' );
			return array( $monday, $monday->modify( '+6 day' ), self::part_weekdays( $t ) );
		}
		if ( preg_match( '/\b(this week|later this week|rest of the week)\b/', $t ) ) {
			return array( $today, $today->modify( '+' . ( 7 - $dow ) . ' day' ), self::part_weekdays( $t ) );
		}
		if ( preg_match( '/\bnext weekend\b/', $t ) ) {
			$sat = $today->modify( '+' . ( ( 6 - $dow + 7 ) % 7 + 7 ) . ' day' );
			return array( $sat, $sat->modify( '+1 day' ), array() );
		}
		if ( preg_match( '/\bweekend\b/', $t ) ) {
			$sat = 7 === $dow ? $today : $today->modify( '+' . ( ( 6 - $dow + 7 ) % 7 ) . ' day' );
			return array( $sat, 7 === $dow ? $today : $sat->modify( '+1 day' ), array() );
		}
		if ( preg_match( '/\bnext month\b/', $t ) ) {
			$first = $today->modify( 'first day of next month' );
			return array( $first, $first->modify( 'last day of this month' ), self::part_weekdays( $t ) );
		}
		return array( $today, $today->modify( '+' . max( 0, $window - 1 ) . ' day' ), self::part_weekdays( $t ) );
	}

	/**
	 * "weekdays" / "weekday" → Mon–Fri filter.
	 *
	 * @param string $t Text.
	 * @return int[]
	 */
	private static function part_weekdays( $t ) {
		return preg_match( '/\bweekdays?\b/', $t ) ? array( 1, 2, 3, 4, 5 ) : array();
	}

	/**
	 * Explicit dates: ISO, 28 oct, oct 28(th), 28/10 or 10/28 (day first when > 12), "the 28th".
	 *
	 * @param string             $t     Text.
	 * @param \DateTimeImmutable $today Today.
	 * @return \DateTimeImmutable|null
	 */
	private static function explicit_date( $t, \DateTimeImmutable $today ) {
		$months = implode( '|', array_keys( self::MONTHS ) );
		$y      = (int) $today->format( 'Y' );
		$make   = static function ( $year, $month, $day ) use ( $today ) {
			if ( ! checkdate( $month, $day, $year ) ) {
				return null;
			}
			$d = $today->setDate( $year, $month, $day );
			return $d < $today ? $d->modify( '+1 year' ) : $d;
		};
		if ( preg_match( '/\b(\d{4})-(\d{2})-(\d{2})\b/', $t, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $today->setDate( (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		if ( preg_match( '/\b(\d{1,2})(st|nd|rd|th)? (of )?(' . $months . ')\b/', $t, $m ) ) {
			return $make( $y, self::MONTHS[ $m[4] ], (int) $m[1] );
		}
		if ( preg_match( '/\b(' . $months . ') (\d{1,2})(st|nd|rd|th)?\b/', $t, $m ) ) {
			return $make( $y, self::MONTHS[ $m[1] ], (int) $m[2] );
		}
		if ( preg_match( '/\b(\d{1,2})\/(\d{1,2})\b/', $t, $m ) ) {
			$a = (int) $m[1];
			$b = (int) $m[2];
			return $a > 12 ? $make( $y, $b, $a ) : $make( $y, $a, $b );
		}
		if ( preg_match( '/\bthe (\d{1,2})(st|nd|rd|th)\b/', $t, $m ) ) {
			$day = (int) $m[1];
			$d   = $make( $y, (int) $today->format( 'n' ), $day );
			if ( $d && $d->format( 'n' ) !== $today->format( 'n' ) ) {
				$d = $make( $y, (int) $today->format( 'n' ), $day );
			}
			if ( ! $d || $d < $today ) {
				$next = $today->modify( 'first day of next month' );
				$d    = $make( (int) $next->format( 'Y' ), (int) $next->format( 'n' ), $day );
			}
			return $d;
		}
		return null;
	}

	/**
	 * Converts "5", "5pm", "17:30", "noon" to HH:MM. Bare hours 1–7 mean the afternoon/evening.
	 *
	 * @param string $h      Hour.
	 * @param string $min    Minutes.
	 * @param string $ampm   am|pm|''.
	 * @return string
	 */
	private static function clock( $h, $min = '', $ampm = '' ) {
		$h = (int) $h;
		$m = '' === $min ? 0 : (int) $min;
		if ( 'pm' === $ampm && $h < 12 ) {
			$h += 12;
		} elseif ( 'am' === $ampm && 12 === $h ) {
			$h = 0;
		} elseif ( '' === $ampm && $h >= 1 && $h <= 7 ) {
			$h += 12;
		}
		if ( $h > 23 || $m > 59 ) {
			return '';
		}
		return sprintf( '%02d:%02d', $h, $m );
	}

	/**
	 * Time window for the start time.
	 *
	 * @param string $t Text.
	 * @return array{0:string,1:string}
	 */
	private static function times( $t ) {
		// Dates must not be read as times ("2026-11-20", "10/28", "28 nov", "in 3 days").
		$months = implode( '|', array_keys( self::MONTHS ) );
		$t      = preg_replace( '/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\/\d{1,2}\b|\bin \d{1,2} (day|days|week|weeks)\b/', ' ', $t );
		$t      = preg_replace( '/\b\d{1,2}(st|nd|rd|th)? (of )?(' . $months . ')\b|\b(' . $months . ') \d{1,2}(st|nd|rd|th)?\b|\bthe \d{1,2}(st|nd|rd|th)\b/', ' ', $t );
		$t      = preg_replace( '/\b(noon|midday)\b/', '12:00', $t );
		$t      = preg_replace( '/\bmidnight\b/', '00:00', $t );
		$time   = '(\d{1,2})(?::(\d{2}))?\s?(am|pm|a m|p m)?';
		$norm   = static fn( $s ) => str_replace( ' ', '', (string) $s );
		if ( preg_match( '/\bbetween ' . $time . ' (and|to|-) ' . $time . '\b/', $t, $m ) || preg_match( '/\b(?:from )?' . $time . ' ?(-|to|until|till) ?' . $time . '\b/', $t, $m2 ) ) {
			if ( isset( $m2 ) && empty( $m ) ) {
				$m = $m2;
			}
			$ampm2 = $norm( $m[7] ?? '' );
			$ampm1 = $norm( $m[3] ?? '' );
			$ampm1 = '' === $ampm1 && '' !== $ampm2 && (int) $m[1] <= (int) $m[5] ? $ampm2 : $ampm1;
			$a     = self::clock( $m[1], $m[2] ?? '', $ampm1 );
			$b     = self::clock( $m[5], $m[6] ?? '', $ampm2 );
			if ( $a && $b && $a < $b ) {
				return array( $a, $b );
			}
		}
		if ( preg_match( '/\b(after|from|later than|past|not before|starting) ' . $time . '\b/', $t, $m ) ) {
			$a = self::clock( $m[2], $m[3] ?? '', $norm( $m[4] ?? '' ) );
			if ( $a ) {
				return array( $a, '' );
			}
		}
		if ( preg_match( '/\b(before|until|till|by|earlier than|no later than) ' . $time . '\b/', $t, $m ) ) {
			$b = self::clock( $m[2], $m[3] ?? '', $norm( $m[4] ?? '' ) );
			if ( $b ) {
				return array( '', $b );
			}
		}
		if ( preg_match( '/\b(at|around|about|for) ' . $time . '\b/', $t, $m ) && ( '' !== ( $m[3] ?? '' ) || '' !== ( $m[4] ?? '' ) || 'at' === $m[1] || 'around' === $m[1] ) ) {
			$a = self::clock( $m[2], $m[3] ?? '', $norm( $m[4] ?? '' ) );
			if ( $a ) {
				$mins = (int) substr( $a, 0, 2 ) * 60 + (int) substr( $a, 3 );
				$lo   = 'at' === $m[1] ? $mins : max( 0, $mins - 60 );
				$hi   = min( 23 * 60 + 59, $mins + 60 );
				return array( sprintf( '%02d:%02d', intdiv( $lo, 60 ), $lo % 60 ), sprintf( '%02d:%02d', intdiv( $hi, 60 ), $hi % 60 ) );
			}
		}
		if ( preg_match( '/\b(\d{1,2})(?::(\d{2}))?\s?(am|pm)\b/', $t, $m ) ) {
			$a = self::clock( $m[1], $m[2] ?? '', $m[3] );
			if ( $a ) {
				$mins = (int) substr( $a, 0, 2 ) * 60 + (int) substr( $a, 3 );
				$hi   = min( 23 * 60 + 59, $mins + 60 );
				return array( $a, sprintf( '%02d:%02d', intdiv( $hi, 60 ), $hi % 60 ) );
			}
		}
		if ( preg_match( '/\b(early morning)\b/', $t ) ) {
			return array( '06:00', '09:00' );
		}
		if ( preg_match( '/\b(morning|mornings)\b/', $t ) ) {
			return array( '06:00', '12:00' );
		}
		if ( preg_match( '/\b(lunch|lunchtime|lunch time)\b/', $t ) ) {
			return array( '11:30', '14:00' );
		}
		if ( preg_match( '/\b(late afternoon)\b/', $t ) ) {
			return array( '15:00', '18:00' );
		}
		if ( preg_match( '/\b(afternoon|afternoons)\b/', $t ) ) {
			return array( '12:00', '17:00' );
		}
		if ( preg_match( '/\b(evening|evenings|tonight|after work)\b/', $t ) ) {
			return array( '17:00', '22:00' );
		}
		return array( '', '' );
	}

	/**
	 * Neutral English summary.
	 *
	 * @param array $c   Constraints.
	 * @param array $ctx Context.
	 * @return string
	 */
	public static function summary( array $c, array $ctx ) {
		$parts = array();
		foreach ( (array) ( $ctx['services'] ?? array() ) as $s ) {
			if ( (int) $s['id'] === (int) $c['service_id'] ) {
				$parts[] = $s['name'];
			}
		}
		if ( $c['date_from'] && $c['date_from'] === $c['date_to'] ) {
			$parts[] = wp_date( 'l j M', strtotime( $c['date_from'] . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
		} elseif ( $c['weekdays'] ) {
			$names = array();
			foreach ( $c['weekdays'] as $n ) {
				$names[] = wp_date( 'l', strtotime( 'monday this week +' . ( $n - 1 ) . ' day 12:00 UTC' ), new \DateTimeZone( 'UTC' ) );
			}
			$parts[] = implode( '/', $names );
		}
		if ( $c['time_from'] && $c['time_to'] ) {
			/* translators: 1: start, 2: end. */
			$parts[] = sprintf( __( 'between %1$s and %2$s', 'dev3-ai-booking-appointments' ), $c['time_from'], $c['time_to'] );
		} elseif ( $c['time_from'] ) {
			/* translators: %s: time. */
			$parts[] = sprintf( __( 'after %s', 'dev3-ai-booking-appointments' ), $c['time_from'] );
		} elseif ( $c['time_to'] ) {
			/* translators: %s: time. */
			$parts[] = sprintf( __( 'before %s', 'dev3-ai-booking-appointments' ), $c['time_to'] );
		}
		foreach ( (array) ( $ctx['staff'] ?? array() ) as $s ) {
			if ( (int) $s['id'] === (int) $c['staff_id'] ) {
				/* translators: %s: staff name. */
				$parts[] = sprintf( __( 'with %s', 'dev3-ai-booking-appointments' ), $s['name'] );
			}
		}
		return implode( ', ', $parts );
	}
}
