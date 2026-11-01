<?php
/**
 * Notifications: reminders (once, offsets, skips), approved templates only, ICS + Google links.
 *
 * @package Dev3\AiBooking
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiBooking\Booking\Manager;
use Dev3\AiBooking\Booking\Presenter;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Notify\Ics;
use Dev3\AiBooking\Notify\Mailer;
use Dev3\AiBooking\Notify\Reminders;
use Dev3\AiBooking\Notify\Templates;

class NotifyTest extends Dev3bk_Base_Case {

	private function booked( $when = '2026-11-06 10:00', array $extra = array() ) {
		$svc   = $this->service();
		$staff = $this->staff( array(), array( $svc['id'] ) );
		$b     = $this->book( $svc, $staff['id'], $this->local_ts( $when ), $extra );
		$this->mails = array();
		return $b;
	}

	public function test_reminders_send_once_at_their_offsets() {
		$b = $this->booked(); // Fri 10:00 London; now Mon 08:00.
		$this->assertSame( 0, Reminders::run()['reminder_1'] );
		$this->now = $this->local_ts( '2026-11-05 10:05' ); // 23h55 before.
		$run       = Reminders::run();
		$this->assertSame( 1, $run['reminder_1'] );
		$this->assertSame( 0, $run['reminder_2'] );
		$this->assertStringContainsString( 'Reminder: Haircut', $this->subjects()[0] );
		$this->assertSame( 0, Reminders::run()['reminder_1'], 'Never twice' );
		$this->now = $this->local_ts( '2026-11-06 08:30' );
		$this->assertSame( 1, Reminders::run()['reminder_2'] );
		$this->assertCount( 2, $this->mails );
		$flags = Bookings::fresh( $b['id'] )['reminders'];
		$this->assertNotEmpty( $flags['reminder_1'] );
		$this->assertNotEmpty( $flags['reminder_2'] );
		$this->now = $this->local_ts( '2026-11-06 11:00' );
		Reminders::run();
		$this->assertCount( 2, $this->mails, 'Nothing after the start' );
	}

	public function test_booked_inside_window_skips_first_reminder() {
		$this->now = $this->local_ts( '2026-11-05 20:00' );
		$b         = $this->booked( '2026-11-06 10:00' );
		$run       = Reminders::run();
		$this->assertSame( 1, $run['skipped'] );
		$this->assertSame( 0, $run['reminder_1'] );
		$this->assertSame( 'skipped', Bookings::fresh( $b['id'] )['reminders']['reminder_1'] );
	}

	public function test_cancelled_and_disabled_are_not_reminded() {
		$b = $this->booked();
		Manager::cancel( $b, '', 'admin' );
		$this->mails = array();
		$this->now   = $this->local_ts( '2026-11-05 11:00' );
		Reminders::run();
		$this->assertEmpty( $this->mails );
		$c = $this->booked( '2026-11-06 14:00', array( 'email' => 'z@example.org' ) );
		Templates::save( 'reminder_1', array( 'enabled' => false ) );
		$this->now = $this->local_ts( '2026-11-05 15:00' );
		Reminders::run();
		$this->assertEmpty( $this->mails );
		$this->assertArrayNotHasKey( 'reminder_1', Bookings::fresh( $c['id'] )['reminders'] );
	}

	public function test_followup_after_visit_when_enabled() {
		$b = $this->booked( '2026-11-03 10:00' );
		$this->settings( array( 'followup_hours' => 3 ) );
		Templates::save( 'followup', array( 'enabled' => true ) );
		Manager::set_status( $b, 'completed' );
		$this->mails = array();
		$this->now   = $this->local_ts( '2026-11-03 12:00' );
		$this->assertSame( 0, Reminders::run()['followup'] );
		$this->now = $this->local_ts( '2026-11-03 14:30' );
		$this->assertSame( 1, Reminders::run()['followup'] );
		$this->assertSame( 0, Reminders::run()['followup'] );
		$this->assertStringContainsString( 'Thanks for visiting', $this->subjects()[0] );
	}

	public function test_templates_only_send_approved_text_and_keep_required_placeholders() {
		$this->assertWPError( Templates::save( 'reminder_1', array( 'subject' => 'Hi', 'body' => 'See you soon.' ) ), '{manage_url} is required' );
		$saved = Templates::save(
			'reminder_1',
			array(
				'subject' => 'Tomorrow: {service} {bogus}',
				'body'    => "Hello {customer_name}! {unknown}\nChange it: {manage_url}",
				'source'  => 'ai',
			)
		);
		$this->assertSame( 'Tomorrow: {service}', trim( $saved['subject'] ) );
		$this->assertStringNotContainsString( '{unknown}', $saved['body'] );
		$this->assertSame( 'ai', $saved['source'] );
		$this->assertNotEmpty( $saved['approved_at'] );
		$b = $this->booked();
		Mailer::send_customer( 'reminder_1', $b );
		$this->assertSame( 'Tomorrow: Haircut', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Hello Jamie Rivera!', $this->mails[0]['message'] );
		$reset = Templates::save( 'reminder_1', array( 'reset' => true ) );
		$this->assertFalse( $reset['customized'] );
	}

	public function test_html_layout_is_escaped_and_neutral() {
		$mail = Mailer::render( 'customer_confirmed', array_merge( Mailer::sample_vars(), array( 'customer_name' => '<script>x</script>' ) ) );
		$this->assertStringNotContainsString( '<script>', $mail['html'] );
		$this->assertStringNotContainsString( 'DEV3', $mail['html'] );
		$this->assertStringContainsString( 'Manage booking', $mail['html'] );
		$this->assertStringContainsString( '&lt;script&gt;', $mail['html'] );
	}

	public function test_ics_and_google_link() {
		$b   = $this->booked();
		$ics = Ics::booking( $b );
		$this->assertStringContainsString( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( 'DTSTART:20261106T100000Z', $ics );
		$this->assertStringContainsString( 'DTEND:20261106T110000Z', $ics );
		$this->assertStringContainsString( 'UID:' . $b['ref'] . '@', $ics );
		$this->assertStringContainsString( 'METHOD:PUBLISH', $ics );
		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), 'Lines are folded' );
		}
		$moved = Manager::reschedule( $b, $this->local_ts( '2026-11-06 12:00' ) );
		$this->assertStringContainsString( 'SEQUENCE:1', Ics::booking( $moved ) );
		$url = Presenter::google_url( $moved );
		$this->assertStringContainsString( 'calendar.google.com/calendar/render', $url );
		$this->assertStringContainsString( 'dates=20261106T120000Z/20261106T130000Z', $url );
		$this->assertStringContainsString( 'ICS', strtoupper( Presenter::ics_url( $moved ) ) );
	}

	public function test_staff_feed_lists_upcoming_bookings() {
		$b     = $this->booked();
		$staff = \Dev3\AiBooking\Data\Staff::get( $b['staff_id'] );
		$feed  = Ics::feed( $staff );
		$this->assertStringContainsString( 'Haircut – Jamie Rivera', $feed );
		$this->assertStringContainsString( 'X-WR-CALNAME:Anna Berg', $feed );
	}
}
