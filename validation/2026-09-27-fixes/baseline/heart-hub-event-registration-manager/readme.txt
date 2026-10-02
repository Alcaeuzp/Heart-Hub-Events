=== Heart Hub Event Registration Manager ===

Requires at least: 6.4

Tested up to: 7.1.1

Requires PHP: 7.4

Stable tag: 1.33.0

License: GPLv2 or later



Private WordPress admin dashboard for reviewing JetEngine CCT event applications for Heart Hub South West.



Developed by Paul Els.

== Features ==

* Reads existing Event Registrations through JetEngine's supported CCT API.

* Loads the Event filter directly from WordPress posts registered under `post_type=events`.

* Creates draft or published `events` posts through a dedicated, validated admin form mapped to the existing JetEngine event fields.

* Lists and edits existing `events` posts without exposing JetEngine's native CPT editor.

* Manages one-off and recurring opening hours, community visits, speeches, and other calendar entries from a dedicated schedule screen.
* Provides a plugin-owned month calendar through `[hherm_events_calendar]`, with AJAX month navigation, browser history support, event-detail popups, and backend display settings for colors, width, and spacing.
* Provides `[hherm_fundraisers_calendar]`, the same calendar limited to fundraising events and raffles. It reaches back 36 months by default so past fundraisers stay on record; override with `months_behind="60"` (up to 120). `title` and `show_title` work as on the events calendar.
* Calculates recurring dates for the month being viewed, including daily, selected weekdays, a day of each month, selected dates, and multiple time slots, without generating thousands of event posts.

* Assigns and manages the existing `event-category` taxonomy through the plugin interface.

* Configures confirmed Website Registration events with one authoritative Registration enabled switch, while External Registration uses its saved URL automatically and No Registration displays no registration CTA.

* Locks website registration for Date TBC events and optionally replaces Register Now with a Contact Us-for-interest button until the date is confirmed.

* Supplies JetEngine-template shortcodes for the event date, registration/contact CTA, remaining spots, and What to Expect section with backward-compatible defaults.

* Separates fundraising events into a dedicated Fundraisers admin screen while retaining the shared Events post type, registration workflow, and categories.

* Captures fundraiser costs and optional raffle pricing at creation, then records the authoritative `amount_raised` value after creation and shows funds raised plus confirmed attendance in the fundraiser list and overview.

* Supplies rolling-period and per-event shortcodes for funds raised, people attending, and events run.

* Manages six-meeting online support group programmes, including programme names, dates, start times, and optional end times.

* Displays the selected programme with a responsive date-and-time shortcode, then replaces the full date list with an underway and expression-of-interest message when registration closes.

* Provides separate shortcodes for the selected support programme’s name and first meeting date so page builders can position and style those details independently from the form.

* Supplies a registration-availability shortcode that returns `true` until the selected programme's first meeting starts and `false` from that cutoff onward.

* Allows the next programme to become the public registration schedule while an earlier six-month programme is still running.

* Provides a plugin-owned support-group form shortcode with first name, last name, email, optional phone, previous-attendance choice, and a conditional reason-for-joining field. At the first-meeting cutoff, the form automatically becomes an expression-of-interest form for the next programme.

* Keeps the support-group expression-of-interest form available when no future programme has been created, and provides a dedicated cross-programme Expressions of interest admin section.

* Automatically confirms returning attendees, holds first-time attendees for review, and sends the matching registration email.

* Stores support-group applicants in a dedicated plugin-owned table and displays them from the programme editor without requiring JetFormBuilder.

* Opens each support-group applicant in a dedicated detail view with their full submission, decline action, and a two-step permanent-deletion confirmation.

* Separates the programme list from create/edit screens, places upcoming programmes first, and requires an edit-page deletion flow with a final permanent-deletion confirmation.

* Organises the full-width Settings screen into Emails, Appearance, Contact & Access, Event Setup, and Shortcodes tabs.

* Reserves capacity immediately for pending registrations using their configurable attendee count.

* Keeps approved reservations in place and returns capacity exactly once when an application is declined.

* Prevents over-capacity registrations and protects capacity changes with short-lived event locks.

* Places the complete registration on the waitlist when it will not fit and the event has `allow_waitlist` enabled.

* Filters waitlisted applications in the admin and allows approval only after enough capacity becomes available.

* Provides an event-specific attendance register for approved registrations with name, phone, email, reason, and group size.

* Records `attendance_status` as `attended`, `no-show`, `partial`, or `unknown`, with audited bulk updates.

* Generates revocable event-specific QR check-in links that open at the event start and expire one hour after the event ends.

* Lets approved attendees verify with their registration email, self-record attendance, and submit optional feedback.

* Shows the same staff-directed register error when the email is not registered for that event or the registration has not been approved.

* Protects public check-in with unguessable tokens, expiry enforcement, nonces, a honeypot, generic failures, layered hashed-IP rate limiting, and anti-framing/privacy headers.

* Stores feedback on the registration CCT record using the configurable `event_feedback` field key.

* Provides a Feedback & Analytics admin screen with attendance totals and event-level feedback review.

* Lets administrators enable or disable approval, decline, waitlist, cancellation, and feedback emails independently and tune each delay in minutes, hours, or days.

* Queues delayed event-triggered messages without storing recipient data in cron arguments, revalidates the registration before delivery, and retries transient transport failures.

* Runs an hourly, idempotent post-event check and sends attended or partially attended registrations a feedback request after the configured delay when no response exists.

* Provides secure 90-day attendee-specific feedback links with rating and optional comments.

* Provides shared temporary-page settings for the header logo/text, footer, page/card/text/button/border colours, and principal check-in, feedback, and attendance-management copy.

* Uses the same sanitized theme across check-in, feedback, and registration-management links, with cache-safe assets and contrast warnings in Settings.

* Initializes hidden `current_event_capacity` from `event_capacity` on creation and maintains it without exposing an editable field.

* Recalculates hidden current capacity to the new total when an unpublished event with no reservations is edited.

* Enforces event publication, registration-enabled, open/close, cancellation, and capacity rules on the server before accepting a form registration.

* Cancels events without deleting them, disables new registrations, and emails or queues pending, approved, and waitlisted registrations.

* Provides an editable, previewable event-cancellation email and safely retries only recipients whose real email was not already sent.

* Adds a Media Library header-icon picker and renders the selected icon in every branded email header.

* Provides an optional reusable rich-text signature inserted with `{signature}` or `[email_signature]`.

* Creates and edits the `parking_access` textarea and JetEngine `what_to_expect` repeater with `title`/`content` subfields.

* Limits What to Expect to six complete entries with matching browser and server validation.

* Lists applications with protected server-side pagination.

* Searches applicant names and email addresses.

* Filters by event, status, and registration date range, with date sorting.

* Shows the complete applicant and related event details in an accessible modal.

* Captures the JetFormBuilder `dietaryrequirements` and `please_let_us_know` fields and shows them on the applicant profile, where administrators can correct them.

* Adds the Heart Hub South West admin design system across the plugin screens.

* Provides registration stat cards, quick filters, row selection, bulk approval/decline, and CSV export.

* Combines QR generation, the attendee-page preview, protected live check-in polling, and the attendance register on one Attendance & Check-in screen.

* Sends and previews a dedicated waitlist email template with a party-size placeholder.

* Approves or declines pending applications and records reviewer/date/notes.

* Sends or safely logs branded decision emails without emailing on view.

* Provides editable approval, decline, waitlist, cancellation, and post-event feedback subjects and rich-text bodies with data placeholders.

* Organises email templates into tabs with a WYSIWYG editor beside the rendered email and an independent save action for each template.

* Sends or safely logs one shared internal-notification template for every new event registration, event expression of interest, support-group registration, and support-group expression of interest, with complete submitted details and a direct admin record link.

* Provides a configurable internal recipient and immediate test-email action under Settings > Emails.

* Shows each event applicant’s complete email-matched registration history from oldest to newest, including registration decision, attendance/no-show state, party size, check-in, and mapped payment details.

* Provides sandboxed rendered email previews and safe logo/colour controls.

* Records decisions and email outcomes in a plugin-owned audit table.



== Installation ==

1. Upload this directory to wp-content/plugins and activate it.

2. Open Heart Hub Events > Settings > Event Setup and enter the live Events CPT, relation ID, and event meta keys.

3. Confirm the default capacity keys: CCT `number_of_attendees` and event meta `current_event_capacity`.

4. Select the nominated owner role. That role can review registrations and manage events when it also has the Events post type's create/edit capabilities. Administrators receive the plugin capability automatically.

5. Open Heart Hub Events > Registrations in the WordPress admin menu to access the application dashboard.

6. Use Heart Hub Events > Events to view or edit existing event posts, and use the Create Event button there to add one.

7. Use Event Categories under Heart Hub Events > Settings > Event Setup to manage the existing `event-category` taxonomy. Fundraising Event, Community Outreach, and Mindfulness Workshop are locked because site templates and workflows depend on them; new categories may require dynamic configuration changes.

8. Use Heart Hub Events > Attendance & Check-in to select an event, generate its QR link, monitor live check-ins, and record attendance for approved registrations.

9. On the Events CPT, add `amount_raised` as Number and add these exact Switcher field IDs: `raffle`, `co_hosted_event`, `show_what_to_expect`, `registration_enabled`, `show_remaining_spots`, `allow_waitlist`, and `show_interest_contact_button`. Store `true` when checked and `false` when unchecked so Elementor Dynamic Visibility reads the same values written by the plugin. Do not use the retired `show_register_now_button` field.

10. Add the CCT textarea field `event_feedback`, numeric field `feedback_score`, datetime/text field `checked_in_at`, text/select field `dietaryrequirements`, and textarea field `please_let_us_know`, or configure the feedback text key in Heart Hub Events > Settings. The two dietary field IDs must match exactly. If payment data is already stored per registration, map its amount, status, and method field keys under Event Setup; the plugin displays those values but does not process payments.

11. Configure automatic email timings, the internal notification recipient, and templates under Settings > Emails. Save the recipient, then use Send test email before switching to live sending. Temporary attendee-page branding remains under Settings > Appearance.

12. Generate an event-day QR link from Attendance & Check-in; feedback is reviewed under Feedback & Analytics.

13. Add the JetFormBuilder Call Hook `heart_hub_registration_created` to form 2774 before its CCT insert action. In the CCT insert action, map `dietaryrequirements` to the same-named CCT field and map `please_let_us_know` to the same-named CCT field.

14. Under Heart Hub Events > Support Groups, create a programme with all six meeting dates and select Use this programme. Add `[hherm_support_groups]` to the schedule area, `[hherm_support_group_name]` and `[hherm_support_group_start_date]` wherever you want the programme details, `[hherm_support_group_registration_form]` to the registration popup, and optionally use `[hherm_support_group_registration_open]` as a dynamic visibility value.

15. Leave Email mode as Log only until dry-run output has been approved.



The plugin creates its own audit table and support-group applicant table. During normal operation it does not alter or delete the Events CPT, Event Registrations CCT, JetFormBuilder forms, pages, listings, or queries.



The management dashboard is available only inside `/wp-admin/` to logged-in users with the dedicated event-registration capability. The plugin does not create a competing WordPress frontend page; its event-display shortcodes are intended for the existing JetEngine cards and single-event template. It also renders temporary standalone check-in, registration-management, and feedback views only for valid bearer links. The check-in view opens at the event start and closes one hour after the event ends; management and feedback links enforce their own expiry windows.



Use Heart Hub Events > Settings > Emails to edit automatic delivery, event and support-group templates, adjust safe brand colours/logo, and view each exact rendered preview beside its editor. Template placeholders insert authoritative applicant, event, and support-programme data at send time.



The Create Event address field reads the Google Maps API key and geocoding provider configured in JetEngine Maps Settings. When the browser key is available, the field provides Google address suggestions; on save, the address is checked through JetEngine's configured geocoder before it is stored as the existing JetEngine location-address string. If the Maps module is unavailable, the field falls back to full-address entry and preserves the existing workflow.



== Uninstallation ==

Use WordPress Plugins > Installed Plugins > Delete after deactivating the plugin. WordPress will run `uninstall.php` and remove only this plugin's settings (including email timing and temporary-page appearance), scheduled-email jobs, temporary review/capacity locks, temporary check-in/management/feedback links and rate limits, custom role capability, audit table, and support-group applicant table. The two plugin-owned tables are deleted because they can contain applicant personal information and email content.



QRCode.js is bundled locally under its MIT licence so QR generation does not send attendee or event data to an external service.



Uninstallation never deletes or modifies the Events CPT, Event Registrations CCT or its records, JetEngine relations, JetFormBuilder forms, WordPress pages, Query Builder queries, or JetEngine listings.

Calendar Schedule entries, their custom fields, and the `Calendar Schedule` event category remain with the existing Events CPT data after plugin uninstall.



== Changelog ==

= 1.33.0 =

* The old standalone Email Templates, Event Categories and Check-in Page screens now redirect to Settings › Emails, Settings › Event Setup and Attendance & Check-in, where the same tools already live. Old bookmarks keep working.
* Settings is now the last item in the Heart Hub Events menu, after Calendar Schedule and Calendar Settings.
* Settings › Shortcodes now lists all 17 shortcodes, including the two calendars and the event fee.
* Events and Fundraisers list actions are a tidy two-column block (Overview, Edit, View page, Cancel), with the cancel action styled as a quiet red link.
* Event overview shows readable labels and coloured pills ("Pending review", "Partially attended", "Published") instead of raw values, and its "Event unavailable" screen is styled with a way back.
* Support Groups screens use the same right-hand Back button as the rest of the admin.
* Plain-language page descriptions replace technical names (custom post type, JetEngine fields, CCT item).
* Confirmation prompts use one shared script instead of inline handlers, so messages with apostrophes can't break them.
* Admin fonts load as their own stylesheet instead of blocking the plugin's CSS.
* Narrow-screen layouts switch at WordPress's own 782px breakpoint on every screen.

= 1.32.0 =

* Fixed: the "Event cancelled" notice always reported 0 emails sent and 0 failed, so failed cancellation emails went unnoticed. It now shows the real counts, and error messages can no longer be altered through the URL.
* Finished events and fundraisers no longer offer "Cancel event" (which would have emailed everyone), and the cancel handler refuses them too. Fundraisers say "Cancel fundraiser".
* Registrations "Export CSV" now exports every application matching the filters, not just the 20 on screen.
* Feedback & Analytics and the Check-in page no longer list Calendar Schedule entries (opening hours, visits) as events, and Analytics leaves out events that have not happened yet.
* WordPress notices (Event saved, Settings saved, etc.) now appear below the page header instead of inside it.
* The registration review dialog's capacity bar, headings and messages are styled, and the page behind it no longer scrolls. The event overview's applicant details are styled again.
* Wide tables scroll sideways on smaller screens instead of being clipped; the attendance status buttons stay reachable.
* The Create/Edit event screen keeps the Heart Hub Events menu open and has a proper browser tab title.
* "Revoke & regenerate" check-in link now asks for confirmation.
* Layout fixes: Support Groups tabs, Calendar Settings and the Settings page line up with the page column; the Settings tabs no longer show a stray scrollbar; the Attendance screen shows a clear "Choose an event" prompt; all six Registrations filters fit on one row.
* Darker admin accent blue so button text and labels meet contrast guidelines.
* Status labels read "Published" rather than "Publish"; upcoming fundraisers show "—" attendees; plural wording fixed ("1 open event", "1 programme").
* Smaller fixes: saved/cancelled notices no longer repeat on page 2 of a list, missing labels added to date and heading inputs, a redirect in the Support Groups editor no longer runs after output, and a background calendar check runs hourly instead of on every admin request.

= 1.31.0 =

* Calendar redesign to match the site: Instrument Sans text with Literata month headings, the brand blue, round navigation buttons, and the grid in a rounded card with single hairlines (theme table borders no longer double up).
* Events show as soft rounded blocks with a coloured dot. Fundraisers have their own orange colour, and raffles carry a Raffle tag.
* A colour key above the grid lists the categories shown that month.
* Opening hours show as one slim line, in the grid and in the phone list, so they no longer crowd out events.
* Event popups have icons for date, time and venue, a registration status badge, and rounded Register and Event details buttons.
* The phone list shows each day as a date tile beside event cards.
* `[hherm_fundraisers_calendar]` shows a "Next fundraiser" banner with a link to the event, and a Fundraisers & Raffles label by default.
* New Fundraiser marker colour in Calendar Settings, and a new default "Heart Hub" font option. Saved colours and fonts that were still on the old defaults move to the new design automatically; customised values are kept.

= 1.30.0 =

* Added the `[hherm_fundraisers_calendar]` shortcode: a calendar showing only fundraisers and raffles, including past ones (36 months back by default, adjustable with `months_behind`).
* Past events on both calendars now show as Completed with the date they finished and the event name, greyed out, with no popup, link or registration details.

= 1.29.0 =

* Calendar on phones (767px and narrower) now shows a readable month grid with coloured event dots and a full event list below it. Tapping a day jumps to that day in the list. Previously event text was 8.5px and the event popup was clipped out of view.
* Calendar ignores theme table striping, so alternate weeks are no longer greyed out and the today highlight shows on every row.
* Months with no events say so and link to the next upcoming event.
* Event titles wrap to two lines (three on tablets) instead of truncating, and event labels are larger.
* The grid shows only the weeks each month needs, and events on the leading and trailing days of neighbouring months are shown.
* Event popups and the phone list show the registration state (open, opens on a date, fully booked, waitlist, closed, external or cancelled) with a Register, Express interest or Join the waitlist button where available.
* Cancelled events are marked as cancelled rather than looking like normal events.
* Multi-day events appear on every day they run, labelled From, Continues all day and Until.
* After changing month or tapping a day, the calendar scrolls to sit below any sticky site header.

= 1.28.6 =

* Attendance register saves only the rows staff changed. Attendee self check-ins made after the register was opened are no longer reset to Unrecorded; conflicting edits are skipped and reported. The event selector button no longer reads "Save register".
* Each staff decision now sends its own approval or decline email. Previously, a registration re-reviewed after the attendee edited their booking received no email.
* Submitting the attendance management form without changes keeps the approved place instead of returning it to pending review.
* Public expression-of-interest and support-group forms accept a signed 30-day form token for logged-out visitors, so pages served from a page cache no longer reject valid submissions. Expired or forged forms show a clear refresh message.

= 1.28.5 =

* Ensures calendar typography settings take precedence over theme styling within the calendar.

= 1.28.4 =

* Adds calendar typography controls for font family, size, weight, style, line height, letter spacing, and primary text color.

= 1.28.3 =

* Loads adjacent calendar months with AJAX and updates the browser URL without reloading the page. Browser back and forward restore the selected month.

= 1.28.2 =

* Adds calendar color, maximum-width, and spacing controls to Calendar Settings.

* Prevents the active theme's button hover colors from overriding calendar event entries.

= 1.28.1 =
* Fixed ordinal labels in monthly repeat date options (for example, 21st and 31st).

= 1.28.0 =
* Added the plugin-owned `[hherm_events_calendar]` shortcode and replaced the Events page JetEngine Dynamic Calendar with it.
* Added recurring schedules for daily, selected weekdays, monthly day numbers, and individually selected dates, with up to 12 shared time slots per schedule.
* Added hover, keyboard-focus, and tap event-detail popups and backend controls for the calendar heading, week start, navigation range, event times, and popups.
* Kept recurrence rules in one schedule record and calculate occurrences only for the month being displayed; the calendar can navigate up to three years ahead by default.

= 1.27.0 =
* Added a Calendar Schedule admin screen for dated opening hours, community visits, speeches, and other entries using the existing Events CPT and JetEngine date/location fields.
* Added a protected Calendar Schedule event category and subtype metadata so calendar entries can be queried and displayed separately.
* Added date, time, location, and optional description beneath Calendar Schedule entry titles in the existing Elementor calendar listing card.

= 1.26.0 =
* Added a dedicated support-group Expressions of interest view and retained interest capture when no future programme exists.
* Added configurable, template-driven staff notifications for every new event or support-group registration and expression of interest, with direct record links and a real test-send action.
* Added chronological customer event history to registration profiles, including declined and no-show records plus optional mapped payment data.

= 1.25.1 =
* Kept the Heart Hub Events admin menu expanded on hidden create, edit, check-in, category, and email-template screens.
* Highlighted the owning menu section on hidden routes, including Fundraisers while creating or editing a fundraiser.

= 1.25.0 =
* Australian dates and times across plugin displays, emails and editor fields.
* Early registration now offers a basic expression-of-interest form with stored applications and customer/team notifications.
* Dietary answers persist to the customer profile; event filter titles decode correctly.
* Events and fundraisers support native featured images; fundraisers can display an external entry fee and link.
* Added registration date range filtering and clarified expression-of-interest status.

= 1.24.3 =

* Stored newly created and edited event start/end dates as the Unix timestamps expected by JetEngine fields with Save as timestamp enabled, and migrated valid legacy date strings automatically.
* Kept timestamp-backed event dates editable and compatible with the plugin’s lists, emails, check-in expiry, attendance, and registration views.
* Passed `dietaryrequirements` and `please_let_us_know` through the registration hook, exposed both fields on applicant profiles, and added protected administrator editing.

= 1.24.2 =

* Kept the email-template screen at its existing scroll position when switching between template tabs, with a template-section anchor as a fallback.
* Removed sticky positioning from the email-template tab bar and rendered email preview.

= 1.24.1 =

* Added a dedicated support-group applicant detail screen with contact information, application metadata, previous-attendance answer, and the full reason for joining.
* Added a decline action that retains the applicant record with a clear Declined status.
* Added applicant deletion through a separate confirmation screen and required confirmation checkbox; only the selected applicant record is removed.

= 1.24.0 =

* Connected support-group confirmation, review, and expression-of-interest emails to the plugin’s shared branded email renderer, logo, icon, colours, signature, and editable template settings.
* Rebuilt Email Templates as tabs for Brand, Signature, each event email, and each support-group email.
* Each template now presents its WYSIWYG editor on the left and its rendered email preview on the right, with its own Save template button.
* Added partial template saves so editing one email cannot reset or overwrite other email templates.

= 1.23.5 =

* Fixed AJAX support-group form submissions resolving the hidden `action` field as the request URL, which produced a `[object HTMLInputElement]` 404 in browsers.

= 1.23.4 =

* Made the embedded support-group form container transparent so its background can be controlled entirely by its Elementor widget or parent container.

= 1.23.3 =

* Removed the programme title and first-meeting introduction from the built-in registration form so Elementor can position those elements independently.
* Added `[hherm_support_group_name]` and `[hherm_support_group_start_date]` for the selected programme.
* Enqueued the namespaced public form assets early on front-end requests so Elementor shortcode widgets receive the complete form styling, conditional-field behaviour, and hidden spam field.

= 1.23.2 =

* Updated `[hherm_support_groups]` to replace the entire meeting-date list once the first meeting starts with a current-programme-underway message directing visitors to the expression-of-interest form.
* Added an `underway_text` shortcode attribute for customising the expression-of-interest prompt while keeping the cutoff aligned with the registration form.

= 1.23.1 =

* Made the built-in support-group form automatically switch from registration to an expression of interest when the selected programme reaches its first-meeting cutoff.
* Added distinct expression-of-interest records, acknowledgement emails, success messaging, and admin labels while retaining the registration-visibility shortcode.
* The same email address can submit one registration and one later expression of interest for a programme, while duplicate submissions of the same type remain blocked.

= 1.23.0 =

* Rebuilt Support Groups as a list-first admin workflow with upcoming programmes at the top and a separate Add/Edit programme screen.
* Limited programme-list actions to Edit. Programme deletion now requires entering the editor, opening a dedicated confirmation screen, and explicitly confirming permanent removal.
* Added per-programme applicant review with names, contact details, previous-attendance answers, reasons, registration state, email delivery state, and submission time.
* Replaced the support-group JetFormBuilder dependency with `[hherm_support_group_registration_form]`, a responsive plugin-owned form styled to match the supplied pale-blue form reference.
* Added conditional reason-for-joining disclosure, nonce and honeypot protection, hashed-IP rate limiting, duplicate-email prevention, and server-side registration-cutoff enforcement.
* Returning attendees are confirmed automatically; first-time attendees are marked for review. Each receives the appropriate registration email.

= 1.22.0 =

* Added a Support Groups admin screen for creating and retaining multiple six-meeting online programmes, with an optional JetFormBuilder form ID.
* Uses the selected programme's first meeting start as the registration cutoff, returning `true` beforehand and `false` from that time onward through `[hherm_support_group_registration_open]`.
* Added `[hherm_support_groups]` to render the selected programme in the supplied date/time-list style while automatically omitting meetings after their calendar dates pass.
* Lets administrators switch the public registration schedule to a future programme while an earlier cohort is still meeting.
* Enforces the selected programme's cutoff on its configured JetFormBuilder form as well as hiding the public button, preventing late submissions through a direct form URL.

= 1.21.2 =

* Allowed website registration to remain open after an event has started while retaining the opening-before-closing check.
* Preserved the complete submitted Create/Edit Event form after server-side validation errors using a short-lived draft scoped to the current user.
* Added inline validation guidance that scrolls to, highlights, and focuses the field or area requiring attention.

= 1.21.1 =

* Linked every active Create/Edit Event toggle to a documented JetEngine Events-post-type Switcher field using the same post-meta key and `true`/`false` values.
* Expanded the configuration checker to report missing or incorrectly typed fields for Raffle, Co-hosted event, Show What to Expect, Registration enabled, Show Remaining Spots, Allow waiting list, and Show Contact Us button for interest.
* Documented the exact field IDs used by Elementor and JetEngine Dynamic Visibility. The retired `show_register_now_button` field remains unused; `registration_enabled` is authoritative.

= 1.21.0 =

* Removed the redundant Show Register Now Button toggle. Confirmed Website Registration events now use Registration enabled as the single source of truth for the Register Now CTA.
* Automatically enables Registration enabled when the user selects Website Registration or changes a Website Registration event from Date TBC to Date Confirmed. The user can still turn registration off manually afterward.
* Locks Registration enabled, remaining spots, and waiting-list controls off for Date TBC Website Registration events, with the message “As this event has no confirmed date, registration is locked.”
* Added Show Contact Us button for interest for Date TBC Website Registration events. It is disabled and cleared again when the date is confirmed.
* External Registration now displays its external CTA automatically while storing internal registration controls as off. No Registration displays no registration CTA.

= 1.20.3 =

* Defaulted Show Register Now Button and Show Remaining Spots off for new events while retaining Website Registration as the default registration type.
* Limited both switches to Website Registration in the form, save handler, event overview, and public shortcode output. External Registration and No Registration clear and disable both settings.
* Shortened the registration-type helper to “Website Registration is used when no type is supplied.”

= 1.20.2 =

* Locked the Fundraising Event, Community Outreach, and Mindfulness Workshop foundational categories in the plugin's category manager.
* Replaced their Edit/Delete actions with a Locked label and enforced the same restriction in the category update and deletion handlers. The categories remain assignable and their events remain editable.

= 1.20.1 =

* New fundraising events automatically receive the Fundraising Event category without showing the additional Categories selector.
* Extra categories remain available while editing an existing fundraiser, and new-fundraiser submissions ignore any manually supplied additional category IDs.

= 1.20.0 =

* Removed Amount raised from new-fundraiser creation; it is now available only while editing an existing fundraiser, when the final result can be recorded.
* Reordered Sponsorship cost and Registration fee onto one row, added an optional validated Raffle entry cost, and clarified that a blank cost means the raffle is simply available.
* Changed fundraiser amount displays and money inputs from pounds to dollars, including the fundraiser summary, list, and overview.
* Made confirmed-event start/end values genuinely optional in both the browser and save handler, and allowed a missing Registration Type to fall back to Website Registration.
* Defaulted Show What to Expect off for newly created events.
* Restyled the Event Type selector, kept required markers inline with their labels, and corrected the co-hosted switch layout and label colour.

= 1.19.6 =

* Registered Create Event and the other non-sidebar screens as directly accessible hidden admin routes instead of registering and then removing submenu entries.
* Fixed WordPress rejecting the Create Event URL before its capability-protected editor callback could run.

= 1.19.5 =

* Allowed configured owner roles to open the Events, Fundraisers, and Create Event routes instead of hitting WordPress's generic access-denied page.
* Retained the Events custom post type's own create/edit capability checks and kept Settings and event-category administration restricted to administrators.

= 1.19.4 =

* Funds-raised shortcodes now output the formatted number without any currency symbol. Add your preferred symbol directly before the shortcode in page content.
* Removed the currency shortcode attribute and updated the shortcode guidance. Decimal formatting and calculations are unchanged.

= 1.19.3 =

* Addressed Plugin Check findings for input sanitization, escaped form errors, prepared audit-table identifiers, and stylesheet/script loading.
* Kept existing class names, bearer links, admin filters, attendance rules, and registration workflows compatible; documented intentional read-only and plugin-owned database operations.
* Normalized PHP line endings and added the tested WordPress version reported by the site owner.
* Added a repeatable release build that excludes development notes, tests, tooling, and previous ZIP archives from the installable plugin.

= 1.19.2 =

* Corrected the Fundraising Event category lookup to use the live `fundraising-event` taxonomy slug.
* Kept the internal fundraising type identifier stable so existing editor submissions and plugin logic remain compatible.

= 1.19.1 =

* Completed pre-upload validation under PHP 7.4.33 and PHP 8.5.10, including full-plugin bootstrap and focused workflow tests.
* Declared optional check-in and email-service constructor dependencies explicitly nullable to avoid PHP 8.5 deprecation warnings.
* Repaired and expanded the regression harness for settings tabs, menu routing, plugin bootstrap, event display dependencies, and modern Reflection behavior.

= 1.19.0 =

* Rebuilt Settings as a full-width tabbed screen with Emails, Appearance, Contact & Access, Event Setup, and Shortcodes sections.
* Moved Email Templates into the Emails tab and removed its redundant sidebar entry while preserving the old route for bookmarks.
* Added an in-plugin reference for all impact and event-display shortcodes.
* Kept every core setting in one safe form so saving a tab does not reset values shown on another tab.

= 1.18.0 =

* Combined the attendance register, event-day QR controls, attendee-page preview, and live check-in feed under Attendance & Check-in.
* Removed the redundant Check-in Page and Create Event sidebar entries while retaining their internal routes for existing links.
* Moved Event Categories into Settings and added a warning that new categories may need help with the dynamic event configuration.

= 1.17.0 =

* Added a dedicated Fundraisers admin screen that lists only fundraising-category events; the Events screen now excludes those records.
* Added the `amount_raised` event meta field to the fundraiser editor, list, overview, validation, and JetEngine readiness check.
* Added rolling 12-month shortcodes for total fundraiser income, confirmed attendees, and events run.
* Added per-event shortcodes for fundraiser income and confirmed attendees, using QR/form check-in headcounts where available.

= 1.16.0 =

* Added per-event Website, External, and No Registration modes, including external URLs and an optional Contact Us CTA.
* Added independent Register Now, remaining-spots, and What to Expect display switches with backward-compatible ON defaults.
* Added Date Confirmed and Date TBC editing while retaining the existing scheduled/TBA metadata compatibility layer.
* Added front-end shortcodes for event date, registration CTA, remaining spots, and What to Expect so JetEngine templates can use the same rules.
* Hid Past Events and Past Fundraisers from the standard event category picker while preserving those lifecycle categories on edit.

= 1.15.0 =

* Added Scheduled and Date to be announced event states, optional expected month, and date-optional event creation.
* Added a JetFormBuilder expression-of-interest hook that stores interest records in the existing registration CCT and relation without reserving capacity.
* Added editable, delayed and audited date-confirmed emails for interested customers when a TBA event is scheduled.
* Added a JetEngine and JetFormBuilder setup guide for the new fields, visibility rules, form and test flow.

= 1.14.0 =
* Event type uses the existing event-category taxonomy: Workshop (standard/free) defaults for new events; Fundraising reveals registration_fee and sponsorship_cost text fields and the raffle switcher.
* Create categories with slugs workshop and fundraising-event, or names Workshop and Fundraising Event/Fundraising. Missing categories produce a clear save error rather than assigning an invented ID.
* Existing events with neither or both type categories default to Keep existing type/categories. Switching type preserves other categories, including Past Events. Fundraising metadata remains stored when switching to Workshop and reloads if switched back.
* Add JetEngine meta fields registration_fee (Text), sponsorship_cost (Text), and raffle (Switcher). View Event displays these details for Fundraising. These are descriptive fields; no payments are processed.
* Heart Hub Events admin screens show a readiness error with setup links until both event-type categories and all three fundraising fields are configured with those exact types.


= 1.13.0 =
* Create/Edit Event supports co_hosted_event (JetEngine Switcher) and presenters (JetEngine Textarea). The switch saves true/false strings and defaults off; presenters preserves multiple lines.
* View Event displays co-hosting status and presenters. Add these exact meta fields to the Events JetEngine configuration; use them in public templates as required.


= 1.12.0 =
* Clicking an event row opens View Event. Keyboard Enter/Space is supported, and embedded action links keep their own behavior.
* Open registration on View Event now opens the existing registration review/edit dialog in place. Saving details or decisions refreshes the event overview without navigating to Registrations.
* Analytics filters now live in a dedicated responsive card below the heading, with separate labels and actions. Export stays beside the heading.


= 1.11.1 =
* Event and category action buttons wrap inside their table cells instead of overflowing or being clipped.

= 1.11.0 =
* Check-in now first looks up the approved registration by email within the current event, then offers all reserved guests or a smaller attending party. Confirmation rechecks the email and reserved count. Lookup alone does not mark attendance.
* Settings > Feedback at check-in controls optional ratings/comments, off by default. Disabled feedback is ignored on the server as well as hidden from guests.
* The preview notice remains admin-only and preview submissions are disabled. Live check-in pages contain no preview notice.
* Events now has separate View Event (plugin overview) and View Page (public page) actions. The overview brings together event details, every registration status, attendance/headcounts, and all event-linked ratings, comments, and suggestions.
* Feedback & Analytics can show all events, one event, preset periods, or a custom inclusive event-date range in the site timezone. Custom dates override the period preset. Rating-only responses are included and comments are no longer limited to eight.
* Post-event thank-you/feedback email automation is retained. Configure its enabled flag/delay and Send real email mode in Settings. It is checked hourly, targets attended/partial registrations without feedback, and uses the existing event-specific, expiring feedback page.
* Data remains on existing event-linked registration records; no duplicate event data store or database migration is introduced. Unrecorded attendance remains separate from confirmed no-shows.




= 1.10.1 =

* Application search now matches name, email, or phone number, including partial numbers and numbers with spaces, brackets, or hyphens.



= 1.10.0 =

* Decline reasons are optional for single and bulk decisions. Blank reasons use: “We’re sorry, we’re unable to approve your registration for this event.”

* Open a registration and expand Edit applicant details to update name, email, phone, organisation, reason for attending, and party size. Save details before reviewing. Admin edits retain the current decision status and do not send a decision email.

* Missing, zero, or negative whole-number party sizes are normalised to one during registration, editing, and review. Lists show at least one. Fractions, nonnumeric values, and sizes above 1,000 are rejected.

* Reserved party-size changes adjust remaining capacity, prevent overbooking, and restore capacity if saving fails. Waitlisted and declined records without reservations do not consume places when edited.

* Party size is locked after check-in to preserve attendance capacity adjustments; contact details remain editable.





= 1.9.0 =

* Automatically appends Past Events (event-category slug past-events, expected ID 83) to published and private events once their configured end date/time is reached. Existing categories remain assigned.

* Checks every five minutes using WP-Cron, including existing past events after upgrade. Execution depends on site traffic or a server cron calling WordPress cron.

* Uses the site timezone for local date/time values and supports Unix timestamps. Missing or invalid end times are skipped. The category must already exist; its slug is used as a fallback if its ID differs.

* This is additive: rescheduling an already categorized event does not automatically remove Past Events. Remove that category manually when rescheduling.



= 1.8.0 =

* Added per-email automation enable switches and configurable delivery delays.

* Added shared branding, palette, and principal copy settings for temporary attendee pages.

* Hardened delayed-email jobs, registration gating, capacity rollback, bearer pages, check-in limits, dashboards, and CSV export.



== Important integration notes ==

The plugin now sends its own template-driven internal notification with the exact registration review link. After confirming delivery with Settings > Emails > Send test email and a staging submission, remove or disable any older JetFormBuilder admin notification to avoid duplicate staff emails. Decision and internal-notification dry runs are stored in the plugin audit table.
