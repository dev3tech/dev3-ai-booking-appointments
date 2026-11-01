<?php
/**
 * DEV3 AI: NL booking (rules + mocked Gemini, real slots only), template drafts, risk hint.
 *
 * @package Dev3\AiBooking
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiBooking\Ai\Nl_Booking;
use Dev3\AiBooking\Ai\Nl_Parser;
use Dev3\AiBooking\Ai\Writers;
use Dev3\AiBooking\Availability\Clock;
use Dev3\AiBooking\Availability\Engine;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Services;
use Dev3\AiBooking\Notify\Templates;

class AiTest extends Dev3bk_Base_Case {

	/**
	 * Parser context (today = Monday 2 Nov 2026).
	 */
	private function ctx() {
		return array(
			'today'    => '2026-11-02',
			'services' => array(
				array(
					'id'   => 1,
					'name' => 'Haircut & styling',
				),
				array(
					'id'   => 2,
					'name' => 'Beard trim',
				),
			),
			'staff'    => array(
				array(
					'id'   => 7,
					'name' => 'Anna Berg',
				),
				array(
					'id'   => 8,
					'name' => 'Ben Ode',
				),
			),
		);
	}

	/**
	 * Parser fixtures: text => expected subset.
	 */
	public function parser_cases() {
		return array(
			'tuesday after 5 with anna'   => array(
				'Tuesday after 5 with Anna',
				array(
					'date_from' => '2026-11-03',
					'date_to'   => '2026-11-03',
					'time_from' => '17:00',
					'time_to'   => '',
					'staff_id'  => 7,
				),
			),
			'tomorrow morning'            => array(
				'tomorrow morning please',
				array(
					'date_from' => '2026-11-03',
					'time_from' => '06:00',
					'time_to'   => '12:00',
				),
			),
			'next monday'                 => array(
				'next Monday at 10am',
				array(
					'date_from' => '2026-11-09',
					'time_from' => '10:00',
					'time_to'   => '11:00',
				),
			),
			'today monday'                => array(
				'monday evening',
				array(
					'date_from' => '2026-11-02',
					'time_from' => '17:00',
				),
			),
			'between friday'              => array(
				'between 2 and 4pm on Friday',
				array(
					'date_from' => '2026-11-06',
					'time_from' => '14:00',
					'time_to'   => '16:00',
				),
			),
			'date month'                  => array(
				'a beard trim on 28 November before noon',
				array(
					'date_from'  => '2026-11-28',
					'time_to'    => '12:00',
					'service_id' => 2,
				),
			),
			'month date'                  => array(
				'Dec 3rd at 15:30 with Ben',
				array(
					'date_from' => '2026-12-03',
					'time_from' => '15:30',
					'staff_id'  => 8,
				),
			),
			'next week evenings'          => array(
				'any evening next week',
				array(
					'date_from' => '2026-11-09',
					'date_to'   => '2026-11-15',
					'time_from' => '17:00',
					'time_to'   => '22:00',
				),
			),
			'weekend haircut'             => array(
				'haircut this weekend',
				array(
					'date_from'  => '2026-11-07',
					'date_to'    => '2026-11-08',
					'service_id' => 1,
				),
			),
			'two weekdays'                => array(
				'Tuesday or Thursday afternoon',
				array(
					'weekdays'  => array( 2, 4 ),
					'time_from' => '12:00',
					'time_to'   => '17:00',
				),
			),
			'no time'                     => array(
				'whenever works',
				array(
					'date_from' => '2026-11-02',
					'date_to'   => '2026-11-15',
					'time_from' => '',
				),
			),
			'pam is not morning'          => array(
				'with Pam after 18:30',
				array(
					'time_from' => '18:30',
					'time_to'   => '',
				),
			),
			'in 3 days'                   => array(
				'in 3 days',
				array( 'date_from' => '2026-11-05' ),
			),
			'iso'                         => array(
				'2026-11-20 at 9',
				array(
					'date_from' => '2026-11-20',
					'time_from' => '09:00',
				),
			),
		);
	}

	/**
	 * @dataProvider parser_cases
	 */
	public function test_rule_parser( $text, array $expected ) {
		$got = Nl_Parser::parse( $text, $this->ctx() );
		foreach ( $expected as $k => $v ) {
			$this->assertSame( $v, $got[ $k ], "{$k} for \"{$text}\"" );
		}
	}

	private function salon() {
		$svc  = $this->service( array( 'name' => 'Haircut' ) );
		$anna = $this->staff(
			array(
				'hours' => array(
					'mon' => array( array( '09:00', '19:00' ) ),
					'tue' => array( array( '09:00', '19:00' ) ),
				),
			),
			array( $svc['id'] )
		);
		$ben  = $this->staff(
			array(
				'name'  => 'Ben Ode',
				'email' => 'ben@example.org',
			),
			array( $svc['id'] )
		);
		return array( $svc, $anna, $ben );
	}

	public function test_rules_answer_with_real_slots_when_ai_is_off() {
		list( $svc, $anna ) = $this->salon();
		$res                = Nl_Booking::suggest( 'Tuesday after 5 with Anna', array( 'service_id' => $svc['id'], 'tz' => 'Europe/London' ) );
		$this->assertSame( 'rules', $res['source'] );
		$this->assertSame( $anna['id'], $res['constraints']['staff_id'] );
		$this->assertSame( array( '17:00', '17:30', '18:00' ), array_column( $res['slots'], 'label' ) );
		foreach ( $res['slots'] as $slot ) {
			$this->assertTrue( Engine::is_free( Services::find( $svc['id'] ), $anna['id'], Clock::parse( $slot['start'] ) ) );
			$this->assertSame( array( $anna['id'] ), $slot['staff'] );
		}
		$this->assertEmpty( $this->requests, 'No AI call without a key' );
	}

	public function test_ai_constraints_are_validated_and_only_real_slots_returned() {
		list( $svc, $anna, $ben ) = $this->salon();
		$this->ai_on();
		$this->http['generativelanguage.googleapis.com'] = $this->gemini(
			array(
				'service_id' => 9999, // Unknown: ignored, the widget's service wins.
				'staff_id'   => $ben['id'],
				'date_from'  => '2020-01-01', // Past: clamped to today.
				'date_to'    => '2026-11-03',
				'weekdays'   => array( 2, 99 ),
				'time_from'  => '16:00',
				'time_to'    => '25:00', // Invalid: dropped.
				'summary'    => 'Tuesday after 16:00 with Ben',
			)
		);
		$res = Nl_Booking::suggest( 'Tue after 4 with Ben', array( 'service_id' => $svc['id'], 'tz' => 'Europe/London' ) );
		$this->assertSame( 'ai', $res['source'] );
		$this->assertSame( $svc['id'], $res['constraints']['service_id'] );
		$this->assertSame( '2026-11-02', $res['constraints']['date_from'] );
		$this->assertSame( array( 2 ), $res['constraints']['weekdays'] );
		$this->assertSame( '', $res['constraints']['time_to'] );
		$this->assertSame( array( '16:00' ), array_column( $res['slots'], 'label' ), 'Ben works until 17:00' );
		$this->assertSame( '2026-11-03', $res['slots'][0]['date'] );
		$body = $this->sent( 'generativelanguage' )[0]['json'];
		$this->assertStringContainsString( 'Today is 2026-11-02 (Monday)', $body['systemInstruction']['parts'][0]['text'] );
		$this->assertStringNotContainsString( 'anna@example.org', wp_json_encode( $body ), 'Staff emails are not sent' );
	}

	public function test_ai_staff_that_does_not_do_the_service_is_ignored() {
		list( $svc ) = $this->salon();
		$other       = $this->staff(
			array(
				'name'  => 'Cleo Massage',
				'email' => 'cleo@example.org',
			)
		);
		$this->ai_on();
		$this->http['generativelanguage'] = $this->gemini(
			array(
				'service_id' => 0,
				'staff_id'   => $other['id'],
				'date_from'  => '2026-11-03',
				'date_to'    => '2026-11-03',
				'weekdays'   => array(),
				'time_from'  => '',
				'time_to'    => '',
				'summary'    => 'x',
			)
		);
		$res = Nl_Booking::suggest( 'with Cleo tomorrow', array( 'service_id' => $svc['id'], 'tz' => 'Europe/London' ) );
		$this->assertSame( 0, $res['constraints']['staff_id'] );
		$this->assertNotEmpty( $res['slots'] );
	}

	public function test_ai_failure_and_storefront_switch_fall_back_to_rules() {
		list( $svc ) = $this->salon();
		$this->ai_on();
		$this->http['generativelanguage'] = array( 500, array( 'error' => array( 'message' => 'down' ) ) );
		$res                              = Nl_Booking::suggest( 'tomorrow at 10', array( 'service_id' => $svc['id'], 'tz' => 'Europe/London' ) );
		$this->assertSame( 'rules', $res['source'] );
		$this->assertSame( '10:00', $res['slots'][0]['label'] );
		$this->assertCount( 1, $this->sent( 'generativelanguage' ), 'Storefront calls do not retry' );
		$this->requests = array();
		$this->settings( array( 'public_ai' => false ) );
		$this->assertSame( 'rules', Nl_Booking::suggest( 'tomorrow at 11', array( 'service_id' => $svc['id'], 'tz' => 'Europe/London' ) )['source'] );
		$this->assertEmpty( $this->requests );
	}

	public function test_no_match_offers_nearest_and_needs_service() {
		list( $svc ) = $this->salon();
		$res         = Nl_Booking::suggest( 'Saturday', array( 'service_id' => $svc['id'], 'tz' => 'Europe/London' ) );
		$this->assertTrue( $res['relaxed'] );
		$this->assertSame( '2026-11-09', $res['slots'][0]['date'], 'Next open day after the closed weekend' );
		$this->assertSame( 'service', Nl_Booking::suggest( 'tomorrow', array( 'tz' => 'Europe/London' ) )['needs'] );
		$this->assertWPError( Nl_Booking::suggest( '   ', array() ) );
	}

	public function test_template_draft_is_not_saved_until_accepted() {
		$this->ai_on();
		$this->http['generativelanguage'] = $this->gemini(
			array(
				'subject' => 'See you tomorrow, {customer_name}! {fake}',
				'body'    => "Hi {customer_name}, your {service} is at {time}.\nNeed to move it? {manage_url}",
			)
		);
		$before = Templates::get( 'reminder_1' );
		$draft  = Writers::template( 'reminder_1', array( 'tone' => 'warm' ) );
		$this->assertSame( 'See you tomorrow, {customer_name}!', trim( $draft['draft']['subject'] ) );
		$this->assertSame( array(), $draft['missing'] );
		$this->assertSame( $before['subject'], Templates::get( 'reminder_1' )['subject'], 'Draft only' );
		$prompt = $this->sent( 'generativelanguage' )[0]['json']['systemInstruction']['parts'][0]['text'];
		$this->assertStringContainsString( 'Tone: warm', $prompt );
		$this->assertStringContainsString( '{manage_url}', $prompt );
		// Missing required placeholder is reported.
		$this->http['generativelanguage'] = $this->gemini(
			array(
				'subject' => 'Reminder',
				'body'    => 'See you soon.',
			)
		);
		Dev3\AiBooking\Core3\Core\Cache::flush();
		$this->assertSame( array( 'manage_url' ), Writers::template( 'reminder_1', array( 'tone' => 'concise' ) )['missing'] );
	}

	public function test_template_draft_respects_ai_off() {
		$this->settings( array( 'ai_enabled' => false ) );
		$res = Writers::template( 'reminder_1', array() );
		$this->assertWPError( $res );
		$this->assertSame( 'dev3bk_ai_disabled', $res->get_error_code() );
	}

	public function test_risk_hint_is_stored_and_changes_nothing_else() {
		list( $svc, $anna ) = $this->salon();
		$b                  = $this->book( $svc, $anna['id'], $this->local_ts( '2026-11-03 10:00' ) );
		$this->ai_on();
		$this->http['generativelanguage'] = $this->gemini(
			array(
				'hint'   => 'First visit and pay later, so the risk is moderate.',
				'action' => 'Send a friendly personal reminder the day before.',
			)
		);
		$risk = Writers::risk( $b );
		$this->assertSame( 'Send a friendly personal reminder the day before.', $risk['ai']['action'] );
		$after = Bookings::fresh( $b['id'] );
		$this->assertSame( 'confirmed', $after['status'] );
		$this->assertSame( $b['starts_at'], $after['starts_at'] );
		$this->assertSame( $risk['ai']['hint'], $after['risk']['ai']['hint'] );
		$sent = wp_json_encode( $this->sent( 'generativelanguage' )[0]['json'] );
		$this->assertStringNotContainsString( 'jamie@example.org', $sent );
		$this->assertStringNotContainsString( 'Jamie', $sent );
		$this->settings( array( 'ai_risk' => false ) );
		$this->assertWPError( Writers::risk( $b ) );
	}
}
