<?php
/**
 * Shopper-facing strings for the storefront bundle (neutral, translatable, no DEV3 branding).
 *
 * @package Dev3\AiBooking
 */

namespace Dev3\AiBooking\Frontend;

use Dev3\AiBooking\Data\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Strings.
 */
class Strings {

	/**
	 * All strings. Placeholders: %s / %d (replaced in JS in order).
	 *
	 * @return array
	 */
	public static function all() {
		$s           = array(
			'loading'          => __( 'Loading…', 'dev3-ai-booking-appointments' ),
			'error'            => __( 'Something went wrong. Please try again.', 'dev3-ai-booking-appointments' ),
			'retry'            => __( 'Try again', 'dev3-ai-booking-appointments' ),
			'back'             => __( 'Back', 'dev3-ai-booking-appointments' ),
			'continue'         => __( 'Continue', 'dev3-ai-booking-appointments' ),
			/* translators: 1: step number, 2: number of steps. */
			'stepOf'           => __( 'Step %1$d of %2$d', 'dev3-ai-booking-appointments' ),
			'chooseService'    => __( 'Choose a service', 'dev3-ai-booking-appointments' ),
			'allCategories'    => __( 'All', 'dev3-ai-booking-appointments' ),
			'noServices'       => __( 'Online booking is not available right now.', 'dev3-ai-booking-appointments' ),
			'chooseStaff'      => __( 'Who would you like to see?', 'dev3-ai-booking-appointments' ),
			'anyStaff'         => __( 'Any available', 'dev3-ai-booking-appointments' ),
			'anyStaffHint'     => __( 'Most choice of times', 'dev3-ai-booking-appointments' ),
			'pickTime'         => __( 'Pick a date and time', 'dev3-ai-booking-appointments' ),
			'nlLabel'          => __( 'Tell us when suits you', 'dev3-ai-booking-appointments' ),
			'nlPlaceholder'    => __( 'e.g. "Tuesday after 5 with Anna"', 'dev3-ai-booking-appointments' ),
			'nlButton'         => __( 'Find times', 'dev3-ai-booking-appointments' ),
			'nlSearching'      => __( 'Looking for free times…', 'dev3-ai-booking-appointments' ),
			/* translators: %s: summary of the request. */
			'nlResults'        => __( 'Free times for: %s', 'dev3-ai-booking-appointments' ),
			'nlNearest'        => __( 'No exact match. The nearest free times are:', 'dev3-ai-booking-appointments' ),
			'nlNone'           => __( 'No free times match that. Try another day or time.', 'dev3-ai-booking-appointments' ),
			'nlClear'          => __( 'Clear', 'dev3-ai-booking-appointments' ),
			'or'               => __( 'or choose a date', 'dev3-ai-booking-appointments' ),
			'morning'          => __( 'Morning', 'dev3-ai-booking-appointments' ),
			'afternoon'        => __( 'Afternoon', 'dev3-ai-booking-appointments' ),
			'evening'          => __( 'Evening', 'dev3-ai-booking-appointments' ),
			'noSlots'          => __( 'No free times on this day.', 'dev3-ai-booking-appointments' ),
			/* translators: %s: date. */
			'nextAvailable'    => __( 'Next free day: %s', 'dev3-ai-booking-appointments' ),
			'noAvailability'   => __( 'There are no free times in the coming weeks.', 'dev3-ai-booking-appointments' ),
			'earlier'          => __( 'Earlier dates', 'dev3-ai-booking-appointments' ),
			'later'            => __( 'Later dates', 'dev3-ai-booking-appointments' ),
			/* translators: %s: time zone name. */
			'timesIn'          => __( 'Times shown in %s', 'dev3-ai-booking-appointments' ),
			'changeTz'         => __( 'Change', 'dev3-ai-booking-appointments' ),
			'timezone'         => __( 'Time zone', 'dev3-ai-booking-appointments' ),
			'today'            => __( 'Today', 'dev3-ai-booking-appointments' ),
			'tomorrow'         => __( 'Tomorrow', 'dev3-ai-booking-appointments' ),
			'yourDetails'      => __( 'Your details', 'dev3-ai-booking-appointments' ),
			'name'             => __( 'Full name', 'dev3-ai-booking-appointments' ),
			'email'            => __( 'Email', 'dev3-ai-booking-appointments' ),
			'phone'            => __( 'Phone', 'dev3-ai-booking-appointments' ),
			'optional'         => __( '(optional)', 'dev3-ai-booking-appointments' ),
			'notes'            => __( 'Anything we should know?', 'dev3-ai-booking-appointments' ),
			'enterName'        => __( 'Enter your name.', 'dev3-ai-booking-appointments' ),
			'enterEmail'       => __( 'Enter a valid email address.', 'dev3-ai-booking-appointments' ),
			'enterPhone'       => __( 'Enter your phone number.', 'dev3-ai-booking-appointments' ),
			'payment'          => __( 'Payment', 'dev3-ai-booking-appointments' ),
			'review'           => __( 'Review and confirm', 'dev3-ai-booking-appointments' ),
			'service'          => __( 'Service', 'dev3-ai-booking-appointments' ),
			'with'             => __( 'With', 'dev3-ai-booking-appointments' ),
			'where'            => __( 'Where', 'dev3-ai-booking-appointments' ),
			'when'             => __( 'When', 'dev3-ai-booking-appointments' ),
			'price'            => __( 'Price', 'dev3-ai-booking-appointments' ),
			/* translators: %s: amount. */
			'dueNow'           => __( 'Deposit due now: %s', 'dev3-ai-booking-appointments' ),
			'free'             => __( 'Free', 'dev3-ai-booking-appointments' ),
			/* translators: %d: minutes. */
			'minutes'          => __( '%d min', 'dev3-ai-booking-appointments' ),
			/* translators: %d: number of free places. */
			'spotsLeft'        => __( '%d spots left', 'dev3-ai-booking-appointments' ),
			'confirm'          => __( 'Confirm booking', 'dev3-ai-booking-appointments' ),
			'toPayment'        => __( 'Continue to payment', 'dev3-ai-booking-appointments' ),
			'booking'          => __( 'Booking…', 'dev3-ai-booking-appointments' ),
			'redirecting'      => __( 'Taking you to payment…', 'dev3-ai-booking-appointments' ),
			'doneTitle'        => __( 'You\'re booked!', 'dev3-ai-booking-appointments' ),
			'donePending'      => __( 'Request received', 'dev3-ai-booking-appointments' ),
			/* translators: %s: email address. */
			'doneText'         => __( 'We sent the details to %s.', 'dev3-ai-booking-appointments' ),
			/* translators: %s: email address. */
			'donePendingText'  => __( 'We will confirm your booking shortly and email %s.', 'dev3-ai-booking-appointments' ),
			'addGoogle'        => __( 'Add to Google Calendar', 'dev3-ai-booking-appointments' ),
			'downloadIcs'      => __( 'Download .ics', 'dev3-ai-booking-appointments' ),
			'manage'           => __( 'Manage booking', 'dev3-ai-booking-appointments' ),
			'bookAnother'      => __( 'Book another', 'dev3-ai-booking-appointments' ),
			'reference'        => __( 'Reference', 'dev3-ai-booking-appointments' ),
			'policy'           => __( 'Cancellation policy', 'dev3-ai-booking-appointments' ),
			'myBookings'       => __( 'My bookings', 'dev3-ai-booking-appointments' ),
			'upcoming'         => __( 'Upcoming', 'dev3-ai-booking-appointments' ),
			'past'             => __( 'Past', 'dev3-ai-booking-appointments' ),
			'noBookings'       => __( 'You have no bookings yet.', 'dev3-ai-booking-appointments' ),
			'noUpcoming'       => __( 'No upcoming bookings.', 'dev3-ai-booking-appointments' ),
			'guestText'        => __( 'Open the link in your confirmation email to view, reschedule or cancel a booking, or log in to see all your bookings.', 'dev3-ai-booking-appointments' ),
			'login'            => __( 'Log in', 'dev3-ai-booking-appointments' ),
			'allBookings'      => __( 'All bookings', 'dev3-ai-booking-appointments' ),
			'reschedule'       => __( 'Reschedule', 'dev3-ai-booking-appointments' ),
			'cancel'           => __( 'Cancel booking', 'dev3-ai-booking-appointments' ),
			'keep'             => __( 'Keep booking', 'dev3-ai-booking-appointments' ),
			'cancelTitle'      => __( 'Cancel this booking?', 'dev3-ai-booking-appointments' ),
			'cancelReason'     => __( 'Reason (optional)', 'dev3-ai-booking-appointments' ),
			/* translators: %s: amount. */
			'refundInfo'       => __( '%s will be refunded to your card.', 'dev3-ai-booking-appointments' ),
			'noRefund'         => __( 'Payments are not refunded this close to the appointment.', 'dev3-ai-booking-appointments' ),
			'cancelledMsg'     => __( 'Your booking was cancelled.', 'dev3-ai-booking-appointments' ),
			'rescheduledMsg'   => __( 'Your booking was moved.', 'dev3-ai-booking-appointments' ),
			'pickNewTime'      => __( 'Pick a new time', 'dev3-ai-booking-appointments' ),
			/* translators: %s: date. */
			'confirmMove'      => __( 'Move to %s', 'dev3-ai-booking-appointments' ),
			'paymentReceived'  => __( 'Payment received. Thank you!', 'dev3-ai-booking-appointments' ),
			'paymentPending'   => __( 'We are waiting for your payment to be confirmed.', 'dev3-ai-booking-appointments' ),
			'paymentCancelled' => __( 'Payment was not completed. You can book again.', 'dev3-ai-booking-appointments' ),
			'cannotChange'     => __( 'Online changes are not available for this booking. Please contact us.', 'dev3-ai-booking-appointments' ),
			'paid'             => __( 'Paid', 'dev3-ai-booking-appointments' ),
			'unpaid'           => __( 'To pay at the appointment', 'dev3-ai-booking-appointments' ),
			'refunded'         => __( 'Refunded', 'dev3-ai-booking-appointments' ),
			'close'            => __( 'Close', 'dev3-ai-booking-appointments' ),
			'bookNow'          => __( 'Book an appointment', 'dev3-ai-booking-appointments' ),
			'selected'         => __( 'Selected', 'dev3-ai-booking-appointments' ),
		);
		$s['status'] = Bookings::status_labels();
		return $s;
	}
}
