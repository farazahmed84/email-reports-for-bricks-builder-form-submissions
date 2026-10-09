=== Email Reports for Bricks Builder Form Submissions ===
Contributors: farazahmed1
Tags: bricks builder, form submissions, csv export, email reports, scheduled reports
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically email one CSV per saved Bricks Builder form on a weekly schedule or for any manual date range.

== Description ==

Email Reports for Bricks Builder Form Submissions reads submissions already saved by Bricks Builder and sends them as CSV email attachments.

The plugin does not create forms or replace the Bricks Builder submissions screen. It adds scheduled and manual email reporting for existing saved submissions.

= Features =

* Automatically discovers every Bricks Builder form represented in the saved submissions table.
* Sends weekly reports on the weekday and time selected by an administrator.
* Supports manual reports for an inclusive date range and administrator-selected recipients.
* Creates one CSV attachment per discovered form.
* Sends all per-form CSV files in one email.
* Uses human-readable Bricks Builder form names and field labels when their metadata is available.
* Tracks scheduled exports independently for each form so submissions are not skipped.
* Advances scheduled checkpoints only after WordPress accepts the complete email through `wp_mail()`.
* Allows empty scheduled reports to be enabled or disabled.
* Leaves Bricks Builder submissions unchanged.

= Requirements =

* WordPress 6.0 or newer.
* PHP 7.4 or newer.
* The Bricks theme with forms that use the **Save submission** action.
* A working WordPress mail configuration.

= Scheduled reports =

Scheduled reports include new submissions from all discovered Bricks Builder forms. Each form has its own checkpoint containing the highest successfully exported submission ID.

When empty-report sending is disabled, forms without new submissions are omitted. If every form is empty, the run completes without sending an email. When empty-report sending is enabled, the email contains one CSV for every discovered form, including empty CSV files.

Scheduling uses WP-Cron in the WordPress site's configured timezone. WP-Cron is traffic-driven, so a report can run later than its configured time when the site receives no requests near that time.

= Manual reports =

Manual reports include every discovered Bricks Builder form for the selected inclusive date range. Each form receives its own CSV, including forms with no submissions in that period.

Manual reports never read, change, or advance scheduled checkpoints.

= CSV attachments =

CSV files use UTF-8 with a byte-order mark for spreadsheet compatibility. Each CSV starts with the submission ID and submission date, followed by the fields discovered for that form.

The plugin uses Bricks Builder field labels when possible, leaves missing values blank, formats array values with a readable separator, and includes file URLs, filenames, or attachment references for file fields.

Temporary files receive real `.csv` basenames before they are attached and are deleted after `wp_mail()` returns.

= Privacy and data handling =

Form submissions can contain personal data, including names, email addresses, telephone numbers, messages, uploaded-file references, and other information configured by the site owner.

This plugin reads submission data from the site's Bricks Builder submissions table and temporarily writes CSV files on the WordPress server. Those temporary files are deleted after the mail operation finishes.

Reports are sent only to email addresses entered by a user with the `manage_options` capability. Delivery uses the site's existing `wp_mail()` configuration and may pass through an SMTP or email service configured by the site owner.

The plugin does not send submission data to the plugin author and does not provide its own external mail or tracking service. Site owners are responsible for selecting authorized report recipients and complying with applicable privacy requirements.

== Installation ==

1. Install and activate the Bricks theme.
2. In Bricks, enable saved form submissions and add the **Save submission** action to the forms that should be reported.
3. Upload the plugin directory to `/wp-content/plugins/`, or install its ZIP through **Plugins > Add New Plugin > Upload Plugin**.
4. Activate **Email Reports for Bricks Builder Form Submissions**.
5. Open **Bricks Email Reports > Settings**.
6. Enter one or more recipients and select the weekly weekday and time.
7. Review the email subject, message, and empty-report setting, then save the settings.

Use **Bricks Email Reports > Manual Report** to send an on-demand report for an inclusive date range without changing scheduled checkpoints.

== Frequently Asked Questions ==

= Does this plugin store form submissions? =

No. It reads submissions already saved by Bricks Builder. It does not create, update, or delete Bricks Builder submission rows.

= Does it send an email for every individual submission? =

No. It sends consolidated CSV reports. Scheduled reports contain submissions that arrived after each form's successful checkpoint, while manual reports use the selected date range.

= Can I select only one form? =

No. Scheduled and manual reports intentionally include all discovered Bricks Builder forms. Each form receives a separate CSV attachment, and all attachments are sent in one email.

= Does the plugin provide SMTP settings? =

No. It uses WordPress' `wp_mail()` function. Configure an SMTP or mail-delivery plugin separately if the site requires one.

= Does a successful send guarantee inbox delivery? =

No. A successful result means WordPress accepted the message for sending. Final delivery depends on the site's mail configuration and receiving mail systems.

= What happens when there are no new scheduled submissions? =

When empty-report sending is disabled, no email is sent if every discovered form is empty. When it is enabled, one empty CSV is attached for every discovered form.

= Do manual reports affect the next scheduled report? =

No. Manual reports do not read or change scheduled checkpoints.

= Why might a scheduled report arrive late? =

WordPress WP-Cron runs when the site receives traffic. A low-traffic site may trigger the scheduled event after its configured time.

= Why do some CSV columns show field IDs? =

The plugin uses Bricks Builder field labels when the related form metadata can be found. A field ID is used only as a fallback when the form or field metadata is unavailable or malformed.

= What happens when the plugin is deleted? =

Deleting the plugin through WordPress removes only this plugin's settings, checkpoints, status, lock, and scheduled event. Bricks Builder submissions are not deleted.

== Changelog ==

= 1.0.0 =

* Initial release.
