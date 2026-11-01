<?php
/**
 * Booking lifecycle: create, statuses, reschedule/cancel policies, risk.
 *
 * @package Dev3\AiBooking
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Booking\Manager;
use Dev3\AiBooking\Booking\Policy;
use Dev3\AiBooking\Booking\Risk;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Customers;
use Dev3\AiBooking\Data\Events;

class BookingTest extends Dev3bk_Base_Case {

	private function setup_basic() {
		$svc   = $this->service();
		$staff = $this->staff( array(), array( $svc['id'] ) );
		return array( $svc, $staff );
	}

	public function test_create_confirms_and_emails_customer_and_team() {
		list( $svc, $staff ) = $this->setup_basic();
		$b = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'notes' => 'First time here' ) );
		$this->assertSame( 'confirmed', $b['status'] );
		$this->assertSame( 'unpaid', $b['payment_status'] );
		$this->assertSame( 'later', $b['payment_method'] );
		$this->assertSame( 45.0, $b['amount'] );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{8}$/', $b['ref'] );
		$this->assertSame( 32, strlen( $b['access_key'] ) );
		$subjects = $this->subjects();
		$this->assertCount( 3, $this->mails, 'Customer, admin and staff' );
		$this->assertStringContainsString( 'Your booking is confirmed: Haircut', $subjects[0] );
		$this->assertStringContainsString( 'New booking ' . $b['ref'], $subjects[1] );
		$this->assertSame( 'jamie@example.org', $this->mails[0]['to'] );
		$this->assertNotEmpty( $this->mails[0]['attachments'], '.ics attached' );
		$this->assertStringContainsString( 'dev3bk_key=' . $b['access_key'], $this->mails[0]['message'] );
		$c = Customers::by_email( 'jamie@example.org' );
		$this->assertSame( 'Jamie Rivera', $c['name'] );
		$this->assertNotEmpty( Events::for_booking( $b['id'] ) );
	}

	public function test_pending_approval_mode_and_confirmation_email() {
		list( $svc, $staff ) = $this->setup_basic();
		$this->settings( array( 'default_status' => 'pending' ) );
		$b = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ) );
		$this->assertSame( 'pending', $b['status'] );
		$this->assertStringContainsString( 'We received your booking request', $this->subjects()[0] );
		$this->mails = array();
		$b           = Manager::set_status( $b, 'confirmed' );
		$this->assertSame( 'confirmed', $b['status'] );
		$this->assertStringContainsString( 'confirmed', $this->subjects()[0] );
	}

	public function test_required_fields() {
		list( $svc, $staff ) = $this->setup_basic();
		$this->assertSame( 'dev3bk_invalid_email', $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'email' => 'nope' ) )->get_error_code() );
		$this->assertSame( 'dev3bk_name_required', $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'name' => ' ' ) )->get_error_code() );
		$this->settings( array( 'phone_field' => 'required' ) );
		$this->assertSame( 'dev3bk_phone_required', $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'phone' => '' ) )->get_error_code() );
		$hidden = $this->service(
			array(
				'name'   => 'Secret',
				'status' => 'hidden',
			)
		);
		$this->assertSame( 'dev3bk_bad_service', $this->book( $hidden, $staff['id'], $this->local_ts( '2026-11-05 10:00' ) )->get_error_code() );
	}

	public function test_free_service_needs_no_payment_and_deposit_amounts() {
		list( , $staff ) = $this->setup_basic();
		$free            = $this->service(
			array(
				'name'  => 'Consultation',
				'price' => 0,
			)
		);
		\Dev3\AiBooking\Data\Staff::set_staff_services( $staff['id'], array( $free['id'] ) );
		$b = $this->book( $free, $staff['id'], $this->local_ts( '2026-11-05 10:00' ) );
		$this->assertSame( 'none', $b['payment_status'] );
		$this->assertSame( 'none', $b['payment_method'] );
		$dep = $this->service(
			array(
				'name'            => 'Colour',
				'price'           => 120,
				'deposit_percent' => 25,
			)
		);
		$this->assertSame( 30.0, \Dev3\AiBooking\Data\Services::amount_due( $dep ) );
	}

	public function test_customer_reschedule_follows_policy_and_availability() {
		list( $svc, $staff ) = $this->setup_basic();
		$b = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ) );
		$this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 14:00' ), array( 'email' => 'other@example.org' ) );
		$this->mails = array();
		$taken       = Manager::reschedule( $b, $this->local_ts( '2026-11-05 14:00' ) );
		$this->assertSame( 'dev3bk_slot_taken', $taken->get_error_code() );
		$moved = Manager::reschedule( $b, $this->local_ts( '2026-11-06 11:00' ) );
		$this->assertSame( Clock::sql( $this->local_ts( '2026-11-06 11:00' ) ), $moved['starts_at'] );
		$this->assertSame( 1, $moved['reschedules'] );
		$this->assertSame( 1, $moved['sequence'] );
		$this->assertStringContainsString( 'Your booking has moved', $this->subjects()[0] );
		// Max 2 reschedules by default.
		$moved = Manager::reschedule( $moved, $this->local_ts( '2026-11-06 12:00' ) );
		$this->assertSame( 2, $moved['reschedules'] );
		$this->assertSame( 'dev3bk_policy_max', Manager::reschedule( $moved, $this->local_ts( '2026-11-06 13:00' ) )->get_error_code() );
		// Admins are not limited.
		$this->assertIsArray( Manager::reschedule( $moved, $this->local_ts( '2026-11-06 13:00' ), 0, 'admin' ) );
	}

	public function test_cutoffs_block_late_customer_changes() {
		list( $svc, $staff ) = $this->setup_basic();
		$b = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-03 10:00' ) ); // 26 h ahead.
		$this->assertTrue( Policy::can_cancel( $b ) );
		$this->now += 3 * HOUR_IN_SECONDS; // 23 h ahead.
		$this->assertSame( 'dev3bk_policy_cutoff', Policy::can_cancel( $b )->get_error_code() );
		$this->assertSame( 'dev3bk_policy_cutoff', Policy::can_reschedule( $b )->get_error_code() );
		$this->assertWPError( Manager::cancel( $b, 'Sick', 'customer' ) );
		$this->settings( array( 'cancel_cutoff_hours' => 2 ) );
		$this->assertTrue( Policy::can_cancel( $b ) );
		$this->settings( array( 'allow_cancel' => false ) );
		$this->assertSame( 'dev3bk_policy_cancel_off', Policy::can_cancel( $b )->get_error_code() );
		// Admin can always cancel.
		$this->assertSame( 'cancelled', Manager::cancel( $b, '', 'admin' )['status'] );
	}

	public function test_customer_cancel_logs_reason_and_emails() {
		list( $svc, $staff ) = $this->setup_basic();
		$b           = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-06 10:00' ) );
		$this->mails = array();
		$done        = Manager::cancel( $b, 'Something came up', 'customer' );
		$this->assertSame( 'cancelled', $done['status'] );
		$this->assertSame( 'Something came up', $done['cancel_reason'] );
		$this->assertNotEmpty( $done['cancelled_at'] );
		$this->assertStringContainsString( 'cancelled', $this->subjects()[0] );
		$this->assertNotEmpty( $this->mails[0]['attachments'] );
		$this->assertStringContainsString( 'METHOD:CANCEL', \Dev3\AiBooking\Notify\Ics::booking( $done ) );
	}

	public function test_admin_force_and_status_changes() {
		list( $svc, $staff ) = $this->setup_basic();
		$ts = $this->local_ts( '2026-11-05 10:00' );
		$this->book( $svc, $staff['id'], $ts );
		$forced = Manager::create(
			array(
				'admin'      => true,
				'force'      => true,
				'service_id' => $svc['id'],
				'staff_id'   => $staff['id'],
				'start'      => $ts,
				'name'       => 'Walk In',
				'email'      => 'walkin@example.org',
				'silent'     => true,
			)
		);
		$this->assertIsArray( $forced, 'Admin can double-book on purpose' );
		$this->assertSame( 'admin', $forced['source'] );
		$this->assertSame( 'no_show', Manager::set_status( $forced, 'no_show' )['status'] );
		$this->assertWPError( Manager::set_status( $forced, 'bogus' ) );
		$paid = Manager::paid( Bookings::fresh( $forced['id'] ), 45, 'later', array(), 'admin' );
		$this->assertSame( 'paid', $paid['payment_status'] );
		$this->assertSame( 45.0, $paid['paid_amount'] );
	}

	public function test_risk_score_reasons() {
		list( $svc, $staff ) = $this->setup_basic();
		$first = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ) );
		$risk  = Risk::score( $first );
		$this->assertContains( 'First visit', $risk['reasons'] );
		$this->assertSame( 'medium', $risk['level'] );
		Manager::set_status( $first, 'no_show' );
		$again = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-06 10:00' ) );
		$risk  = Risk::score( $again );
		$this->assertSame( 'high', $risk['level'] );
		$this->assertContains( '1 earlier no-show', $risk['reasons'] );
		$this->assertSame( 'high', Bookings::fresh( $again['id'] )['risk']['level'], 'Stored on create' );
		// A regular, prepaid customer is low risk.
		foreach ( array( '2026-11-09', '2026-11-10', '2026-11-11' ) as $i => $d ) {
			$b = $this->book( $svc, $staff['id'], $this->local_ts( $d . ' 10:00' ), array( 'email' => 'reg@example.org' ) );
			Manager::set_status( $b, 'completed' );
		}
		$reg = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-12 10:00' ), array( 'email' => 'reg@example.org' ) );
		$reg = Manager::paid( $reg, 45, 'later' );
		$this->assertSame( 'low', Risk::score( $reg )['level'] );
	}

	public function test_customer_link_by_email_and_user() {
		list( $svc, $staff ) = $this->setup_basic();
		$uid = self::factory()->user->create( array( 'user_email' => 'jamie@example.org' ) );
		$b   = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'user_id' => $uid ) );
		$this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 12:00' ), array( 'name' => 'J. Rivera' ) );
		$c = Customers::get( $b['customer_id'] );
		$this->assertSame( $uid, $c['user_id'] );
		$this->assertSame( 2, Customers::stats( $c['id'] )['total'] );
	}
}
