<?php
/**
 * Reminder + follow-up runner (5-minute cron). Each booking gets each message at most once.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Notify;

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Booking\Manager;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reminders.
 */
class Reminders {

	const HOOK = 'dev3bk_reminders';

	/**
	 * Hooks.
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( self::class, 'schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minutes is intended.
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_event( time() + 60, 'dev3bk_five_minutes', self::HOOK );
				}
			}
		);
	}

	/**
	 * Adds the 5-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedule( $schedules ) {
		$schedules['dev3bk_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'dev3-ai-booking-appointments' ),
		);
		return $schedules;
	}

	/**
	 * Sends due reminders and follow-ups; expires unpaid holds.
	 *
	 * @return array Counts { reminder_1, reminder_2, followup, skipped, expired }.
	 */
	public static function run() {
		$now     = Clock::now();
		$counts  = array(
			'reminder_1' => 0,
			'reminder_2' => 0,
			'followup'   => 0,
			'skipped'    => 0,
			'expired'    => Manager::expire_holds(),
		);
		$offsets = array(
			'reminder_1' => (int) Settings::get( 'reminder1_hours', 24 ),
			'reminder_2' => (int) Settings::get( 'reminder2_hours', 2 ),
		);
		$max     = max( $offsets ) * HOUR_IN_SECONDS;
		if ( $max > 0 ) {
			foreach ( Bookings::between( Clock::sql( $now ), Clock::sql( $now + $max + 10 * MINUTE_IN_SECONDS ), array( 'pending', 'confirmed' ) ) as $b ) {
				$start   = Clock::ts( $b['starts_at'] );
				$created = Clock::ts( $b['created_at'] );
				$flags   = (array) $b['reminders'];
				$changed = false;
				foreach ( $offsets as $id => $hours ) {
					if ( $hours <= 0 || ! empty( $flags[ $id ] ) || ! Templates::get( $id )['enabled'] ) {
						continue;
					}
					$due = $start - $hours * HOUR_IN_SECONDS;
					if ( $now < $due || $now >= $start ) {
						continue;
					}
					if ( $created > $due ) {
						$flags[ $id ] = 'skipped'; // Booked inside the reminder window: the confirmation is enough.
						++$counts['skipped'];
					} else {
						$flags[ $id ] = Mailer::send_customer( $id, $b ) ? Clock::sql( $now ) : 'failed';
						++$counts[ $id ];
					}
					$changed = true;
				}
				if ( $changed ) {
					Bookings::update( $b['id'], array( 'reminders' => $flags ) );
				}
			}
		}
		$follow = (int) Settings::get( 'followup_hours', 0 );
		if ( $follow > 0 && Templates::get( 'followup' )['enabled'] ) {
			$window = $now - $follow * HOUR_IN_SECONDS;
			foreach ( Bookings::between( Clock::sql( $window - 7 * DAY_IN_SECONDS ), Clock::sql( $window ), array( 'confirmed', 'completed' ) ) as $b ) {
				$flags = (array) $b['reminders'];
				if ( ! empty( $flags['followup'] ) || Clock::ts( $b['ends_at'] ) > $window ) {
					continue;
				}
				$flags['followup'] = Mailer::send_customer( 'followup', $b ) ? Clock::sql( $now ) : 'failed';
				Bookings::update( $b['id'], array( 'reminders' => $flags ) );
				++$counts['followup'];
			}
		}
		return $counts;
	}
}
