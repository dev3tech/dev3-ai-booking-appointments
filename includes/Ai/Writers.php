<?php
/**
 * Admin DEV3 AI helpers: reminder/template copy drafts (A2) and the no-show risk hint (A3).
 * Both return drafts/hints only; nothing is saved or sent from here.
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Ai;

use Dev3\AiBooking\Booking\Presenter;
use Dev3\AiBooking\Booking\Risk;
use Dev3\AiBooking\Core3\Api\Prompts;
use Dev3\AiBooking\Data\Bookings;
use Dev3\AiBooking\Notify\Templates;
use Dev3\AiBooking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Writers.
 */
class Writers {

	/**
	 * Drafts a template (subject + body). Unknown placeholders are stripped; missing required ones
	 * are reported so the admin can fix them before accepting.
	 *
	 * @param string $id   Template id.
	 * @param array  $opts tone, length, language, instructions.
	 * @return array|\WP_Error { draft{subject,body}, missing[], model, tokens, cached }
	 */
	public static function template( $id, array $opts ) {
		$tpl = Templates::get( $id );
		if ( ! $tpl ) {
			return new \WP_Error( 'dev3bk_not_found', __( 'Unknown template.', 'dev3-ai-booking-appointments' ), array( 'status' => 404 ) );
		}
		$tones   = array( 'friendly', 'professional', 'warm', 'concise', 'playful', 'luxury' );
		$tone    = in_array( $opts['tone'] ?? '', $tones, true ) ? $opts['tone'] : 'friendly';
		$lengths = array( 'short', 'medium', 'long' );
		$length  = in_array( $opts['length'] ?? '', $lengths, true ) ? $opts['length'] : 'short';
		$result  = Prompts::run(
			'template_copy',
			array(
				'tone'         => $tone,
				'length'       => $length,
				'language'     => mb_substr( sanitize_text_field( (string) ( $opts['language'] ?? '' ) ), 0, 40 ) ? mb_substr( sanitize_text_field( (string) $opts['language'] ), 0, 40 ) : get_locale(),
				'placeholders' => '{' . implode( '}, {', Templates::PLACEHOLDERS ) . '}',
				'required'     => $tpl['required'] ? '{' . implode( '}, {', $tpl['required'] ) . '}' : 'none',
				'label'        => $tpl['label'],
				'business'     => Presenter::business_name(),
				'subject'      => $tpl['subject'],
				'body'         => $tpl['body'],
				'instructions' => mb_substr( sanitize_textarea_field( (string) ( $opts['instructions'] ?? '' ) ), 0, 500 ),
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$subject = sanitize_text_field( Templates::strip_unknown( (string) ( $result['data']['subject'] ?? '' ) ) );
		$body    = sanitize_textarea_field( Templates::strip_unknown( (string) ( $result['data']['body'] ?? '' ) ) );
		if ( '' === trim( $subject ) || '' === trim( $body ) ) {
			return new \WP_Error( 'dev3bk_ai_empty', __( 'DEV3 AI returned an empty draft. Try again.', 'dev3-ai-booking-appointments' ), array( 'status' => 502 ) );
		}
		return array(
			'draft'   => array(
				'subject' => mb_substr( $subject, 0, 200 ),
				'body'    => mb_substr( $body, 0, 5000 ),
			),
			'missing' => Templates::missing( $id, $subject, $body ),
			'model'   => (string) ( $result['model'] ?? '' ),
			'tokens'  => (int) ( $result['usage']['total'] ?? 0 ),
			'cached'  => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Adds a plain-language hint + suggested action to a booking's risk (stored on the booking).
	 *
	 * @param array $b Booking.
	 * @return array|\WP_Error Risk.
	 */
	public static function risk( array $b ) {
		if ( ! Settings::get( 'ai_risk', true ) ) {
			return new \WP_Error( 'dev3bk_ai_disabled', __( 'The DEV3 AI risk hint is turned off in Settings.', 'dev3-ai-booking-appointments' ), array( 'status' => 400 ) );
		}
		$risk     = Risk::store( $b );
		$features = Risk::features( $b );
		$result   = Prompts::run(
			'risk_hint',
			array(
				'features' => $features,
				'score'    => $risk['score'],
				'level'    => $risk['level'],
				'reasons'  => implode( '; ', $risk['reasons'] ),
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$risk['ai'] = array(
			'hint'   => mb_substr( sanitize_text_field( (string) ( $result['data']['hint'] ?? '' ) ), 0, 300 ),
			'action' => mb_substr( sanitize_text_field( (string) ( $result['data']['action'] ?? '' ) ), 0, 200 ),
			'model'  => (string) ( $result['model'] ?? '' ),
		);
		Bookings::update( $b['id'], array( 'risk' => $risk ) );
		return $risk;
	}
}
