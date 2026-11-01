<?php
/**
 * GDPR exporter + eraser for customers and their bookings.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Data;

use Dev3\AiBooking\Booking\Presenter;
use Dev3\AiBooking\Core3\Core\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data.
 */
class Personal_Data {

	/**
	 * Registers exporter/eraser.
	 */
	public static function register() {
		Privacy::add_exporter( __( 'Appointments', 'dev3-ai-booking-appointments' ), array( self::class, 'export' ) );
		Privacy::add_eraser( __( 'Appointments', 'dev3-ai-booking-appointments' ), array( self::class, 'erase' ) );
	}

	/**
	 * Exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		unset( $page );
		$c    = Customers::by_email( $email );
		$data = array();
		if ( $c ) {
			$data[] = array(
				'group_id'    => 'dev3bk-customer',
				'group_label' => __( 'Appointment customer profile', 'dev3-ai-booking-appointments' ),
				'item_id'     => 'dev3bk-customer-' . $c['id'],
				'data'        => array(
					array(
						'name'  => __( 'Name', 'dev3-ai-booking-appointments' ),
						'value' => $c['name'],
					),
					array(
						'name'  => __( 'Email', 'dev3-ai-booking-appointments' ),
						'value' => $c['email'],
					),
					array(
						'name'  => __( 'Phone', 'dev3-ai-booking-appointments' ),
						'value' => $c['phone'],
					),
				),
			);
			foreach ( Bookings::search(
				array(
					'customer' => $c['id'],
					'per_page' => 500,
				)
			)['rows'] as $b ) {
				$v      = Presenter::base( $b );
				$data[] = array(
					'group_id'    => 'dev3bk-bookings',
					'group_label' => __( 'Appointments', 'dev3-ai-booking-appointments' ),
					'item_id'     => 'dev3bk-booking-' . $b['id'],
					'data'        => array(
						array(
							'name'  => __( 'Reference', 'dev3-ai-booking-appointments' ),
							'value' => $b['ref'],
						),
						array(
							'name'  => __( 'Service', 'dev3-ai-booking-appointments' ),
							'value' => $v['service'],
						),
						array(
							'name'  => __( 'When', 'dev3-ai-booking-appointments' ),
							'value' => $v['date_label'] . ' ' . $v['time_label'],
						),
						array(
							'name'  => __( 'Status', 'dev3-ai-booking-appointments' ),
							'value' => $v['status_label'],
						),
						array(
							'name'  => __( 'Notes', 'dev3-ai-booking-appointments' ),
							'value' => $b['notes'],
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Eraser: anonymises the customer and clears booking notes (bookings stay for accounting).
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		global $wpdb;
		$c = Customers::by_email( $email );
		if ( ! $c ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		Customers::update(
			$c['id'],
			array(
				'name'    => __( 'Deleted customer', 'dev3-ai-booking-appointments' ),
				'email'   => 'deleted-' . $c['id'] . '@invalid.invalid',
				'phone'   => '',
				'notes'   => '',
				'user_id' => 0,
			)
		);
		$wpdb->update( Bookings::table(), array( 'notes' => '' ), array( 'customer_id' => $c['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array(
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
