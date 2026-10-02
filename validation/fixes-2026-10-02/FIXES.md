# Review fixes — 2 October 2026

Heart Hub Event Registration Manager **1.34.2** implements the 12 findings from the [1.34.1 review](../review-2026-10-02/REVIEW.md). Changes preserve the existing WordPress, JetEngine CCT, JetFormBuilder, capacity-ledger, and email-template architecture.

## Changes and regression coverage

| Review finding | Implemented change | Regression coverage |
| --- | --- | --- |
| 1. Lost party size | Keep validated submitted fields until the CCT callback; repair and verify the saved party size before reserving places. | `jetform-dietary.php`, `registration-create-persistence.php` |
| 2. Incomplete customer records reported as received | Read back inserts and updates; reject and remove incomplete new records before notification. Propagate failed dietary/customer-field persistence. | `registration-create-persistence.php`, `audit-customer-persistence.php` |
| 3. Duplicate interest submissions | Use an atomic lock for the event and normalized email across lookup and creation. Failed lookup stops insertion; request shutdown releases abandoned locks. | `interest-workflow.php`, `registration-create-persistence.php` |
| 4. Missing email recovery | Retry failed event/support acknowledgements and internal notifications using stored record identifiers and current templates. Keep support approval independent of delivery; provide a capability- and nonce-protected confirmation resend. | `direct-email-recovery.php`, `support-confirmation-recovery.php`, existing email fixtures |
| 5. Concurrent feedback delivery | Use atomic global and registration locks; reread eligibility and delivery audit under the lock. Reuse the persisted feedback token on retry. | `feedback-delivery.php` |
| 6. Unpersisted attendee links | Verify feedback, check-in, replacement, revocation, and management-link storage before returning success or exposing a link. Failed feedback storage leaves the message eligible for recovery. | `feedback-delivery.php`, `attendee-link-persistence.php` |
| 7. Broken Unicode comments | Validate UTF-8 and count characters, with an mbstring-free fallback. Preserve comments within 5,000 characters; reject larger input without truncation or writes. | `checkin-workflow.php`, `feedback-delivery.php` |
| 8. Cancelled-event buttons | Apply cancellation before selecting external, website, or interest buttons and remaining-capacity display. | `event-public-display.php` |
| 9. Registration after event start | Keep the calendar registration action until the configured closing phase; completed events still suppress booking actions. | `calendar-display.php`, `event-registration-phase.php` |
| 10. Colliding calendar popups | Include calendar instance and occurrence date in IDs; JavaScript opens/closes the popup inside the owning card. | `calendar-display.php`, `calendar-display.js` |
| 11. Zero-capacity messaging | Treat configured zero available places as full, matching backend capacity enforcement and waitlist rules. | `calendar-display.php` |
| 12. Imported ISO dates | Add bounded numeric and string date-query branches, then use the existing date parser and occurrence-range filtering. | `calendar-display.php` |

The new retry descriptors contain record identifiers and delivery context, without copied recipients or message bodies. Automatic direct-email delivery has at most three attempts; hourly recovery restores missing cron jobs. Retries use current records and stop for deleted or changed customer contexts. Uninstall removes the new descriptors, recovery schedules, cursors, and temporary locks while retaining event and JetEngine CCT data.

Save verification tolerates the absence of optional `user_id` and interest `registration_type` columns in existing CCT schemas. It still requires submitted customer fields, registration status, party size, and registration dates to match the saved record.

Additional fault checks prevent duplicate confirmation after a successful mail handoff but failed delivery-status save, and prevent a fourth automatic attempt when terminal-job cleanup fails. Both use checked delivery audit evidence. Replaced lock owners stop before changing a successor's token or retry descriptor. Complementary support tests check record-bound nonces, mismatched applicants/programmes, log mode, recovery-button visibility, and data retention when job cleanup fails.

JetEngine incomplete-record cleanup uses the handler's noninteractive `raw_delete_item()` API, documented in [Crocoblock's CCT CRUD example](https://gist.github.com/Crocoblock/a9be7dbb1cb05aa2741aec97757c7f72). A cleanup or capacity rollback that cannot be verified reports an explicit organiser-action error instead of claiming a completed application.

## Validation

Ran the complete local validation suite:

```powershell
.\tools\validate-plugin.ps1 -OutputDirectory validation/fixes-2026-10-02
```

**127 checks passed, 771 assertions, zero failures or skips**: 77 PHP syntax checks, 12 JavaScript syntax checks, 36 executable PHP fixtures, and two executable JavaScript fixtures. PHP fixtures ran with `E_ALL` and displayed diagnostics; the runner detected none. The runtime was PHP 8.3.33 and Node 24.15.0. The runner enabled PHP's bundled SQLite extension for the isolated persistence/uninstall cases. Installed PHP required approved execution outside the filesystem sandbox. Full output is in [results.json](results.json).

The added fixtures cover registration creation/schema persistence, attendee links, direct-email recovery, support-confirmation recovery, feedback delivery, and calendar popup interaction. Existing workflow, capacity, retention, customer-history, display, and email fixtures also passed. The shared email adapter runs the real `Audit_Log`, `Email_Service`, `Support_Groups`, and feedback classes, avoiding a substituted audit API shape.

Built [heart-hub-event-registration-manager-1.34.2.zip](<G:/Heart Hub Events Plugin/heart-hub-event-registration-manager-1.34.2.zip>) with the existing release builder, then ran:

```powershell
.\validation\fixes-2026-10-02\verify-release.ps1
```

The package contains **68 runtime/licence files**, **273,666 bytes**, with matching bootstrap/header/readme version **1.34.2**. Every packaged file matches the source SHA-256, all paths are under the correct plugin root, and tests, tooling, validation notes, and previous releases are excluded. Package verification and the 15 changed runtime paths are recorded in [package-results.json](package-results.json).

ZIP SHA-256: `F98CFD6EF53836F22401FC6616B2AC0B05524FAC040500791E7434CA384B79F7`.

## Follow-up upgrade review

The follow-up read-only review found no additional upgrade defect. Updating an already-active 1.34.1 installation enables direct-email recovery through the normal bootstrap and `init` scheduling; reactivation is unnecessary. The existing decision-email queue, audit implementation, support schema version, and one-time event-date migration routines are unchanged. New descriptor/lock prefixes remain separate from existing jobs and links. Deactivation retains descriptors so normal recovery can restore schedules after reactivation.

For installation, use WordPress's **Replace current with uploaded** workflow with the 1.34.2 ZIP. This retains existing support applicants and audit history; WordPress plugin deletion invokes the existing uninstall policy that removes those plugin-owned tables. After updating on staging, check the displayed version, direct-email recovery hooks, a multi-attendee registration with dietary details, support confirmation recovery, and customer/staff inbox delivery.

The available in-app browser had no existing authenticated WordPress session. Navigation and tab inspection timed out, and the public Cloudways events URL could not be accessed through the web tool either. This establishes a limitation of the available access, not that the site is down. No site upload or update was performed during this follow-up.

## Limits

Local fixtures exercise runtime classes with WordPress/JetEngine/storage/mail adapters, including fault injection and overlapping requests. A live WordPress/JetEngine/JetFormBuilder installation, browser presentation, MariaDB execution, and SMTP/inbox delivery have not been verified in this implementation pass. Docker is installed but its daemon is unavailable. No deployed site was changed. Existing historical details that were never stored cannot be reconstructed by these fixes.

Unexpired feedback links created by older versions keep their original hash-only token record. If delivery was not recorded successfully, these old links cannot be rebuilt for an automatic retry without invalidating a potentially mailed URL, so the new code preserves them and reports the limitation in the delivery audit. Newly generated tokens support safe reuse and retries. The new acknowledgement/staff retry descriptors are created for failures occurring after this update; they do not retroactively resend historical failed notifications.
