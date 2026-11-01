<?php
/**
 * Availability engine: hours, breaks, closures, time off, special days, buffers, capacity,
 * notice/advance, holds, DST and customer timezones.
 *
 * @package Dev3\AiBooking
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Availability\Engine;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Exceptions;
use Dev3\AiBooking\Data\Services;
use Dev3\AiBooking\Data\Staff;

class AvailabilityTest extends Dev3bk_Base_Case {

	/**
	 * Slots for one staff member on a London date.
	 */
	private function day_slots( array $service, $staff_id, $date, $tz = 'Europe/London', array $opts = array() ) {
		$z = new DateTimeZone( $tz );
		return Engine::slots( Services::find( $service['id'] ), array( $staff_id ), Clock::local( $date, 0, $z ), Clock::local( $date, 1440, $z ), $opts );
	}

	public function test_weekly_hours_give_wall_clock_slots() {
		$svc   = $this->service();
		$staff = $this->staff( array(), array( $svc['id'] ) );
		$slots = $this->day_slots( $svc, $staff['id'], '2026-11-03' );
		$this->assertSame( '09:00', $this->labels( $slots )[0] );
		$this->assertSame( '16:00', end( $slots ) ? $this->labels( $slots )[ count( $slots ) - 1 ] : '' );
		$this->assertCount( 15, $slots ); // 09:00 … 16:00 every 30 min, 60-min service.
		$this->assertSame( 3600, $slots[0]['end'] - $slots[0]['start'] );
		$this->assertSame( array(), $this->day_slots( $svc, $staff['id'], '2026-11-07' ), 'Saturday is closed' );
	}

	public function test_breaks_and_split_shifts() {
		$svc   = $this->service();
		$staff = $this->staff(
			array(
				'hours'  => array( 'tue' => array( array( '09:00', '12:00' ), array( '14:00', '18:00' ) ) ),
				'breaks' => array( 'tue' => array( array( '15:00', '15:30' ) ) ),
			),
			array( $svc['id'] )
		);
		$labels = $this->labels( $this->day_slots( $svc, $staff['id'], '2026-11-03' ) );
		$this->assertSame( array( '09:00', '09:30', '10:00', '10:30', '11:00', '14:00', '15:30', '16:00', '16:30', '17:00' ), $labels );
	}

	public function test_overlapping_hours_are_merged_on_save() {
		$svc   = $this->service();
		$staff = $this->staff( array( 'hours' => array( 'wed' => array( array( '13:00', '15:00' ), array( '09:00', '11:00' ), array( '10:30', '13:30' ) ) ) ), array( $svc['id'] ) );
		$this->assertSame( array( array( '09:00', '15:00' ) ), Staff::get( $staff['id'] )['hours']['wed'] );
	}

	public function test_business_closure_and_yearly_holiday() {
		$svc   = $this->service();
		$staff = $this->staff( array(), array( $svc['id'] ) );
		Exceptions::create(
			array(
				'staff_id'  => 0,
				'date_from' => '2026-11-04',
				'date_to'   => '2026-11-05',
				'label'     => 'Training days',
			)
		);
		Exceptions::create(
			array(
				'staff_id'  => 0,
				'date_from' => '2020-12-25',
				'date_to'   => '2020-12-26',
				'label'     => 'Christmas',
				'yearly'    => true,
			)
		);
		$this->assertEmpty( $this->day_slots( $svc, $staff['id'], '2026-11-04' ) );
		$this->assertEmpty( $this->day_slots( $svc, $staff['id'], '2026-11-05' ) );
		$this->assertNotEmpty( $this->day_slots( $svc, $staff['id'], '2026-11-06' ) );
		$this->assertEmpty( $this->day_slots( $svc, $staff['id'], '2026-12-25' ), 'Yearly holiday repeats' );
	}

	public function test_partial_time_off_and_special_hours() {
		$svc   = $this->service();
		$staff = $this->staff( array(), array( $svc['id'] ) );
		Exceptions::create(
			array(
				'staff_id'   => $staff['id'],
				'date_from'  => '2026-11-03',
				'start_time' => '12:00',
				'end_time'   => '17:00',
				'label'      => 'Dentist',
			)
		);
		Exceptions::create(
			array(
				'staff_id'   => $staff['id'],
				'type'       => 'hours',
				'date_from'  => '2026-11-07',
				'start_time' => '10:00',
				'end_time'   => '12:00',
				'label'      => 'Saturday pop-up',
			)
		);
		$this->assertSame( array( '09:00', '09:30', '10:00', '10:30', '11:00' ), $this->labels( $this->day_slots( $svc, $staff['id'], '2026-11-03' ) ) );
		$this->assertSame( array( '10:00', '10:30', '11:00' ), $this->labels( $this->day_slots( $svc, $staff['id'], '2026-11-07' ) ) );
	}

	public function test_existing_bookings_and_buffers_block_overlaps() {
		$svc   = $this->service(
			array(
				'buffer_before' => 15,
				'buffer_after'  => 15,
			)
		);
		$other = $this->service(
			array(
				'name'     => 'Colour',
				'duration' => 90,
			)
		);
		$staff = $this->staff( array(), array( $svc['id'], $other['id'] ) );
		$this->assertIsArray( $this->book( $other, $staff['id'], $this->local_ts( '2026-11-03 11:00' ) ) ); // 11:00–12:30.
		$labels = $this->labels( $this->day_slots( $svc, $staff['id'], '2026-11-03' ) );
		// A haircut needs 15 min before and after: it must end by 10:45 and start from 12:45.
		$this->assertContains( '09:30', $labels );
		$this->assertNotContains( '10:00', $labels );
		$this->assertNotContains( '12:30', $labels );
		$this->assertContains( '13:00', $labels );
		// The booked colour itself has no buffers, so a colour can start right at 12:30.
		$this->assertContains( '12:30', $this->labels( $this->day_slots( $other, $staff['id'], '2026-11-03' ) ) );
	}

	public function test_capacity_shares_seats_only_at_the_same_start() {
		$class = $this->service(
			array(
				'name'     => 'Yoga class',
				'capacity' => 3,
			)
		);
		$staff = $this->staff( array(), array( $class['id'] ) );
		$ts    = $this->local_ts( '2026-11-03 10:00' );
		$this->assertIsArray( $this->book( $class, $staff['id'], $ts, array( 'seats' => 2 ) ) );
		$slot = array_values( array_filter( $this->day_slots( $class, $staff['id'], '2026-11-03' ), static fn( $s ) => $s['start'] === $ts ) );
		$this->assertSame( 1, $slot[0]['seats_left'] );
		$labels = $this->labels( $this->day_slots( $class, $staff['id'], '2026-11-03' ) );
		$this->assertNotContains( '10:30', $labels, 'A different start overlapping the class is blocked' );
		$this->assertIsArray( $this->book( $class, $staff['id'], $ts, array( 'email' => 'b@example.org' ) ) );
		$this->assertNotContains( '10:00', $this->labels( $this->day_slots( $class, $staff['id'], '2026-11-03' ) ), 'Full' );
		$full = $this->book( $class, $staff['id'], $ts, array( 'email' => 'c@example.org' ) );
		$this->assertWPError( $full );
		$this->assertSame( 'dev3bk_slot_taken', $full->get_error_code() );
	}

	public function test_min_notice_and_max_advance() {
		$svc   = $this->service(
			array(
				'min_notice'  => 180,
				'max_advance' => 3,
			)
		);
		$staff = $this->staff( array(), array( $svc['id'] ) );
		// Now = Monday 08:00 UTC = 08:00 London: earliest start 11:00.
		$this->assertSame( '11:00', $this->labels( $this->day_slots( $svc, $staff['id'], '2026-11-02' ) )[0] );
		$this->assertNotEmpty( $this->day_slots( $svc, $staff['id'], '2026-11-04' ) );
		$this->assertEmpty( $this->day_slots( $svc, $staff['id'], '2026-11-06' ), 'Beyond 3 days ahead' );
		$this->assertNotEmpty( $this->day_slots( $svc, $staff['id'], '2026-11-06', 'Europe/London', array( 'ignore_rules' => true ) ), 'Admins may ignore notice/advance' );
	}

	public function test_unpaid_hold_blocks_until_it_expires() {
		$svc   = $this->service();
		$staff = $this->staff( array(), array( $svc['id'] ) );
		$ts    = $this->local_ts( '2026-11-03 10:00' );
		$id    = Bookings::insert(
			array(
				'ref'            => 'HOLD0001',
				'service_id'     => $svc['id'],
				'staff_id'       => $staff['id'],
				'customer_id'    => 0,
				'starts_at'      => Clock::sql( $ts ),
				'ends_at'        => Clock::sql( $ts + 3600 ),
				'status'         => 'pending_payment',
				'payment_method' => 'stripe',
				'notes'          => '',
				'risk'           => array(),
				'created_at'     => Clock::sql( $this->now - 10 * MINUTE_IN_SECONDS ),
			)
		);
		$this->assertNotContains( '10:00', $this->labels( $this->day_slots( $svc, $staff['id'], '2026-11-03' ) ) );
		$this->now += HOUR_IN_SECONDS;
		$this->assertContains( '10:00', $this->labels( $this->day_slots( $svc, $staff['id'], '2026-11-03' ) ) );
		$this->assertSame( 1, \Dev3\AiBooking\Booking\Manager::expire_holds() );
		$this->assertSame( 'expired', Bookings::fresh( $id )['status'] );
	}

	public function test_cancelled_bookings_free_the_slot() {
		$svc   = $this->service();
		$staff = $this->staff( array(), array( $svc['id'] ) );
		$b     = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-03 10:00' ) );
		$this->assertNotContains( '10:00', $this->labels( $this->day_slots( $svc, $staff['id'], '2026-11-03' ) ) );
		\Dev3\AiBooking\Booking\Manager::cancel( $b, '', 'admin' );
		$this->assertContains( '10:00', $this->labels( $this->day_slots( $svc, $staff['id'], '2026-11-03' ) ) );
	}

	public function test_dst_spring_forward_skips_missing_wall_clock_times() {
		$tz    = 'America/New_York';
		$this->now = strtotime( '2026-03-01 12:00:00 UTC' );
		$svc   = $this->service(
			array(
				'duration'      => 30,
				'slot_interval' => 30,
			)
		);
		$staff = $this->staff(
			array(
				'timezone' => $tz,
				'hours'    => array( 'sun' => array( array( '01:00', '04:00' ) ) ),
			),
			array( $svc['id'] )
		);
		$slots = $this->day_slots( $svc, $staff['id'], '2026-03-08', $tz );
		$this->assertSame( array( '01:00', '01:30', '03:00', '03:30' ), $this->labels( $slots, $tz ), '02:00–02:59 does not exist on 8 March 2026' );
		foreach ( $slots as $s ) {
			$this->assertSame( 1800, $s['end'] - $s['start'] );
		}
		// A 60-minute appointment at 01:30 EST lasts a real hour and ends at 03:30 EDT.
		$long  = $this->service(
			array(
				'name'          => 'Long',
				'duration'      => 60,
				'slot_interval' => 30,
			)
		);
		Staff::set_staff_services( $staff['id'], array( $svc['id'], $long['id'] ) );
		$slots = $this->day_slots( $long, $staff['id'], '2026-03-08', $tz );
		$this->assertSame( array( '01:00', '01:30', '03:00' ), $this->labels( $slots, $tz ) );
		$this->assertSame( '03:30', Clock::format( $slots[1]['end'], new DateTimeZone( $tz ), 'H:i' ) );
	}

	public function test_dst_fall_back_offers_ambiguous_times_once() {
		$tz        = 'America/New_York';
		$this->now = strtotime( '2026-10-20 12:00:00 UTC' );
		$svc       = $this->service(
			array(
				'duration'      => 30,
				'slot_interval' => 30,
			)
		);
		$staff     = $this->staff(
			array(
				'timezone' => $tz,
				'hours'    => array( 'sun' => array( array( '00:00', '03:00' ) ) ),
			),
			array( $svc['id'] )
		);
		$slots     = $this->day_slots( $svc, $staff['id'], '2026-11-01', $tz );
		$this->assertSame( array( '00:00', '00:30', '01:00', '01:30', '02:00', '02:30' ), $this->labels( $slots, $tz ) );
		$this->assertSame( '2026-11-01T05:00:00Z', Clock::iso( $slots[2]['start'] ), '01:00 is offered at its first occurrence (EDT)' );
		$this->assertSame( '2026-11-01T07:00:00Z', Clock::iso( $slots[4]['start'] ), '02:00 EST' );
		$this->assertSame( count( $slots ), count( array_unique( array_column( $slots, 'start' ) ) ) );
	}

	public function test_dst_week_keeps_local_opening_hours() {
		// Europe/London changes on 25 Oct 2026: 09:00 local is 08:00 UTC before, 09:00 UTC after.
		$this->now = strtotime( '2026-10-19 06:00:00 UTC' );
		$svc       = $this->service();
		$staff     = $this->staff( array(), array( $svc['id'] ) );
		$before    = $this->day_slots( $svc, $staff['id'], '2026-10-23' );
		$after     = $this->day_slots( $svc, $staff['id'], '2026-10-26' );
		$this->assertSame( '2026-10-23T08:00:00Z', Clock::iso( $before[0]['start'] ) );
		$this->assertSame( '2026-10-26T09:00:00Z', Clock::iso( $after[0]['start'] ) );
		$this->assertCount( 15, $before );
		$this->assertCount( 15, $after );
	}

	public function test_customer_timezone_groups_by_their_local_date() {
		$svc   = $this->service();
		$staff = $this->staff( array(), array( $svc['id'] ) );
		// Tokyo is UTC+9: Tue 3 Nov in Tokyo = Mon 15:00 → Tue 15:00 London.
		$days   = Engine::days( Services::find( $svc['id'] ), 0, '2026-11-03', '2026-11-03', 'Asia/Tokyo' )['days'];
		$labels = array_column( $days['2026-11-03'], 'label' );
		$this->assertSame( array( '00:00', '00:30', '01:00' ), array_slice( $labels, 0, 3 ), 'Monday 15:00–16:00 London' );
		$this->assertSame( '2026-11-02T15:00:00Z', $days['2026-11-03'][0]['start'] );
		$this->assertContains( '18:00', $labels, 'Tuesday 09:00 London' );
		$this->assertSame( '23:30', end( $labels ), 'Tuesday 14:30 London is the last slot before midnight in Tokyo' );
		$this->assertSame( array( '2026-11-03' ), array_keys( $days ), 'Only the asked local date is returned' );
		$wide = Engine::days( Services::find( $svc['id'] ), 0, '2026-11-03', '2026-11-04', 'Asia/Tokyo' )['days'];
		$this->assertSame( '00:00', $wide['2026-11-04'][0]['label'] );
		$this->assertSame( '2026-11-03T15:00:00Z', $wide['2026-11-04'][0]['start'] );
	}

	public function test_staff_timezone_differs_from_business() {
		$svc   = $this->service();
		$staff = $this->staff( array( 'timezone' => 'America/New_York' ), array( $svc['id'] ) );
		$slots = $this->day_slots( $svc, $staff['id'], '2026-11-03', 'America/New_York' );
		$this->assertSame( '2026-11-03T14:00:00Z', Clock::iso( $slots[0]['start'] ), '09:00 New York (EST)' );
	}

	public function test_any_staff_merges_and_assigns_free_staff() {
		$svc  = $this->service();
		$anna = $this->staff( array( 'sort' => 1 ), array( $svc['id'] ) );
		$ben  = $this->staff(
			array(
				'name'  => 'Ben Ode',
				'email' => 'ben@example.org',
				'sort'  => 2,
			),
			array( $svc['id'] )
		);
		$ts   = $this->local_ts( '2026-11-03 10:00' );
		$this->book( $svc, $anna['id'], $ts );
		$days = Engine::days( Services::find( $svc['id'] ), 0, '2026-11-03', '2026-11-03', 'Europe/London' )['days']['2026-11-03'];
		$ten  = array_values( array_filter( $days, static fn( $s ) => '10:00' === $s['label'] ) );
		$this->assertSame( array( $ben['id'] ), $ten[0]['staff'] );
		$any = $this->book( $svc, 0, $ts, array( 'email' => 'x@example.org' ) );
		$this->assertSame( $ben['id'], $any['staff_id'] );
		// Ben now has 1 booking, Anna 1: a 14:00 "any" booking goes to Anna (first in sort order on a tie).
		$next = $this->book( $svc, 0, $this->local_ts( '2026-11-03 14:00' ), array( 'email' => 'y@example.org' ) );
		$this->assertSame( $anna['id'], $next['staff_id'] );
		$third = $this->book( $svc, 0, $this->local_ts( '2026-11-03 15:00' ), array( 'email' => 'z@example.org' ) );
		$this->assertSame( $ben['id'], $third['staff_id'], 'Fewest bookings that day wins' );
	}

	public function test_second_request_for_the_same_slot_fails() {
		$svc   = $this->service();
		$staff = $this->staff( array(), array( $svc['id'] ) );
		$ts    = $this->local_ts( '2026-11-03 10:00' );
		$this->assertIsArray( $this->book( $svc, $staff['id'], $ts ) );
		$second = $this->book( $svc, $staff['id'], $ts, array( 'email' => 'late@example.org' ) );
		$this->assertWPError( $second );
		$this->assertSame( 409, $second->get_error_data()['status'] );
		$overlap = $this->book( $svc, $staff['id'], $ts + 1800, array( 'email' => 'late@example.org' ) );
		$this->assertWPError( $overlap, 'Overlapping start is refused too' );
		$off = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-03 10:10' ), array( 'email' => 'odd@example.org' ) );
		$this->assertWPError( $off, 'Times that are not offered slots are refused' );
	}

	public function test_staff_must_perform_the_service() {
		$svc   = $this->service();
		$other = $this->service( array( 'name' => 'Massage' ) );
		$staff = $this->staff( array(), array( $other['id'] ) );
		$this->assertSame( array(), Engine::staff_for( Services::find( $svc['id'] ), $staff['id'] ) );
		$this->assertWPError( $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-03 10:00' ) ) );
	}

	public function test_next_available_skips_closed_days() {
		$svc   = $this->service();
		$staff = $this->staff( array(), array( $svc['id'] ) );
		$this->assertSame( '2026-11-09', Engine::next_available( Services::find( $svc['id'] ), $staff['id'], '2026-11-07', 'Europe/London' ) );
	}

	public function test_midnight_end_and_late_shift() {
		$svc   = $this->service();
		$staff = $this->staff( array( 'hours' => array( 'fri' => array( array( '20:00', '00:00' ) ) ) ), array( $svc['id'] ) );
		$this->assertSame( array( '20:00', '20:30', '21:00', '21:30', '22:00', '22:30', '23:00' ), $this->labels( $this->day_slots( $svc, $staff['id'], '2026-11-06' ) ) );
	}
}
