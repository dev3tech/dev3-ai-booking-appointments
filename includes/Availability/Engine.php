<?php
/**
 * Availability engine: working intervals → wall-clock aligned slots → conflict, buffer, capacity,
 * notice and advance checks. Pure computation over the repositories; no writes.
 *
 * Rules (see docs/SPEC.md §2.4):
 * - Slots are aligned to wall-clock times in the staff member's timezone; durations/buffers are real minutes.
 * - DST: wall-clock times that do not exist are skipped; ambiguous ones are offered once (first occurrence).
 * - Occupied span = [start - buffer_before, end + buffer_after]; spans of one staff member never overlap.
 * - Group services (capacity > 1): bookings of the same service at the same start share seats.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Availability;

use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Exceptions;
use Dev3\AiBooking\Data\Services;
use Dev3\AiBooking\Data\Staff;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Engine.
 */
class Engine {

	const DAY_KEYS = array(
		1 => 'mon',
		2 => 'tue',
		3 => 'wed',
		4 => 'thu',
		5 => 'fri',
		6 => 'sat',
		7 => 'sun',
	);

	/**
	 * Working intervals (unix [start, end) pairs) for a staff member on a staff-local date.
	 *
	 * Special hours for the date replace the weekly hours (and weekly breaks); time off and business
	 * closures are subtracted (all day or a time window).
	 *
	 * @param array  $staff Staff row.
	 * @param string $date  Y-m-d in the staff timezone.
	 * @return array
	 */
	public static function working( array $staff, $date ) {
		$tz                    = Staff::tz( $staff );
		$exceptions            = Exceptions::on_date( (int) $staff['id'], $date );
		list( $week, $breaks ) = Staff::week( $staff );
		$dow                   = self::DAY_KEYS[ (int) ( new \DateTimeImmutable( $date, $tz ) )->format( 'N' ) ];
		$own                   = array();
		$biz                   = array();
		$off                   = array();
		foreach ( $exceptions as $ex ) {
			if ( 'hours' === $ex['type'] ) {
				if ( $ex['staff_id'] ) {
					$own[] = array( $ex['start_time'], $ex['end_time'] );
				} else {
					$biz[] = array( $ex['start_time'], $ex['end_time'] );
				}
			} else {
				if ( '' === $ex['start_time'] ) {
					return array(); // All-day time off or closure.
				}
				$off[] = array( $ex['start_time'], $ex['end_time'] );
			}
		}
		if ( $own || $biz ) {
			$hours      = Settings::sanitize_intervals( $own ? $own : $biz );
			$day_breaks = array();
		} else {
			$hours      = $week[ $dow ] ?? array();
			$day_breaks = $breaks[ $dow ] ?? array();
		}
		$to_ts     = static function ( $pairs ) use ( $date, $tz ) {
			$out = array();
			foreach ( $pairs as $p ) {
				$a = Clock::local( $date, Clock::minutes( $p[0] ), $tz );
				$b = Clock::local( $date, Clock::minutes( $p[1] ), $tz );
				if ( $b > $a ) {
					$out[] = array( $a, $b );
				}
			}
			return $out;
		};
		$intervals = $to_ts( $hours );
		foreach ( array_merge( $to_ts( $day_breaks ), $to_ts( $off ) ) as $cut ) {
			$intervals = self::subtract( $intervals, $cut );
		}
		return $intervals;
	}

	/**
	 * Removes [a, b) from a list of intervals.
	 *
	 * @param array $intervals Intervals.
	 * @param array $cut       [a, b].
	 * @return array
	 */
	public static function subtract( array $intervals, array $cut ) {
		$out = array();
		foreach ( $intervals as $iv ) {
			if ( $cut[1] <= $iv[0] || $cut[0] >= $iv[1] ) {
				$out[] = $iv;
				continue;
			}
			if ( $cut[0] > $iv[0] ) {
				$out[] = array( $iv[0], $cut[0] );
			}
			if ( $cut[1] < $iv[1] ) {
				$out[] = array( $cut[1], $iv[1] );
			}
		}
		return $out;
	}

	/**
	 * Candidate slot starts inside an interval, aligned to the wall clock of the interval start.
	 *
	 * @param string        $date  Staff-local date of the interval.
	 * @param array         $iv    [start, end] unix.
	 * @param int           $dur   Duration seconds.
	 * @param int           $step  Step minutes.
	 * @param \DateTimeZone $tz    Zone.
	 * @return int[]
	 */
	public static function candidates( $date, array $iv, $dur, $step, \DateTimeZone $tz ) {
		$out   = array();
		$day0  = ( new \DateTimeImmutable( $date . ' 00:00:00', $tz ) );
		$start = ( new \DateTimeImmutable( '@' . $iv[0] ) )->setTimezone( $tz );
		// Wall-clock minutes of the interval start, relative to the local date (may be ≥ 1440 when it starts after midnight).
		$days = (int) $day0->diff( $start->setTime( 0, 0 ) )->format( '%r%a' );
		$m    = $days * 1440 + (int) $start->format( 'G' ) * 60 + (int) $start->format( 'i' );
		$seen = array();
		for ( $guard = 0; $guard < 600; $guard++, $m += $step ) {
			$ts = Clock::local( $date, $m, $tz, true );
			if ( null === $ts ) {
				continue; // Wall-clock time skipped by a DST change.
			}
			if ( $ts < $iv[0] || isset( $seen[ $ts ] ) ) {
				continue;
			}
			if ( $ts + $dur > $iv[1] ) {
				break;
			}
			$seen[ $ts ] = true;
			$out[]       = $ts;
		}
		return $out;
	}

	/**
	 * Free slots for a service across staff in a UTC window.
	 *
	 * @param array $service   Service row.
	 * @param int[] $staff_ids Staff ids.
	 * @param int   $from      Window start (unix).
	 * @param int   $to        Window end (unix, exclusive).
	 * @param array $opts      seats (1), exclude (booking id), now (unix), ignore_rules (admin: skip notice/advance).
	 * @return array List of [ start, end, staff_id, seats_left ] sorted by start then staff order.
	 */
	public static function slots( array $service, array $staff_ids, $from, $to, array $opts = array() ) {
		$now      = isset( $opts['now'] ) ? (int) $opts['now'] : Clock::now();
		$seats    = max( 1, (int) ( $opts['seats'] ?? 1 ) );
		$exclude  = (int) ( $opts['exclude'] ?? 0 );
		$dur      = max( 5, (int) $service['duration'] ) * MINUTE_IN_SECONDS;
		$step     = max( 5, Services::interval( $service ) );
		$bb       = (int) $service['buffer_before'] * MINUTE_IN_SECONDS;
		$ba       = (int) $service['buffer_after'] * MINUTE_IN_SECONDS;
		$capacity = max( 1, (int) $service['capacity'] );
		if ( empty( $opts['ignore_rules'] ) ) {
			$from = max( $from, $now + Services::min_notice( $service ) * MINUTE_IN_SECONDS );
			$to   = min( $to, $now + Services::max_advance( $service ) * DAY_IN_SECONDS );
		}
		if ( $to <= $from ) {
			return array();
		}
		$busy  = Bookings::busy( $staff_ids, $from, $to + $dur, $now, $exclude );
		$slots = array();
		foreach ( $staff_ids as $order => $sid ) {
			$staff = Staff::get( $sid );
			if ( ! $staff ) {
				continue;
			}
			$tz   = Staff::tz( $staff );
			$day  = new \DateTimeImmutable( Clock::date( $from, $tz ), $tz );
			$last = Clock::date( $to, $tz );
			for ( $i = 0; $i < 800; $i++, $day = $day->modify( '+1 day' ) ) {
				$date = $day->format( 'Y-m-d' );
				if ( $date > $last ) {
					break;
				}
				foreach ( self::working( $staff, $date ) as $iv ) {
					foreach ( self::candidates( $date, $iv, $dur, $step, $tz ) as $ts ) {
						if ( $ts < $from || $ts >= $to ) {
							continue;
						}
						$left = self::seats_left( $service, $ts, $dur, $bb, $ba, $capacity, $busy[ $sid ] ?? array() );
						if ( $left >= $seats ) {
							$slots[] = array(
								'start'      => $ts,
								'end'        => $ts + $dur,
								'staff_id'   => (int) $sid,
								'seats_left' => $left,
								'order'      => $order,
							);
						}
					}
				}
			}
		}
		usort( $slots, static fn( $a, $b ) => 0 !== ( $a['start'] <=> $b['start'] ) ? $a['start'] <=> $b['start'] : $a['order'] <=> $b['order'] );
		/**
		 * Filters computed slots.
		 *
		 * @param array $slots   Slots.
		 * @param array $service Service.
		 * @param array $opts    Options.
		 */
		return apply_filters( 'dev3bk_slots', $slots, $service, $opts );
	}

	/**
	 * Seats still free at a start for a staff member (0 = blocked).
	 *
	 * @param array $service  Service.
	 * @param int   $ts       Start.
	 * @param int   $dur      Duration (s).
	 * @param int   $bb       Buffer before (s).
	 * @param int   $ba       Buffer after (s).
	 * @param int   $capacity Capacity.
	 * @param array $busy     Staff's blocking bookings.
	 * @return int
	 */
	public static function seats_left( array $service, $ts, $dur, $bb, $ba, $capacity, array $busy ) {
		$a1    = $ts - $bb;
		$a2    = $ts + $dur + $ba;
		$taken = 0;
		foreach ( $busy as $b ) {
			$s = Clock::ts( $b['starts_at'] );
			$e = Clock::ts( $b['ends_at'] );
			if ( $capacity > 1 && (int) $b['service_id'] === (int) $service['id'] && $s === $ts ) {
				$taken += max( 1, (int) $b['seats'] );
				continue;
			}
			$b1 = $s - (int) $b['buffer_before'] * MINUTE_IN_SECONDS;
			$b2 = $e + (int) $b['buffer_after'] * MINUTE_IN_SECONDS;
			if ( $a1 < $b2 && $b1 < $a2 ) {
				return 0;
			}
		}
		return max( 0, $capacity - $taken );
	}

	/**
	 * Is an exact start free for a staff member?
	 *
	 * @param array $service  Service.
	 * @param int   $staff_id Staff.
	 * @param int   $ts       Start.
	 * @param array $opts     seats, exclude, now, ignore_rules.
	 * @return bool
	 */
	public static function is_free( array $service, $staff_id, $ts, array $opts = array() ) {
		foreach ( self::slots( $service, array( (int) $staff_id ), $ts, $ts + 1, $opts ) as $slot ) {
			if ( $slot['start'] === (int) $ts ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Staff ids to search: one member (when they perform the service) or all who do.
	 *
	 * @param array $service  Service.
	 * @param int   $staff_id Staff (0 = any).
	 * @return int[]
	 */
	public static function staff_for( array $service, $staff_id ) {
		$all = Staff::for_service( (int) $service['id'] );
		if ( $staff_id ) {
			return in_array( (int) $staff_id, $all, true ) ? array( (int) $staff_id ) : array();
		}
		return $all;
	}

	/**
	 * Customer-facing days between two customer-local dates (inclusive), merged across staff.
	 *
	 * @param array  $service  Service.
	 * @param int    $staff_id Staff (0 = any).
	 * @param string $from     Y-m-d (customer tz).
	 * @param string $to       Y-m-d (customer tz).
	 * @param string $tz_name  Customer timezone.
	 * @param array  $opts     Engine options.
	 * @return array { tz, days: [ date => [ { start, label, staff[], seats_left } ] ], first }
	 */
	public static function days( array $service, $staff_id, $from, $to, $tz_name, array $opts = array() ) {
		$tz    = Clock::zone( $tz_name );
		$start = Clock::local( $from, 0, $tz );
		$end   = Clock::local( $to, 1440, $tz );
		$slots = self::slots( $service, self::staff_for( $service, $staff_id ), $start, $end, $opts );
		return array(
			'tz'   => $tz->getName(),
			'days' => self::group( $slots, $tz ),
		);
	}

	/**
	 * Groups slots by local date and merges the same start across staff.
	 *
	 * @param array         $slots Slots.
	 * @param \DateTimeZone $tz    Customer zone.
	 * @return array
	 */
	public static function group( array $slots, \DateTimeZone $tz ) {
		$fmt  = '12' === (string) Settings::get( 'time_format', '24' ) ? 'g:i A' : 'H:i';
		$days = array();
		foreach ( $slots as $s ) {
			$date = Clock::date( $s['start'], $tz );
			$key  = (string) $s['start'];
			if ( ! isset( $days[ $date ][ $key ] ) ) {
				$days[ $date ][ $key ] = array(
					'start'      => Clock::iso( $s['start'] ),
					'label'      => Clock::format( $s['start'], $tz, $fmt ),
					'hour'       => (int) Clock::format( $s['start'], $tz, 'G' ),
					'staff'      => array(),
					'seats_left' => 0,
				);
			}
			$days[ $date ][ $key ]['staff'][]    = $s['staff_id'];
			$days[ $date ][ $key ]['seats_left'] = max( $days[ $date ][ $key ]['seats_left'], $s['seats_left'] );
		}
		foreach ( $days as $date => $list ) {
			$days[ $date ] = array_values( $list );
		}
		ksort( $days );
		return $days;
	}

	/**
	 * First customer-local date with a free slot, searching forward from a date.
	 *
	 * @param array  $service  Service.
	 * @param int    $staff_id Staff.
	 * @param string $from     Y-m-d.
	 * @param string $tz_name  Zone.
	 * @return string Y-m-d or ''.
	 */
	public static function next_available( array $service, $staff_id, $from, $tz_name ) {
		$tz    = Clock::zone( $tz_name );
		$start = Clock::local( $from, 0, $tz );
		$limit = Clock::now() + Services::max_advance( $service ) * DAY_IN_SECONDS;
		$ids   = self::staff_for( $service, $staff_id );
		for ( $win = $start; $win < $limit; $win += 14 * DAY_IN_SECONDS ) {
			$slots = self::slots( $service, $ids, $win, $win + 14 * DAY_IN_SECONDS );
			if ( $slots ) {
				return Clock::date( $slots[0]['start'], $tz );
			}
		}
		return '';
	}
}
