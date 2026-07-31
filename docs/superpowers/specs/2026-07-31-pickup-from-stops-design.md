# Pickup info from Stops — design

**Date:** 2026-07-31
**Status:** approved

## Problem
The booking page's inbound "Pickup" selector is hardwired to two locations
(`airport`, `casa_da_musica`) in five places (frontend config + template,
`MetaBoxes::PICKUP_LOCATIONS`, `BookingService::normalizePickup`,
`BookingContext::pickupLabel`). It does not derive from a trip's **Stops**, so
adding/renaming stops has no effect on pickup options.

## Goal
Pickup options for an inbound trip come from that trip's **Stops** (all but the
last, which is the arrival). Consistent across the frontend selector, the admin
"Add Booking" page, the booking-edit field, server validation, and emails/PDF.

## Decisions (locked)
- **Which stops:** all stops **except the last** (final stop = arrival/hotel).
- **Stored value:** the chosen stop's **label text** (self-describing; renders in
  email/PDF with no lookup; survives reordering; renaming a stop later does not
  rewrite past bookings). Old `airport`/`casa_da_musica` values still resolve via
  a small legacy map.
- **Scope:** full — all input paths (frontend + admin Add Booking) derive from
  stops; booking-edit field becomes free text; emails/PDF resolve automatically.

## Design

### Single source of truth — `src/Domain/TripStops.php` (new)
- `all(int $tripId): array` → `[{label,time}]`, empty-label rows filtered.
- `pickups(int $tripId): array` → all but the last stop.
- `pickupLabels(int $tripId): array` → labels only (for validation).
- `pickupsFrom(array $stops): array` — **pure** (all-but-last + filter), unit-tested.

### Storage & legacy
- `inbound_pickup_location` stores the stop **label**.
- `MetaBoxes::PICKUP_LOCATIONS` → renamed `LEGACY_PICKUP_LABELS` (2 old keys →
  labels). Only used to render pre-existing bookings.

### API — `GET /nvf/v1/trips`
- Each trip gains `pickups: [{label,time}]` from `TripStops::pickups`. The
  "all but last" rule lives only in PHP; the frontend renders what it's given.

### Frontend — `assets/js/booking.js` + `PublicAssets` template
- Replace the two literal radios with `x-for` over the selected inbound trip's
  `pickups`; each radio's value **is the label**.
- `pickupLabel(v)` → `pickupLegacy[v] || v`.
- Pickup block shown / required **only when the trip has pickups**.
- `toggleTrip('inbound', …)` clears `inbound_pickup` on any inbound-trip change.
- `PublicAssets` drops hardcoded `$pickups`; passes `pickupLegacy`.

### Admin
- **Add Booking** (`ManualAddPage` + `admin.js`): emit a `tripId → [labels]` map;
  on inbound-trip change, repopulate the pickup `<select>` from that trip's stops
  (extends the existing show/hide block).
- **Booking-edit field** (`MetaBoxes` `inbound_pickup_location`): `select` → `text`.

### Display — emails/PDF (`Mail/BookingContext.php`)
- `pickupLabel($v)` → `LEGACY_PICKUP_LABELS[$v] ?? $v`.

### Validation — `BookingService`
- `normalizePickup(string $raw, int $tripId): ?string` → valid iff `trim($raw)` ∈
  `TripStops::pickupLabels($tripId)`; returns the label or null. Callers
  `create`/`createAsAdmin` pass the inbound trip; `updatePickup` looks it up.
- Requirement is conditional: pickup required only when the inbound trip has
  pickups. `BookingController::book`'s pre-check updated to match.

## Tests
- Unit-test pure `pickupsFrom(stops)`: 3→2, 1→0, empty→0, empty-label filtered,
  order preserved.

## Files touched
`src/Domain/TripStops.php` (new), `MetaBoxes.php`, `BookingService.php`,
`BookingController.php`, `Mail/BookingContext.php`, `Admin/ManualAddPage.php`,
`assets/js/booking.js`, `assets/js/admin.js`, `Rest/PublicAssets.php`, + unit test.

## Out of scope (YAGNI)
Outbound drop-off selection; per-stop "pickup point" toggle; backfilling old
bookings' stored keys into labels.
