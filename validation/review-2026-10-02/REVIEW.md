# Plugin review — 2 October 2026

Reviewed Heart Hub Event Registration Manager **1.34.1**. Found **12 actionable issues: two P1 issues affecting captured customer data and ten P2 issues affecting duplicate prevention, email recovery, feedback capture, and displayed information**. P1 means fix first; P2 means a reproducible bug with a narrower trigger or impact.

This was a review. Runtime PHP, JavaScript, CSS, settings, and packaged releases were not changed. New review evidence is saved under `validation`.

## Findings

### 1. [P1] A missing party-size mapping silently turns a group booking into one person

Location: [class-jetform-integration.php:238](<G:/Heart Hub Events Plugin/includes/class-jetform-integration.php:238>), especially lines 238–243 and 258–259.

**Trigger:** Form 2774 submits four attendees, but its CCT insert mapping omits the configured attendee-count field. The normalisation hook has already captured four in the form context and capacity lock.

**Observed:** `after_created()` derives the party again from the inserted item, defaults the missing value to one, writes one, and completes the reservation for one. The reproduction recorded `submitted_party=4`, `normalized_context_party=4`, `persisted_party=1`, `reserved_party=1`, and `remaining=9` from a total capacity of ten.

**Impact:** The customer record and capacity ledger both lose three attendees. Those places remain available for other bookings, creating an overbooking risk even though the submission has completed normally.

**Fix:** Retain the validated submission party size through creation, persist and verify that value, then complete the reservation using it. Treat an incomplete mapping/save as an explicit failure needing recovery.

Evidence: [jetform-party-loss-repro.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/jetform-party-loss-repro.php>). Uses the actual integration, repository, and capacity classes with an injected CCT storage adapter.

### 2. [P1] Incomplete customer records can still be reported as successfully received

Locations: [class-cct-repository.php:47](<G:/Heart Hub Events Plugin/includes/class-cct-repository.php:47>), lines 47–50; [class-jetform-integration.php:233](<G:/Heart Hub Events Plugin/includes/class-jetform-integration.php:233>), lines 233–234.

**Trigger:** The CCT handler returns an item ID while dropping an unmapped field or truncating a value. Separately, the defensive dietary update can return an error after creation.

**Observed:** `create_registration()` accepts the ID without comparing the stored record against the submitted fields. The public interest flow then reports received. Unlike updates, creation has no read-back verification. Injected storage faults returned successful IDs when `Longer Customer Name` was stored as `Longe` and when supplied dietary fields were absent. The JetForm callback also logs `dietary_persistence_failed` and continues its normal side effects after a failed dietary update.

**Impact:** Staff and customers can receive apparent success despite missing submitted details. The defensive dietary path does not make its detected failure visible through the submission result.

**Fix:** Read back and verify created fields against the original validated request. Retain request values independently of the mapped CCT item. Give incomplete records an explicit recovery state, and propagate or surface dietary persistence failures instead of treating them as normal completion. Keep the existing update verification and compensation approach.

Evidence: [review-registration-create-repro.php](<G:/Heart Hub Events Plugin/validation/review-registration-create-repro.php>). Creation failures were injected through the existing storage adapter; the dietary callback continuation was confirmed from source.

### 3. [P2] Overlapping event-interest submissions create duplicate customers and acknowledgements

Location: [class-jetform-integration.php:56](<G:/Heart Hub Events Plugin/includes/class-jetform-integration.php:56>), lines 56–63.

**Trigger:** Two requests for the same event and email overlap between the duplicate lookup and insertion, such as a double submit or retry from a second tab.

**Observed:** Both can read no existing interest, insert a record, and report received. The deterministic interleaving produced two records for the same email/event and two acknowledgements. A duplicate-lookup `WP_Error` also permits insertion.

**Impact:** Duplicate customer records, repeated emails, and inflated interest totals.

**Fix:** Serialize lookup and creation with an event/email identity lock, or enforce uniqueness in storage. Stop insertion if the duplicate lookup fails, and make retries idempotent.

Evidence: [interest-duplicate-repro.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/interest-duplicate-repro.php>).

### 4. [P2] Failed support-group confirmations cannot be retried through the confirmation action

Location: [class-support-groups.php:640](<G:/Heart Hub Events Plugin/includes/class-support-groups.php:640>), with the status/send sequence at lines 645–658.

**Trigger:** Staff confirms a pending applicant while the mail transport returns false or throws, then restores the transport and repeats the confirmation action.

**Observed:** The applicant remains confirmed with `email_status=failed`. Repeating confirmation exits as already-confirmed before sending. The reproduction still had one mail attempt after transport recovered.

**Impact:** The customer misses the confirmation unless staff sends a separate message outside this workflow. Event/support interest acknowledgements and internal submission notifications also use direct delivery without the stored retry mechanism used for event decision emails.

**Fix:** Preserve approval independently from delivery. Queue bounded delivery retries and provide a capability/nonce-protected resend for failed confirmations, without reapproving the applicant or resending successful deliveries.

Evidence: [email-review-repro.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/email-review-repro.php>), transport failure and recovery assertions.

### 5. [P2] Concurrent feedback workers send duplicate emails and invalidate the first link

Location: [class-post-event-feedback.php:42](<G:/Heart Hub Events Plugin/includes/class-post-event-feedback.php:42>), lines 42–43, 60–63, and 105–109.

**Trigger:** Two feedback cron workers both read the transient before either stores it and reach delivery before a successful audit entry exists.

**Observed:** The read-then-write transient is not an atomic lock. Both workers hand off an email for the same registration, and each generates a different token. The second token overwrites the first. In a deterministic interleaving of the real feedback handler and email service, the first emailed link failed resolution and the second succeeded.

**Impact:** A customer receives duplicate requests and an unusable first link.

**Fix:** Use the plugin's atomic mutation lock across eligibility checking, token creation, delivery, and audit recording. Recheck eligibility after acquiring the lock. Reuse a valid persisted token for retries.

Evidence: [email-review-repro.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/email-review-repro.php>), three concurrency assertions. PHP Fibers model the overlapping request boundaries; mail is intercepted.

### 6. [P2] Failed feedback-token persistence still sends an unusable email link

Location: [class-post-event-feedback.php:108](<G:/Heart Hub Events Plugin/includes/class-post-event-feedback.php:108>), with delivery at lines 62–63 and validation at 112–120.

**Trigger:** The token option write fails while the mail transport works.

**Observed:** `feedback_url()` ignores the write result and returns a URL anyway. The reproduction handed off a message with status sent, but the exact emailed token could not be resolved. A healthy-storage control resolved successfully.

**Impact:** A customer receives an unusable request, and the sent audit suppresses later reminders that could repair it.

**Fix:** Verify the persisted token hash, event, and expiry before sending. Return an error on storage failure, skip delivery, and retain retry eligibility. Apply equivalent persistence checks to other generated attendee links.

Evidence: [email-review-repro.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/email-review-repro.php>), failed token storage and healthy control assertions.

### 7. [P2] Valid Unicode comments are truncated into invalid UTF-8 before storage

Locations: [class-checkin-manager.php:219](<G:/Heart Hub Events Plugin/includes/class-checkin-manager.php:219>), lines 218–219; [class-post-event-feedback.php:95](<G:/Heart Hub Events Plugin/includes/class-post-event-feedback.php:95>), lines 95–96.

**Trigger:** A comment is within the form's 5,000-character limit but exceeds 5,000 bytes, with a multibyte character crossing the byte cutoff.

**Observed:** Both handlers use `strlen()` and `substr(..., 0, 5000)`. A valid 1,251-character comment consisting of one ASCII character plus 1,250 emoji became an invalid UTF-8 5,000-byte storage payload. The injected check-in adapter accepted it and the handler reported attendance recorded.

**Impact:** Comments are shortened despite meeting the UI limit. The resulting malformed payload can be rejected or rendered incorrectly by the storage/rendering layer. Actual database handling of that payload was not tested.

**Fix:** Use a consistent character limit with Unicode-aware counting/truncation, including a safe fallback when mbstring is unavailable. Prefer a clear validation error if oversized text should be rejected.

Evidence: [checkin-unicode-repro.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/checkin-unicode-repro.php>).

### 8. [P2] Cancelled external events still display Register Now

Location: [class-event-public-display.php:155](<G:/Heart Hub Events Plugin/includes/class-event-public-display.php:155>), lines 155–158. The TBC interest display path at lines 89–96 has a related cancellation omission.

**Trigger:** Cancel an event configured for external registration while retaining its organiser URL.

**Observed:** Cancellation disables `registration_enabled`, but the external CTA branch checks neither that field nor `event_cancelled`. The reproduction still rendered Register Now with the organiser link.

**Impact:** Visitors are directed to register for a cancelled event. A cancelled TBC event can also retain its interest button while submission validation rejects the request.

**Fix:** Check cancellation before selecting any registration or interest CTA.

Evidence: [display-edge-repros.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/display-edge-repros.php>), `CANCELLED_EXTERNAL_CTA` output.

### 9. [P2] The calendar removes registration access when an event starts, despite a later closing time

Location: [class-calendar-display.php:487](<G:/Heart Hub Events Plugin/includes/class-calendar-display.php:487>), lines 487–488.

**Trigger:** An event has started but registration is still open under its configured closing time. The editor explicitly permits this.

**Observed:** For an event that started one hour ago, ends one hour ahead, and closes registration in thirty minutes, the public registration button is enabled but the calendar returns no registration status or CTA.

**Impact:** Calendar visitors lose a valid registration route, and the event page and calendar disagree.

**Fix:** Use the configured registration phase consistently instead of treating the start time as the closing time.

Evidence: [display-edge-repros.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/display-edge-repros.php>), `AFTER_START_PUBLIC_REGISTER` and `AFTER_START_CALENDAR_STATUS`.

### 10. [P2] Duplicate calendar popup IDs open details beside the wrong card

Locations: [class-calendar-display.php:665](<G:/Heart Hub Events Plugin/includes/class-calendar-display.php:665>); [calendar-display.js:15](<G:/Heart Hub Events Plugin/assets/calendar-display.js:15>), also line 6.

**Trigger:** Display a multi-day event with multiple middle days, or place events and fundraisers calendars containing the same event on one page.

**Observed:** Popup IDs contain the event ID, original start, and segment, without the occurrence date or calendar instance. A five-day event generated the same middle popup ID three times. Two calendars duplicated the shared IDs. JavaScript uses global `document.getElementById()`, which targets the first match.

**Impact:** Later cards open or close the first popup instead of their own; expanded state and displayed information diverge.

**Fix:** Make IDs unique per calendar instance and displayed occurrence, and resolve a popup within its owning card.

Evidence: [display-edge-repros.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/display-edge-repros.php>), duplicate popup ID output. ID collisions were reproduced from real rendering; browser interaction follows the inspected selector behavior.

### 11. [P2] Zero-capacity events advertise open registration despite rejecting every booking

Location: [class-calendar-display.php:521](<G:/Heart Hub Events Plugin/includes/class-calendar-display.php:521>), lines 521–524.

**Trigger:** Capacity automation is configured, and total and remaining capacity are both zero.

**Observed:** The calendar's `is_full()` returns false for total zero, so it shows Registrations open and Register. The capacity manager reports zero remaining and rejects a positive party size.

**Impact:** Visitors are offered a booking path that cannot accept their registration; waitlist messaging is also wrong when enabled.

**Fix:** Align calendar availability with the capacity manager. If zero should mean unlimited, implement that consistently in both layers; the current backend treats it as zero places.

Evidence: [display-edge-repros.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/display-edge-repros.php>), zero-capacity status output and backend remaining/lock checks.

### 12. [P2] Imported or restored ISO-string event dates can disappear from the calendar

Location: [class-calendar-display.php:338](<G:/Heart Hub Events Plugin/includes/class-calendar-display.php:338>), lines 338–339; migration guard at [class-plugin.php:102](<G:/Heart Hub Events Plugin/includes/class-plugin.php:102>).

**Trigger:** After the one-time date migration has completed, an import, restore, or integration writes a valid supported date string such as `2026-11-05T10:00` instead of an epoch timestamp.

**Observed:** The calendar applies a numeric metadata range query before the shared date parser runs. The string is treated numerically as 2026 and falls outside the epoch range. The reproduction's shared parser returned a valid timestamp while the calendar returned zero occurrences. The migration exits once data version is at least 1.24.3, so later string writes are not repaired.

**Impact:** A published event with a valid date can be absent from the calendar. This is conditional on string-backed dates; normal timestamp-backed events are unaffected.

**Fix:** Normalize subsequent supported writes/imports, or include supported string storage in the range query without losing bounded calendar reads.

Evidence: [display-edge-repros.php](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/display-edge-repros.php>), ISO parsing and missing occurrence output. The WordPress query is modeled by the local fixture; a real database was not used for this reproduction.

## Validation and coverage

Ran the existing local validation runner once successfully against the current source:

```powershell
.\tools\validate-plugin.ps1 -OutputDirectory validation/review-2026-10-02
```

**114 checks passed, 628 assertions, zero failures/skips**: 70 PHP syntax checks, 12 JavaScript syntax checks, 31 PHP fixtures, and one JavaScript fixture. Runtime versions were PHP 8.3.33 and Node 24.15.0. The installed PHP executable required an approved execution outside the filesystem sandbox after the initial launch failed. Results: [results.json](<G:/Heart Hub Events Plugin/validation/review-2026-10-02/results.json>).

Additional targeted reproductions used current runtime classes and synthetic storage, transport, and request interleavings. The email reproduction passed seven assertions. These cases expose gaps beyond the existing passing fixtures.

Source review covered registration normalisation, CCT creation/update and customer history, review/self-service/capacity transitions, support programme/applicant flows, check-in and attendance, audit persistence, email templates/queues/recovery and feedback, event/calendar display and editor behavior, settings/access/bootstrap, and uninstall retention.

Not checked: a running WordPress/MariaDB integration environment, licensed JetEngine/JetFormBuilder compatibility or the installed site's actual field mappings/form actions/templates, live browser behavior, SMTP/inbox delivery and bounces, or a fresh PHP 7.4/8.4 matrix. Docker was installed but its daemon was unavailable. Earlier validation artifacts were not treated as fresh verification. Mail status sent in these reproductions means successful handoff to the intercepted transport, not proven inbox delivery.

Recommended order: fix findings 1–2 first, then duplicate creation and email recovery/token handling, then comment capture and calendar/display consistency. Add focused regression cases for the reproduced failures when implementing the fixes.
