**Heart Hub Event Registration Manager 1.33.0 — validation audit, 27 September 2026**

The existing local tests pass, but targeted fault and concurrency probes reproduce several defects. The highest priority is capacity integrity, followed by retention/deletion behavior and failure handling. Normal profile edits work in the isolated test environment; production customer saving has not been certified.

Production PHP, JavaScript, settings and release ZIPs were not changed. This audit adds validation tooling, five diagnostic scripts and this report.

**Validation evidence**

| Check | Result |
|---|---|
| Existing PHP fixture suite | 24/24 passed, 338 assertions; no skips in the final run |
| PHP syntax | 62 files passed: runtime plus original/new PHP tests |
| Shipped JavaScript syntax | 12/12 passed, including bundled QR library |
| Targeted diagnostic scripts | Five executed successfully; their outputs reproduce defects and measure workload, not certify corrected behavior |
| Release integrity | 1.33.0 ZIP: 66 runtime files, all identical to local source; no tests/tooling/old ZIPs included |
| Runtime used | PHP 8.3.33; Node.js 24.15.0 |

The decision-email fixture initially exited successfully while printing SKIP because SQLite was not enabled. It was subsequently executed with the installed PDO SQLite extension and all 11 assertions passed. The new runner treats skips and PHP warnings as unsuccessful validation.

Evidence: [results.json](<G:/Heart Hub Events Plugin/validation/2026-09-27/results.json>), [package-results.json](<G:/Heart Hub Events Plugin/validation/2026-09-27/package-results.json>). ZIP SHA-256: `E2F68668F5CDB8E1CD7BBC4395D9DC3651C67B6AF51E2A52CE89B71BA6968634`.

**Confirmed behavior needing correction**

P1 means fix first because of capacity/data integrity impact. P2 means a reproducible defect under the stated conditions. These reproductions execute production classes/functions with synthetic database, WordPress or browser adapters; they do not establish that a fault has already occurred on the live site.

1. **P1 — Concurrent capacity changes can return seats twice or lose capacity.** Reservation state is read before the event lock is acquired. Another request can finish between that read and the lock, leaving the first request to apply stale state. Deterministic interleavings produced 8 remaining seats instead of 6 after two withdrawals, 1 instead of 3 after overlapping party edits, and 6 instead of 5 after partial check-ins. Acquire the lock before reading the reservation ledger, then calculate and persist from fresh state. Cover the complete registration/capacity transition so different entry points cannot race. Evidence: [class-capacity-manager.php:120](<G:/Heart Hub Events Plugin/includes/class-capacity-manager.php:120>), also lines 161 and 198; `audit-performance-workflows.php`.

2. **P2 — Self-service withdrawal crashes on recoverable failures.** `withdraw()` declares an array return type but returns a `WP_Error` when a capacity lock is busy or saving the registration fails. Both branches produced a PHP `TypeError`, bypassing the normal user-facing error handling. Correct the return contract while retaining PHP 7.4 compatibility, and verify rollback results. Evidence: [class-registration-self-service.php:154](<G:/Heart Hub Events Plugin/includes/class-registration-self-service.php:154>); `audit-performance-workflows.php`.

3. **P2 — Partial check-in reports success while failing to return unused seats.** Attendance is saved before `release_unused()` runs, and its failure is ignored. A retry sees `checked_in_at` and returns early, so the missed release is never recovered. A simulated lock conflict left one unused seat unavailable. Make the adjustment retryable and reconcile it before marking the operation complete. Evidence: [class-checkin-manager.php:241](<G:/Heart Hub Events Plugin/includes/class-checkin-manager.php:241>); `audit-performance-workflows.php`.

4. **P2 — A failed write of zero remaining seats can be mistaken for success.** Missing post metadata is cast to integer zero in verification. If creation of the remaining-capacity field fails while reserving the last seats, the ledger says reserved but `remaining()` falls back to total capacity. The probe reserved the final two seats while both stayed available. Require metadata existence and a valid stored value, as the total-capacity writer already does. Evidence: [class-capacity-manager.php:312](<G:/Heart Hub Events Plugin/includes/class-capacity-manager.php:312>); `audit-performance-workflows.php`.

5. **P2 — Some legacy Pending registrations cannot be approved and miss cancellation messages.** Blank statuses are treated as Pending in the list but remain blank in the detail response. Approval controls disappear and an approval request is rejected as already reviewed. Those records are also excluded from cancellation recipients and pending statistics. Normalize legacy empty/missing states consistently at the repository boundary. Evidence: [class-cct-repository.php:327](<G:/Heart Hub Events Plugin/includes/class-cct-repository.php:327>), [class-rest-controller.php:186](<G:/Heart Hub Events Plugin/includes/class-rest-controller.php:186>); `audit-customer-persistence.php`.

6. **P2 — Legacy expressions of interest miss date-confirmed emails.** The admin list recognizes `expression_of_interest`, but the notification query filters for raw `interest` before normalization. The probe found the record in the Interest list and no recipient for its notification. Include recognized legacy values or migrate them with verification. Evidence: [class-cct-repository.php:256](<G:/Heart Hub Events Plugin/includes/class-cct-repository.php:256>); `audit-customer-persistence.php`.

7. **P2 — Deleting a completed support programme erases future interest.** Post-cutoff submissions are explicitly expressions of interest in a later programme but remain attached to the completed programme. Its deletion removes those rows. The actual submit/delete handlers reproduced this loss. Store future interest in the independent pool or migrate/preserve it when deleting the old programme. Evidence: [class-support-groups.php:597](<G:/Heart Hub Events Plugin/includes/class-support-groups.php:597>), deletion at line 524; `audit-retention-validation.php`.

8. **P2 — “Permanent” applicant deletion leaves personal information in audit logs.** Deletion removes the support-applicant row only. Log-only emails retain rendered HTML containing email/contact details and the reason for joining; sent-email audit entries still retain recipient addresses. The probe deleted an applicant and recovered its email and sensitive reason from audit data. Add source-aware personal-data redaction/erasure, since support and event registrations share numeric IDs in the audit table. Evidence: [class-support-groups.php:568](<G:/Heart Hub Events Plugin/includes/class-support-groups.php:568>), [class-email-service.php:477](<G:/Heart Hub Events Plugin/includes/class-email-service.php:477>); `audit-retention-validation.php`.

9. **P2 — Failed programme deletion reports success and strands applicant rows.** The parent programme option is removed before the applicant-table deletion, and write results are ignored. Injecting a failed database delete removed the programme, left its applicants, and redirected to a success notice; the normal editor can no longer open those applicants. Preserve/recover the parent if child deletion fails and report the actual outcome. Evidence: [class-support-groups.php:517](<G:/Heart Hub Events Plugin/includes/class-support-groups.php:517>); `audit-retention-validation.php`.

10. **P2 — Payment history hides contradictory payment statuses.** Whenever an amount exists, history labels it “Paid” and suppresses the saved payment status. A record with amount 25.00 and status refunded displayed “Paid $25.00” without the refund. Show amount and status separately and only claim payment when the mapped status supports it. Evidence: [dashboard.js:25](<G:/Heart Hub Events Plugin/assets/dashboard.js:25>); `audit-dashboard.js`.

11. **P2 — CSV export silently stops at 10,000 registrations.** The export loop caps itself at 200 pages of 50 records, although its UI promises matching applications. A synthetic 10,001-record result downloaded 10,000 without warning. Use a complete, bounded server export or explicitly report truncation and offer continuation. Evidence: [dashboard.js:106](<G:/Heart Hub Events Plugin/assets/dashboard.js:106>); `audit-dashboard.js`.

**Customer saving and retention assessment**

A positive-control edit passed first name, last name, email, phone, organisation, reason, party size and both dietary fields through the real REST controller/repository into the test adapter; every requested value read back correctly and unrelated fields were retained. Existing fixtures also cover dietary clearing, input rejection, self-service sanitization and capacity rollback.

There is a conditional robustness gap in [class-cct-repository.php:180](<G:/Heart Hub Events Plugin/includes/class-cct-repository.php:180>): update methods trust a truthy handler return and do not compare requested fields with the returned record. Injecting an adapter that discarded dietary columns produced a success response/audit despite unchanged values; an injected `WP_Error` was also treated as truthy success. These are fault-handling weaknesses, not evidence that the installed JetEngine currently discards customer data. Validate required schema/mappings and verify critical persisted fields before displaying “saved”.

There is no independent customer profile identity: history groups registrations by email and edits change one registration. Correcting an email can split history; shared household addresses combine histories. An explicit customer ID and linking/correction workflow would make long-term histories more reliable while keeping per-event registration snapshots.

Deactivation preserves saved records. Uninstall deliberately removes the plugin audit and support-applicant tables, settings and temporary links, while retaining Events posts and Event Registrations CCT data. This matches `uninstall.php` and `readme.txt`; do not use uninstall as a harmless reset when support-applicant history matters. Audit deletion also removes operational capacity/email state, so restoration/reinstallation needs reconciliation testing.

No configurable record-retention period, anonymization job, or WordPress personal-data exporter/eraser was found. Add retention by data category, expiry cleanup and a customer export/erasure workflow. **Do not simply purge old audit rows:** capacity accounting and email idempotency depend on them. Separate durable operational state from personal email content first.

**Performance and feature improvements**

| Priority | Evidence and impact | Focused improvement |
|---|---|---|
| High | A 20-row dashboard page performs two unlimited registration queries. The 50,000-row synthetic probe fetched/normalized 100,000 rows. [Repository:60](<G:/Heart Hub Events Plugin/includes/class-cct-repository.php:60>), statistics at line 101. | Database-side pagination/filtering through supported JetEngine APIs; aggregate/cache statistics with invalidation. Normalize legacy statuses so they do not require loading all rows. |
| High | Email recovery runs on every WordPress `init`. For 1,000 already-scheduled jobs the probe recorded one options enumeration, 1,001 option reads and 1,000 cron lookups. [Email automation:172](<G:/Heart Hub Events Plugin/includes/class-email-automation.php:172>). | Move recovery to bounded periodic maintenance and keep ordinary requests independent of queue size. Counts are API calls, not necessarily separate SQL queries under object caching. |
| High | First-time support applicants become Pending, but their detail screen provides Decline/Delete and no Confirm action. [Support groups:739](<G:/Heart Hub Events Plugin/includes/class-support-groups.php:739>). | Complete the staff approval workflow, with a confirmation message and audited transition. Add contact correction and interest-to-programme invitations. |
| Medium | Support applicant queries stop at 500 rows and the interest pool at 1,000 with no pagination. Older rows stay stored but disappear from available list navigation. [Support groups:765](<G:/Heart Hub Events Plugin/includes/class-support-groups.php:765>). | Pagination, searching and actual total counts; export all matching records. |
| Medium | Check-in loads an event's approved registrations before matching email; reporting and feedback jobs can process broad record sets. | Indexed event/email lookup, cached attendance summaries, and bounded feedback batches. Validate with representative staging data. |
| Medium | Delivery failures and capacity inconsistencies need code/audit inspection to diagnose. | Queue status/retry screen, capacity reconciliation tool, and actionable schema/mapping health checks. |

The scaling probes measure deterministic work counts, not production latency or memory benchmarks. They show where growth adds work without claiming a current live slowdown.

Other static follow-ups: support schema installation marks version 3 without confirming migration success; expired management/feedback options are mainly cleaned when revisited; uninstall leaves minor design/schedule/retry options. Test migration failure recovery and bounded cleanup on a real database.

**Coverage limits and remaining integration checks**

No production data was changed or real email sent. Live WordPress/JetEngine/MySQL save-reload behavior, the installed form mappings, schema upgrades, browser interactions, SMTP delivery, real simultaneous requests, PHP 7.4 compatibility, and multisite uninstall were not validated. Passing PHP 8.3 syntax does not certify the advertised PHP 7.4 minimum.

An attempt to open the WordPress admin address in historical project notes was blocked by automatic approval review because access to that specific site/account was not authorized. Read-only inspection of that destination is pending the user's permission. Even read-only inspection cannot prove successful writes; destructive and email-generating integration tests belong in a disposable staging copy.

The staging pass should save a synthetic customer through the actual registration form, reload it through CCT and admin views, edit every profile/dietary field, and confirm persistence in a fresh request. Then exercise approve/decline/waitlist/withdraw/partial attendance, simultaneous final-seat requests, failed writes, date-confirmed messages, queued retries, support approvals, future-interest retention, deletion/redaction and migration rollback. Use a mail catcher and compare database state/capacity after each transition.

**Rerunning the local suite**

From the plugin directory, run:

```powershell
.\tools\validate-plugin.ps1 -OutputDirectory validation/latest -IncludeAuditProbes
```

[validate-plugin.ps1](<G:/Heart Hub Events Plugin/tools/validate-plugin.ps1>) checks syntax, runs all original fixtures and optionally runs the audit probes. It enables the installed Windows PDO SQLite extension when available and treats missing-dependency skips as failures. The audit scripts intentionally assert current defects; after fixing a defect, replace its diagnostic expectation with a desired-behavior regression test. A green diagnostic result means reproduction succeeded, not that the plugin is defect-free.

Suggested fix order: capacity and rollback integrity; future-interest preservation and deletion/redaction; consistent status/save verification and the support approval action; payment/export correctness; then pagination, queue maintenance and reporting performance.
