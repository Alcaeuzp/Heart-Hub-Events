# Version 1.28.1 — Native calendar and repeating schedules — 23 September 2026

- Added the plugin-owned `[hherm_events_calendar]` shortcode and replaced the visible Dynamic Calendar on the Events archive with the native calendar. The Elementor event-card template no longer contains a calendar, avoiding one calendar per event.
- Added Calendar Settings for the displayed heading, week start, navigation range, event times, and event-detail popups. The default navigation window is 12 months back and 36 months ahead.
- Added Calendar Schedule admin screens for one-off and recurring entries. Repeats can be daily, on selected weekdays, on a selected day number each month, or on individually selected dates. Entries accept multiple time slots, a location, and optional description; repeats continue until unpublished or moved to Trash.
- Recurrence occurrences are calculated for the displayed month instead of creating one event post per occurrence, so the three-year navigation window does not expand into a large set of posts. Entries remain in the existing Events CPT under the Calendar Schedule category with registration disabled.
- Manually verified the live Events page at `https://wordpress-783633-6597969.cloudwaysapps.com/events/`: one visible native calendar is rendered; the October 2026 view shows the existing Pottery event with its time, and opening it displays its date, time, location, and event-details link. Verified the admin schedule list, add-entry screen, weekly weekday controls, monthly day selector, selected-date controls, time-slot controls, and settings page. No schedule records or sample entries were created.
- Installed and confirmed the plugin active at version 1.28.1 in live WordPress. The visible calendar heading and archive placement were published through Elementor.
- Built the runtime-only 1.28.1 ZIP with 64 files (226,432 bytes). SHA-256: `0DE935E577BF6F6B47050BE0CC2C98EFB4C5ED95B8007E6D6519F55575826844`.
- Automated tests, PHP lint, and JavaScript checks were not run.

# Version 1.27.0 — Calendar Schedule entries — 23 September 2026

- Added the Calendar Schedule admin screen for one-off opening periods, community visits, speeches, and other dated entries. Entries use the existing Events CPT, configured JetEngine start/end/venue/info fields, and a protected Calendar Schedule category.
- The public Elementor listing now adds each marked entry’s date/time, location, and optional description beneath its title. Existing regular event cards are not modified.
- Inspected the live Events archive configuration: its Dynamic Calendar groups by `start_date`, has no category restriction, and has multi-day handling disabled, so the new entries are included without a calendar query edit.
- Built the runtime-only 1.27.0 ZIP with 59 files. SHA-256: `CC7346472B6D45C5667DB75425490EF36BE15E91CECE12EC68431ED688258152`.
- PHP lint and the existing fixture suite were not run. The PHP CLI could not be launched in this environment.

# Version 1.26.0 — Support-group interest pool, staff notifications, and customer history — 22 September 2026

- Closed support-group programmes now collect expressions of interest through the existing public form. If no programme has been created or selected, the form stores the submission against a safe future-programme bucket instead of disappearing.
- Added a dedicated Support Groups > Expressions of interest view spanning every programme, with customer contact details, programme context, submitted reason, customer/staff email outcomes, and links to each complete applicant record.
- Added one configurable internal recipient and one reusable internal-notification template under Settings > Emails. Every new event registration, event expression of interest, support-group registration, and support-group expression of interest includes the submitted customer/item details and a direct administrator record link.
- Added a nonce- and capability-protected Send test email action. It deliberately attempts real delivery even when normal email mode is Log only, and its audit entry is not associated with a sample customer ID.
- Registration profiles now include an email-matched event history ordered from oldest to newest, retaining pending, waitlist, approved, declined, expression-of-interest, attended, partial, no-show, and unrecorded states. Optional amount/status/method CCT mappings expose existing payment data without treating an event fee as proof of payment.
- All 50 PHP source and fixture files pass `php -l` with PHP 8.3.33. All 20 executable PHP fixtures pass, including direct record links, configurable staff delivery, forced test delivery, support-group notification context, the no-programme interest form, chronological history, declined/no-show retention, and mapped payment values. All nine JavaScript assets pass `node --check` with Node.js 24.15.0.
- The 1.26.0 runtime-only ZIP contains 57 source-identical files under the correct plugin root and excludes tests, tooling, validation notes, and prior releases. SHA-256: `C8D9DD159B694A9D8CE278683499227865D2C8E1752D27DF639A4DFB946FD27C`.
- A live WordPress/JetEngine site and configured mail transport were not available for this final local pass. The support-table schema upgrade, WordPress admin presentation, SMTP/inbox delivery, and live CCT payment-field mappings still require staging verification before production rollout.

# Version 1.25.1 — Persistent plugin admin navigation — 22 September 2026

- Added WordPress `parent_file` and `submenu_file` mappings so the Heart Hub Events sidebar remains expanded throughout the plugin's visible and hidden admin routes.
- Create/edit fundraiser screens highlight Fundraisers; ordinary event screens highlight Events. Hidden check-in, support-programme, event-category, and email-template routes highlight their owning visible section.
- The focused admin-navigation fixture passes, including registration of both menu-state filters, fundraiser/event ownership, support-programme ownership, and preservation of unrelated WordPress menu state. The affected PHP file passes syntax lint.
- The 1.25.1 runtime-only ZIP contains 57 source-identical files, excluding tests, tooling, validation notes, and old ZIPs. SHA-256: `CA15FA62DC9D27A9C3A5AE63B96AABEF211AD7CCFD64C83A0064F0CBC9D44D64`.
- A live WordPress admin session was not available, so the final expanded-menu appearance was not browser-tested against the production site.

# Version 1.25.0 — Australian dates, early interest and event editor updates — 22 September 2026

- Added fixed Australian display formats and day-first editor inputs. Event editor text uses DD/MM/YYYY hh:mm am/pm and converts back to the existing site-timezone storage. Date filters and support-group dates use DD/MM/YYYY. Legacy ISO submissions remain supported; date/time storage and calendar timestamps are preserved.
- Future registration openings reject normal registration. A built-in basic interest form replaces JetForm 2774 in shortcode/Elementor contexts, including validated archive popup event context. Single-event pages have a fallback; listing interest links lead to the correct event. Interest submissions retain the event ID and status interest, do not reserve capacity, and dispatch separate audited customer/team messages respecting existing send/log mode.
- Confirmed JetEngine creation API against Crocoblock's published CRUD example: https://gist.github.com/Crocoblock/a9be7dbb1cb05aa2741aec97757c7f72 — update_item without _ID creates a record and fires the existing created-item lifecycle.
- Live WordPress form 2774 inspection confirmed heart_hub_registration_created precedes its CCT insert action. Both dietary fields were present but UNMAPPED in the explicit field map. Mapped dietaryrequirements and please_let_us_know to their CCT fields, saved, reloaded, and verified both mappings persisted. This corrects the earlier 1.24.3 note claiming automatic name matching. No historical dietary answers were reconstructed and no live registration was submitted.
- Added defensive dietary persistence after CCT creation, decoded numeric HTML entities in REST event titles (Jack&#8217;s Match 2027), Australian registration/review timestamps, a registration end-date filter, and clear Expression of interest labels. Existing event and status filters combine correctly in the submission fixture.
- Added native WordPress featured-image selection/removal to the event/fundraiser editor, including pre-save image checks. Added an external-fee toggle, amount and safe link. The fee shortcode and compatible JetEngine Dynamic Field widgets prefer the external fee over registration/sponsorship prices. Event overview includes external fee details.
- Root agent performed validation: all 19 executable PHP fixtures pass, including end-to-end isolated interest persistence/lifecycle, customer/team mail dispatch, failed-mail auditing, duplicate/invalid/forged/cancelled/open-period rejection, archive popup context, dietary mapping, Australian date parsing, and fee-widget output. All 49 PHP files passed syntax lint; asset JavaScript passed node --check. Focused affected checks were rerun after final integration changes.
- The 1.25.0 runtime-only ZIP contains 57 source-identical files, excluding tests, tooling and old ZIPs. SHA-256: 463B66A7DD6367C2740209D9940881499F45E84E08A0E1C2338D249EBF25234C.
- Deployment was attempted but automatic approval review rejected the upload because explicit site-deployment approval is required. The installed plugin remains unchanged. The new image picker, external fee controls and interest form have not been exercised on deployed WordPress; real inbox delivery was not tested. Local fixtures use WordPress/JetEngine doubles, not a full local WordPress installation.
- Also found the existing JetForm customer email contains administrator review instructions/links and raw date macros. Automatic approval review rejected changing that template as broader than the request. It remains unchanged; explicit approval is needed to correct that existing template. The new interest notification implementation is separate and complete.
# Version 1.24.3 — JetEngine calendar timestamps and dietary profiles — 21 September 2026

- Added one shared event-date converter that accepts existing Unix timestamps and valid legacy local datetime strings, writes JetEngine timestamp storage, and returns local `datetime-local` editor values without a timezone shift.
- Added an idempotent upgrade that converts valid legacy Events CPT start/end strings once the configured post type is available; blank and already numeric values are left unchanged.
- Confirmed the event editor, application/event views, emails, attendance label, and check-in expiry consume timestamp-backed start/end values.
- Added server-side normalisation for JetFormBuilder fields `dietaryrequirements` and `please_let_us_know`, including Yes/No validation, textarea sanitisation, a 1,000-character limit, and clearing details unless the answer is Yes.
- Added both dietary fields to the protected applicant response and administrator detail editor; focused fixtures confirm valid saves, clearing, rejection without mutation, and the two JetForm context mappings.
- PHP 8.3.33 was installed through WinGet. Every PHP source and fixture file passes `php -l`, all 14 executable PHP fixtures pass, and `assets/dashboard.js` passes `node --check` under Node 24.15.0.
- The public Events page was rechecked after deployment: Charity Golf Day and Pottery both render in the listing, and the November 2026 calendar now shows Heart Hub Charity Golf Day on 21 November.
- The 1.24.3 runtime-only ZIP contains 57 files, excludes tests/tooling/prior releases, reports the correct version, includes the date converter, and every packaged file matches its source bytes. SHA-256: `188561F52E22017553E84E69692FBE7BA3C4B759AEF804A9C186B00161B7D4FE`.
- Live WordPress verification completed in Chrome: plugin 1.24.3 is installed and active; the Event Registrations CCT persists 34 fields including `dietaryrequirements` (text) and `please_let_us_know` (textarea, 1,000-character limit); the Dynamic Calendar has multi-day mode disabled and no end-date key; and its public November view renders the Golf Day. Form 2774 already exposes `dietaryrequirements` as a scalar Yes/No radio field and `please_let_us_know` as the conditional text field, while its CCT action uses automatic name matching, so the form itself was not resaved. No live registration was submitted. Pottery still needs an actual `start_date` before it can appear on the calendar.

# Version 1.24.2 — Stable email-template tab navigation — 20 September 2026

- Kept the existing one-editor-at-a-time WordPress rendering so the page does not initialise every WYSIWYG editor simultaneously.
- Added exact scroll-position persistence for ordinary email-template tab clicks using session storage, restored after the destination page has loaded.
- Added `#hherm-email-templates` to every template-tab URL and a matching navigation anchor as the fallback when browser storage is unavailable.
- Removed sticky positioning and top offsets from both the email-template tab bar and rendered preview at all viewport sizes.
- Focused anchor, scroll-store/restore, non-sticky CSS, version-consistency, CSS delimiter, and JavaScript syntax checks pass.
- The 1.24.2 runtime-only ZIP contains 56 files, excludes tests/tooling/prior releases, reports the correct version, and every packaged file matches its source bytes.
- A live WordPress admin session was not available locally, so browser scroll restoration with the production admin bar and live TinyMCE editors was not exercised here.

# Version 1.24.1 — Support-group applicant review and deletion — 20 September 2026

- Added a dedicated detail route for each support-group applicant. The programme ID and numeric applicant ID are both required when loading, declining, or deleting a record.
- Confirmed the detail screen presents the applicant’s contact details, prior-attendance answer, application type, applied date, email-delivery state, status, and complete escaped reason-for-joining text.
- Confirmed decline requests are capability-protected, nonce-protected, and update only the exact applicant/programme pair to `declined`; the record remains visible with a dedicated Declined badge.
- Confirmed applicant deletion requires the user to open a separate confirmation route, select a required permanent-deletion checkbox, and submit a capability- and nonce-protected POST request.
- Confirmed deletion targets only the exact applicant ID and programme ID and returns to the programme’s applicant list with a success notice.
- Focused route, handler, query-targeting, confirmation-copy, status-style, version-consistency, and PHP/CSS delimiter checks pass.
- The 1.24.1 runtime-only ZIP contains 56 files, excludes tests/tooling/prior releases, reports the correct version, and every packaged file matches its source bytes.
- PHP is not installed on this machine, so PHP syntax lint, executable PHP fixtures, and live WordPress applicant review/decline/delete flows were not run locally.

# Version 1.24.0 — Branded support emails and tabbed template editor — 20 September 2026

- Routed support-group confirmed, review-required, and expression-of-interest messages through the same branded HTML renderer, From header, logo, icon, colours, contact details, and reusable signature used by event emails.
- Added editable subjects and WYSIWYG bodies for all three support-group email states, including `{programme_name}` and `{programme_start}` placeholders and rendered sample previews.
- Rebuilt Email Templates as 11 navigation tabs: Brand, Signature, six event-email templates, and three support-group templates. Only the active editor and its rendered preview are displayed, in a responsive left/right workspace.
- Removed the global template save action. Brand, Signature, and each email template now have their own scoped save button.
- Added merge-based partial sanitisation so a scoped save updates only the active template and preserves every other saved template. A focused regression fixture covers this preservation contract.
- Focused version, template-count, renderer-routing, dependency-injection, scoped-save, CSS structure, and JavaScript syntax checks pass.
- The 1.24.0 runtime-only ZIP contains 56 files, excludes tests/tooling/prior releases, reports the correct packaged version, and every packaged file matches its source bytes.
- PHP is not installed on this machine and the local WSL PHP route remains unavailable, so PHP syntax lint, executable PHP fixtures, live WordPress template saves/previews, and email delivery were not run locally.

# Version 1.23.5 — Support form AJAX endpoint fix — 20 September 2026

- Fixed the support-group form’s browser submission endpoint. The hidden WordPress field named `action` was shadowing the DOM form `action` property, causing requests to `/[object HTMLInputElement]` and returning 404.
- The script now reads the literal form `action` attribute before constructing the request.
- JavaScript syntax, endpoint-source, and version checks pass. The 1.23.5 runtime-only ZIP contains 56 source-identical files.
- Live WordPress submission and email delivery were not available locally.

# Version 1.23.4 — Transparent Elementor form container — 20 September 2026

- Removed the support-group form container’s pale-blue background and made it transparent so the Elementor shortcode widget or parent container controls the background.
- Input, button, validation-error, and submission-success styling remains unchanged.
- Focused background, stylesheet structure, and version checks pass. The 1.23.4 runtime-only ZIP contains 56 source-identical files.
- Live Elementor rendering was not available locally.

# Version 1.23.3 — Elementor-ready form and programme detail shortcodes — 20 September 2026

- Removed the programme title and first-meeting introduction from `[hherm_support_group_registration_form]`; the form now begins directly with its fields while retaining mode-specific submit-button wording.
- Added `[hherm_support_group_name]` and `[hherm_support_group_start_date]`. Both use the programme marked In use by default and accept `programme_id`; the date shortcode also accepts `date_format`.
- Enqueued the namespaced support-form CSS and JavaScript on the front-end asset hook so Elementor shortcode widgets receive the styling before the document head is printed. The shortcode also retains its explicit asset request for dynamically rendered contexts.
- Added the HTML `hidden` fallback to the form honeypot so the “Website” spam field cannot appear if a page-builder preview temporarily omits plugin CSS.
- Focused shortcode registration, output contract, version, asset-hook, shortcode-count, JavaScript syntax, and stylesheet structure checks pass.
- The 1.23.3 runtime-only ZIP contains 56 files, excludes tests/tooling/prior releases, reports the correct packaged version, and every packaged file matches its source bytes.
- PHP is not installed on this machine and the local WSL PHP route remains unavailable, so PHP syntax lint, executable PHP fixtures, live Elementor rendering, and live WordPress shortcode output were not run locally.

# Version 1.23.2 — Underway programme messaging — 20 September 2026

- Updated `[hherm_support_groups]` to use the same first-meeting cutoff as registration. Before the cutoff it shows the full date list; from the cutoff onward it replaces the list with “Current support group is underway” and an expression-of-interest prompt.
- Remaining dates are intentionally suppressed once the current programme begins, while `[hherm_support_group_registration_form]` independently switches to its expression-of-interest state.
- Added an optional `underway_text` attribute for customising the prompt and matching pale-blue message styling.
- Focused cutoff, content, version, test-fixture, and stylesheet checks pass. The 1.23.2 runtime-only ZIP contains 56 files, excludes tests/tooling/prior releases, reports the correct packaged version, and every packaged file matches its source bytes.
- PHP is not installed on this machine and the local WSL PHP route remains unavailable, so PHP syntax lint, executable PHP fixtures, and live WordPress cutoff rendering were not run locally.

# Version 1.23.1 — Automatic expression-of-interest mode — 20 September 2026

- Kept `[hherm_support_group_registration_open]` unchanged: it still returns `true` before the first meeting starts and `false` from the cutoff onward.
- Updated `[hherm_support_group_registration_form]` so the same form automatically changes its introduction, submit button, stored application type, success state, and acknowledgement email to an expression of interest after that cutoff.
- Added an application-type-aware schema upgrade. Existing applicant rows default to registrations, the retired registration-only unique key is removed, and the same email address may submit one registration plus one later expression of interest while same-type duplicates remain blocked.
- Added distinct expression-of-interest labels and status badges to the programme editor’s applicant table.
- The changed JavaScript passes `node --check`. Focused mode, schema, email, version, and stylesheet assertions pass.
- The 1.23.1 runtime-only ZIP contains 56 files, excludes tests/tooling/prior releases, reports the correct packaged version, and every packaged file matches its source bytes.
- PHP is not installed on this machine and the local WSL PHP route remains unavailable, so PHP syntax lint, executable PHP regression fixtures, live WordPress schema migration, form submission, and email delivery were not run locally.

# Version 1.23.0 — Built-in support group registration — 20 September 2026

- Moved programme creation and editing to a dedicated hidden admin route, placed upcoming programmes first on the main screen, and limited list actions to Edit.
- Added the deliberate edit → delete → explicit confirmation sequence. Confirmed deletion removes the programme and its plugin-owned applicant records.
- Added a dedicated applicant table and an applicant review table inside each programme editor.
- Added `[hherm_support_group_registration_form]` with the requested identity/contact fields, returning-attendee choice, conditional first-time-attendee explanation, and styling derived from the supplied donation-form CSS reference.
- Returning attendees receive confirmation immediately; first-time attendees receive a pending-review acknowledgement. Both submissions enforce the first-meeting cutoff on the server.
- Added nonce, honeypot, duplicate-email, and hashed-IP rate-limit protections to the public form.
- New form JavaScript syntax, focused source assertions, version consistency, and stylesheet delimiter checks passed. The 1.23.0 runtime-only ZIP contains 56 files, includes the new public form/admin assets, excludes tests/tooling/prior releases, and every packaged file matches its source bytes.
- PHP is not installed on this machine and the local WSL PHP route remains unavailable, so PHP syntax lint, executable PHP regression fixtures, live WordPress schema installation, form submission, and email delivery were not run locally.

# Version 1.22.0 — Six-session support group programmes — 20 September 2026

- Added a Support Groups admin section for named six-meeting programmes with date, start time, and optional end time fields.
- Added a selectable public programme so the next cohort can open for registration while an earlier programme is still underway.
- Added `[hherm_support_groups]` for the supplied front-end date/time list and `[hherm_support_group_registration_open]` for an exact `true`/`false` dynamic-visibility value.
- Registration closes at the selected programme's first meeting start in the configured WordPress timezone. Individual meeting rows disappear only after their calendar date passes.
- Added an optional per-programme JetFormBuilder form ID and server-side cutoff validation so hiding the registration button is not the only protection against late submissions.
- Focused source assertions and version checks passed. The 1.22.0 runtime-only release ZIP contains 54 files, includes the new admin/public assets, excludes tests/tooling/prior releases, and every packaged file matches its source bytes.
- PHP is not installed on this machine and the local WSL PHP route is unavailable, so PHP syntax lint, executable regression fixtures, and live WordPress/JetFormBuilder submission were not run locally.

# Version 1.21.2 — Event form validation recovery — 20 September 2026

- Removed the server-side requirement that website registration close before the event starts. Registration closing still must be later than registration opening when both are supplied.
- Added a random-token, current-user-scoped, one-hour retry draft for nonce-verified event submissions so validation redirects restore entered text, dates, switches, categories, description, and What to Expect rows.
- Mapped validation codes to their relevant editor control. The restored form now scrolls to, highlights, describes, and focuses the field or area requiring correction while retaining the top-level WordPress error notice.
- Added field help confirming that registration may remain open after the event starts.
- JavaScript syntax and focused source assertions passed. The 1.21.2 runtime-only release ZIP contains 51 files, and every packaged file matches its source bytes.
- PHP is not installed on this machine, so PHP syntax lint, executable PHP regression scripts, and live WordPress/JetEngine form submission were not run locally.

# Version 1.21.1 — JetEngine toggle-field linkage — 20 September 2026

- Declared every active Create/Edit Event toggle as a required JetEngine Events-post-type Switcher: `raffle`, `co_hosted_event`, `show_what_to_expect`, `registration_enabled`, `show_remaining_spots`, `allow_waitlist`, and `show_interest_contact_button`.
- Kept the plugin editor and Elementor visibility data on the exact same ordinary post-meta keys, storing `true`/`false` strings.
- Expanded the readiness checker and its focused regression fixture so missing or incorrectly typed toggle fields are reported in WordPress.
- Updated the JetEngine setup guide with field IDs, types, default/effective behavior, and Dynamic Visibility usage. The retired `show_register_now_button` key is explicitly excluded.

# Version 1.21.0 — Authoritative registration state and Date TBC interest CTA — 20 September 2026

- Removed the Show Register Now Button control and made `registration_enabled` the authoritative Register Now state for confirmed Website Registration events.
- Added coordinated Registration Type + Event Date Status behavior: confirmed Website Registration unlocks registration; Date TBC Website Registration locks registration/remaining spots/waitlist off and enables the new `show_interest_contact_button`; External and No Registration lock internal controls off.
- Added the exact Date TBC lock message requested. Returning to Date Confirmed clears/disables the interest Contact Us toggle and automatically enables Registration enabled.
- Updated the public CTA state machine: confirmed Website Registration follows `registration_enabled`, External Registration automatically uses its URL, No Registration emits no CTA, and Date TBC Website Registration can emit Contact Us when the interest toggle is on.
- Enforced every state in the save handler so disabled controls and crafted requests cannot persist incompatible values. The overview now reports Registration enabled and the effective interest Contact Us state instead of the removed Show Register toggle.
- Added focused state-helper regression assertions. JavaScript syntax, focused source assertions, version consistency, runtime package contents, and source-to-ZIP byte equality were checked locally. PHP is not installed on this machine, so PHP lint and executable PHP regression scripts were not run.

# Version 1.20.3 — Registration display-switch defaults — 20 September 2026

- Defaulted `show_register_now_button` and `show_remaining_spots` to false for new events while retaining Website Registration as the missing/initial registration type.
- Made both switches interactive only for Website Registration. Selecting External Registration or No Registration clears, disables, and visually dims them.
- Enforced the restriction in the save handler and public display methods so crafted submissions or stale non-website meta cannot expose either output. The event overview reports both as off for non-website types.
- Removed “Optional.” from the Registration Type helper text.
- JavaScript syntax, focused source assertions, version consistency, runtime package contents, and source-to-ZIP byte equality were checked locally. PHP is not installed on this machine, so PHP lint and executable PHP regression scripts were not run.

# Version 1.20.2 — Foundational event-category locks — 20 September 2026

- Added protected-category recognition for Fundraising Event, Community Outreach, and Mindfulness Workshop naming/slug variants, including the existing `workshop` type slug.
- Replaced Edit/Delete actions with `Locked` in the plugin category manager and show an explanatory notice if a protected category edit URL is opened directly.
- Enforced the lock in both category update and delete handlers so crafted requests cannot bypass the interface. The category definitions are protected; assigning them to events and editing event content remains available.
- Added focused event-type regression assertions for all three protected category families and an ordinary editable category.
- Focused source assertions, version consistency, runtime package contents, and source-to-ZIP byte equality were checked locally. PHP is not installed on this machine, so PHP lint and executable PHP regression scripts were not run.

# Version 1.20.1 — Automatic new-fundraiser category — 20 September 2026

- Confirmed the existing `Event_Types::categories()` save path automatically adds the resolved `fundraising-event` taxonomy term whenever the selected event type is Fundraising.
- Hid and disabled the additional Categories card only for new Fundraising events, including the direct Create fundraiser route. Switching a new standard event back from Fundraising restores the category choices.
- Ignored submitted additional category IDs server-side for new fundraising events, so the automatic type category remains authoritative even if a request bypasses the browser UI. Existing fundraiser edits retain category management.
- JavaScript syntax, focused source assertions, version consistency, runtime package contents, and source-to-ZIP byte equality were checked locally. PHP is not installed on this machine, so PHP lint and executable PHP regression scripts were not run.

# Version 1.20.0 — Event editor fundraiser and optional-field fixes — 20 September 2026

- Removed Amount raised from new-fundraiser markup while retaining it for existing fundraiser edits, and changed all admin fundraiser currency displays to dollars.
- Reordered Sponsorship cost and Registration fee, added an optional non-negative `raffle_cost` decimal, and changed the overview output to `Available` when the raffle is enabled without a price.
- Removed browser and server requirements from confirmed-event start/end fields. A missing registration type now safely normalises to Website Registration.
- Defaulted Show What to Expect off for new events, restyled Event Type, kept required markers inline, and isolated switch copy styling so the co-hosted control remains a horizontal toggle with normal label colour.
- JavaScript syntax, focused source assertions, version consistency, runtime package contents, and source-to-ZIP byte equality were checked locally. PHP is not installed on this machine, so PHP lint and executable PHP regression scripts were not run.

# Version 1.19.6 — Hidden admin-route access — 20 September 2026

- Reproduced the access failure on the live WordPress 7.1.1 site while logged in as an Administrator and confirmed plugin version 1.19.5 was active.
- Confirmed the visible Events route worked, its generated Create event link used the expected `hherm-create-event` slug, and another route removed from the submenu failed with the same core WordPress access-denied page.
- Replaced the register-then-remove submenu pattern with directly registered hidden admin pages (`parent_slug = null`) for Create Event, Check-in Page, Event Categories, and the legacy Email Templates route. Their existing capability checks and callbacks are unchanged.
- Updated the admin-navigation regression harness to verify hidden routes have no menu parent and are never removed after registration.
- Static checks passed for all four hidden route registrations, retained capability boundaries, visible Events registration, and version metadata. PHP is not available on this machine's `PATH`, so PHP syntax lint and the executable regression harness were not run locally.
- Built the runtime-only 1.19.6 ZIP with 51 entries. Every packaged file matched its source bytes, with no tests, tooling, or nested archives included.

# Version 1.19.5 — Owner event-management access — 20 September 2026

- Registered the Events, Fundraisers, and hidden Create Event admin routes with the plugin's configured owner capability, matching the Create event action already shown on the registrations dashboard.
- Changed event list, editor, save, cancellation, and overview access gates to the same owner capability. The existing Events CPT create/edit checks still protect writes; Settings and event-category administration continue to require `manage_options`.
- Updated the admin-navigation regression harness to verify the three event routes use the owner capability while event categories remain administrator-only.
- Static access checks passed for all changed gates, capability boundaries, and version metadata. PHP is not available on this machine's `PATH`, so PHP syntax lint and the executable regression harness were not run here.
- Built the runtime-only 1.19.5 release ZIP with 51 entries. Every packaged file matched its source bytes, and no development tests, tooling, or nested archives were included.

# Version 1.19.4 — Shortcode currency output — 19 September 2026

- Removed currency symbols from `hherm_total_funds_raised` and `hherm_event_funds_raised`, including the former `currency` attribute. They now output only the formatted number; page content can supply the desired symbol before the shortcode.
- Preserved the existing calculations, period/event selection, decimal formatting, escaping, and CSS classes. Updated the in-plugin shortcode reference and JetEngine display setup guide.
- PHP 8.3.33 syntax checks passed for the three changed runtime PHP files. All eight existing event-metrics assertions passed, with the two funds-raised assertions updated to require currency-free output.
- Built the installable 1.19.4 ZIP with the existing runtime-only release script.
- Live WordPress rendering and the broader test suite were not rerun for this focused formatting change.

# Version 1.19.3 — Plugin Check fixes — 19 September 2026

Reviewed the supplied WordPress Plugin Check report against the local 1.19.2 source. This release keeps the existing namespace, hooks, shortcodes, URLs, registration states, capacity calculations, and stored data format.

| Report finding | Resolution |
|---|---|
| Mixed PHP line endings | Normalized all 28 runtime PHP files to UTF-8 without BOM and LF endings. Added `.editorconfig` to preserve that convention. |
| Namespace prefix warnings | Retained the existing `HeartHub\EventRegistrations` vendor namespace for integration compatibility. Each namespace declaration has a narrowly scoped explanation for the checker's inferred-prefix warning. Other global-prefix checks remain enabled. |
| Exception output not escaped | Escaped the messages passed to JetFormBuilder action exceptions and the fallback runtime exception. |
| Missing unslashing/sanitization | Sanitized request methods, check-in steps and party counts, self-service attendee counts, bulk attendance statuses, and the category return-page value. Invalid array-shaped party/status values are rejected. |
| Self-service POST nonce warnings | Moved the allowlisted request-field reads and sanitization into `process_submission()`, after its existing token-bound nonce verification. The private update method receives sanitized values and retains the existing review/capacity behavior. |
| Read-only GET nonce warnings | Added scoped explanations for admin filters, tabs, notices, route detection, calendar downloads, and attendee link resolution. Public links still validate their bearer token/expiry; actual submissions still verify a separate nonce. These annotations suppress only `NonceVerification.Recommended`, not missing POST nonce checks. |
| Stylesheets not enqueued | The three standalone attendee pages now use versioned `wp_enqueue_style()` and explicitly print their own handle in the standalone document head. CSS files, theme overrides, and page markup remain otherwise unchanged. |
| Missing script version | Added the plugin version to the Google Maps script enqueue for cache invalidation. |
| Audit SQL identifiers | Used `%i` placeholders for the audit table in both reads and uninstall. WordPress has supported these since 6.2, below the plugin's 6.4 minimum. |
| Direct database/schema warnings | Documented the necessary insert into the plugin-owned audit ledger and removal of that same table during uninstall. No core/JetEngine tables or data are added to the cleanup scope. |
| Slow taxonomy-query warning | Kept the existing category query used to separate events and fundraisers, with a scoped explanation of its 20-post pagination. |
| Unprefixed uninstall variables | Prefixed the two multisite loop variables with `hherm_`. |
| Missing readme tested header | Added `Tested up to: 7.1.1`, the WordPress version confirmed by the site owner. This is not a claim that this new release was installed and tested on that site during this task. |
| Unexpected root Markdown files | Added `tools/build-release.ps1`, which packages only runtime files, assets and licences, and `readme.txt`. Development notes, tests, tools, and historical archives remain in the workspace and are excluded from the installable ZIP. |

Validation completed locally with PHP 8.3.33 and the official WordPress Plugin Check 2.1.0 package:

- All 28 runtime PHP files passed syntax lint.
- The report's PHP check categories passed with **zero errors and zero warnings**: namespace/global prefixes, escaping, nonce verification, input validation/sanitization, resource enqueues/versions, taxonomy queries, prepared SQL, and direct database calls. Existing and new scoped justifications are honored by these checks.
- Plugin Check's complete `phpcs-rulesets/plugin-check.ruleset.xml` also passed with **zero errors and zero warnings**.
- Seven focused test scripts passed 106 assertions: plugin bootstrap (8), registration workflow (19), check-in workflow (18), event reporting (9), settings tabs (22), admin navigation (11), and public submissions (19).
- The public-submission tests verify missing/wrong-token nonces, bearer token validity/expiry, sanitized names and multiline comments, invalid party shapes/counts, preserved defaults, over-capacity rejection, failed-write capacity rollback, withdrawal, and nonce-protected feedback.
- Verified all 51 ZIP entries match their source bytes, use portable `/` paths under the plugin folder, include the QR library licence, and exclude development notes, tests, tooling, and nested archives.

Build the installable release with `powershell -File tools/build-release.ps1`. Use `-Force` only to rebuild the current version's ZIP. Install the generated ZIP rather than uploading this development workspace wholesale.

Not performed: installation on the live WordPress 7.1.1 site, the WordPress-hosted Plugin Check runtime suite, real JetEngine database writes, actual email delivery, cron execution, QR scanning, or browser layout checks. PHP 7.4/8.5 were not rerun for this release; the focused checks above ran on the available PHP 8.3.33 runtime. After installing the ZIP, rerun Plugin Check and exercise the attendee pages on staging.

References: [WordPress identifier placeholders](https://developer.wordpress.org/reference/classes/wpdb/prepare/), [enqueuing styles](https://developer.wordpress.org/reference/functions/wp_enqueue_style/), and [printing selected styles](https://developer.wordpress.org/reference/functions/wp_print_styles/).

# Version 1.19.2 — 19 September 2026

- Corrected the event-category lookup to use the live Fundraising Event slug `fundraising-event` while retaining `fundraising` as the plugin's internal event-type key.
- Added regression assertions for the exact taxonomy slug lookup and the corresponding readiness guidance.
- All 38 PHP files pass syntax lint and all ten test scripts pass 118 assertions under both PHP 7.4.33 and PHP 8.5.10.

# Version 1.19.1 — 19 September 2026

- Downloaded official portable PHP runtimes and validated the release with both PHP 7.4.33 (the declared minimum) and PHP 8.5.10.
- All 38 PHP source and test files pass syntax lint on both runtimes.
- All ten focused test scripts pass on both runtimes: event types, event-type readiness, public event display, event metrics, registration workflow, check-in workflow, event reporting, settings tabs, admin navigation, and full-plugin bootstrap. The suite covers more than 100 individual assertions.
- The settings-tabs test confirms every tab keeps all four Settings API option groups in the form, preventing cross-tab data loss, activates exactly one panel, lists all nine shortcodes, and avoids a misleading save button on the read-only Shortcodes tab.
- The admin-navigation test confirms the four redundant sidebar routes are hidden, Emails embeds the templates editor, Event Setup embeds categories, unrelated embedded panels do not render, and invalid tabs fall back safely.
- The bootstrap test instantiates the complete plugin and confirms all nine shortcodes plus the key admin, attendance, event-save, REST, and public-route hooks are registered.
- Corrected two PHP 8.5 deprecation warnings by explicitly marking the optional check-in manager and email service dependencies nullable. Updated older test harnesses to load their current dependencies and avoid deprecated Reflection setup on PHP 8.1+.
- All seven JavaScript files pass `node --check`; all 13 CSS files have balanced block structure; all 26 include files are present in the root loader; every literal plugin asset reference resolves.
- Live WordPress/JetEngine persistence, browser layout, email delivery, cron execution, and real QR scanning still require staging acceptance after upload because those integrations cannot be reproduced fully in the isolated local harness.

# Version 1.19.0 — 19 September 2026

- Reworked Settings into full-width URL-backed tabs: Emails, Appearance, Contact & Access, Event Setup, and Shortcodes.
- The Emails tab contains both automatic-delivery controls and the existing email-template editors/previews. The standalone Email Templates sidebar link is hidden, while its route remains available for old bookmarks.
- Appearance contains attendee-page branding, colours, copy, and the check-in feedback switch. Contact & Access contains the public events URL, contact address, and owner roles. Event Setup contains technical integration, the installation checklist, and category management.
- Added a Shortcodes reference for all five impact metrics and all four event-display shortcodes.
- All core settings fields remain inside one Settings API form even when their tab is visually hidden, preventing one tab save from resetting options on another tab. Email templates retain their separate Settings API form and save action.
- Added `assets/settings-tabs.css` after the legacy settings styles so the page, tab bar, template editor, and category section can use the full WordPress content width.
- PHP is not installed in this workspace, so PHP lint and live WordPress rendering were not run locally. Static structure and package checks remain the available validation before staging upload.

# Version 1.18.0 — 19 September 2026

- Renamed the admin navigation item to Attendance & Check-in and combined its QR controls, attendee-page preview, live check-in feed, and editable attendance register on one screen.
- Removed the separate Check-in Page, Create Event, and Event Categories sidebar links. Their underlying routes remain registered so existing bookmarks and in-plugin links continue to work.
- Embedded Event Categories in Settings. Category add, edit, validation-error, and delete redirects now return to that section, and the add form warns that new categories may require support because event layouts and workflows use dynamic category configuration.
- Updated the event-type readiness link to open the Event Categories section in Settings.
- PHP is not installed in this workspace, so PHP lint could not be run locally. Static structure checks and JavaScript syntax checks were used for this release; live WordPress rendering remains a staging check after upload.

# Version 1.17.0 — 19 September 2026

- Added a Fundraisers admin view over the existing Events CPT. Its query requires the Fundraising category, while the Events view explicitly excludes that category.
- Added `amount_raised` as a non-negative, optional decimal with at most two decimal places. It is stored on the fundraiser event itself and retained if the event type changes.
- Added impact metrics for rolling calendar-month windows. Events are included when their configured start date is inside the window, at or before the current site time, and the event is not cancelled.
- Attendance totals use approved attendance records: full attendance uses the checked-in party size when present or the approved party size otherwise; partial attendance requires an explicit checked-in party size; no-show and unknown contribute zero.
- Added five escaped shortcodes for total funds, total attendees, events run, per-event funds, and per-event attendees, plus focused isolated coverage in `tests/event-metrics.php`.
- The new `amount_raised` JetEngine Number field is enforced by the event-type readiness check and documented in `JETENGINE-EVENT-DISPLAY-SETUP.md`.
- PHP is not installed in this workspace, so PHP lint and the focused PHP scripts could not be run locally. Static brace checks pass for the changed PHP classes. The live Events CPT now has the persisted `amount_raised` Number field with minimum `0` and step `0.01`; plugin 1.17 admin screens and shortcode rendering remain staging checks until the ZIP is uploaded.

# Version 1.16.0 — 19 September 2026

- Added backward-compatible event presentation defaults: missing Registration Type is Website Registration; missing Register Now, remaining-spots, and What to Expect switches are ON; missing Event Date Status is Date Confirmed. Legacy `event_schedule_status=tba` maps to Date TBC.
- The event editor conditionally shows website settings, external URL, No Registration CTA, confirmed-date inputs, and What to Expect content without deleting hidden website settings, external/CTA values, capacity, or repeater content.
- Past Events and Past Fundraisers are excluded from the event category picker. The category merge rejects submitted lifecycle IDs but preserves lifecycle IDs already assigned to the event.
- Internal JetForm registration and expression-of-interest hooks reject External Registration and No Registration events before reserving capacity or creating a CCT record.
- Public shortcodes centralise date, CTA, remaining-spots, and What to Expect output. External links include a new-tab target with `noopener noreferrer`; No Registration never emits Register Now; Contact Us resolves through the existing page path/filter.
- `node --check` passes for `assets/event-editor.js` and `assets/events.js`.
- PHP is not installed in this workspace, so PHP lint and the focused PHP scripts were not run locally. Live WordPress/JetEngine persistence, builder-template wiring, and browser rendering still require staging verification using `JETENGINE-EVENT-DISPLAY-SETUP.md`.

# Version 1.14.0 — 16 September 2026

- Added an admin readiness check for the 1.14.0 event-type release. On Heart Hub Events admin screens it reports each missing Workshop/Fundraising category and each missing or incorrectly typed fundraising field, with direct links to Event Categories or JetEngine Post Types.
- The checker is read-only: it does not create terms or alter JetEngine configuration. It recognises the required `registration_fee` and `sponsorship_cost` Text fields and `raffle` Switcher field from JetEngine CPT/meta-box configuration; custom providers can supply the same map through `hherm/event_type_meta_fields`.
- Focused isolated checks in `tests/event-type-readiness.php` cover a ready configuration, all five missing requirements, normalised type names, incorrect field types, and JetEngine's post-type field discovery path. All seven checks pass under PHP 8.3.33; changed PHP files pass syntax lint.
- Live JetEngine configuration discovery and WordPress admin notice rendering still require confirmation on staging. On staging, remove or mistype one requirement at a time, confirm the actionable error appears on Heart Hub Events screens, then restore the requirement and confirm the notice clears.

# Version 1.12.0 — 9 September 2026

- Inspected the live WordPress analytics page and confirmed the crowded header/filter arrangement.
- Changed PHP files pass PHP lint; dashboard.js and events.js pass node --check. Event reporting regression checks pass.
- Local browser fixture using rendered plugin PHP/CSS verified: analytics heading/filter card layout; event-row click navigation; embedded action link independence; registration dialog opening on the same event URL; edit fields expanding in that dialog; closing the dialog without navigation.
- Local fixtures use sample data and simplified WordPress base styles. Live plugin installation, production save/review actions, and narrow-screen rendering were not exercised. The live site was inspected only.

# Version 1.11.0 — 9 September 2026

- PHP 8.3.33 lint passed for changed PHP files.
- tests/checkin-workflow.php passes: event/email matching, lookup-only behavior, actual party options, oversized count rejection, 3-of-4 attendance, returning one unused place, repeat-submission behavior, server-side feedback toggle, invalid nonce, and disabled preview submission.
- tests/event-reporting.php passes: event-scoped overview, inclusion of all registration statuses and rating-only feedback, escaped applicant content, separate no-show/unknown counts, event filters, all-time aggregate, inclusive custom dates, combined filters, and local/Unix timestamps.
- Tests use isolated WordPress/JetEngine doubles. Live WordPress persistence, browser layout, QR scanning, actual mail delivery, and real cron execution were not exercised.
- Live acceptance: generate an active event QR link; look up a four-person approved registration; confirm three; inspect the event overview and capacity. Repeat with check-in feedback off/on. Use a different event/email to confirm rejection. Compare the overview against event-specific analytics and date filters. Confirm the configured post-event email opens the matching event feedback page and submitted feedback appears in the event overview.

# Version 1.10.0 — 9 September 2026

- Optional single/bulk decline reasons with a shared generic fallback; editable applicant contact details and party size; minimum party size of one on intake, review, and edits.
- PHP 8.3.33 lint passed for changed PHP files. JavaScript syntax check passed for dashboard.js.
- Focused isolated regression checks passed (tests/registration-workflow.php): minimum and invalid sizes, contact edits, capacity increases/decreases, overbooking rejection, rollback of existing and newly created reservations after failed persistence, waitlist edits, checked-in restrictions, field allowlisting, blank email, blank single/bulk decline reasons, and zero-party approval.
- These checks use WordPress/JetEngine test doubles; live database persistence, browser layout, actual emails, and JetFormBuilder form integration were not exercised.
- Past Events parsing and append-only assignment tests from version 1.9.0 also now pass using the restored PHP runtime, superseding the earlier local-runtime limitation.
- Manual check: install 1.10.0, open a registration, expand Edit applicant details, save name/contact/party changes, and confirm capacity and CCT data. Decline singly and in bulk with no reason; verify generic reason in the email. Submit a zero-party registration and confirm one is stored/reserved.
# Version 1.9.0 — 9 September 2026

- Added an independent five-minute WP-Cron category check with paginated event reads, append-only term assignment, site-timezone date parsing, and category slug fallback.
- Scheduler registers on init for existing installations; deactivation and uninstall clear its hook.
- Reviewed changed source. PHP lint and a focused stub-based behavior check were attempted, but the available temporary PHP 8.3.33 executable failed to start without diagnostics. No successful runtime validation is claimed.
- Live verification required: install the ZIP; confirm event-category term 83 has slug past-events; run hherm_categorize_past_events using a cron management tool; verify a past event gains the category and retains other categories, a future event stays unchanged, and repeated runs are harmless. Confirm site timezone and cron execution.
# Validation report — version 1.8.0

Validated on 20 August 2026.

## Version 1.8 source-tree pass — 20 August 2026

- Added independently configurable enable switches and minute/hour/day delays for approval, decline, waitlist, cancellation, and post-event feedback email automation.
- Delayed event-triggered email jobs use registration IDs rather than recipient data, revalidate current event/status state at delivery, and retry transient mail failures only.
- Added shared logo, header/footer, palette, and principal page-copy controls for check-in, feedback, and registration-management bearer pages.
- Added no-cache/no-index/no-referrer/nosniff/anti-framing headers across all three temporary page types and a capability/nonce-protected check-in preview.
- Enforced registration-enabled and local-time open/close gates server-side; fixed configured event/attendee field usage, partial check-in totals, dashboard export/stats, and email-preview token mutation.
- All seven JavaScript files pass `node --check`. A live WordPress/JetEngine integration run was not available in this workspace; the required live checks remain listed below.

## Focused staging-validation pass — 18 August 2026

This pass targets the current source tree and was run with email mode left at **Log only**. No live registration was submitted. The workspace did not contain a staging URL, logged-in staging browser tab, WordPress runtime, or site export, so live-only checks are recorded as not run rather than inferred from source inspection.

| Area | Result | Evidence / remaining live check |
|---|---|---|
| JetEngine | Source contract passes. | The plugin resolves the configured Event Registrations CCT through JetEngine's CCT module, supported database query API, and item handler. Installed JetEngine version and activation were not available in this workspace. |
| JetFormBuilder form 2774 | Source contract passes; live form mapping not verified. | The `heart_hub_registration_created` Call Hook is restricted to form `2774` and must run before the CCT insert action. Live action order and field mappings still require the staging form editor. |
| JetFormBuilder form 2899 | Not verified. | Form `2899` is not referenced by the plugin source. Its live purpose, action order, and mappings require the staging form editor; no code change was inferred without that evidence. |
| Events CPT and meta | Source defaults pass; live site mapping not verified. | Defaults remain `events`, `start_date`, `end_date__time`, `venue`, `organiser`, `_description`, `event_capacity`, and `current_event_capacity`. The Create/Edit Event mapper was restored so the screen no longer calls a missing `event_values()` method. Live CPT, relation ID, and JetEngine field definitions still require staging. |
| Capacity transitions | Source and focused formula checks pass. | New event capacity `10` initializes to `10`; `10 → 20` with no reservations yields `20`; `20 → 30` with three reserved places yields `27`; reducing below reserved places is rejected. No staging CCT records were changed. |
| Check-in | Source security/flow checks pass; live QR flow not run. | Tokens are 24 random bytes / 48 hex characters, activate at event start, expire one hour after event end, match approved registrations by email address only, write attendance and feedback through JetEngine, and return generic verification failures. Cancellation detection now handles `1/true/yes/on` consistently, including a stored string `false`. QR scanning, attendee self-check-in, and `event_feedback` persistence still require a copied staging registration. |
| Email mode | Log-only branch confirmed. | The default and settings sanitisation keep mode at `log`; decision, waitlist, and cancellation messages are audited instead of sent unless explicitly switched to `send`. No mail was submitted. |

### Targeted fixes made during this pass

- Restored `Event_Manager::event_values()` so the Create/Edit Event screen can load existing/default field values instead of failing on an undefined method.
- Replaced the check-in cancellation truthiness test with the plugin's accepted boolean-value check, so the literal string `false` is not treated as cancellation.

The live staging items above remain open until a staging URL/session or equivalent site export is available.

## Automated checks carried forward from the previous source-tree pass

The following checks were carried forward from the prior report and were not re-run here because PHP/WordPress were not available in the workspace:

- Every PHP file passes `php -l` with PHP 8.3.33.
- The admin dashboard JavaScript passes `node --check` with Node.js 24.
- The plugin bootstrap and admin-menu registration load without fatal errors using WordPress hook/menu stubs.
- The uninstall routine executes without fatal errors against WordPress database/role stubs and issues exactly the expected lock-cleanup and audit-table queries.
- The installable archive has one correctly named plugin root directory.
- Plugin header, version, readme stable tag, text domain, and minimum PHP/WordPress declarations are present.
- All custom REST routes use the capability-and-nonce permission callback.
- Review status validation allows only `approved` or `declined`; `rejected` is not used.
- Applicant and event details used for review and email are loaded server-side.
- The CCT implementation contains no hard-coded CCT table name or direct CCT SQL.
- Email defaults to log-only mode and does not globally modify `wp_mail_content_type`.
- Approval and decline templates are sanitised, previewable, and support server-resolved placeholders.
- Registration dates can be filtered with validated ISO date inputs.
- The event dropdown is populated from WordPress posts using the confirmed `events` post type, independently of whether an event already has registrations.
- The WordPress admin dashboard uses scoped, responsive styles with distinct filter/results panels, a simple empty table row, an accessible loading state, and no frontend CSS dependency.
- Plugin settings are registered only beneath Event Registrations, preventing WordPress's global Settings menu from being selected.
- Event creation uses WordPress's post API for the existing `events` CPT, a protected admin-post action, nonce verification, strict field sanitisation, and server-side date/capacity validation.
- Confirmed JetEngine event keys are used for start/end, venue, organiser, description, registration settings, capacity, waitlist, returning-attendee approval, and cancellation deadline.
- Existing events are queried through `WP_Query`, edited through `wp_update_post`, and assigned categories through `wp_set_object_terms`; the plugin never registers a competing CPT or taxonomy.
- Capacity transition tests cover initialisation, pending reservation, idempotent approval, single release on decline, over-capacity rejection, and preserving reservations when total capacity changes.
- Capacity automation uses the confirmed Crocoblock field keys and rejects partially configured overrides.
- Confirmed capacity fields default to CCT `number_of_attendees` (default attendee count 1) and event meta `current_event_capacity`.
- New events initialize hidden `current_event_capacity` to exactly `event_capacity`; the current value is not rendered as an editable create/edit field.
- Capacity changes made before registrations exist reset `current_event_capacity` to the new total; any later changes preserve reserved places even if the event is subsequently unpublished.
- Capacity edit transition test confirms `10 → 20` recalculates current capacity to `20` before registrations, while changing a total from `20 → 30` with three reserved places produces `27` remaining.
- Registrations that cannot fit are assigned `registration_status=waitlist` only when the event's `allow_waitlist` switch is enabled; they do not consume capacity until approved.
- Waitlist applications are filterable and reviewable, and approval returns an at-capacity error unless the full attendee group can be reserved.
- Waitlist routing test confirms full events accept registrations only when `allow_waitlist` is enabled and otherwise return a frontend-safe capacity error.
- The attendance register loads only approved registrations for the selected event through JetEngine's CCT API.
- Attendance writes accept only `attended`, `no-show`, `partial`, or `unknown`; each record is revalidated against its event and approved status before updating `attendance_status`.
- Attendance changes are nonce/capability protected and written to the plugin audit log.
- Event check-in links use 192-bit random tokens, are revocable, open at the event start, and expire one hour after the event end in the WordPress site timezone.
- The standalone check-in endpoint is no-index/no-cache, rejects cancelled events, verifies an approved registration by normalised email, and returns generic verification failures.
- Unregistered and non-approved email addresses receive the same attendance-register message, preventing the page from disclosing registration status while directing the attendee to staff.
- Public check-in submissions include a nonce, honeypot, 5,000-character feedback limit, a ten-attempt per-identity limit, and a broader sixty-failed-identity-attempt limit per address within ten minutes.
- Self check-in writes `attendance_status=attended` and optional feedback through JetEngine's CCT item handler; the field key defaults to configurable `event_feedback`.
- Feedback & Analytics lists event-level attendance totals and feedback from approved registration records without creating a separate customer or feedback table.
- QRCode.js is bundled locally with its MIT licence, so QR rendering makes no third-party request.
- Event cancellation is nonce/capability protected, sets `event_cancelled=true`, records the cancellation date, and forces `registration_enabled=false` without deleting the event.
- Cancellation recipients are loaded through the CCT API and limited to pending, approved, and waitlisted registrations; declined registrations are excluded.
- Cancellation email subject/body are sanitized, editable, and previewable with the existing safe brand template and placeholders.
- Successfully sent cancellation emails are skipped on retry; transient transport failures receive bounded retries, while log-only outcomes remain terminal for that trigger.
- Cancellation email test confirms the cancellation template is selected and a successfully sent recipient is skipped on the next retry.
- Email branding supports a separately configured header icon selected from the WordPress Media Library.
- The reusable rich-text signature is sanitized, can be globally enabled or disabled, and is inserted only where `{signature}` or `[email_signature]` appears.
- Branding test confirms the icon renders in the header, signature contact placeholder resolves, shortcode text is removed, and disabling the signature removes its content.
- Event creation/editing maps parking instructions to `parking_access` and the repeater to `what_to_expect` with exact `title` and `content` subkeys.
- What to Expect is limited to six rows; every non-empty row requires both values, with length, shape, sanitization, and count checks enforced server-side.
- Repeater test confirms six valid entries pass, a seventh is rejected, incomplete rows fail, and submitted HTML is stripped from both subfields.
- Missing `registration_id` deep links no longer open an error modal; the modal is deferred until a valid application loads and stale URL parameters are removed silently.
- Event-scoped locks serialize registration and review capacity changes, with stale-lock recovery and audit-ledger rollback handling.
- The review interface is registered only as a capability-protected WordPress admin menu page.
- Dashboard JavaScript loads only on the Registrations screen; scoped admin styles load only across the plugin's own screens.
- Uninstall is guarded by `WP_UNINSTALL_PLUGIN` and removes only plugin-owned data.

## Uninstall behavior

Uninstall removes:

- `hherm_settings`, `hherm_owner_roles`, `hherm_email_templates`, `hherm_email_automation`, and `hherm_public_page_settings` options;
- scheduled-email job data and temporary `hherm_review_lock_*`, `hherm_capacity_lock_*`, `hherm_checkin_event_*`, `hherm_registration_manage_*`, and `hherm_feedback_link_*` options;
- check-in rate-limit transients beginning `hherm_ci_`;
- `manage_heart_hub_event_registrations` from WordPress roles;
- the plugin-owned `{prefix}hherm_audit_log` and `{prefix}hherm_support_group_applications` tables.

Uninstall does not access or remove JetEngine CCT records, Events posts, relations, JetFormBuilder forms, pages, Query Builder queries, or listings.

## Checks requiring the live WordPress site

These cannot be validated from the standalone source tree:

- activation against the installed JetEngine and JetFormBuilder versions;
- the live Events CPT slug, relation ID, and event meta field keys;
- forms 2774 and 2899 action order and mappings;
- the configured internal notification recipient/template, direct record links, and real inbox delivery;
- the support-group schema upgrade and no-programme expression-of-interest flow against the live database;
- live registration-history rendering with the site's configured payment field mappings;
- owner-role selection and access to the Event Registrations admin menu;
- rendering within the site's Elementor theme styles;
- viewing, editing, and publishing a copied event through the plugin interface;
- adding/editing an `event-category` term and assigning it to a copied event;
- reconciliation of existing pending/approved registrations when capacity automation is first enabled;
- live rendering and bulk-saving the `attendance_status` CCT field through the Attendance Register;
- QR scanning, event-start through one-hour-after-end activation/expiry in the configured site timezone, attendee self-check-in, and `event_feedback` persistence against a copied registration;
- cancellation mail delivery through the staging site's configured WordPress mail transport;
- dry-run approval and decline against copied/staging CCT records.
- the Create Event address suggestions and save-time validation against the configured JetEngine Maps Settings provider; confirm the live JetEngine `address` map field Value format if it is configured to require latitude/longitude or an array instead of a location string.

The local smoke suite also renders the approval preview and subject, verifies that all sample placeholders are replaced, and confirms that the configured brand colour is present in the resulting email HTML.

Run those checks on staging with email mode left at **Log only**. Do not submit a live registration during validation.

# Version 1.34.2 — Registration, email and display review fixes — 2 October 2026

- Implemented the 12 findings from the 1.34.1 review: verified customer/party capture, serialized interest submissions, bounded direct-email recovery and protected support resends, atomic feedback delivery, checked attendee-link persistence, Unicode-safe comments, and consistent calendar/event actions and dates.
- Additional regressions cover optional CCT metadata columns, failed delivery-status saves, exhausted retries whose cleanup fails, stale lock owners, and record-bound support actions.
- Full local validation passed **127 checks and 771 assertions, zero failures/skips**: 77 PHP syntax, 12 JavaScript syntax, 36 executable PHP fixtures, and two executable JavaScript fixtures. [Detailed fixes and validation](validation/fixes-2026-10-02/FIXES.md) and [machine-readable results](validation/fixes-2026-10-02/results.json) are preserved.
- Built the runtime-only 1.34.2 ZIP: **68 source-identical files, 273,666 bytes**. Header, runtime constant, and stable tag agree. SHA-256: `F98CFD6EF53836F22401FC6616B2AC0B05524FAC040500791E7434CA384B79F7`. [Package verification](validation/fixes-2026-10-02/package-results.json) also records changed runtime paths.
- This pass did not exercise installed WordPress/JetEngine/JetFormBuilder, browser presentation, MariaDB, or SMTP/inbox delivery. Docker's daemon was unavailable. No deployed site was changed. Legacy hash-only feedback tokens are preserved; new retry descriptors apply to failures after this update, as documented in the fix report.
