<?php
/**
 * ICalendar (RFC 5545) output: one booking, or a private staff feed.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Notify;

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Booking\Presenter;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Customers;
use Dev3\AiBooking\Data\Services;
use Dev3\AiBooking\Data\Staff;

defined( 'ABSPATH' ) || exit;

/**
 * ICS.
 */
class Ics {

	/**
	 * Escapes a TEXT value.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function esc( $text ) {
		$text = str_replace( array( '\\', ';', ',', "\r\n", "\n", "\r" ), array( '\\\\', '\;', '\,', '\n', '\n', '\n' ), (string) $text );
		return $text;
	}

	/**
	 * Folds a content line at 75 octets.
	 *
	 * @param string $line Line.
	 * @return string
	 */
	public static function fold( $line ) {
		$out = '';
		$max = 75; // The first line holds 75 octets; continuation lines start with a space, so 74.
		$len = strlen( $line );
		while ( $len > $max ) {
			$cut = $max;
			// Do not split a multibyte UTF-8 character.
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
				--$cut;
			}
			$out .= substr( $line, 0, $cut ) . "\r\n ";
			$line = substr( $line, $cut );
			$len  = strlen( $line );
			$max  = 74;
		}
		return $out . $line;
	}

	/**
	 * Wraps VEVENT lines in a VCALENDAR.
	 *
	 * @param array  $events Lines per event.
	 * @param string $method PUBLISH|REQUEST|CANCEL.
	 * @param string $name   Calendar name.
	 * @return string
	 */
	public static function calendar( array $events, $method = 'PUBLISH', $name = '' ) {
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//DEV3//Booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:' . $method );
		if ( $name ) {
			$lines[] = 'X-WR-CALNAME:' . self::esc( $name );
		}
		foreach ( $events as $ev ) {
			$lines = array_merge( $lines, $ev );
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * VEVENT lines for a booking.
	 *
	 * @param array $b         Booking.
	 * @param bool  $for_staff Staff feed (includes the customer name).
	 * @return array
	 */
	public static function event( array $b, $for_staff = false ) {
		$service   = Services::get( $b['service_id'] );
		$staff     = Staff::get( $b['staff_id'] );
		$cancelled = in_array( $b['status'], array( 'cancelled', 'expired' ), true );
		$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$summary   = Presenter::title( $b );
		$desc      = array();
		if ( $for_staff ) {
			$customer = Customers::get( $b['customer_id'] );
			$summary  = ( $service ? $service['name'] : __( 'Appointment', 'dev3-ai-booking-appointments' ) ) . ( $customer ? ' – ' . $customer['name'] : '' );
			if ( $customer ) {
				$desc[] = trim( $customer['name'] . ' ' . $customer['phone'] );
			}
			if ( $b['notes'] ) {
				$desc[] = $b['notes'];
			}
		} else {
			if ( $staff ) {
				/* translators: %s: staff name. */
				$desc[] = sprintf( __( 'With %s', 'dev3-ai-booking-appointments' ), $staff['name'] );
			}
			$desc[] = __( 'Manage your booking:', 'dev3-ai-booking-appointments' ) . ' ' . Presenter::manage_url( $b );
		}
		/* translators: %s: booking reference. */
		$desc[] = sprintf( __( 'Reference: %s', 'dev3-ai-booking-appointments' ), $b['ref'] );
		$lines  = array(
			'BEGIN:VEVENT',
			'UID:' . $b['ref'] . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z', Clock::ts( $b['updated_at'] ? $b['updated_at'] : $b['created_at'] ) ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', Clock::ts( $b['starts_at'] ) ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', Clock::ts( $b['ends_at'] ) ),
			'SEQUENCE:' . ( (int) $b['sequence'] + ( $cancelled ? 1 : 0 ) ),
			'SUMMARY:' . self::esc( $summary ),
			'DESCRIPTION:' . self::esc( implode( "\n", $desc ) ),
			'STATUS:' . ( $cancelled ? 'CANCELLED' : ( 'confirmed' === $b['status'] || 'completed' === $b['status'] ? 'CONFIRMED' : 'TENTATIVE' ) ),
		);
		if ( $service && $service['location'] ) {
			$lines[] = 'LOCATION:' . self::esc( $service['location'] );
		}
		if ( ! $for_staff && ! $cancelled ) {
			$lines = array_merge( $lines, array( 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::esc( $summary ), 'TRIGGER:-PT1H', 'END:VALARM' ) );
		}
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/**
	 * Single booking file.
	 *
	 * @param array $b Booking.
	 * @return string
	 */
	public static function booking( array $b ) {
		$cancelled = in_array( $b['status'], array( 'cancelled', 'expired' ), true );
		return self::calendar( array( self::event( $b ) ), $cancelled ? 'CANCEL' : 'PUBLISH' );
	}

	/**
	 * Staff feed: the past 7 and next 90 days.
	 *
	 * @param array $staff Staff.
	 * @return string
	 */
	public static function feed( array $staff ) {
		$now    = Clock::now();
		$events = array();
		foreach ( Bookings::between( Clock::sql( $now - 7 * DAY_IN_SECONDS ), Clock::sql( $now + 90 * DAY_IN_SECONDS ), array( 'pending', 'confirmed', 'completed', 'no_show' ) ) as $b ) {
			if ( (int) $b['staff_id'] === (int) $staff['id'] ) {
				$events[] = self::event( $b, true );
			}
		}
		return self::calendar( $events, 'PUBLISH', $staff['name'] . ' – ' . Presenter::business_name() );
	}
}
