=== DEV3 AI Booking & Appointments for WordPress ===
Contributors: dev3tech
Tags: booking, appointments, scheduling, calendar, ai
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Appointment booking with services, staff, schedules, payments, reminders, an admin calendar and DEV3 AI natural-language booking.

== Description ==

* Services with duration, price, deposits, group capacity, buffers, notice and advance limits.
* Staff with their own time zones, weekly hours, breaks, time off, special days and a private iCal feed.
* Business hours, closures and yearly holidays. DST-safe availability with customer time zones.
* Mobile-first booking widget (Gutenberg block + [dev3bk_booking] shortcode).
* Payments: pay at the appointment, Stripe Checkout (webhooks, refunds) and WooCommerce checkout (HPOS + block checkout).
* Customer area ([dev3bk_my_bookings]) with reschedule and cancel policies.
* Emails, reminders and follow-ups from editable templates, .ics files and Google Calendar links.
* Admin calendar (day, week, month), bookings list with CSV export, customers.
* DEV3 AI (Google Gemini, your own key): natural-language booking that returns only real free slots, AI-written reminder copy you approve, and a no-show risk hint.

== Installation ==

1. Upload the zip in Plugins › Add New › Upload Plugin and activate.
2. Open DEV3 AI Booking: add services, staff and hours.
3. The "Book an appointment" and "My bookings" pages are created for you.
4. Optional: add your Google Gemini API key in API Config.

== Frequently Asked Questions ==

= Does it need WooCommerce? =
No. WooCommerce is only used for checkout payments if you turn it on.

= Does it work without AI? =
Yes. Every feature works without a key.

== Privacy ==

When DEV3 AI is enabled, the booking request text typed by customers, email template text and anonymised booking facts are sent to Google Gemini. Customer data is included in WordPress's personal data export and erase tools.

== Screenshots ==

1. Dashboard with no-show watch
2. Admin calendar, month view
3. Admin calendar, week view
4. DEV3 AI no-show risk hint
5. DEV3 AI reminder copy review
6. Natural-language booking on a phone
7. Booking confirmation

== Changelog ==

= 1.0.0 =
* Initial release.
