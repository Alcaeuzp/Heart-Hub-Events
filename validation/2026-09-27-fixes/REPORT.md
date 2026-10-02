**Heart Hub Event Registration Manager 1.34.0 — fixes and adversarial validation, 27 September 2026**

All 11 confirmed defects from the 1.33.0 audit have local fixes and passing regression coverage. Additional fault-injection and concurrency checks found further issues, which were corrected before the final run. The updated plugin is packaged; no production site or customer data was changed and no real email was sent.

The final suite passed **111 checks, including 534 assertions across 32 executable fixtures**, with no failures, warnings or skips. These are local tests using production classes with WordPress/JetEngine test adapters and disposable SQLite databases. They do not certify the installed site's integrations.

**Release and evidence**

- [Installable 1.34.0 ZIP](<G:/Heart Hub Events Plugin/heart-hub-event-registration-manager-1.34.0.zip>) — 67 runtime files, 261,611 bytes. Every entry matches the tested source. Tests, tooling, validation documents and older ZIPs are excluded.
- [Full results and assertion output](<G:/Heart Hub Events Plugin/validation/2026-09-27-fixes/results.json>), [run log](<G:/Heart Hub Events Plugin/validation/2026-09-27-fixes/run.log>), [package verification](<G:/Heart Hub Events Plugin/validation/2026-09-27-fixes/package-results.json>), [changed runtime files](<G:/Heart Hub Events Plugin/validation/2026-09-27-fixes/changed-runtime-files.json>).
- ZIP SHA-256: `266DBB54E6476B48ED96209B239AC0BE60C9404436323921331D05FFD835FD51`.
- The original 1.33.0 ZIP and its [audit report](<G:/Heart Hub Events Plugin/validation/2026-09-27/REPORT.md>) remain available. Original runtime files were also retained under this report's `baseline` directory for comparison.

**Corrections to the original findings**

| Original finding | Implemented behavior | Main regression evidence |
|---|---|---|
| 1. Concurrent capacity changes use stale reservation state | Shared token-owned registration locks cover staff review, attendee changes and attendance. Event locks span capacity calculation, registration writes and compensation; the ledger is read after acquiring the lock. Stale owners cannot release replacement locks. | `audit-performance-workflows.php`, `adversarial-registration-storage.php` |
| 2. Withdrawal crashes when returning an error | Corrected the return contract and made failed withdrawal reconcile capacity against the actually persisted status. | Workflow and storage adversarial fixtures |
| 3. Partial check-in silently loses released places | Retry reconciles the saved attendance and unused capacity exactly once. Incomplete saved party information produces an explicit repair error instead of guessed capacity or false success. | `checkin-workflow.php`, storage adversarial fixture |
| 4. Failed zero-capacity write looks successful | Verification requires metadata to exist and hold a valid saved value. Failed ledger writes trigger checked compensation; failed compensation is reported explicitly. | Capacity fault-injection fixture |
| 5. Blank legacy Pending registrations cannot be reviewed | Normalized missing/blank states consistently for detail, listing, review, statistics and notifications. | `audit-customer-persistence.php` |
| 6. Legacy interest aliases miss notifications | Canonical interest handling now includes the recognized legacy values in recipient and registration workflows. | Customer and interest fixtures |
| 7. Programme deletion removes future interest | New future-interest submissions use an independent pool. Legacy interest rows survive deletion and remain accessible even when the old programme is gone. | `audit-retention-validation.php` |
| 8. Applicant deletion leaves email personal data | Identified support email audit payloads are redacted before applicant deletion. Source/programme checks preserve unrelated event records sharing the same numeric ID; operational delivery status remains. Ambiguous legacy data stops deletion for manual identification. | Retention fixture: source collisions, legacy payloads, 205-row redaction |
| 9. Failed programme deletion hides surviving applicants | Child writes and personal-data cleanup are checked before removing the programme. Failures retain the parent and remaining accessible records, and show failure. Processing is bounded and refuses a no-progress delete. | Retention fixture: database faults and multi-batch deletion |
| 10. Payment history calls refunded amounts Paid | Amount and payment status are displayed separately; unknown status is explicit and output is escaped. | `audit-dashboard.js` |
| 11. CSV silently stops at 10,000 rows | A 10,001-record export completes. Frozen filters, row counts, page counts and unique IDs are checked. Incomplete/failed responses produce no download. Above 100,000 matches, a clear message requires narrower filters. | Dashboard fixture: complete, duplicate, changed, malformed, failed and empty responses |

**Additional fixes and adversarial findings resolved**

Registration updates now read back every requested field, recognize `WP_Error` and thrown storage exceptions, and attempt to restore touched fields after partial writes. If restoration also fails, capacity follows the actual persisted registration where possible; otherwise the response and audit identify a reconciliation failure. This covers party sizes, contact details, dietary fields and approval status without claiming that arbitrary database failures can always be repaired automatically.

The integrated storage fixture runs the real repository, REST controller, capacity manager, self-service and check-in classes together. Its **67 assertions** include partial and truncated writes, exceptions before/after writes, failed/partial compensation, approval/decline failure, repeated check-in and replacement locks. It exposed the incomplete-check-in retry defect described above. Decision email delivery now occurs after releasing the event capacity lock, while the registration remains protected against duplicate review.

Capacity compensation branches now surface a second write failure, and partial-release markers belong to a particular reservation cycle. An old check-in marker therefore cannot suppress release after a later reservation.

Support mutation locks close submission-versus-programme-deletion and confirmation-versus-applicant-deletion races. Programme state is re-read after locking. Locks are released on both exceptions and WordPress redirect/JSON exits. Legacy status-only interests also retain their type when declined and use the correct list/navigation routes.

The support workflow now includes confirmation of pending applicants, an atomic Pending-to-Confirmed transition and duplicate-send protection. Delivery failures and thrown transport errors retain the confirmed registration with an accurate warning. Applicant and interest lists use 50-row pages and actual totals. Programme/settings saves are checked, and schema version 4 is recorded only after required columns and the replacement unique index are verified.

Email recovery moved off ordinary requests into hourly maintenance with batches of 100 and continuation scheduling. A dedicated adversarial test found that legacy queue migration could cancel unrelated current callbacks; migration now preserves them. Scheduling failure retains the durable job for a later retry, and stale callbacks cannot delete a newer generation.

Uninstall clears the added recovery hooks, cursors, support locks and previously missed plugin-owned temporary options. A disposable two-site SQLite fixture checks prefix escaping, unrelated-option preservation and original-site restoration. Existing behavior is unchanged: uninstall removes support/audit tables while preserving Events posts and Event Registrations CCT data; deactivation preserves records. Uninstall is therefore not a harmless reset for support history.

**Performance evidence and limits**

| Area | Measured local result | Practical limit |
|---|---|---|
| Registration dashboard | The 50,000-record fixture reads 50,000 rows once, replacing 100,000 rows across two unlimited reads. Each query requests at most 500 rows and retains only a batch plus the requested page. | Exact global totals still require O(n) processing and 101 adapter calls in this fixture, including the final empty read. Lower live latency has not been established. |
| CSV export | Global statistics are omitted; deterministic ordering and completeness checks prevent silent truncation. | Matching totals still require scans. Large exports remain expensive, the explicit limit is 100,000, and the export is not a transactional snapshot. Equal-count replacements during export may escape drift detection. |
| Email recovery | Ordinary initialization schedules maintenance without enumerating jobs. A 250-job test advances in 100/100/50 batches. | Recovery depends on WP-Cron running. If continuation scheduling fails, the next hourly run resumes the retained jobs. |
| Support lists/deletion | Lists page by 50; deletion and audit cleanup process batches of 100. | Deletion is retryable but not all-or-nothing: earlier successful deletions remain deleted if a later row fails. |

**Final validation**

| Check | Result |
|---|---|
| PHP syntax: runtime and tests | 67/67 passed |
| Shipped JavaScript syntax | 12/12 passed |
| PHP fixtures | 31/31 passed, 524 assertions |
| JavaScript fixture | 1/1 passed, 10 assertions |
| Release integrity | 67/67 entries match source; version metadata agrees; no extra entries |
| Runtime | PHP 8.3.33 with PDO SQLite; Node.js 24.15.0 |

All former diagnostic scripts now assert corrected behavior. The runner executes them by default and rejects skips, PHP diagnostics and fixtures with zero counted assertions. Independent cross-file reviews covered registration/capacity integration, support retention and email recovery; no unresolved local blocker remained in those checks.

Rerun from the plugin directory:

```powershell
.\tools\validate-plugin.ps1 -OutputDirectory validation/latest
```

The release was built with `tools/build-release.ps1` after the final passing run, then compared byte-for-byte with the runtime source. No further runtime changes followed that verification.

**Remaining integration checks and separate improvement work**

Live WordPress/JetEngine/MySQL, installed form mappings, real simultaneous HTTP requests, browser layout/interaction, SMTP delivery, actual multisite installation/uninstall and PHP 7.4 execution were not validated. New production code keeps PHP 7.4-compatible syntax, but execution on PHP 8.3 does not certify the advertised minimum. JetEngine creation retains its existing hook lifecycle and requires a real staging save/reload check.

Before deployment, use a disposable staging copy with a mail catcher to submit and reload a synthetic customer, edit every mapped field in a fresh request, exercise review/withdrawal/partial attendance, race requests for the final seats, upgrade support storage, confirm an applicant, delete an old programme while preserving future interest, and verify redaction and queued mail recovery. If both storage and compensation fail, staff repair can still be necessary; the plugin now exposes that outcome.

Stable customer IDs/linking, configurable retention periods, WordPress personal-data export/erasure, a reconciliation/retry screen, and further database-side aggregation remain separate improvements from the audit. Customer history still groups records by email; these changes do not introduce a new customer identity system or automatically purge historical data.
