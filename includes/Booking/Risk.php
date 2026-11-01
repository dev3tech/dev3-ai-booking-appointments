<?php
/**
 * No-show risk hint: a deterministic, explainable score (0–100) from booking + customer history.
 * It is a hint for the admin only; nothing acts on it automatically.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Booking;

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Customers;

defined( 'ABSPATH' ) || exit;

/**
 * Risk.
 */
class Risk {

	/**
	 * Anonymised features (no names/emails) — also what DEV3 AI sees.
	 *
	 * @param array $b Booking.
	 * @return array
	 */
	public static function features( array $b ) {
		$stats   = Customers::stats( (int) $b['customer_id'] );
		$start   = Clock::ts( $b['starts_at'] );
		$created = Clock::ts( $b['created_at'] );
		return array(
			'past_no_shows'       => (int) $stats['no_show'],
			'late_cancellations'  => (int) $stats['late_cancel'],
			'completed_visits'    => (int) $stats['completed'],
			'first_visit'         => 0 === (int) $stats['completed'] && 0 === (int) $stats['no_show'],
			'unpaid'              => in_array( $b['payment_status'], array( 'unpaid' ), true ),
			'pay_later'           => 'later' === $b['payment_method'],
			'lead_time_days'      => round( max( 0, $start - $created ) / DAY_IN_SECONDS, 1 ),
			'booked_hours_before' => round( max( 0, $start - $created ) / HOUR_IN_SECONDS, 1 ),
			'reschedules'         => (int) $b['reschedules'],
			'local_hour'          => (int) Clock::format( $start, Clock::zone( $b['customer_tz'] ), 'G' ),
			'amount'              => (float) $b['amount'],
		);
	}

	/**
	 * Rule-based score with reasons.
	 *
	 * @param array $f Features.
	 * @return array { score, level, reasons[] }
	 */
	public static function score_features( array $f ) {
		$score   = 5;
		$reasons = array();
		if ( $f['past_no_shows'] > 0 ) {
			$score += min( 60, 35 * $f['past_no_shows'] );
			/* translators: %d: count. */
			$reasons[] = sprintf( _n( '%d earlier no-show', '%d earlier no-shows', $f['past_no_shows'], 'dev3-ai-booking-appointments' ), $f['past_no_shows'] );
		}
		if ( $f['late_cancellations'] > 0 ) {
			$score += min( 20, 10 * $f['late_cancellations'] );
			/* translators: %d: count. */
			$reasons[] = sprintf( _n( '%d late cancellation', '%d late cancellations', $f['late_cancellations'], 'dev3-ai-booking-appointments' ), $f['late_cancellations'] );
		}
		if ( $f['first_visit'] ) {
			$score    += 10;
			$reasons[] = __( 'First visit', 'dev3-ai-booking-appointments' );
		}
		if ( $f['unpaid'] && $f['pay_later'] ) {
			$score    += 10;
			$reasons[] = __( 'Pay later, nothing paid yet', 'dev3-ai-booking-appointments' );
		}
		if ( $f['lead_time_days'] >= 21 ) {
			$score    += 10;
			$reasons[] = __( 'Booked more than 3 weeks ahead', 'dev3-ai-booking-appointments' );
		}
		if ( $f['reschedules'] >= 2 ) {
			$score    += 10;
			$reasons[] = __( 'Rescheduled several times', 'dev3-ai-booking-appointments' );
		}
		if ( $f['completed_visits'] >= 3 && 0 === $f['past_no_shows'] ) {
			$score    -= 15;
			$reasons[] = __( 'Regular customer with a clean record', 'dev3-ai-booking-appointments' );
		}
		if ( ! $f['unpaid'] && $f['amount'] > 0 ) {
			$score    -= 10;
			$reasons[] = __( 'Paid in advance', 'dev3-ai-booking-appointments' );
		}
		$score = (int) max( 0, min( 100, $score ) );
		/**
		 * Filters the no-show risk score.
		 *
		 * @param int   $score    Score.
		 * @param array $features Features.
		 */
		$score = (int) apply_filters( 'dev3bk_risk_score', $score, $f );
		return array(
			'score'   => $score,
			'level'   => $score >= 50 ? 'high' : ( $score >= 25 ? 'medium' : 'low' ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Score for a booking.
	 *
	 * @param array $b Booking.
	 * @return array
	 */
	public static function score( array $b ) {
		return self::score_features( self::features( $b ) );
	}

	/**
	 * Computes and stores the score, keeping any AI hint whose features still match.
	 *
	 * @param array $b Booking.
	 * @return array Stored risk.
	 */
	public static function store( array $b ) {
		$features = self::features( $b );
		$risk     = self::score_features( $features );
		$hash     = md5( (string) wp_json_encode( $features ) );
		$old      = is_array( $b['risk'] ) ? $b['risk'] : array();
		if ( ! empty( $old['ai'] ) && ( $old['hash'] ?? '' ) === $hash ) {
			$risk['ai'] = $old['ai'];
		}
		$risk['hash'] = $hash;
		$risk['at']   = Clock::sql( Clock::now() );
		Bookings::update( $b['id'], array( 'risk' => $risk ) );
		return $risk;
	}
}
