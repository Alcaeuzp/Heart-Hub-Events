# Built-in expression-of-interest flow (1.26.0)

No separate form or Elementor container is required for the built-in flow. The plugin replaces registration form 2774 before registration opens, using the event ID from the rendered form or the validated current event. It supports the JetForm shortcode and Elementor/JetPopup rendering; singular event pages also have a fallback form.

The form collects first name, last name, email, optional phone and an optional note. It saves `registration_status=interest` to the existing CCT without reserving capacity, then dispatches customer and staff acknowledgements using the plugin's email mode. Staff notifications use the Internal notification email in Settings > Emails, include the complete submitted customer and event details, and link directly to the saved registration record. Log-only mode records normal messages without sending them; the Send test email action always attempts a real delivery. Registration becomes available at `registration_open` in the WordPress site timezone.

The built-in form does not require a `registration_type` CCT field. Existing separate JetForm interest forms can still use the optional setup below; do not add a duplicate acknowledgement email because the plugin now sends it after creation.

For form 2774, explicitly map `dietaryrequirements` to `dietaryrequirements` and `please_let_us_know` to `please_let_us_know` in the CCT save action. These are not automatically matched when the form has an explicit fields map. Keep `heart_hub_registration_created` before the CCT save action.

---
# JetEngine expression-of-interest setup

The plugin supports two event date states (with the legacy schedule values kept
for compatibility):

- `event_date_status=confirmed` (`event_schedule_status=scheduled`): start and end date/time are required.
- `event_date_status=tbc` (`event_schedule_status=tba`): start and end are blank and an expression-of-interest form can be used when Registration Type is Website Registration.

Use **Heart Hub Events > Create/Edit Event** for the transition from TBA to Scheduled. The plugin sends the date-confirmed email only after the complete event has been saved.

## 1. Events CPT fields

In JetEngine, add these fields to the existing `events` post type:

| Label | Name / ID | Type | Values / format | Required |
|---|---|---|---|---|
| Event date status | `event_date_status` | Select or Radio | `confirmed` = Date Confirmed; `tbc` = Date TBC | Yes |
| Legacy schedule status | `event_schedule_status` | Select or Radio | `scheduled` = Scheduled; `tba` = Date TBC | No; written automatically |

Keep the existing `start_date` and `end_date__time` fields, but turn off **Is required** in JetEngine. The plugin validates them when Event Date Status is `confirmed` and clears them when the event is saved as `tbc`.

The plugin form writes these exact keys. The existing Settings mappings still control the start and end field keys.

### Public listing/template rules

In the single-event and event-card templates:

- Use `[hherm_event_date]` for the date so confirmed events show the normal date and unconfirmed events show exactly “Date TBC”.
- Website Registration events may show the expression-of-interest form while `event_date_status` is `tbc`.
- External Registration and No Registration events must not show either internal form.
- Use the shortcodes and wiring in `JETENGINE-EVENT-DISPLAY-SETUP.md` for the public CTA, remaining spots, and What to Expect output.

The legacy `expected_month` value is preserved on existing TBC events but is no longer used in the public date output; the requested public label is always “Date TBC”.

## 2. Event Registrations CCT fields

Expressions of interest use the existing `event_registrations` CCT and the existing Events-to-registrations relation. This keeps each person's details attached to the event and lets the plugin use the established audit/email workflow.

Add or update these CCT fields:

| Label | Name / ID | Type | Notes |
|---|---|---|---|
| Registration status | `registration_status` | Select or Text | Add the allowed value `interest` alongside pending, waitlist, approved and declined. |
| Registration type | `registration_type` | Select or Text | Add `expression_of_interest`. This makes the record's purpose explicit. |
| Event | `event_id` | Number | Existing parent event post ID. |
| First name | `first_name` | Text | Required in the form. |
| Last name | `last_name` | Text | Required in the form. |
| Email | `email` | Text | Required and validated as email. |
| Phone | `phone` | Text | Optional. |
| Organisation | `organisation` | Text | Optional. |
| Notes | `reason_for_attending` | Textarea | Optional; relabel as “What interests you about this event?” if useful. |
| Created date | `registration_date` | Datetime or Text | The plugin supplies the current site date/time. |
| Source form | `form_id` | Number | Optional but useful for reporting. |

No new CCT or relation is needed. Interest records use `number_of_attendees = 1` internally but do **not** reserve event capacity and cannot be approved through the registration review endpoint.

## 3. JetFormBuilder form

Create a form named **Event expression of interest** with these fields:

1. `first_name` — Text, required
2. `last_name` — Text, required
3. `email` — Email, required
4. `phone` — Text/Tel, optional
5. `organisation` — Text, optional
6. `reason_for_attending` — Textarea, optional
7. `event_id` — Hidden, populated with the current post ID
8. `registration_status` — Hidden, default `interest`
9. `registration_type` — Hidden, default `expression_of_interest`
10. `registration_date` — Hidden (the plugin overwrites it with the current site date/time)
11. `number_of_attendees` — Hidden, default `1`

Configure the post-submit actions in this order:

1. **Call Hook** with hook name `heart_hub_interest_created`
2. **Insert/Update Custom Content Type Item**, targeting `event_registrations`, with each form field mapped to the same-name CCT field
3. Optional success email to acknowledge receipt of the expression of interest

The Call Hook validates that the event is published, not cancelled, and still TBA. The plugin then connects the created CCT item to the event using the configured JetEngine relation. Do not add a second relation action unless your site needs it for another purpose.

Place the form in the TBA branch of the event template and use the current event post ID for `event_id`. Do not accept `event_id` from a user-editable field.

## 4. Date-confirmed email

The plugin supplies the scheduled-date automation:

1. Go to **Heart Hub Events > Email Templates > Date confirmed** and edit the subject/body.
2. Available useful placeholders include `{first_name}`, `{event_name}`, `{event_url}`, `{event_date}`, `{event_time}`, `{venue}`, `{organiser}`, `{event_details}`, `{contact_email}` and `{signature}`.
3. Go to **Heart Hub Events > Settings > Automatic emails > Date confirmed** to enable it and set an optional delay.
4. Keep Email mode on **Log only** for testing; change it to **Send real email** when ready.
5. Edit the TBA event through the plugin, choose **Scheduled**, enter valid start/end values, and publish/update it.

On that first TBA-to-Scheduled transition, every related CCT item with `registration_status = interest` is queued or sent. Delivery is deduplicated per interest record, audited, revalidated before delayed sending, and retried for transient mail failures.

## Recommended pre-live test

1. Create a published TBA event with an expected month.
2. Submit two expressions of interest with test email addresses.
3. Confirm both CCT items contain the event ID, `interest` status and `expression_of_interest` type, and are related to the event.
4. In Log-only mode, change the event to Scheduled and save a start/end date.
5. Confirm two `schedule_confirmation_email` audit entries were logged and the rendered messages contain the correct event date and link.
6. Save the scheduled event again and confirm no duplicate date-confirmed message is produced.
