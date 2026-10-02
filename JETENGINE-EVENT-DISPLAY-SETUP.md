# JetEngine event display setup

Version 1.28.1 keeps the existing `events` post type and `event-category`
taxonomy. The Create/Edit Event screen writes the options below as ordinary
event post meta; it does not create another event system.

## Event post meta

| Label | Meta key / field ID | JetEngine type | Stored values | Missing-value behaviour |
|---|---|---|---|---|
| Registration Type | `registration_type` | Select | `website_registration`, `external_registration`, `no_registration` | Website Registration |
| External Registration URL | `external_registration_url` | Text | Complete HTTP/HTTPS URL | Empty |
| Registration Enabled | `registration_enabled` | Switcher | `true`, `false` | On for confirmed Website Registration |
| Show Remaining Spots | `show_remaining_spots` | Switcher | `true`, `false` | On for existing events; off for new events |
| Allow waiting list | `allow_waitlist` | Switcher | `true`, `false` | Off |
| Show Contact Us button for interest | `show_interest_contact_button` | Switcher | `true`, `false` | Off |
| Show What to Expect | `show_what_to_expect` | Switcher | `true`, `false` | On for existing events; off for new events |
| Co-hosted event | `co_hosted_event` | Switcher | `true`, `false` | Off |
| Raffle | `raffle` | Switcher | `true`, `false` | Off |
| Event Date Status | `event_date_status` | Select | `confirmed`, `tbc` | Date Confirmed |
| Amount Raised | `amount_raised` | Number | Number with up to two decimal places | Empty / zero in metrics |
| Raffle Entry Cost | `raffle_cost` | Number | Empty or a number with up to two decimal places | Raffle available without a displayed price |
| Use External Fee Details | `external_fee_enabled` | Switcher | `true`, `false` | Off |
| External Fee Amount | `external_fee_amount` | Number | Number with up to two decimal places | Empty; external site supplies the fee |
| External Fee Link | `external_fee_url` | Text | Complete HTTP/HTTPS URL | Empty |

The plugin also continues writing `event_schedule_status=scheduled|tba` for
compatibility with the 1.15 expression-of-interest workflow. Existing events
without any of the new meta continue to behave as website-registration events
with registration enabled, the legacy display switches on, and a confirmed
date. Newly created confirmed Website Registration events start with
Registration enabled on and Show Remaining Spots and Show What to Expect off.
External Registration and No Registration store internal registration,
remaining spots, and waiting-list settings as off.

## Replace public template output with the plugin shortcodes

The public event cards and single-event layout are JetEngine/Elementor
templates and are not stored in this plugin. Update those templates once to
use these shortcodes so the per-event settings control all public output:

| Output | Shortcode |
|---|---|
| Date | `[hherm_event_date]` |
| Registration or Contact CTA | `[hherm_event_registration_cta]` |
| Remaining spots | `[hherm_event_remaining_spots]` |
| What to Expect section | `[hherm_event_what_to_expect]` |
| Fundraiser fee details | `[hherm_event_fee]` |

On a single-event template, the shortcode automatically uses the current post.
In a listing/card, pass the listing post ID through the builder's shortcode
macro if its shortcode widget does not automatically establish the current
post, for example `event_id="%current_id%"` using the equivalent macro provided
by the installed builder.

For a confirmed Website Registration event with Registration enabled, the CTA
defaults to `#event-registration`. If the existing form or popup uses another
URL/anchor, pass it without changing the registration workflow:

`[hherm_event_registration_cta website_url="#existing-registration-form"]`

The site can also supply the existing destination programmatically through the
`hherm/website_registration_url` filter. External Registration automatically
uses the saved External Registration URL. No Registration outputs no CTA. For
a Date TBC Website Registration event, internal registration is locked off; if
Show Contact Us for Interest is enabled, the shortcode instead renders a
Contact Us button. It links to the published page at the `contact-us` path, and
the `hherm/contact_url` filter can override that path.

Remove the old standalone date, remaining-capacity, What to Expect, and Register
Now widgets after inserting the shortcodes. Leaving both versions in the
template would allow the old widgets to bypass the per-event display switches.

## Existing fundraiser Dynamic Field compatibility

For existing Elementor templates, the plugin keeps the current Dynamic Field
placement when external fee details are enabled. This applies only to JetEngine
**Dynamic Field** widgets configured with **Source: Meta Data** and the exact
meta field `registration_fee` or `sponsorship_cost`.

When `external_fee_enabled` is `true`, the `registration_fee` widget is replaced
with the same output as `[hherm_event_fee]`, and the `sponsorship_cost` widget is
suppressed. The original two widgets continue to render normally when the
toggle is off. Do not add a second standalone price widget beside them.

For new templates, use one Shortcode widget containing `[hherm_event_fee]` and
remove both price Dynamic Field widgets. In a listing/card where the builder
does not establish the current event post, pass its current-post macro through
the shortcode as `event_id`, as described above for the other event shortcodes.

## JetEngine field definitions

The plugin editor and the JetEngine fields use the same ordinary post-meta keys.
Create every toggle in the table as a JetEngine **Switcher** on the Events post
type, with `true` as the checked value and `false` as the unchecked value. This
makes each state directly available to Elementor/JetEngine Dynamic Visibility.
For example, show an interest container when the custom field
`show_interest_contact_button` equals `true`.

Do not create or use a `show_register_now_button` field. That control is retired
and the plugin stores it as false only to neutralise stale data. Use
`registration_enabled` for the Register Now container. Do not make the
start/end date fields globally required in JetEngine; the plugin accepts blank
start/end values for both Date Confirmed and Date TBC events.

Define `start_date` and `end_date__time` as JetEngine **Datetime Local** fields
with **Save as timestamp** enabled. Version 1.24.3 writes that storage format and
automatically converts valid date strings saved by earlier plugin versions. In
the Dynamic Calendar, group items by `start_date`. Enable multi-day events and
set `end_date__time` as the end-date field only when every displayed event has a
real end value; otherwise leave multi-day events disabled so a blank end cannot
remove an otherwise valid event from the calendar.

## Calendar Schedule entries and the plugin calendar

Heart Hub Events > Calendar Schedule manages one-off entries and repeating
opening hours, community visits, speeches, and other dates. Schedules use the
existing `events` post type and the configured start, end, venue, and event
information fields. One schedule may repeat daily, on selected weekdays, on a
day of each month, or on selected dates. Up to 12 time slots are shared across
the chosen dates. Repeating schedules continue until changed or moved to Trash.

The public Events page uses `[hherm_events_calendar]` in an Elementor Shortcode
widget. The plugin calendar calculates repeated dates as visitors view each
month, rather than creating a post for every occurrence. Calendar Settings
controls the heading, week start, past/future navigation, event times, and
detail popups. By default, visitors can navigate 36 months ahead. Event details
open on hover, keyboard focus, or tap.

One-off schedule entries are published `events` posts with the configured
timestamp date fields. A repeating schedule is stored as one draft master post
plus recurrence metadata; it appears in the plugin calendar only when its
calendar visibility is Published. This keeps series definitions out of other
event queries while retaining the shared location, description, category, and
no-registration metadata. The entry type is stored in
`hherm_calendar_entry_type` with one of these values:

| Type shown to admins | Stored value |
|---|---|
| Opening hours | `opening_hours` |
| Community visit | `community_visit` |
| Speech / presentation | `speech` |
| Other | `other` |

The plugin assigns the protected `Calendar Schedule` event category
(`calendar-schedule`). Published one-off entries and recurring dates appear in
the native calendar; draft schedules stay hidden. All schedule entries are
marked `No Registration`, kept out of the normal Events admin list and
attendance selector, and excluded from the “Events run” impact metric.

To select the entry type in JetEngine Dynamic Fields or Dynamic Visibility,
add a Select (or Text) field to the Events post type with the exact meta key
`hherm_calendar_entry_type`. Use the values in the table above as the Select
option values if the listing needs type-based styling or visibility rules. The
plugin reads the configured venue field to display each location. Use the
configured start field for the calendar date and the configured end field only
when every item in the calendar query has an end value; otherwise keep
multi-day events disabled as described above.

## Event registration dietary fields

Add these exact fields to the Event Registrations CCT:

| CCT field ID | Recommended type | Stored value |
|---|---|---|
| `dietaryrequirements` | Text or Select | `yes`, `no`, or blank for an older record |
| `please_let_us_know` | Textarea | Sanitised free text, up to 1,000 characters |

On JetFormBuilder form 2774, keep the Call Hook
`heart_hub_registration_created` before the Insert/Update Custom Content Type
action. In that CCT action, map each form field to the same-named CCT field. The
hook normalises both values before insertion and clears the details when the
answer is not Yes. Conditional visibility of the details field remains a form
editor setting and is independent of this storage mapping.

`amount_raised` is the exception: add it to the Events post type as a JetEngine
**Number** field so it is also available to Dynamic Fields. The plugin's
Fundraiser editor writes the same meta key and accepts zero or more with up to
two decimal places. It appears only after the fundraiser has been created, so it
records the final result rather than an estimate at creation time.

`raffle_cost` is optional. Add it as a JetEngine **Number** field only if the
public JetEngine template needs to display the entry price. When the Raffle
switch is on and this value is blank, treat the raffle as available without a
published price.

## Impact metric shortcodes

Use these in Elementor text or shortcode elements. Rolling totals use the event
start date, exclude cancelled events and future events, and default to the last
12 calendar months.

| Output | Shortcode |
|---|---|
| Funds raised by fundraisers in the last 12 months | `[hherm_total_funds_raised]` |
| People recorded as attending any event in the last 12 months | `[hherm_total_attendees]` |
| Events run in the last 12 months | `[hherm_events_run]` |
| Funds raised by the current event | `[hherm_event_funds_raised]` |
| People recorded as attending the current event | `[hherm_event_attendees]` |

Pass `months="6"` (or another whole number from 1 to 120) to a rolling-total
shortcode to change the period. Pass `event_id="123"` to either per-event
shortcode outside a single-event template. Funds-raised shortcodes output only
the formatted number, with no currency symbol. They accept `decimals="2"` and
default to no decimal places. Add the currency symbol directly before the
shortcode in your content, for example `$[hherm_total_funds_raised]`.

Attendance uses approved registration records. An `attended` registration
counts its checked-in party size when recorded, otherwise its approved party
size. A `partial` registration counts only when the QR/form workflow recorded
an actual checked-in party size. No-shows and unrecorded attendance count as
zero.

## Acceptance check

1. Start a new event and confirm it loads as Website Registration with
   Registration enabled, Show Remaining Spots off, Show What to Expect off, and
   Date Confirmed.
2. Confirm the public Register Now CTA follows Registration enabled and that
   Show Remaining Spots independently controls its output.
3. Change to External Registration and confirm internal registration, remaining
   spots, and the waiting list are cleared and disabled while the saved external
   URL supplies the public CTA. Change to No Registration and confirm no CTA.
4. Change a Website Registration event to Date TBC. Confirm registration is
   locked with the explanatory message, enable Show Contact Us button for
   interest, and confirm the public Contact Us CTA appears.
5. Change the same event back to Date Confirmed. Confirm the interest toggle is
   cleared and disabled, Registration enabled is restored, and Register Now
   replaces Contact Us.
6. Turn remaining spots and What to Expect off separately and confirm their
   public output disappears while capacity and repeater content remain in the
   editor.
7. Publish a Date TBC event with blank start/end values and confirm every updated
   card/page date location says `Date TBC`.
8. Edit that event, choose Date Confirmed, leave start/end blank, and confirm it
   saves; then enter valid values and confirm the normal date returns.
9. Add the `amount_raised` Number field and create a fundraiser from Heart Hub
   Events > Fundraisers. Confirm Amount raised and the additional Categories
   selector are absent while creating, and confirm the saved event automatically
   has the Fundraising Event category. Then edit the saved fundraiser, record the
   final value, and confirm it appears in the Fundraisers list and event overview.
10. Turn Raffle on, leave Raffle entry cost blank, and confirm the overview says
   `Available`; then enter a cost and confirm the dollar price appears.
11. Check the five impact shortcodes against the Fundraisers list, Attendance
   Register, and events whose start dates fall just inside and outside the
   selected rolling period.
