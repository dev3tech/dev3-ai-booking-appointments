<?php
/**
 * REST: permissions, public widget flow, manage links, files, admin CRUD, export.
 *
 * @package Dev3\AiBooking
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Data\Staff;

class RestTest extends Dev3bk_Base_Case {

	private function fixture() {
		$svc   = $this->service();
		$staff = $this->staff( array( 'phone' => '+44 1234' ), array( $svc['id'] ) );
		return array( $svc, $staff );
	}

	public function test_admin_routes_need_manage_capability_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( array( array( 'GET', 'bookings' ), array( 'GET', 'dashboard' ), array( 'POST', 'services' ), array( 'GET', 'calendar' ), array( 'PUT', 'templates/reminder_1' ), array( 'GET', 'payments/stripe' ) ) as $r ) {
			$this->assertSame( 403, $this->rest( $r[0], $r[1] )->get_status(), $r[1] );
		}
		$this->admin();
		$this->assertSame( 403, $this->rest( 'GET', 'bookings', array(), false )->get_status(), 'Nonce required' );
		$this->assertSame( 200, $this->rest( 'GET', 'bookings' )->get_status() );
	}

	public function test_public_catalog_hides_private_staff_data() {
		$this->fixture();
		$this->service( array( 'name' => 'Orphan service' ) ); // No staff: not offered.
		$data = $this->rest( 'GET', 'public/catalog', array(), false )->get_data();
		$this->assertCount( 1, $data['services'] );
		$this->assertSame( array( 'later' ), $data['services'][0]['payments'] );
		$json = wp_json_encode( $data );
		$this->assertStringNotContainsString( 'anna@example.org', $json );
		$this->assertStringNotContainsString( '+44 1234', $json );
		$this->assertStringNotContainsString( 'feed_token', $json );
		$this->assertSame( 'AB', $data['staff'][0]['initials'] );
	}

	public function test_cross_origin_requests_are_refused() {
		$this->fixture();
		$req = new WP_REST_Request( 'GET', '/dev3bk/v1/public/catalog' );
		$req->set_header( 'Origin', 'https://evil.example' );
		$this->assertSame( 403, rest_get_server()->dispatch( $req )->get_status() );
	}

	public function test_widget_flow_book_manage_cancel() {
		list( $svc, $staff ) = $this->fixture();
		$avail               = $this->rest(
			'GET',
			'public/availability',
			array(
				'service' => $svc['id'],
				'from'    => '2026-11-03',
				'to'      => '2026-11-04',
				'tz'      => 'Europe/Paris',
			),
			false
		)->get_data();
		$this->assertSame( 'Europe/Paris', $avail['tz'] );
		$slot = $avail['days']['2026-11-03'][0];
		$this->assertSame( '10:00', $slot['label'], '09:00 London = 10:00 Paris' );
		$res = $this->rest(
			'POST',
			'public/bookings',
			array(
				'service' => $svc['id'],
				'staff'   => 0,
				'start'   => $slot['start'],
				'tz'      => 'Europe/Paris',
				'name'    => 'Lou Martin',
				'email'   => 'lou@example.org',
				'payment' => 'later',
			),
			false
		);
		$this->assertSame( 201, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'done', $data['next']['type'] );
		$this->assertSame( $staff['id'], $data['booking']['staff_id'] );
		$this->assertSame( 'Europe/Paris', $data['booking']['tz'] );
		$ref = $data['booking']['ref'];
		$key = $data['booking']['key'];
		$this->assertSame( 404, $this->rest( 'GET', 'public/bookings/' . $ref, array( 'key' => 'wrong' ), false )->get_status() );
		$view = $this->rest( 'GET', 'public/bookings/' . $ref, array( 'key' => $key ), false )->get_data();
		$this->assertTrue( $view['can_cancel'] );
		$ics = $this->rest( 'GET', 'public/bookings/' . $ref . '/ics', array( 'key' => $key ), false );
		$this->assertStringContainsString( 'BEGIN:VEVENT', $ics->get_data() );
		$this->assertStringStartsWith( 'text/calendar', $ics->get_headers()['Content-Type'] );
		$this->assertSame( 409, $this->rest( 'POST', 'public/bookings', array( 'service' => $svc['id'], 'start' => $slot['start'], 'name' => 'B', 'email' => 'b@example.org' ), false )->get_status(), 'Slot now taken' );
		$moved = $this->rest(
			'POST',
			'public/bookings/' . $ref . '/reschedule',
			array(
				'key'   => $key,
				'start' => $avail['days']['2026-11-04'][2]['start'],
			),
			false
		)->get_data();
		$this->assertSame( 1, Bookings::by_ref( $ref )['reschedules'] );
		$this->assertSame( $avail['days']['2026-11-04'][2]['start'], $moved['start'] );
		$done = $this->rest(
			'POST',
			'public/bookings/' . $ref . '/cancel',
			array(
				'key'    => $key,
				'reason' => 'Travel',
			),
			false
		)->get_data();
		$this->assertSame( 'cancelled', $done['status'] );
		$this->assertFalse( $done['can_cancel'] );
	}

	public function test_honeypot_and_validation() {
		list( $svc ) = $this->fixture();
		$this->assertSame( 400, $this->rest( 'POST', 'public/bookings', array( 'service' => $svc['id'], 'website' => 'http://spam' ), false )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', 'public/bookings', array( 'service' => 999 ), false )->get_status() );
		$this->assertSame( 400, $this->rest( 'GET', 'public/availability', array( 'service' => 999 ), false )->get_status() );
	}

	public function test_my_bookings_requires_login_and_matches_email() {
		list( $svc, $staff ) = $this->fixture();
		$this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ) );
		$this->assertSame( 401, $this->rest( 'GET', 'public/my-bookings' )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'jamie@example.org' ) ) );
		$data = $this->rest( 'GET', 'public/my-bookings' )->get_data();
		$this->assertCount( 1, $data['bookings'] );
		$this->assertSame( 'Haircut', $data['bookings'][0]['service'] );
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'other@example.org' ) ) );
		$this->assertCount( 0, $this->rest( 'GET', 'public/my-bookings' )->get_data()['bookings'] );
	}

	public function test_public_nl_returns_real_slots() {
		list( $svc ) = $this->fixture();
		$data        = $this->rest(
			'POST',
			'public/nl',
			array(
				'text'    => 'Wednesday after 3',
				'service' => $svc['id'],
				'tz'      => 'Europe/London',
			),
			false
		)->get_data();
		$this->assertSame( 'rules', $data['source'] );
		$this->assertSame( array( '15:00', '15:30', '16:00' ), array_column( $data['slots'], 'label' ) );
	}

	public function test_staff_feed_token() {
		list( $svc, $staff ) = $this->fixture();
		$this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ) );
		$this->assertSame( 404, $this->rest( 'GET', 'feed/' . $staff['id'], array( 'token' => 'nope' ), false )->get_status() );
		$token = Staff::get( $staff['id'] )['feed_token'];
		$res   = $this->rest( 'GET', 'feed/' . $staff['id'], array( 'token' => $token ), false );
		$this->assertSame( 200, $res->get_status() );
		$this->assertStringContainsString( 'Jamie Rivera', $res->get_data() );
		$this->admin();
		$rot = $this->rest( 'POST', 'staff/' . $staff['id'] . '/feed-token' )->get_data();
		$this->assertStringNotContainsString( $token, $rot['feed_url'] );
		$this->assertArrayNotHasKey( 'feed_token', $rot );
	}

	public function test_admin_crud_calendar_and_export() {
		$this->admin();
		$svc = $this->rest(
			'POST',
			'services',
			array(
				'name'     => 'Massage',
				'duration' => 45,
				'price'    => 60,
				'color'    => '#ff0000',
			)
		);
		$this->assertSame( 201, $svc->get_status() );
		$sid   = $svc->get_data()['id'];
		$staff = $this->rest(
			'POST',
			'staff',
			array(
				'name'     => 'Cleo',
				'services' => array( $sid ),
				'timezone' => 'Not/AZone',
			)
		)->get_data();
		$this->assertSame( '', $staff['timezone'] );
		$this->assertSame( array( $sid ), $staff['services'] );
		$this->assertSame( 400, $this->rest( 'POST', 'services', array( 'name' => '' ) )->get_status() );
		$exc = $this->rest(
			'POST',
			'exceptions',
			array(
				'staff_id'  => $staff['id'],
				'date_from' => '2026-11-10',
				'label'     => 'Holiday',
			)
		);
		$this->assertSame( 201, $exc->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', 'exceptions', array( 'date_from' => 'bad' ) )->get_status() );
		$sched = $this->rest( 'PUT', 'schedule', array( 'hours' => array( 'mon' => array( array( '08:00', '12:00' ) ) ) ) )->get_data();
		$this->assertSame( array( array( '08:00', '12:00' ) ), $sched['hours']['mon'] );

		$b = $this->rest(
			'POST',
			'bookings',
			array(
				'service' => $sid,
				'staff'   => $staff['id'],
				'start'   => gmdate( 'Y-m-d\TH:i:s\Z', $this->local_ts( '2026-11-02 09:00' ) ),
				'name'    => '=HYPERLINK("x")',
				'email'   => 'csv@example.org',
				'notify'  => false,
			)
		);
		$this->assertSame( 201, $b->get_status() );
		$this->assertEmpty( $this->mails, 'Admin bookings are silent unless notify is set' );
		$id  = $b->get_data()['id'];
		$cal = $this->rest(
			'GET',
			'calendar',
			array(
				'from' => '2026-11-02',
				'to'   => '2026-11-08',
			)
		)->get_data();
		$this->assertCount( 1, $cal['bookings'] );
		$this->assertSame( '2026-11-02T09:00', $cal['bookings'][0]['local'] );
		$this->assertSame( 'confirmed', $this->rest( 'GET', 'bookings/' . $id )->get_data()['status'] );
		$this->assertSame( 'completed', $this->rest( 'POST', 'bookings/' . $id . '/status', array( 'status' => 'completed' ) )->get_data()['status'] );
		$this->assertSame( 'paid', $this->rest( 'POST', 'bookings/' . $id . '/payment', array( 'action' => 'paid' ) )->get_data()['payment_status'] );
		$csv = $this->rest( 'GET', 'bookings/export' )->get_data()['csv'];
		$this->assertStringContainsString( "'=HYPERLINK", $csv, 'Formula neutralised' );
		$list = $this->rest( 'GET', 'bookings', array( 'search' => 'csv@' ) )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 1, $list['counts']['completed'] );
		$this->assertSame( 200, $this->rest( 'DELETE', 'services/' . $sid )->get_status() );
		$this->assertSame( 'archived', \Dev3\AiBooking\Data\Services::get( $sid )['status'], 'Booked services are archived, not deleted' );
	}

	public function test_template_routes_preview_and_ai_draft() {
		$this->admin();
		$list = $this->rest( 'GET', 'templates' )->get_data();
		$this->assertCount( 10, $list['templates'] );
		$prev = $this->rest(
			'POST',
			'templates/reminder_1/preview',
			array(
				'subject' => 'Hi {customer_name}',
				'body'    => 'Body {manage_url}',
			)
		)->get_data();
		$this->assertSame( 'Hi Jamie Rivera', $prev['subject'] );
		$this->assertSame( 400, $this->rest( 'PUT', 'templates/reminder_1', array( 'subject' => 'x', 'body' => 'no link' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', 'templates/reminder_1/ai', array() )->get_status(), 'No key: error envelope' );
		$this->assertSame( 200, $this->rest( 'POST', 'templates/reminder_1/test', array( 'to' => 'me@example.org' ) )->get_status() );
		$this->assertSame( 'me@example.org', $this->mails[0]['to'] );
	}

	public function test_stripe_settings_route_masks_secret() {
		$this->admin();
		$res = $this->rest(
			'PUT',
			'payments/stripe',
			array(
				'publishable' => 'pk_test_1',
				'secret'      => 'sk_test_ABCDEF123456',
			)
		)->get_data();
		$this->assertTrue( $res['secret_set'] );
		$this->assertStringNotContainsString( 'ABCDEF123456', wp_json_encode( $res ) );
		$this->assertStringNotContainsString( 'sk_test_ABCDEF', wp_json_encode( $this->rest( 'GET', 'settings' )->get_data() ) );
	}

	public function test_privacy_export_and_erase() {
		list( $svc, $staff ) = $this->fixture();
		$this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ), array( 'notes' => 'Allergic to X' ) );
		$export = \Dev3\AiBooking\Data\Personal_Data::export( 'jamie@example.org' );
		$this->assertCount( 2, $export['data'] );
		\Dev3\AiBooking\Data\Personal_Data::erase( 'jamie@example.org' );
		$this->assertNull( \Dev3\AiBooking\Data\Customers::by_email( 'jamie@example.org' ) );
		$this->assertSame( '', Bookings::search( array( 'per_page' => 5 ) )['rows'][0]['notes'] );
	}

	public function test_admin_local_time_force_and_bulk_confirm() {
		list( $svc, $staff ) = $this->fixture();
		$this->admin();
		$b = $this->rest(
			'POST',
			'bookings',
			array(
				'service' => $svc['id'],
				'staff'   => $staff['id'],
				'local'   => '2026-11-07 08:15',
				'force'   => true,
				'name'    => 'Sat Walkin',
				'email'   => 'sat@example.org',
				'status'  => 'pending',
			)
		);
		$this->assertSame( 201, $b->get_status() );
		$this->assertSame( '2026-11-07T08:15', $b->get_data()['local'], 'Business-local time, outside hours (forced)' );
		$this->settings( array( 'default_status' => 'pending' ) );
		$p2  = $this->book( $svc, $staff['id'], $this->local_ts( '2026-11-05 10:00' ) );
		$res = $this->rest(
			'POST',
			'bookings/bulk',
			array(
				'action' => 'confirm',
				'ids'    => array( $b->get_data()['id'], $p2['id'], 99999 ),
			)
		)->get_data();
		$this->assertSame( 2, $res['done'] );
		$this->assertSame( 1, $res['failed'] );
		$this->assertSame( 'confirmed', Bookings::fresh( $p2['id'] )['status'] );
		$this->assertSame( 400, $this->rest( 'POST', 'bookings/bulk', array( 'action' => 'delete' ) )->get_status() );
	}
}
