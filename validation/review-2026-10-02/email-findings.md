# Email and feedback review — 2 October 2026

Read-only runtime review. Focused fixture: `email-review-repro.php` (7 assertions pass). The fixture uses local WordPress/database doubles and does not submit real mail.

## P2: Failed support confirmation cannot be retried through the plugin

- Primary location: `includes/class-support-groups.php:640`.
- Supporting locations: `includes/class-support-groups.php:645-658`; `includes/class-email-service.php:475-488`; `includes/class-email-automation.php:67-72`.
- Trigger: approve a pending support-group applicant while `wp_mail()` returns false (or transport raises an exception), then restore transport and repeat confirmation.
- Observed: approval remains `confirmed` with `email_status=failed`; the next confirmation action redirects `already-confirmed` without attempting mail. Repro output: `mail_attempts=1` after retry.
- Impact: customer never receives their confirmation without staff composing a separate message. The same direct-send helper also means event/support interest acknowledgements and internal staff notifications have no stored automatic retry.
- Suggested fix: preserve approval independently; store a delivery job keyed by applicant and confirmation generation, retry transient errors with bounded attempts, and expose a nonce/capability-protected resend of failed confirmation. Do not reapprove or resend successful messages.

## P2: Concurrent feedback workers send duplicate requests and invalidate the first link

- Primary location: `includes/class-post-event-feedback.php:42-43`.
- Supporting locations: `includes/class-post-event-feedback.php:60-63` and `105-108`.
- Trigger: two cron/CLI/manual workers overlap and both read the transient lock before either sets it; neither successful audit exists before the two sends start.
- Observed: deterministic PHP Fibers suspend immediately after lock snapshots and inside fake wp_mail before it returns. Both workers observe false, both hand off mail for registration 7, and generate different tokens. After both complete, resolving the first emailed token fails while the second succeeds.
- Impact: duplicate feedback requests confuse the customer and the earlier message contains an invalid link, even when database and mail transport are healthy.
- Suggested fix: use the existing atomic Mutation_Lock abstraction, hold a per-registration delivery lock through eligibility recheck/token creation/mail handoff/audit, and reuse a persisted valid token. A check-then-set transient is insufficient for mutual exclusion.
## P2: Feedback emails can contain guaranteed-invalid links after token persistence failure

- Primary location: `includes/class-post-event-feedback.php:108`.
- Supporting location: `includes/class-post-event-feedback.php:62-63` and `112-120`.
- Trigger: `update_option('hherm_feedback_link_<id>', ...)` fails while mail transport is working.
- Observed: `feedback_url()` still returns a URL, the real Email_Service hands off the message (`status=sent`), and resolving exactly that URL fails because no corresponding token exists. The healthy-storage control resolves correctly.
- Impact: customer receives an unusable feedback request; its `sent` audit prevents subsequent reminders from repairing the link.
- Suggested fix: read back and verify stored hash/event/expiry before returning a link; represent failure as WP_Error and skip mail while retaining retry eligibility. Reuse a persisted link for a retry rather than silently replacing it.

## P2: Multibyte feedback is truncated in the middle of a UTF-8 character

- Primary location: `includes/class-post-event-feedback.php:95-96`.
- Trigger: legitimate comments below the UI's 5,000-character maximum occupy more than 5,000 bytes, with a multibyte character crossing byte 5,000.
- `strlen`/`substr` enforce a byte limit and can generate invalid UTF-8. Same confirmed issue exists in check-in; root fixture covers the precise boundary (1 ASCII character plus 1,250 emoji).
- Impact: comments are unnecessarily shortened; malformed payload can fail storage or render blank depending on the JetEngine/database transport.
- Suggested fix: reject oversized character-count input or truncate safely with mb_substr/Unicode-aware fallback consistent with the form's character limit.

## Reviewed behavior and limits

- Delayed event decisions, waitlist, cancellation and date-confirmation messages store generation-bound per-job options, use mutation locks, recover rejected schedules, check stale registration status, and bound transport retries.
- Successfully sent/logged messages intentionally suppress repeat automatic delivery; email-mode Log only does not send mail.
- `wp_mail()` true verifies transport handoff only. SMTP acceptance, inbox delivery, bounce handling and third-party filtering need live staging/site verification and were not checked.
- Full rendering of configured site templates/form email actions needs the live JetFormBuilder/site configuration. No site configuration was changed.

