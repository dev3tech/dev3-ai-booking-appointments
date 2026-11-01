<?php
/**
 * Time source + local-time helpers (DST-aware).
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * Clock.
 */
class Clock {

	/**
	 * Current unix time (filterable so tests can freeze it).
	 *
	 * @return int
	 */
	public static function now() {
		return (int) apply_filters( 'dev3bk_now', time() );
	}

	/**
	 * Unix time of a wall-clock time on a local date. Minutes may exceed 1440 (next day).
	 *
	 * @param string        $date    Y-m-d.
	 * @param int           $minutes Minutes after local midnight.
	 * @param \DateTimeZone $tz      Zone.
	 * @param bool          $strict  Return null when the wall-clock time does not exist (DST gap).
	 * @return int|null
	 */
	public static function local( $date, $minutes, \DateTimeZone $tz, $strict = false ) {
		$day     = new \DateTimeImmutable( $date . ' 00:00:00', $tz );
		$extra   = intdiv( (int) $minutes, 1440 );
		$minutes = (int) $minutes % 1440;
		if ( $extra ) {
			$day = $day->modify( '+' . $extra . ' day' );
		}
		$wall = sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
		$dt   = new \DateTimeImmutable( $day->format( 'Y-m-d' ) . ' ' . $wall . ':00', $tz );
		if ( $strict && $dt->format( 'H:i' ) !== $wall ) {
			return null;
		}
		return $dt->getTimestamp();
	}

	/**
	 * "HH:MM" → minutes.
	 *
	 * @param string $hm Time.
	 * @return int
	 */
	public static function minutes( $hm ) {
		$parts = explode( ':', (string) $hm );
		return (int) $parts[0] * 60 + (int) ( $parts[1] ?? 0 );
	}

	/**
	 * Local Y-m-d for a unix time.
	 *
	 * @param int           $ts Unix.
	 * @param \DateTimeZone $tz Zone.
	 * @return string
	 */
	public static function date( $ts, \DateTimeZone $tz ) {
		return ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz )->format( 'Y-m-d' );
	}

	/**
	 * Formats a unix time in a zone.
	 *
	 * @param int           $ts     Unix.
	 * @param \DateTimeZone $tz     Zone.
	 * @param string        $format PHP date format.
	 * @return string
	 */
	public static function format( $ts, \DateTimeZone $tz, $format ) {
		return ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz )->format( $format );
	}

	/**
	 * Localised (wp_date) formatting in a zone.
	 *
	 * @param int           $ts     Unix.
	 * @param \DateTimeZone $tz     Zone.
	 * @param string        $format Format.
	 * @return string
	 */
	public static function i18n( $ts, \DateTimeZone $tz, $format ) {
		return (string) wp_date( $format, (int) $ts, $tz );
	}

	/**
	 * Zone from a string with fallback.
	 *
	 * @param string             $tz       IANA name.
	 * @param \DateTimeZone|null $fallback Fallback.
	 * @return \DateTimeZone
	 */
	public static function zone( $tz, $fallback = null ) {
		$tz = trim( (string) $tz );
		if ( '' !== $tz && ( 'UTC' === $tz || in_array( $tz, timezone_identifiers_list(), true ) ) ) {
			return new \DateTimeZone( $tz );
		}
		return $fallback ? $fallback : \Dev3\AiBooking\Settings::timezone();
	}

	/**
	 * Unix → UTC MySQL datetime.
	 *
	 * @param int $ts Unix.
	 * @return string
	 */
	public static function sql( $ts ) {
		return gmdate( 'Y-m-d H:i:s', (int) $ts );
	}

	/**
	 * UTC MySQL datetime → unix.
	 *
	 * @param string $sql Datetime.
	 * @return int
	 */
	public static function ts( $sql ) {
		return (int) strtotime( (string) $sql . ' UTC' );
	}

	/**
	 * ISO 8601 UTC.
	 *
	 * @param int $ts Unix.
	 * @return string
	 */
	public static function iso( $ts ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts );
	}

	/**
	 * Parses an ISO/MySQL datetime string (UTC unless it has an offset) → unix or 0.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	public static function parse( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value ) ) {
			return 0;
		}
		try {
			$dt = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return 0;
		}
		return $dt->getTimestamp();
	}
}
