<?php
/**
 * Versioned DEV3 AI prompts (overridable with dev3bk_prompt / dev3bk_prompt_<id>).
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Ai;

use Dev3\AiBooking\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt registrations.
 */
class Prompts_Library {

	/**
	 * Registers every prompt.
	 */
	public static function register() {
		Prompts::register(
			'nl_booking',
			array(
				'version'  => '1',
				'task'     => 'nl_booking',
				'system'   => "You turn a customer's request for an appointment into search constraints for a booking system.\n"
					. "Today is {{today}} ({{weekday}}); the customer's timezone is {{tz}}. Weeks start on Monday.\n"
					. "Only use ids from the lists. Use 0 when the customer did not mention a service or staff member.\n"
					. "Dates are YYYY-MM-DD in the customer's timezone; when no date is mentioned use today to today + 13 days.\n"
					. "weekdays uses ISO numbers (1 = Monday … 7 = Sunday); leave it empty unless specific weekdays were asked for.\n"
					. "time_from/time_to are 24-hour HH:MM bounds for the START time; 'after 5' means 17:00 unless the morning is clear; use empty strings when no time was given.\n"
					. "morning = 06:00–12:00, afternoon = 12:00–17:00, evening = 17:00–22:00.\n"
					. "Never invent appointment times: you only describe what the customer wants; the system finds real free slots.\n"
					. 'summary is a short neutral restatement in the language of the request, e.g. "Tuesday after 17:00 with Anna".',
				'template' => "Services: {{services}}\nStaff: {{staff}}\nRequest: {{text}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'service_id' => array( 'type' => 'INTEGER' ),
						'staff_id'   => array( 'type' => 'INTEGER' ),
						'date_from'  => array(
							'type'      => 'STRING',
							'maxLength' => 10,
						),
						'date_to'    => array(
							'type'      => 'STRING',
							'maxLength' => 10,
						),
						'weekdays'   => array(
							'type'     => 'ARRAY',
							'items'    => array( 'type' => 'INTEGER' ),
							'maxItems' => 7,
						),
						'time_from'  => array(
							'type'      => 'STRING',
							'maxLength' => 5,
						),
						'time_to'    => array(
							'type'      => 'STRING',
							'maxLength' => 5,
						),
						'summary'    => array(
							'type'      => 'STRING',
							'maxLength' => 160,
						),
					),
					'required'   => array( 'service_id', 'staff_id', 'date_from', 'date_to', 'weekdays', 'time_from', 'time_to', 'summary' ),
				),
				'profile'  => 'fast',
				'cache'    => DAY_IN_SECONDS,
			)
		);
		Prompts::register(
			'template_copy',
			array(
				'version'  => '1',
				'task'     => 'template_copy',
				'system'   => "You write appointment emails for a small business. Tone: {{tone}}. Length: {{length}}. Language: {{language}}.\n"
					. "Write plain text (no HTML, no markdown). Use these placeholders exactly as written, in curly braces, where the value belongs: {{placeholders}}.\n"
					. "Always keep these placeholders: {{required}}. Do not invent other placeholders, prices, addresses, phone numbers or policies.\n"
					. 'The goal of reminders is fewer no-shows: make it easy to reschedule or cancel with {manage_url}.',
				'template' => "Email: {{label}}\nBusiness: {{business}}\nCurrent subject: {{subject}}\nCurrent message:\n{{body}}\nExtra instructions: {{instructions}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'subject' => array(
							'type'      => 'STRING',
							'maxLength' => 120,
						),
						'body'    => array(
							'type'      => 'STRING',
							'maxLength' => 2000,
						),
					),
					'required'   => array( 'subject', 'body' ),
				),
				'profile'  => 'fast',
			)
		);
		Prompts::register(
			'risk_hint',
			array(
				'version'  => '1',
				'task'     => 'risk_hint',
				'system'   => "You help a small business reduce appointment no-shows. You get anonymised facts about one upcoming booking and a rule-based risk score.\n"
					. 'Explain the risk in one or two plain sentences and suggest one practical, polite action (e.g. a personal reminder, asking to confirm, a deposit next time). Never suggest cancelling the booking or treating the customer differently because of who they are.',
				'template' => "Facts: {{features}}\nRule score: {{score}} ({{level}})\nRule reasons: {{reasons}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'hint'   => array(
							'type'      => 'STRING',
							'maxLength' => 300,
						),
						'action' => array(
							'type'      => 'STRING',
							'maxLength' => 200,
						),
					),
					'required'   => array( 'hint', 'action' ),
				),
				'profile'  => 'fast',
				'cache'    => WEEK_IN_SECONDS,
			)
		);
	}
}
