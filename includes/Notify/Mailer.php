<?php
/**
 * Sends booking emails from approved templates (HTML + plain-text alternative, .ics attached).
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Notify;

use Dev3\AiBooking\Booking\Policy;
use Dev3\AiBooking\Booking\Presenter;
use Dev3\AiBooking\Data\Customers;
use Dev3\AiBooking\Data\Events;
use Dev3\AiBooking\Data\Staff;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Mailer.
 */
class Mailer {

	/**
	 * Placeholder values for a booking.
	 *
	 * @param array $b  Booking.
	 * @param bool  $for_admin Use the business timezone.
	 * @return array
	 */
	public static function vars( array $b, $for_admin = false ) {
		$view     = Presenter::base( $b, $for_admin ? Settings::timezone()->getName() : '' );
		$customer = Customers::get( $b['customer_id'] );
		$vars     = array(
			'customer_name' => $customer ? ( $customer['name'] ? $customer['name'] : $customer['email'] ) : '',
			'service'       => $view['service'],
			'staff'         => $view['staff'],
			'date'          => $view['day_label'] . ', ' . $view['date_label'],
			'time'          => $view['time_label'],
			'timezone'      => str_replace( '_', ' ', $view['tz'] ),
			/* translators: %d: minutes. */
			'duration'      => sprintf( __( '%d min', 'dev3-ai-booking-appointments' ), $view['duration'] ),
			'location'      => $view['location'],
			'price'         => $view['amount_label'],
			'amount_due'    => $b['amount'] > $b['paid_amount'] ? Settings::money( $b['amount'] - $b['paid_amount'], $b['currency'] ) : Settings::money( 0, $b['currency'] ),
			'ref'           => $b['ref'],
			'manage_url'    => Presenter::manage_url( $b ),
			'ics_url'       => Presenter::ics_url( $b ),
			'google_url'    => Presenter::google_url( $b ),
			'site_name'     => Presenter::business_name(),
			'policy'        => Policy::summary(),
		);
		/**
		 * Filters email placeholder values.
		 *
		 * @param array $vars Vars.
		 * @param array $b    Booking.
		 */
		return apply_filters( 'dev3bk_email_vars', $vars, $b );
	}

	/**
	 * Sample values for previews/tests.
	 *
	 * @return array
	 */
	public static function sample_vars() {
		return array(
			'customer_name' => 'Jamie Rivera',
			'service'       => __( 'Haircut & styling', 'dev3-ai-booking-appointments' ),
			'staff'         => 'Anna',
			'date'          => wp_date( 'l, ' . get_option( 'date_format', 'F j, Y' ), time() + DAY_IN_SECONDS ),
			'time'          => '17:30 – 18:15',
			'timezone'      => str_replace( '_', ' ', Settings::timezone()->getName() ),
			'duration'      => __( '45 min', 'dev3-ai-booking-appointments' ),
			'location'      => '12 High Street',
			'price'         => Settings::money( 45 ),
			'amount_due'    => Settings::money( 0 ),
			'ref'           => 'K7Q2M9XA',
			'manage_url'    => home_url( '/?dev3bk_ref=K7Q2M9XA' ),
			'ics_url'       => home_url( '/' ),
			'google_url'    => 'https://calendar.google.com/',
			'site_name'     => Presenter::business_name(),
			'policy'        => Policy::summary(),
		);
	}

	/**
	 * Renders a template.
	 *
	 * @param string $id   Template id.
	 * @param array  $vars Vars.
	 * @param array  $tpl  Optional template override (preview of unsaved text).
	 * @return array|null { subject, html, text }
	 */
	public static function render( $id, array $vars, array $tpl = array() ) {
		$template = Templates::get( $id );
		if ( ! $template ) {
			return null;
		}
		$subject = Templates::fill( $tpl['subject'] ?? $template['subject'], $vars );
		$body    = Templates::fill( $tpl['body'] ?? $template['body'], $vars );
		$subject = trim( preg_replace( '/\s+/', ' ', $subject ) );
		$text    = trim( preg_replace( "/[ \t]+\n/", "\n", $body ) );
		return array(
			'subject' => $subject,
			'text'    => $text,
			'html'    => self::layout( $subject, $text, $vars, 'admin' !== $template['audience'] ),
		);
	}

	/**
	 * Neutral HTML layout (no DEV3 branding; shopper-facing).
	 *
	 * @param string $title   Title.
	 * @param string $text    Plain body.
	 * @param array  $vars    Vars.
	 * @param bool   $buttons Show customer buttons.
	 * @return string
	 */
	public static function layout( $title, $text, array $vars, $buttons = true ) {
		$accent = sanitize_hex_color( (string) Settings::get( 'accent_color', '#4f46e5' ) );
		$accent = $accent ? $accent : '#4f46e5';
		$body   = make_clickable( wpautop( esc_html( $text ) ) );
		$rows   = '';
		foreach (
			array(
				__( 'Service', 'dev3-ai-booking-appointments' ) => $vars['service'] ?? '',
				__( 'With', 'dev3-ai-booking-appointments' )    => $vars['staff'] ?? '',
				__( 'Date', 'dev3-ai-booking-appointments' )    => $vars['date'] ?? '',
				__( 'Time', 'dev3-ai-booking-appointments' )    => ( $vars['time'] ?? '' ) . ( ! empty( $vars['timezone'] ) ? ' (' . $vars['timezone'] . ')' : '' ),
				__( 'Where', 'dev3-ai-booking-appointments' )   => $vars['location'] ?? '',
				__( 'Reference', 'dev3-ai-booking-appointments' ) => $vars['ref'] ?? '',
			) as $label => $value
		) {
			if ( '' !== trim( (string) $value ) ) {
				$rows .= '<tr><td style="padding:6px 12px 6px 0;color:#64748b;white-space:nowrap;vertical-align:top">' . esc_html( $label ) . '</td><td style="padding:6px 0;color:#0f172a;font-weight:600">' . esc_html( $value ) . '</td></tr>';
			}
		}
		$cta = '';
		if ( $buttons && ! empty( $vars['manage_url'] ) ) {
			$cta = '<p style="margin:24px 0 8px"><a href="' . esc_url( $vars['manage_url'] ) . '" style="display:inline-block;background:' . esc_attr( $accent ) . ';color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600">' . esc_html__( 'Manage booking', 'dev3-ai-booking-appointments' ) . '</a>';
			if ( ! empty( $vars['google_url'] ) ) {
				$cta .= ' &nbsp; <a href="' . esc_url( $vars['google_url'] ) . '" style="color:' . esc_attr( $accent ) . ';font-weight:600">' . esc_html__( 'Add to Google Calendar', 'dev3-ai-booking-appointments' ) . '</a>';
			}
			$cta .= '</p>';
		}
		$html  = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#0f172a">';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9"><tr><td align="center" style="padding:24px 12px">';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:12px;border:1px solid #e2e8f0">';
		$html .= '<tr><td style="height:6px;background:' . esc_attr( $accent ) . ';border-radius:12px 12px 0 0"></td></tr>';
		$html .= '<tr><td style="padding:24px 24px 8px"><div style="font-size:13px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.04em">' . esc_html( $vars['site_name'] ?? '' ) . '</div></td></tr>';
		$html .= '<tr><td style="padding:0 24px">' . $body . '</td></tr>';
		if ( $rows ) {
			$html .= '<tr><td style="padding:8px 24px"><table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-top:1px solid #e2e8f0;padding-top:8px">' . $rows . '</table></td></tr>';
		}
		$html .= '<tr><td style="padding:0 24px 24px">' . $cta . '</td></tr>';
		$html .= '</table></td></tr></table></body></html>';
		return $html;
	}

	/**
	 * Sends one template to an address.
	 *
	 * @param string $id      Template.
	 * @param string $to      Address.
	 * @param array  $vars    Vars.
	 * @param array  $b       Booking (for the .ics attachment and the log), or empty.
	 * @param bool   $ics     Attach .ics.
	 * @return bool
	 */
	public static function send( $id, $to, array $vars, array $b = array(), $ics = false ) {
		$template = Templates::get( $id );
		if ( ! $template || ! $template['enabled'] || ! is_email( $to ) ) {
			return false;
		}
		$mail = self::render( $id, $vars );
		if ( ! $mail ) {
			return false;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = sanitize_email( (string) Settings::get( 'business_email', '' ) );
		if ( $from && is_email( $from ) ) {
			$headers[] = 'Reply-To: ' . Presenter::business_name() . ' <' . $from . '>';
		}
		$files = array();
		if ( $ics && $b ) {
			$path = self::temp_ics( $b );
			if ( $path ) {
				$files[] = $path;
			}
		}
		$alt = static function ( $phpmailer ) use ( $mail ) {
			$phpmailer->AltBody = $mail['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'phpmailer_init', $alt );
		$sent = wp_mail( $to, $mail['subject'], $mail['html'], $headers, $files );
		remove_action( 'phpmailer_init', $alt );
		foreach ( $files as $file ) {
			wp_delete_file( $file );
			@rmdir( dirname( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		if ( $b ) {
			/* translators: 1: template label, 2: address. */
			Events::log( $b['id'], 'email', sprintf( __( 'Email "%1$s" sent to %2$s.', 'dev3-ai-booking-appointments' ), $template['label'], $to ) . ( $sent ? '' : ' ' . __( '(failed)', 'dev3-ai-booking-appointments' ) ), 'system', array( 'template' => $id ) );
		}
		return (bool) $sent;
	}

	/**
	 * Writes the booking's .ics to a temp file.
	 *
	 * @param array $b Booking.
	 * @return string Path or ''.
	 */
	private static function temp_ics( array $b ) {
		$dir = trailingslashit( get_temp_dir() ) . 'dev3bk-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$path = $dir . '/booking-' . sanitize_file_name( $b['ref'] ) . '.ics';
		return false !== file_put_contents( $path, Ics::booking( $b ) ) ? $path : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Sends a customer template.
	 *
	 * @param string $id Template.
	 * @param array  $b  Booking.
	 * @return bool
	 */
	public static function send_customer( $id, array $b ) {
		$customer = Customers::get( $b['customer_id'] );
		if ( ! $customer ) {
			return false;
		}
		$ics = in_array( $id, array( 'customer_confirmed', 'customer_pending', 'customer_rescheduled', 'customer_cancelled' ), true );
		return self::send( $id, $customer['email'], self::vars( $b ), $b, $ics );
	}

	/**
	 * Sends an admin/staff template.
	 *
	 * @param string $id Template.
	 * @param array  $b  Booking.
	 */
	public static function send_team( $id, array $b ) {
		$vars = self::vars( $b, true );
		if ( Settings::get( 'admin_notify', true ) ) {
			$to = sanitize_email( (string) Settings::get( 'business_email', '' ) );
			self::send( $id, $to ? $to : (string) get_option( 'admin_email' ), $vars, $b );
		}
		$staff = Staff::get( $b['staff_id'] );
		if ( Settings::get( 'staff_notify', true ) && $staff && $staff['email'] ) {
			$admin = sanitize_email( (string) Settings::get( 'business_email', '' ) );
			$admin = $admin ? $admin : (string) get_option( 'admin_email' );
			if ( strtolower( $staff['email'] ) !== strtolower( $admin ) || ! Settings::get( 'admin_notify', true ) ) {
				self::send( $id, $staff['email'], $vars, $b );
			}
		}
	}

	/**
	 * New booking (or paid / approved) emails.
	 *
	 * @param array $b Booking.
	 */
	public static function booking_created( array $b ) {
		self::send_customer( 'pending' === $b['status'] ? 'customer_pending' : 'customer_confirmed', $b );
		self::send_team( 'admin_new', $b );
	}

	/**
	 * Rescheduled emails.
	 *
	 * @param array $b Booking.
	 */
	public static function booking_rescheduled( array $b ) {
		self::send_customer( 'customer_rescheduled', $b );
		self::send_team( 'admin_rescheduled', $b );
	}

	/**
	 * Cancelled emails.
	 *
	 * @param array  $b     Booking.
	 * @param string $actor Who cancelled.
	 */
	public static function booking_cancelled( array $b, $actor ) {
		unset( $actor );
		self::send_customer( 'customer_cancelled', $b );
		self::send_team( 'admin_cancelled', $b );
	}
}
