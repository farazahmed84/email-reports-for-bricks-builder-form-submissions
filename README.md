# Email Reports for Bricks Builder Form Submissions

Email scheduled and manual CSV reports containing submissions saved by Bricks Builder forms.

[View the plugin on WordPress.org](https://wordpress.org/plugins/email-reports-for-bricks-builder-form-submissions/)

## Features

- Automatically discovers every form represented in the Bricks submissions table.
- Sends one CSV attachment per discovered form in a single email.
- Supports scheduled weekly reports and manual date-range reports.
- Tracks scheduled progress independently for each form.
- Uses human-readable Bricks form names and field labels when available.
- Preserves real `.csv` attachment filenames.
- Handles empty reports, Unicode, multiline values, arrays, and file fields.
- Uses WordPress `wp_mail()` and does not provide or require a proprietary email service.
- Never modifies or deletes Bricks submission records.

## Requirements

- WordPress 6.0 or newer.
- PHP 7.4 or newer.
- Bricks Builder forms with **Save submissions** enabled.
- A working WordPress mail configuration.

## Installation

### From WordPress.org

1. In WordPress, open **Plugins > Add New**.
2. Search for **Email Reports for Bricks Builder Form Submissions**.
3. Install and activate the plugin.
4. Open **Bricks Email Reports > Settings**.

### Manual installation

1. Download the plugin ZIP from the [WordPress.org plugin page](https://wordpress.org/plugins/email-reports-for-bricks-builder-form-submissions/).
2. In WordPress, open **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP, then install and activate it.

## Scheduled reports

Scheduled reports process all discovered Bricks forms. Each form has an independent checkpoint, and only submissions newer than that checkpoint are included. All affected checkpoints advance only after WordPress accepts the email through `wp_mail()`.

When **Send email when there are no new submissions** is disabled, forms without new entries are omitted. If every form is empty, no email is sent. When enabled, every discovered form receives a CSV attachment, including empty CSVs.

The schedule uses WP-Cron in the WordPress site timezone. WP-Cron is traffic-driven, so reports may run late when the site receives no requests near the configured time.

## Manual reports

Manual reports accept an inclusive start and end date and one or more recipients. They include one CSV per discovered form for the selected date range, including empty CSVs, and never read or update scheduled checkpoints.

## CSV behavior

- UTF-8 with a byte-order mark for Excel compatibility.
- One CSV attachment per form.
- Human-readable field labels when Bricks metadata is available.
- `Submission ID` and `Submitted At` as the first columns.
- Missing values remain blank.
- Arrays are joined with ` | `.
- Temporary report files are removed immediately after the mail attempt.

## Privacy and data handling

The plugin reads locally stored Bricks submissions solely to create requested email reports. It does not send data to the plugin author, provide public CSV download URLs, add analytics, or modify Bricks submission rows.

Email delivery is handled by the site's existing WordPress mail configuration. Site administrators are responsible for choosing appropriate recipients and protecting exported submission data.

## Support

Use the [WordPress.org support forum](https://wordpress.org/support/plugin/email-reports-for-bricks-builder-form-submissions/) for support requests.

## License

Licensed under the GNU General Public License v2.0 or later. See [LICENSE.txt](LICENSE.txt).
