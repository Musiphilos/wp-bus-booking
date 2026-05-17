# Bus Booking

WordPress plugin for managing shuttle bus bookings for event participants. Built around custom post types for trips and bookings, with magic-link authentication for participants and a printable manifest + CSV export for organizers.

## Features

- Two custom post types: **Trip** (route + capacity + departure) and **Booking** (participant + inbound/outbound legs)
- Atomic seat-ledger that prevents over-booking under concurrent claims
- Waitlist with FIFO promotion (single + bulk)
- Magic-link email authentication (no password)
- Per-trip printable manifest with passenger search + CSV export
- Manual booking entry for admins (with capacity override)
- PDF ticket generation (dompdf)
- Editorial-string overrides for every customer-facing message
- Configurable booking retention with auto-purge cron

## Requirements

- WordPress 6.4+
- PHP 8.2+
- [Meta Box AIO](https://metabox.io/) (data layer)

## Installation

Download the latest release zip from the [Releases](../../releases) page, then in WordPress admin go to **Plugins → Add New → Upload Plugin** and pick the zip. Activate.

## Configuration

After activation, visit **Bus Booking → Settings** in the WordPress admin. Configure event dates, sender address, ticket pricing, and any editorial copy overrides on the per-template tabs.

## Building from source

```bash
composer install --no-dev --optimize-autoloader
```

The plugin entry point is `nvf-bus-booking.php`; everything under `src/` is PSR-4 autoloaded from the `NVF\BusBooking\` namespace.

## License

GPL-2.0-or-later
