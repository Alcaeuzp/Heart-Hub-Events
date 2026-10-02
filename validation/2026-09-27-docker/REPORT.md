**Heart Hub Event Registration Manager 1.34.1 — secondary edge-case and Docker validation**

The secondary pass found and fixed additional defects, including a lock race that only appeared with competing real WordPress processes. All available fixtures now pass in Docker on PHP 7.4, 8.3 and 8.4, along with JavaScript and real WordPress/MariaDB integration tests. No production site, customer data or mail service was used.

**Results**

| Environment / suite | Final result |
|---|---|
| PHP 7.4.33 | 70 syntax checks and all 31 PHP fixtures passed; 617 assertions |
| PHP 8.3.35 | 70 syntax checks and all 31 PHP fixtures passed; 617 assertions |
| PHP 8.4.26 | 70 syntax checks and all 31 PHP fixtures passed; 617 assertions |
| Node.js 24.21.0 | 12 shipped-JavaScript syntax checks and the dashboard fixture passed; 11 assertions |
| WordPress 7.1.2 + PHP 8.3.35 + MariaDB 12.3.3 | 40 native integration assertions passed |
| Actual parallel PHP processes against MariaDB | Four concurrency scenarios passed in five successive rounds; 10 assertions per round, 160 worker processes total |
| Final WordPress debug log | Empty |
| Release ZIP | All 67 entries match source; no development files or extra entries |

The same 617 PHP assertions ran on each PHP version; these are repeated compatibility executions, not 1,851 different tests. The native integration suite uses real WordPress options, metadata, nonces, capabilities, database schema and persistence. It intercepts redirect termination and email transport. The capacity concurrency test uses separate PHP processes and native MariaDB operations, rather than a simulated scheduler.

**New defects fixed**

| Finding | Correction and evidence |
|---|---|
| **P1: WordPress option upserts can replace a competing lock.** Native `add_option()` checks existence and then uses `INSERT ... ON DUPLICATE KEY UPDATE`. Concurrent contenders could both proceed, overwrite ownership and produce failed or inconsistent capacity operations. The first real-process runs reproduced this. | `Mutation_Lock` now uses atomic `INSERT IGNORE`, checks the result, and invalidates both positive and negative option caches. Token-checked deletion remains atomic. Email queue locks use the same helper. Final-seat allocation, repeated release/reservation and partial refunds passed five rounds after the fix. |
| **P1: An expired/replaced owner can continue a save or compensation.** A storage hook could replace the registration lock between reads, capacity changes and the CCT write, or before repository compensation. | Ownership is checked before and after writes. Repository write callbacks stop stale compensation. Queued mail rechecks ownership after loading its registration, protecting a newer job generation. Five integrated storage-hook cases cover the different mutation entry points. |
| **P1/P2: Invalid reservation state can change the wrong event or return unreserved seats.** Wrong event IDs, corrupt party values, unknown ledger statuses, invalid remaining counters and partial releases without an active reservation were not consistently rejected. | Capacity transitions and snapshots validate the ledger, event identity, whole-number limits and refund markers. They return actionable errors before unsafe mutations. Repeated partial refunds must agree with the original recorded amount. |
| **P1: A failed audit SELECT can look like no reservation.** Treating a database error as an empty ledger can double-reserve or report a release that never happened. | New `latest_entry_checked()` distinguishes absence from SQL failure or invalid data. Capacity transitions and rollback snapshots propagate those errors. Native SQL-fault tests and focused workflow regressions cover this. |
| **P1: Malformed bulk IDs can target another registration.** Values such as nested arrays, booleans, negatives and fractional IDs could be coerced through `absint()` into valid record IDs. | Bulk review validates IDs before any mutation and rejects malformed inputs. |
| **P2: Cancelled events can still receive approvals.** | Review now rejects approval of an explicitly cancelled event. |
| **P2: Temporary registration read errors can discard queued email.** | Temporary read failures retain and retry the durable job. Missing records remain distinct from transient storage failures. Replacement-worker tests also ensure an older callback cannot overwrite a newer job. |
| **P2: Query failures can masquerade as empty history or no recipients.** | Customer/event/attendance/cancellation/interest queries propagate failures. The dashboard displays unavailable history separately from a genuinely empty history, while preserving the result of a successful edit. |
| **P2: Support text limits cut Unicode by bytes.** Real submissions with multibyte names/reasons failed or lost valid text; the interest preview could split a character. | Limits and previews preserve complete Unicode characters. The fallback also passes without mbstring. Native WordPress/MariaDB tests cover the saved values. |
| **P2: Distinct long email addresses collide in a prefix-only unique index.** The native database rejected two valid addresses sharing their first 90 characters. | Support schema 5 uses complete programme/email/type values. A checked atomic index replacement preserves the old key and migration marker on failure. Input validation also rejects malformed non-scalar or overlength email values. |

PHP 7.4 initially exposed six test-harness failures caused by PHP 8-only string helpers and changed private reflection behavior. Those fixtures were made compatible without adding production polyfills or masking missing production functions. The final full PHP 7.4 run passes.

**Concurrency evidence**

Each round launches eight workers at a synchronization barrier for each scenario:

1. Eight different registrations compete for three seats: exactly three succeed and five receive the capacity-full result; remaining capacity is zero.
2. Eight workers release the same reservation: all retries complete, with exactly one seat returned.
3. Eight workers reserve the same registration: all retries complete, consuming exactly one seat.
4. Eight workers repeat a partial check-in: exactly two unused seats return once, and no lock remains afterward.

The failed pre-fix runs led to inspection of actual WordPress option SQL and the atomic-insert correction. The final runs use the same production capacity, audit and lock classes. They test competing processes, not browser HTTP requests or JetEngine writes.

**Release and evidence**

- [Installable plugin 1.34.1](<G:/Heart Hub Events Plugin/heart-hub-event-registration-manager-1.34.1.zip>) — 67 files, 265,121 bytes.
- SHA-256: `DC2C7B01EF1ECD9E4E6FD2C1BBC7310C658E24382E4743058758A2BD95F530B7`.
- [Matrix summary](<G:/Heart Hub Events Plugin/validation/2026-09-27-docker/matrix.json>), [PHP 7.4](<G:/Heart Hub Events Plugin/validation/2026-09-27-docker/php-7.4.json>), [PHP 8.3](<G:/Heart Hub Events Plugin/validation/2026-09-27-docker/php-8.3.json>), [PHP 8.4](<G:/Heart Hub Events Plugin/validation/2026-09-27-docker/php-8.4.json>), [JavaScript](<G:/Heart Hub Events Plugin/validation/2026-09-27-docker/javascript.json>).
- [Native integration](<G:/Heart Hub Events Plugin/validation/2026-09-27-docker/wordpress-mariadb.json>), [concurrency worker results](<G:/Heart Hub Events Plugin/validation/2026-09-27-docker/capacity-concurrency.json>), [package verification](<G:/Heart Hub Events Plugin/validation/2026-09-27-docker/package-results.json>), [exact image identities](<G:/Heart Hub Events Plugin/validation/2026-09-27-docker/image-identities.txt>). All five concurrency rounds and detailed command logs are retained in this directory.

The previous 1.34.0 ZIP remains intact. The new release was built after the final passing matrix and compared against the source. No runtime changes followed package verification.

**Reproduction and cleanup**

With Docker Desktop running, execute from the plugin root:

```powershell
.\tools\validate-docker.ps1
```

The [Docker runner](<G:/Heart Hub Events Plugin/tools/validate-docker.ps1>) creates the isolated PHP/Node containers and a dedicated WordPress/MariaDB Compose project. [Setup details](<G:/Heart Hub Events Plugin/tools/docker/README.md>) describe individual commands and troubleshooting. Source is mounted read-only; the database and WordPress filesystem are temporary; no ports are published; the integration network blocks external traffic. Missing assertions, skips, nonzero exits and PHP diagnostics fail the fixture runner.

All task-owned containers and the `hherm-secondary-validation` network were removed after validation. Other Docker projects were not modified. Reports and downloaded image caches remain available.

**Limits still requiring staging**

JetEngine and JetFormBuilder are separately licensed and were not available in the Docker environment. The complete available CCT fixture suite ran, but installed vendor hooks, field mappings and real registration creation still require a staging save/reload test. Browser layouts and SMTP delivery were not tested; email was intercepted or logged. PHP 7.4 execution is now covered by the full standalone suite, while native WordPress integration ran on PHP 8.3.

The full support uniqueness index needs approximately 1,096 bytes under utf8mb4. Older database engines or row formats limited to 767-byte indexes retain their previous key and leave schema migration pending; they need an upgrade for the long-email correction. Current MariaDB in Docker passed fresh creation, prefix-index migration, failure preservation and retry.

Lease checks prevent subsequent writes by an expired owner. They cannot atomically fence a third-party database write already running beyond the lease duration; that requires stronger database/adapter coordination. Data restoration can still require staff intervention when both the original write and compensation fail. These outcomes are now explicit errors rather than silent success.
