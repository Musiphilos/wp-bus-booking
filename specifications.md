# NVF Bus Booking — Plugin Specifications

> Status: **Draft v0.3** — All open questions resolved. Ready for implementation planning.

---

## 1. Overview

### 1.1 Purpose
This WordPress plugin manages shuttle bus bookings for participants of the LB Swing event at lbswing.com. It allows registered event participants to book a seat on one of four fixed shuttle trips between Porto and Grande Hotel Thermas (São Pedro do Sul).

### 1.2 Scope
- Shuttle booking across four fixed trips (two inbound Sep 24, two outbound Sep 28) with a 55-seat capacity per trip and waiting-list fallback
- Participant email verification against Elementor registration data
- Admin management interface with print-friendly manifests
- Transactional email notifications (English-only) with PDF tickets
- MCP Abilities API for AI/agent access to booking data

### 1.3 Out of Scope
- Online payment (cash only, collected on the bus)
- Mobile app (web only)
- Integration with external ticketing systems
- Numbered seat selection (capacity-only model)

### 1.4 Tech Dependencies
- **Meta Box AIO** (installed) — provides all required Pro extensions (Relationships, Settings Page, Admin Columns, Conditional Logic, Group).
- **Elementor Pro** — source of registration submissions.
- An existing SMTP plugin on lbswing.com (WP Mail SMTP / FluentSMTP / similar) — for deliverability.

---

## 2. Roles & Access

| Role | Access |
|------|--------|
| **Unauthenticated visitor** | Cannot access booking page |
| **Verified participant** | Can book, view, edit, and cancel their own booking (within deadlines) |
| **WordPress Admin** | Full access to all bookings, manifests, exports, manual overrides |

### 2.1 Participant Verification Flow
Participants do **not** need a WordPress account. Access is gated by a **one-time magic link** sent to the email they enter, provided that email exists in the Elementor submissions for `Registrations2026`. All registered emails are accepted regardless of payment status — payment is tracked offline.

1. Participant enters their email on the booking page.
2. System checks if the email exists in Elementor form submissions for `Registrations2026`.
3. If found: a signed, single-use magic-link token (default expiry: 24h) is emailed to that address. If not found: show error — "This email is not registered for the event."
4. Participant clicks the link → token is validated and consumed → an HttpOnly signed-cookie session (same expiry as the token) is established → booking form is shown.
5. The same magic link can later be reused to view/cancel the booking; if the cookie has expired, the link re-issues a fresh session.

**Token properties:**
- HMAC-signed with a plugin secret; contains email + expiry + nonce.
- Single-use for first activation; subsequent visits within validity refresh the session cookie.
- Configurable expiry via §7.6.

One email = one booking record covering both directions. Duplicate sign-ups by the same email update the existing record rather than creating a new one (see §4.3).

---

## 3. Registration Verification

### 3.1 Elementor Data Source
The Elementor Pro form stores submissions in the default tables `e_submissions` + `e_submissions_values`. The plugin queries these read-only (joined by form ID `997de44`) to verify participant emails and pre-fill booking fields. No payment gate — every submission grants booking access.

**Registration form identifiers:**
- Form Name: `Registrations 2026`
- Form ID: `Registrations2026`
- Internal ID: `997de44`

**Known fields in the registration form:**
- Full Name
- Email
- Phone

**Fields the plugin uses (read-only) — must exist on the registration form:**
- GDPR acceptance checkbox
- Pick-up location (Casa da Música, Airport)

### 3.2 Pre-fill Behaviour
When a participant is verified, pre-fill their booking form with: Name, Email, and Phone from the Elementor submission.

---

## 4. Shuttle Module

### 4.1 Routes & Schedule

There are **four fixed shuttle trips** — two inbound on **Sep 24** and two outbound on **Sep 28**. All trips run between Porto and Grande Hotel Thermas (São Pedro do Sul). There is no Lisbon route.

| Trip ID | Direction | Date | Itinerary |
|---------|-----------|------|-----------|
| `SHUTTLE-A` | Inbound (OPO-IN) | Sep 24 | Porto Airport **14:30** → Terminal Alsa/Autna – Casa da Música **15:00** → Grande Hotel Thermas |
| `SHUTTLE-B` | Inbound (OPO-IN) | Sep 24 | Porto Airport **15:30** → Terminal Alsa/Autna – Casa da Música **16:00** → Grande Hotel Thermas |
| `SHUTTLE-C` | Outbound (OPO-OUT) | Sep 28 | Grande Hotel Thermas **09:00** → Porto Airport (no intermediate stops) |
| `SHUTTLE-D` | Outbound (OPO-OUT) | Sep 28 | Grande Hotel Thermas **12:00** → Terminal Alsa/Autna – Casa da Música → Porto Airport |

**Pickup / drop-off points (Porto side):**
- **Porto Airport** — meet at the Vodafone store
- **Terminal Alsa/Autna – Casa da Música**

**Pickup-point selection rules:**
- `SHUTTLE-A` / `SHUTTLE-B` (inbound): booking form must ask **where the participant boards** (Airport or Casa da Música) — needed so the team knows where to expect them.
- `SHUTTLE-C` (outbound): no choice — single departure from the hotel, no intermediate stops.
- `SHUTTLE-D` (outbound): no choice required — destination for everyone is the Airport. Casa da Música is only a drop-off-on-request, and we do not need to track who alights there.


### 4.2 Capacity (no seat map)

Each trip has a **fixed capacity of 55 seats**. There is **no numbered-seat model** — no physical layout, no per-seat labels, no participant seat selection. The plugin tracks only the **count** of confirmed bookings vs. capacity.

**Admin reservations:** Admin can pre-create bookings for team members (effectively reserving seats off the public pool). The number of team reservations is not fixed in advance — the admin simply books team members like any other participant, and the remaining capacity is what's offered to the public. No separate "accessibility seat" hold mechanism is needed.

### 4.3 Booking Flow

**Step 1 — Verify identity**
- Enter email → matched against Elementor `Registrations2026` submissions → magic-link email sent → clicking the link establishes an HttpOnly signed-cookie session (see §2.1).

**Step 2 — Choose trip(s)**
- Select inbound trip (`SHUTTLE-A` or `SHUTTLE-B`) and/or outbound trip (`SHUTTLE-C` or `SHUTTLE-D`).
- For inbound trips: select pickup location (Airport / Casa da Música).
- Outbound trips do not need a pickup-location selection.

**Step 3 — Confirm**
- Summary: trip(s), departure time, pickup location, GDPR acceptance, cash-on-board reminder.
- Submit → booking(s) created → confirmation email sent.

**Booking constraints:**
- **One ticket per person.** A given email may hold at most one confirmed inbound booking and one confirmed outbound booking.
- No companion bookings — each participant books their own seat with their own registered email.
- Duplicate-email submissions for the same direction are rejected.

**Partial availability handling:**
When a participant attempts a round-trip booking and one (or both) chosen trips is full, the server returns a "partial availability" response without committing. The UI shows an intermediate confirmation step:
> "SHUTTLE-A is full. We can confirm your outbound (SHUTTLE-C) seat and place you on the waiting list for SHUTTLE-A. Continue?"

The participant may then:
- **Continue** → confirmed seat(s) booked, full trip(s) joined the waiting list.
- **Change selection** → return to Step 2 and pick a different trip.
- **Cancel** → nothing is recorded.

The atomic capacity check is re-run when "Continue" is submitted to handle the race where state changed during the confirmation prompt.

### 4.3.1 Editing a Confirmed Booking

Until the cancellation deadline (§4.4), the participant may freely edit:
- Pickup location (inbound only)

To change the chosen shuttle (e.g. SHUTTLE-A → SHUTTLE-B), the participant must **cancel and rebook**. This keeps the capacity/waitlist logic simple and race-safe.

After the cancellation deadline, all edits are admin-only.

### 4.4 Cancellation Policy

- Participants may cancel their booking up to `nvf_cancellation_days_before` days before the global `nvf_event_start_date` (both configured in §7.6). The deadline is the same for inbound and outbound directions.
- After the cancellation deadline: booking is locked; only admins can cancel.
- On cancellation: the freed seat triggers a simultaneous notification to everyone on that trip's waiting list (see §4.5).
- A participant may cancel a single direction (e.g. outbound only) without affecting the other.

### 4.5 Waiting List

- When a trip reaches full capacity (55 booked), further bookings go to a waiting list.
- Waiting list is ordered by submission time (FIFO) for record-keeping, but **claims are first-come, first-served**, not strictly sequential.
- When a seat is freed (cancellation or admin removal):
  - The system **emails everyone currently on that trip's waiting list at the same time**.
  - The first person to click through and confirm gets the seat; the booking endpoint atomically rejects the others once capacity is filled.
  - Unsuccessful claimants stay on the waiting list in their original order.
- Waiting list entries are also cancelled (with notification) after the cancellation deadline passes.

> Because claims are first-come-first-served on a shared notification, there is **no per-person claim window** to configure. The `Waiting list claim window` setting in §7.6 is therefore removed.

---

## 7. Admin Interface

Located under **WordPress Admin → NVF Bus Booking**.

### 7.1 Dashboard
- Overview: trips today, total bookings, available seats, waiting list count
- Quick links to each trip's manifest

### 7.2 Trips Management
- Create / edit / delete trips (trip_code, direction, date, time, stops, capacity).
- The four launch trips are seeded at activation; admin can override capacity or cancel a trip entirely.

### 7.3 Bookings Management
- List all bookings: filterable by route, trip, status
- View individual booking: all participant details, travel info
- **Manually add participant** — admin can book *any* email (no Elementor check, capacity check optional/overridable, GDPR not required). Intended for team-seat reservations and day-of additions.
- Cancel booking (with or without notification)
- Move waiting-list entry to confirmed booking
- Bulk actions: export to CSV, send email to group

Manually-added bookings are flagged internally (`source = admin`) so they can be excluded from auto-retention purges if desired.

### 7.4 Waiting List
- View waiting list per trip (ordered)
- Manually promote a waiting-list entry to confirmed

### 7.4.1 Print-Friendly Manifest
- Each trip has a dedicated **Print Manifest** view in admin: name, email, phone, pickup location, and a tick-box for boarding check-off.
- Uses a print-only stylesheet (no nav, no chrome) so admins can print or save-as-PDF from the browser.
- This is the day-of contingency: if the plugin is unreachable, the printed manifest is the source of truth.

### 7.5 Admin Notifications
- Admin receives **one email per new booking** (no digest). This applies to both confirmed bookings and waiting-list joins so the team can manage them closely.

### 7.6 Settings
- Event date (used for cancellation-deadline calculation — global across all trips)
- Cancellation buffer (number of days before the event date, up until which cancellations are allowed; default 1)
- Shuttle ticket price (displayed informationally)
- Email sender name and address
- Admin notification recipient address(es)
- Magic-link token expiry (default: 24 hours)
- Booking retention window in days after event (default: 90)

---

## 8. Email Notifications

All emails sent from WordPress using the configured sender address.

| Trigger | Recipient | Content |
|---------|-----------|---------|
| Booking confirmed | Participant | Trip details, price reminder (cash), trip instructions, **PDF ticket attached** |
| Booking cancelled (by participant) | Participant | Cancellation confirmation |
| Booking cancelled (by admin) | Participant | Cancellation notice + reason (optional) |
| Waiting list joined | Participant | "You're on the waiting list for [trip]. We'll notify you if a spot opens." |
| Waiting list spot opened | **All current waitlisters for that trip, simultaneously** | "A seat is available on [trip]. First to confirm wins." Includes a one-click claim link. |
| Waiting list — spot claimed by someone else | Notified participants who didn't claim | "The spot has been taken. You remain on the waiting list in your current position." |
| Magic-link verification | Participant | Single-use signed link to access the booking page (24h default expiry) |
| New booking — admin notification | Admin recipients (§7.6) | One email per new booking or waitlist join (§7.5) |

### 8.1 PDF Ticket
- Confirmation emails attach a **PDF ticket** containing: participant name, trip code, date, departure time, pickup location, price (cash on board), and a booking reference ID.
- A QR code is **not required** but the booking reference is printed for manual check-in by the team.

### 8.2 Language
- All emails, the PDF ticket, and the **public booking page** are in **English only**.
- Strings are still wrapped in WP i18n functions (`__()`) so a translation can be added later without code changes.

---

## 9. Data Model

### 9.1 Custom Post Types (via MetaBox)

#### `nvf_trip` — A specific bus departure
| Field | Type | Notes |
|-------|------|-------|
| `trip_code` | select | `SHUTTLE-A`, `SHUTTLE-B`, `SHUTTLE-C`, `SHUTTLE-D` |
| `direction` | select | `OPO-IN`, `OPO-OUT` |
| `departure_datetime` | datetime | |
| `stops` | text (repeatable) | Ordered list of stops with time |
| `capacity` | number | Default 55 |
| `price` | number | Informational only |
| `status` | select | `open`, `full`, `cancelled` — `full` is auto-computed from confirmed count vs. capacity; `cancelled` is a manual admin override |

#### `nvf_booking` — A participant's booking (one record per participant, covers both directions)
| Field | Type | Notes |
|-------|------|-------|
| `participant_email` | email | Verified against Elementor; **unique across all bookings** |
| `participant_name` | text | Pre-filled from Elementor by email |
| `participant_phone` | text | Pre-filled from Elementor by email |
| `inbound_trip_id` | MB Relationship | → `nvf_trip`, nullable (participant may book only outbound) |
| `inbound_status` | select | `confirmed`, `waitlist`, `cancelled`, `none` |
| `inbound_pickup_location` | select | `airport` / `casa_da_musica` |
| `inbound_waitlist_position` | number | Nullable |
| `outbound_trip_id` | MB Relationship | → `nvf_trip`, nullable (participant may book only inbound) |
| `outbound_status` | select | `confirmed`, `waitlist`, `cancelled`, `none` |
| `outbound_waitlist_position` | number | Nullable |
| `gdpr_accepted_at` | datetime | Required at booking |
| `created_at` | datetime | |
| `updated_at` | datetime | Bumped on any status change |
| `source` | select | `public` (self-booked) or `admin` (manually added) |
| `history` | repeatable group | Audit entries: `{timestamp, actor, action, note}` appended on every status change |

**Notes:**
- Every status change (book, cancel, waitlist promotion, edit, admin override) appends a row to `history` capturing the actor (`email` or WP user ID) and a short note.
- Per-direction status lets one record represent partial states (e.g. confirmed inbound + waitlisted outbound).
- The trip manifest query joins `nvf_booking` filtered by `inbound_trip_id` OR `outbound_trip_id` and the matching status field.
- "One ticket per person per direction" is enforced by the unique `participant_email` constraint plus the per-direction status flags.
- All datetimes are stored UTC; UI/email rendering uses Europe/Lisbon.

### 9.2 WordPress Options (settings)
- `nvf_event_start_date`
- `nvf_cancellation_days_before` (default: 1)
- `nvf_email_sender_name`
- `nvf_email_sender_address`
- `nvf_admin_notification_recipients`
- `nvf_elementor_form_id` (default: `Registrations2026`)
- `nvf_ticket_price`
- `nvf_magic_link_expiry_hours` (default: 24)
- `nvf_booking_retention_days` (default: 90)
- `nvf_plugin_secret` (auto-generated on activation; used for HMAC token signing)

---

## 9.2.1 GDPR & Data Retention
- Booking form requires an explicit GDPR consent checkbox; `gdpr_accepted_at` is timestamped on submit.
- Booking page links to lbswing.com's privacy notice (must list the data collected by this plugin).
- All `nvf_booking` records are **auto-purged 90 days after the event end date** via a daily WP-Cron job (configurable in §7.6).
- No self-service data-export endpoint — admin CSV export from §7.3 fulfils data-subject access requests on demand.

---

## 9.3 Build Approach

The plugin uses a **hybrid build strategy**: Meta Box AIO owns the data + admin layer, custom code owns the public booking experience.

### Use Meta Box AIO for
- Custom post types `nvf_trip` and `nvf_booking` (MB CPT).
- All field definitions and validation (MB Builder, MB Group, MB Conditional Logic).
- Booking ↔ Trip relations (MB Relationships).
- Admin list columns, filters, and quick edit (MB Admin Columns).
- Settings page in §7.6 (MB Settings Page).
- Admin-side "manually add booking" form — uses native MB post editor.

### Build custom (do NOT use MB Frontend Submission)
- Public booking page (Steps 1–3 in §4.3) rendered via a plugin shortcode.
- Magic-link issuance + verification against Elementor submissions (no Payed gate).
- HttpOnly signed-cookie session management.
- Capacity check + waiting-list fallback (atomic, race-safe at submit time).
- "One booking per email" uniqueness check (single record covering both directions).
- Partial-availability confirmation flow (§4.3).
- Edit endpoint (pickup + travel info) with deadline enforcement.
- Cancellation endpoint with deadline enforcement.
- Waiting-list simultaneous-notification + first-come-first-served claim endpoint.
- PDF ticket generation and email attachment.
- All transactional emails (use `wp_mail` with English templates).
- WP-Cron retention purge job.

### Why not MB Frontend Submission for the booking page
- It's a generic post-submission renderer; expressing multi-step inbound/outbound selection with conditional pickup location is awkward.
- It cannot express business rules we need: capacity check at submit time, waitlist fallback, uniqueness-per-direction, atomic seat allocation under concurrent submits.
- Bending it via hooks and JS costs more than a purpose-built ~3-step form calling a couple of REST endpoints.
- Styling and UX control are significantly better with custom front-end code.

### Time Zones
- All datetimes are stored as **UTC** in the database.
- All UI and email rendering uses **Europe/Lisbon**.
- Trip departure times in §4.1 are expressed in Europe/Lisbon.

### Technology choices
- **Front end:** Plugin shortcode renders a small vanilla-JS / Alpine.js form (no React build step required for a WordPress plugin of this size).
- **Back end:** WordPress REST API endpoints (`/wp-json/nvf/v1/...`) for verify, book, cancel, claim-waitlist.
- **PDF:** [Dompdf](https://github.com/dompdf/dompdf) bundled via Composer for ticket generation.
- **Email delivery:** Uses lbswing.com's existing SMTP plugin (WP Mail SMTP / FluentSMTP / similar) via `wp_mail()`. The plugin does **not** ship its own SMTP integration. Verify SPF + DKIM are configured on the sender domain before launch.
- **Concurrency:** Single atomic SQL insert with a capacity guard (`INSERT ... SELECT ... WHERE (SELECT COUNT(*) FROM bookings WHERE trip = ? AND status = 'confirmed') < capacity`). Failed inserts return a "waitlist" response. No external locks required.

---

## 9.4 Testing Strategy
- **Unit tests (PHPUnit):**
  - Atomic capacity-guarded insert under concurrent calls
  - Uniqueness constraint on `participant_email`
  - Waitlist FIFO ordering and simultaneous-claim race
  - Cancellation deadline calculation
  - Magic-link token signing / verification / expiry
- **Manual staging walkthrough (pre-launch checklist):**
  - Public flow: email entry → magic-link → book inbound → book outbound → confirmation email + PDF
  - Edit flow: change pickup location
  - Cancel flow: cancel before deadline, attempt cancel after deadline
  - Waitlist flow: fill SHUTTLE-A to 55 bookings, attempt 56th, cancel one, observe simultaneous notification
  - Admin: manually add booking, export CSV, send group email
  - Email rendering in Gmail, Outlook, Apple Mail
  - PDF rendering on iOS, Android, desktop PDF viewers

---

## 10. MCP Abilities

All abilities require `mcp.public = true` and an appropriate `permission_callback`.

| Ability | Description |
|---------|-------------|
| `nvf-bus-booking/get-bookings` | List bookings with optional status filter *(already implemented)* |
| `nvf-bus-booking/get-trips` | List all trips with availability counts |
| `nvf-bus-booking/get-trip-manifest` | Full passenger list for a specific trip |
| `nvf-bus-booking/get-waiting-list` | Waiting list for a specific trip |
| `nvf-bus-booking/get-booking-by-email` | Look up a participant's booking by email |
| `nvf-bus-booking/cancel-booking` | Cancel a booking (admin only — `permission_callback` requires `manage_options`) |

---

## 11. Open Questions

All open questions resolved as of this revision.

> **Resolved:** Q1 (email-only verification), Q2 (form `Registrations2026` / `997de44`), Q4–Q7 (trip schedule and stops), Q8–Q11 (capacity 55, no seat map), Q12–Q13 (one ticket per email), Q14 (waiting list = simultaneous notification, first-come-first-served), Q15 (travel info dropped — flight details no longer collected at booking), Q18 (one admin email per booking), Q19 (PDF ticket attached), Q20 (English only), Q22 (Meta Box AIO installed).

---

*Last updated: 2026-05-15*
