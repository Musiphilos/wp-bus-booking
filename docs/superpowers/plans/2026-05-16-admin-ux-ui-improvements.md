# Admin UX/UI Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Elevate the admin backend from scattered inline styles to a consistent, polished interface with a shared design system and improved operational UX.

**Architecture:** Create a single `assets/css/admin.css` as the source of truth for all plugin admin page styles, with CSS custom properties mirroring the brand tokens in `booking.css`. A companion `assets/js/admin.js` delivers JS enhancements (manifest search, bulk promote, unsaved-changes guard, override warning). Both assets are enqueued via a new hook in `AdminMenu.php` and scoped to plugin-owned admin pages only.

**Tech Stack:** PHP 8.1 (WordPress plugin), vanilla CSS with CSS custom properties, vanilla JS (no build step — matches existing codebase pattern), WordPress `admin-post.php` for the new bulk-promote action.

---

## File Map

| Action | Path | Responsibility |
|--------|------|----------------|
| **CREATE** | `assets/css/admin.css` | Shared design tokens, status pills, capacity bars, stat cards, breadcrumb, manifest utilities, danger zone |
| **CREATE** | `assets/js/admin.js` | Manifest search filter, bulk-promote select-all, pickup visibility, override warning, unsaved-changes guard |
| **MODIFY** | `src/Admin/AdminMenu.php` | Enqueue both assets on plugin admin pages |
| **MODIFY** | `src/Admin/Dashboard.php` | Remove inline `<style>`, use admin.css classes, add capacity bars + status pills |
| **MODIFY** | `src/Admin/TripColumns.php` | Replace inline capacity color with shared capacity bar class |
| **MODIFY** | `src/Admin/BookingColumns.php` | Replace hardcoded pill styles with shared `nvf-pill--*` classes |
| **MODIFY** | `src/Admin/ManifestPage.php` | Add breadcrumb, responsive table class, search bar HTML, bulk-promote form + handler |
| **MODIFY** | `src/Admin/ManualAddPage.php` | Show/hide pickup select based on trip selection, add override warning |
| **MODIFY** | `src/Admin/SettingsPage.php` | Add unsaved-changes banner element (page was renamed from StringsSettingsPage and rebuilt with tabs on 2026-05-16) |
| **MODIFY** | `src/Admin/DebugLogPage.php` | Wrap rotate-secret button in danger-zone box |

### Pre-flight context (verified 2026-05-16)

The codebase moved since the initial analysis. Before each task, confirm these facts:

- **`StringsSettingsPage.php` no longer exists.** It was replaced by `src/Admin/SettingsPage.php`, a unified 1043-line tabbed page (General + Booking page + 5 Email tabs + PDF ticket). Plugin.php registers `SettingsPage`. **Task 9 targets this new file.**
- **`ManifestPage.php` already uses** `nvf-pill nvf-pill--{status}` and an action bar (`nvf-manifest__bar`). Task 5 has been reduced accordingly — do not re-do the pill replacement.
- **ManifestPage's existing BEM namespace is `nvf-manifest__*`** (double-underscore). Search/bulk-promote class names in Tasks 6–7 use distinct names (`nvf-manifest-search`, `nvf-waitlist-cb`) so they don't collide.
- **SettingsPage has its own large inline `<style>` block** (line 575+, ~100 lines, `.nvf-settings__*` selectors). **Leave it alone.** It's page-specific (tabs/chips/search) and was just built — extracting risks regression. Out of scope for this plan.

---

## Task 1: Create `assets/css/admin.css`

**Files:**
- Create: `assets/css/admin.css`

- [ ] **Step 1: Create the file**

```css
/*
 * LB Swing — Bus Booking admin styles.
 * Single source of truth for all plugin admin pages.
 * Tokens mirror booking.css palette (verified 2026-05-16).
 */

/* ── Design tokens ─────────────────────────────────────── */
.nvf-admin {
    --nvf-primary:        #036773;
    --nvf-text:           #014B51;
    --nvf-muted:          #5b7479;
    --nvf-yellow:         #EBB02B;
    --nvf-success:        #166534;
    --nvf-success-bg:     #ecfdf5;
    --nvf-success-border: #b6dec3;
    --nvf-warning:        #92580a;
    --nvf-warning-bg:     #fef7e1;
    --nvf-warning-border: #f4dba0;
    --nvf-error:          #881812;
    --nvf-error-bg:       #fdecec;
    --nvf-error-border:   #f3c0bd;
    --nvf-danger:         #991b1b;
    --nvf-danger-bg:      #fef2f2;
    --nvf-danger-border:  #fca5a5;
}

/* ── Status pills ──────────────────────────────────────── */
.nvf-pill {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 3px;
    white-space: nowrap;
    border: 1px solid transparent;
    line-height: 1.6;
}
.nvf-pill--confirmed {
    background: var(--nvf-success-bg);
    color: var(--nvf-success);
    border-color: var(--nvf-success-border);
}
.nvf-pill--waitlist {
    background: var(--nvf-warning-bg);
    color: var(--nvf-warning);
    border-color: var(--nvf-warning-border);
}
.nvf-pill--cancelled {
    background: var(--nvf-error-bg);
    color: var(--nvf-error);
    border-color: var(--nvf-error-border);
}
.nvf-pill--open {
    background: var(--nvf-success-bg);
    color: var(--nvf-success);
    border-color: var(--nvf-success-border);
}
.nvf-pill--full {
    background: var(--nvf-error-bg);
    color: var(--nvf-error);
    border-color: var(--nvf-error-border);
}
.nvf-pill--cancelled-trip {
    background: #f0f0f1;
    color: #50575e;
    border-color: #c3c4c7;
}

/* ── Capacity bar ──────────────────────────────────────── */
.nvf-cap {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.nvf-cap__label {
    font-weight: 600;
    font-size: 13px;
    white-space: nowrap;
    color: #1e1e1e;
}
.nvf-cap__track {
    width: 80px;
    height: 5px;
    background: #e5e7eb;
    border-radius: 99px;
    overflow: hidden;
}
.nvf-cap__fill {
    height: 100%;
    border-radius: 99px;
    background: #15803d;
}
.nvf-cap--mid  .nvf-cap__label { color: #b45309; }
.nvf-cap--mid  .nvf-cap__fill  { background: #b45309; }
.nvf-cap--full .nvf-cap__label { color: #b91c1c; }
.nvf-cap--full .nvf-cap__fill  { background: #b91c1c; }

/* ── Stat cards ────────────────────────────────────────── */
.nvf-admin__stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin: 1.5rem 0 2rem;
}
.nvf-admin__stat {
    background: #ffffff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 14px 16px;
    border-top: 3px solid #036773;
}
.nvf-admin__stat-label {
    font-size: 11px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #50575e;
}
.nvf-admin__stat-value {
    font-size: 28px;
    font-weight: 600;
    color: #014B51;
    margin-top: 4px;
    line-height: 1.1;
}

/* ── Table cell alignment ──────────────────────────────── */
.nvf-admin__trips th,
.nvf-admin__trips td {
    vertical-align: middle;
}

/* ── Breadcrumb nav ────────────────────────────────────── */
.nvf-breadcrumb {
    margin: 0 0 1rem;
    font-size: 13px;
    color: #50575e;
    display: flex;
    align-items: center;
    gap: 6px;
}
.nvf-breadcrumb a {
    color: #036773;
    text-decoration: none;
}
.nvf-breadcrumb a:hover { text-decoration: underline; }
.nvf-breadcrumb__sep { color: #c3c4c7; }

/* ── Manifest: action bar ──────────────────────────────── */
.nvf-manifest-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

/* ── Manifest: search bar ──────────────────────────────── */
.nvf-manifest-search {
    margin: 0 0 1rem;
    display: flex;
    align-items: center;
    gap: 10px;
}
.nvf-manifest-search__input {
    width: 240px;
    padding: 5px 10px;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    font-size: 13px;
}
.nvf-manifest-search__count {
    font-size: 13px;
    color: #50575e;
}
.nvf-manifest-row--hidden { display: none !important; }

/* ── Manifest: responsive table ────────────────────────── */
@media (max-width: 782px) {
    .nvf-manifest-table {
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
}

/* ── Danger zone box ───────────────────────────────────── */
.nvf-danger-zone {
    margin-top: 2rem;
    padding: 1rem 1.25rem;
    border: 1px solid #fca5a5;
    border-radius: 4px;
    background: #fef2f2;
}
.nvf-danger-zone__title {
    margin: 0 0 0.4rem;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #991b1b;
}
.nvf-danger-zone__desc {
    margin: 0 0 0.85rem;
    font-size: 13px;
    color: #7f1d1d;
    line-height: 1.5;
}
.nvf-btn-danger.button {
    background: #fef2f2 !important;
    color: #991b1b !important;
    border-color: #fca5a5 !important;
    font-weight: 700;
}
.nvf-btn-danger.button:hover {
    background: #991b1b !important;
    color: #fff !important;
    border-color: #991b1b !important;
}

/* ── Unsaved-changes banner ────────────────────────────── */
.nvf-unsaved-banner {
    display: none;
    background: #fef7e1;
    border: 1px solid #f4dba0;
    color: #92580a;
    padding: 8px 14px;
    border-radius: 3px;
    font-size: 13px;
    margin-bottom: 1rem;
    font-weight: 600;
}
.nvf-unsaved-banner.is-visible { display: block; }

/* ── Override warning ──────────────────────────────────── */
.nvf-override-warning {
    display: none;
    margin-top: 6px;
    color: #881812;
    font-weight: 600;
    font-size: 13px;
}
.nvf-override-warning.is-visible { display: block; }
```

- [ ] **Step 2: Verify the file**

```bash
wc -l /Users/davidafonso/Dev/lbswing/nvf-bus-booking/assets/css/admin.css
```
Expected: 200+ lines, exit 0.

- [ ] **Step 3: Commit**

```bash
git add assets/css/admin.css
git commit -m "feat(admin): create shared admin.css with design tokens, pills, capacity bars, and utilities"
```

---

## Task 2: Create `assets/js/admin.js` and enqueue both assets in `AdminMenu.php`

**Files:**
- Create: `assets/js/admin.js`
- Modify: `src/Admin/AdminMenu.php`

- [ ] **Step 1: Create assets/js/admin.js**

```javascript
/* LB Swing — Bus Booking admin JS. */

/* ── Manifest: passenger search filter ─────────────────── */
(function () {
    var input   = document.getElementById('nvf-manifest-search');
    var counter = document.getElementById('nvf-manifest-count');
    if (!input) return;

    var rows  = document.querySelectorAll('.nvf-manifest-row');
    var total = rows.length;

    function updateCount(visible) {
        if (counter) {
            counter.textContent = (visible === total) ? '' : (visible + ' / ' + total + ' shown');
        }
    }

    input.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        var visible = 0;
        rows.forEach(function (row) {
            var match = !q || (row.dataset.search || '').indexOf(q) !== -1;
            row.classList.toggle('nvf-manifest-row--hidden', !match);
            if (match) visible++;
        });
        updateCount(visible);
    });

    updateCount(total);
}());

/* ── Manifest: bulk waitlist promote ───────────────────── */
(function () {
    var selectAll = document.getElementById('nvf-select-all-waitlist');
    var bulkBtn   = document.getElementById('nvf-bulk-promote-btn');
    var countEl   = document.getElementById('nvf-bulk-count');
    if (!selectAll || !bulkBtn) return;

    function syncButton() {
        var checked = document.querySelectorAll('.nvf-waitlist-cb:checked');
        bulkBtn.disabled = checked.length === 0;
        if (countEl) countEl.textContent = checked.length;
    }

    selectAll.addEventListener('change', function () {
        document.querySelectorAll('.nvf-waitlist-cb').forEach(function (cb) {
            cb.checked = selectAll.checked;
        });
        syncButton();
    });

    document.querySelectorAll('.nvf-waitlist-cb').forEach(function (cb) {
        cb.addEventListener('change', syncButton);
    });
}());

/* ── ManualAddPage: show/hide pickup on trip change ─────── */
(function () {
    var tripSelect   = document.getElementById('nvf_inbound');
    var pickupWrap   = document.getElementById('nvf-pickup-wrap');
    if (!tripSelect || !pickupWrap) return;

    function toggle() {
        pickupWrap.style.display = tripSelect.value && tripSelect.value !== '0' ? '' : 'none';
    }

    tripSelect.addEventListener('change', toggle);
    toggle(); // run on page load to respect pre-selected value
}());

/* ── ManualAddPage: override capacity warning ───────────── */
(function () {
    var cb      = document.getElementById('nvf-override-capacity');
    var warning = document.getElementById('nvf-override-warning');
    if (!cb || !warning) return;

    cb.addEventListener('change', function () {
        warning.classList.toggle('is-visible', cb.checked);
    });
}());

/* ── StringsSettingsPage: unsaved-changes guard ─────────── */
(function () {
    var form   = document.getElementById('nvf-strings-form');
    var banner = document.getElementById('nvf-unsaved-banner');
    if (!form || !banner) return;

    var dirty = false;

    function markDirty() {
        dirty = true;
        banner.classList.add('is-visible');
    }

    form.addEventListener('change', markDirty);
    form.addEventListener('input', markDirty);

    form.addEventListener('submit', function () {
        dirty = false;
        window.removeEventListener('beforeunload', beforeUnloadHandler);
    });

    function beforeUnloadHandler(e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
    }

    window.addEventListener('beforeunload', beforeUnloadHandler);
}());
```

- [ ] **Step 2: Read AdminMenu.php to find the register() method**

Read `src/Admin/AdminMenu.php` and note:
- Where `register()` is defined (it currently has `add_action( 'admin_menu', ... )` and `add_action( 'admin_notices', ... )`)
- The value of `self::SLUG` (it is `'nvf-bus-booking'`)
- The `plugin_dir_url()` call pattern used (or the absence of one — it doesn't exist yet)

- [ ] **Step 3: Add enqueueAssets to AdminMenu.php**

Add a new `add_action` call inside `register()`, after the existing ones:

```php
add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueAssets' ] );
```

Then add the method to the class:

```php
public static function enqueueAssets( string $hookSuffix ): void {
    // Scope to our plugin pages only: top-level page, CPT list tables (nvf_booking, nvf_trip).
    $isOurPage = str_contains( $hookSuffix, self::SLUG )
        || str_contains( $hookSuffix, 'nvf_booking' )
        || str_contains( $hookSuffix, 'nvf_trip' );

    if ( ! $isOurPage ) {
        return;
    }

    $base = plugin_dir_url( dirname( __DIR__ ) . '/../nvf-bus-booking.php' );
    $ver  = '1.1.0';
    wp_enqueue_style(  'nvf-admin', $base . 'assets/css/admin.css', [], $ver );
    wp_enqueue_script( 'nvf-admin', $base . 'assets/js/admin.js',  [], $ver, true );
}
```

> **Note on `plugin_dir_url` path:** `src/Admin/AdminMenu.php` is two directories deep from the plugin root. `dirname( __DIR__ )` gives `src/`, and `dirname( __DIR__ ) . '/../nvf-bus-booking.php'` gives the plugin root file. Verify this resolves correctly by checking the actual directory depth.

- [ ] **Step 4: Verify hook suffix in browser**

Temporarily add to the `enqueueAssets` method body:
```php
error_log( 'nvf hook: ' . $hookSuffix );
```
Visit the Bus Booking dashboard, Bookings list, and Trips list. Check the PHP error log and confirm the hook suffix contains the expected strings. Remove the debug line.

- [ ] **Step 5: Commit**

```bash
git add assets/js/admin.js src/Admin/AdminMenu.php
git commit -m "feat(admin): enqueue admin.css and admin.js scoped to plugin admin pages"
```

---

## Task 3: Dashboard — remove inline styles, add capacity bars and status pills

**Files:**
- Modify: `src/Admin/Dashboard.php`

The current file has a `<style>` block at the bottom of `render()` and uses inline `style="color:..."` for capacity coloring.

- [ ] **Step 1: Add `raw_status` to the trip data array in `loadTrips()`**

In `loadTrips()`, the returned array currently has `status_label`. Add `raw_status` beside it:

```php
$rawStatus = (string) get_post_meta( $post->ID, 'status', true );
$out[] = [
    'id'           => $post->ID,
    'code'         => (string) get_post_meta( $post->ID, 'trip_code', true ),
    'direction'    => (string) get_post_meta( $post->ID, 'direction', true ),
    'departure'    => self::lisbonHuman( (string) get_post_meta( $post->ID, 'departure_datetime', true ) ),
    'capacity'     => $capacity,
    'confirmed'    => $confirmed,
    'waitlist'     => self::countWaitlist( $post->ID ),
    'raw_status'   => $rawStatus,
    'status_label' => self::statusLabel( $rawStatus, $confirmed, $capacity ),
];
```

- [ ] **Step 2: Replace the capacity `<td>` with a capacity bar**

Find the `<td>` block that currently outputs `<span style="color:...">X / Y</span>`. Replace it:

```php
<td>
    <?php
    $capCls = $pct >= 100 ? 'full' : ( $pct >= 80 ? 'mid' : '' );
    ?>
    <div class="nvf-cap nvf-cap--<?php echo esc_attr( $capCls ); ?>">
        <span class="nvf-cap__label"><?php echo (int) $t['confirmed']; ?> / <?php echo (int) $t['capacity']; ?></span>
        <div class="nvf-cap__track">
            <div class="nvf-cap__fill" style="width:<?php echo esc_attr( (string) min( 100, $pct ) ); ?>%"></div>
        </div>
    </div>
</td>
```

- [ ] **Step 3: Replace the status `<td>` with a status pill**

Find `<td><?php echo esc_html( $t['status_label'] ); ?></td>` and replace:

```php
<td>
    <?php
    if ( $t['raw_status'] === 'cancelled' ) {
        $pillCls = 'cancelled-trip';
    } elseif ( $t['capacity'] > 0 && $t['confirmed'] >= $t['capacity'] ) {
        $pillCls = 'full';
    } else {
        $pillCls = 'open';
    }
    ?>
    <span class="nvf-pill nvf-pill--<?php echo esc_attr( $pillCls ); ?>">
        <?php echo esc_html( $t['status_label'] ); ?>
    </span>
</td>
```

- [ ] **Step 4: Delete the inline `<style>` block**

Remove the entire `<style>...</style>` block from `render()`. The stat cards (`nvf-admin__stats`, `nvf-admin__stat`, etc.) and table (`nvf-admin__trips`) are now styled by `admin.css`.

- [ ] **Step 5: Verify in browser**

Open the Bus Booking dashboard. Confirm:
- Four stat cards render with teal top border (no inline style block in page source)
- Each trip row shows a capacity bar under the confirmed/capacity count
- Bars are green < 80%, amber 80–99%, red at 100%
- Status column shows colored pills

- [ ] **Step 6: Commit**

```bash
git add src/Admin/Dashboard.php
git commit -m "feat(admin): dashboard capacity bars and status pills, remove inline <style>"
```

---

## Task 4: TripColumns and BookingColumns — shared pill and capacity bar classes

**Files:**
- Modify: `src/Admin/TripColumns.php`
- Modify: `src/Admin/BookingColumns.php`

- [ ] **Step 1: Read TripColumns.php**

Read `src/Admin/TripColumns.php` in full. Find:
- The `case` or `if` block that renders the seat capacity column (look for percentage calculation with `minmax`, `round`, or color hex values)
- The block that renders the trip status column

- [ ] **Step 2: Replace TripColumns capacity output**

Replace whatever currently renders inline-styled seat counts with:

```php
$pct    = $capacity > 0 ? min( 100, (int) round( $confirmed / $capacity * 100 ) ) : 0;
$capCls = $pct >= 100 ? 'full' : ( $pct >= 80 ? 'mid' : '' );
echo '<div class="nvf-cap nvf-cap--' . esc_attr( $capCls ) . '">'
   . '<span class="nvf-cap__label">' . (int) $confirmed . ' / ' . (int) $capacity . '</span>'
   . '<div class="nvf-cap__track"><div class="nvf-cap__fill" style="width:' . (int) $pct . '%"></div></div>'
   . '</div>';
```

Adjust variable names (`$capacity`, `$confirmed`) to whatever the column renderer uses.

- [ ] **Step 3: Replace TripColumns status output**

Replace inline-styled status text with a pill. Trip statuses are `'open'`, `'cancelled'`, and derived `'full'`:

```php
$rawStatus = (string) get_post_meta( $postId, 'status', true );
if ( $rawStatus === 'cancelled' ) {
    $pillCls = 'cancelled-trip';
    $label   = __( 'Cancelled', 'nvf-bus-booking' );
} elseif ( $capacity > 0 && $confirmed >= $capacity ) {
    $pillCls = 'full';
    $label   = __( 'Full', 'nvf-bus-booking' );
} else {
    $pillCls = 'open';
    $label   = __( 'Open', 'nvf-bus-booking' );
}
echo '<span class="nvf-pill nvf-pill--' . esc_attr( $pillCls ) . '">' . esc_html( $label ) . '</span>';
```

- [ ] **Step 4: Read BookingColumns.php**

Read `src/Admin/BookingColumns.php`. Find the block that renders leg status (inbound/outbound). The status values are `'confirmed'`, `'waitlist'`, `'cancelled'`.

- [ ] **Step 5: Replace BookingColumns leg status pills**

Replace whatever currently renders inline-styled status spans with:

```php
// $legStatus is 'confirmed', 'waitlist', or 'cancelled'
echo '<span class="nvf-pill nvf-pill--' . esc_attr( $legStatus ) . '">' . esc_html( ucfirst( $legStatus ) ) . '</span>';
```

Apply this pattern to both inbound and outbound leg status outputs.

- [ ] **Step 6: Verify in browser**

Open the Trips CPT list and the Bookings CPT list. Confirm pills and capacity bars render with the correct colors and no inline style attributes for color.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/TripColumns.php src/Admin/BookingColumns.php
git commit -m "feat(admin): shared pill and capacity bar classes in TripColumns and BookingColumns"
```

---

## Task 5: ManifestPage — breadcrumb + responsive table

**Files:**
- Modify: `src/Admin/ManifestPage.php`

> **Reduced scope (2026-05-16):** The pill replacement (former Step 5) is already done — ManifestPage already uses `nvf-pill nvf-pill--{status}` and `nvf-manifest__bar` for actions. Skip those steps. This task now only adds the breadcrumb and the responsive-table class.

- [ ] **Step 1: Read ManifestPage.php in full**

Read the file. Note:
- The render method starts with `<div class="wrap nvf-manifest">`
- The action bar already exists as `<div class="nvf-manifest__bar">` (Back / CSV / Print)
- The title is `<h1 class="nvf-manifest__title">` followed by `<p class="nvf-manifest__meta">`
- The `<table>` element uses `class="widefat striped nvf-manifest__table"`
- The trip code (not the post title) is shown in the H1 via `get_post_meta( $tripId, 'trip_code', true )`

- [ ] **Step 2: Add breadcrumb after the `<h1>`**

Immediately after the `<h1 class="nvf-manifest__title">` line, add:

```php
<nav class="nvf-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'nvf-bus-booking' ); ?>">
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::SLUG ) ); ?>">
        <?php esc_html_e( 'Bus Booking', 'nvf-bus-booking' ); ?>
    </a>
    <span class="nvf-breadcrumb__sep" aria-hidden="true">›</span>
    <span><?php echo esc_html( get_post_meta( $tripId, 'trip_code', true ) ); ?> — <?php esc_html_e( 'Manifest', 'nvf-bus-booking' ); ?></span>
</nav>
```

`$tripId` is already in scope inside `render()`.

- [ ] **Step 3: Add `nvf-manifest-table-responsive` class to the table**

The existing class is `widefat striped nvf-manifest__table`. Add our responsive wrapper class beside it:

```php
<table class="widefat striped nvf-manifest__table nvf-manifest-table">
```

(`nvf-manifest-table` is the class defined in `admin.css` with the `@media (max-width: 782px)` overflow rule. The existing `nvf-manifest__table` continues to control print-specific styling from the page's own inline `<style>` block.)

- [ ] **Step 4: Add `.nvf-admin` to the wrap div for token scoping**

The token-scoped CSS custom properties in `admin.css` live on `.nvf-admin`. The current wrap is `<div class="wrap nvf-manifest">`. Change it to:

```php
<div class="wrap nvf-admin nvf-manifest">
```

This activates the `--nvf-*` custom properties (used by `.nvf-breadcrumb` and other utilities) without disturbing the existing `.nvf-manifest` styles.

- [ ] **Step 5: Verify in browser**

Open a Manifest page. Confirm:
- Breadcrumb shows "Bus Booking › [TRIP-CODE] — Manifest" between the H1 and the action bar
- At narrow viewport (resize browser to <782px), table scrolls horizontally instead of clipping
- Pill colors and existing action bar are unchanged

- [ ] **Step 6: Commit**

```bash
git add src/Admin/ManifestPage.php
git commit -m "feat(admin): manifest breadcrumb and responsive-table wrapper class"
```

---

## Task 6: ManifestPage — client-side passenger search filter

**Files:**
- Modify: `src/Admin/ManifestPage.php`

> `assets/js/admin.js` already has the search JS from Task 2, Step 1. This task wires the HTML.

- [ ] **Step 1: Add search bar HTML before the manifest table**

Between the action bar div and the `<table>`, add:

```php
<div class="nvf-manifest-search no-print">
    <input
        type="search"
        id="nvf-manifest-search"
        class="nvf-manifest-search__input"
        placeholder="<?php esc_attr_e( 'Filter passengers…', 'nvf-bus-booking' ); ?>"
        aria-label="<?php esc_attr_e( 'Filter passengers', 'nvf-bus-booking' ); ?>"
    >
    <span class="nvf-manifest-search__count" id="nvf-manifest-count" aria-live="polite"></span>
</div>
```

- [ ] **Step 2: Add `nvf-manifest-row` class and `data-search` to each passenger `<tr>`**

The existing row already has `class="nvf-status-{status}"`. Append the `nvf-manifest-row` class and a `data-search` attribute (lowercased name + email + pickup for JS substring matching):

```php
<tr class="nvf-status-<?php echo esc_attr( $r['status'] ); ?> nvf-manifest-row" data-search="<?php
    echo esc_attr( strtolower(
        ( $r['name']   ?? '' ) . ' ' .
        ( $r['email']  ?? '' ) . ' ' .
        ( $r['pickup'] ?? '' )
    ) );
?>">
```

> Confirm `$r['name']`, `$r['email']`, `$r['pickup']` are the actual array keys by reading the row-data builder above the loop. If a field is missing in the array, omit it from the concatenation rather than adding it as `??`.

- [ ] **Step 3: Verify in browser**

Open a Manifest page with multiple passengers. Type a name fragment — matching rows stay, others hide. Counter reads "2 / 18 shown". Clearing input restores all rows.

- [ ] **Step 4: Commit**

```bash
git add src/Admin/ManifestPage.php
git commit -m "feat(admin): client-side passenger search filter on manifest page"
```

---

## Task 7: ManifestPage — bulk waitlist promote

**Files:**
- Modify: `src/Admin/ManifestPage.php`

The existing promote is one-at-a-time via `admin_post_nvf_promote_waitlist`. This task adds a new `admin_post_nvf_promote_bulk` handler and the UI for it.

- [ ] **Step 1: Register the new bulk action in `register()`**

In `ManifestPage::register()` (wherever `add_action( 'admin_post_nvf_promote_waitlist', ... )` lives), add:

```php
add_action( 'admin_post_nvf_promote_bulk', [ self::class, 'handlePromoteBulk' ] );
```

- [ ] **Step 2: Add a checkbox column header to the `<thead>`**

In the manifest table `<thead>`, add a new `<th>` at the start (before the name/ref column):

```php
<th class="no-print" style="width:32px;">
    <input
        type="checkbox"
        id="nvf-select-all-waitlist"
        title="<?php esc_attr_e( 'Select all waitlist passengers', 'nvf-bus-booking' ); ?>"
    >
</th>
```

- [ ] **Step 3: Add a checkbox cell to each passenger `<tr>`**

In the passenger row loop, add a cell at the start. Show the checkbox only for waitlist rows; render an empty cell for confirmed/cancelled so the table stays aligned:

```php
<td class="no-print">
    <?php if ( $r['status'] === 'waitlist' ) : ?>
        <input
            type="checkbox"
            class="nvf-waitlist-cb"
            name="promote_ids[]"
            value="<?php echo (int) $r['booking_id']; ?>"
            form="nvf-bulk-promote-form"
        >
    <?php endif; ?>
</td>
```

- [ ] **Step 4: Add the bulk promote `<form>` after the closing `</table>`**

```php
<form id="nvf-bulk-promote-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="no-print" style="margin-top:1rem;">
    <?php wp_nonce_field( 'nvf_promote_bulk_' . $tripId, 'nvf_bulk_nonce' ); ?>
    <input type="hidden" name="action"   value="nvf_promote_bulk">
    <input type="hidden" name="trip_id"  value="<?php echo (int) $tripId; ?>">
    <button type="submit" id="nvf-bulk-promote-btn" class="button button-primary" disabled>
        <?php esc_html_e( 'Promote selected from waitlist', 'nvf-bus-booking' ); ?>
        (<span id="nvf-bulk-count">0</span>)
    </button>
</form>
```

- [ ] **Step 5: Add `handlePromoteBulk()` static method**

Add this method to `ManifestPage`:

```php
public static function handlePromoteBulk(): void {
    if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
        wp_die( esc_html__( 'You do not have permission.', 'nvf-bus-booking' ) );
    }

    $tripId = isset( $_POST['trip_id'] ) ? (int) $_POST['trip_id'] : 0;
    check_admin_referer( 'nvf_promote_bulk_' . $tripId, 'nvf_bulk_nonce' );

    $ids      = isset( $_POST['promote_ids'] ) ? array_map( 'intval', (array) $_POST['promote_ids'] ) : [];
    $promoted = 0;

    foreach ( $ids as $bookingId ) {
        // Reuse the same promote logic as handlePromoteWaitlist, but without redirect per item.
        // Read handlePromoteWaitlist() to confirm the exact SeatLedger/Mailer calls, then mirror them here.
        $booking   = get_post( $bookingId );
        if ( ! $booking || $booking->post_type !== PostTypes::BOOKING ) {
            continue;
        }

        $direction = (string) get_post_meta( $bookingId, 'trip_id', true ) === (string) $tripId
            ? ( str_starts_with( (string) get_post_meta( $tripId, 'direction', true ), 'OPO-IN' ) ? 'inbound' : 'outbound' )
            : null;

        if ( $direction === null ) {
            continue;
        }

        $statusKey = $direction === 'inbound' ? 'inbound_status' : 'outbound_status';
        if ( get_post_meta( $bookingId, $statusKey, true ) !== 'waitlist' ) {
            continue;
        }

        if ( ! SeatLedger::hasCapacity( $tripId ) ) {
            break; // no point continuing if trip is now full
        }

        update_post_meta( $bookingId, $statusKey, 'confirmed' );
        SeatLedger::increment( $tripId );
        $promoted++;

        // Fire mail + webhook best-effort (mirror handlePromoteWaitlist).
        try {
            $ctx = BookingContext::forBooking( $bookingId, $direction );
            Mailer::sendAdminNotification( 'booking.promoted', $ctx );
        } catch ( \Throwable $e ) {
            Logger::error( 'admin.promote_bulk_mail_failed', [ 'booking' => $bookingId, 'reason' => $e->getMessage() ] );
        }
        try {
            GoogleSheetsWebhook::dispatch( 'booking.promoted', $bookingId );
        } catch ( \Throwable $e ) {
            Logger::error( 'admin.promote_bulk_sheets_failed', [ 'booking' => $bookingId, 'reason' => $e->getMessage() ] );
        }

        Logger::info( 'admin.promoted_waitlist_bulk', [ 'booking' => $bookingId, 'trip' => $tripId ] );
    }

    wp_safe_redirect( add_query_arg(
        [ 'page' => self::SLUG, 'trip' => $tripId, 'bulk_promoted' => $promoted ],
        admin_url( 'admin.php' )
    ) );
    exit;
}
```

> **Important:** Read `handlePromoteWaitlist()` carefully before implementing. Mirror its exact SeatLedger and Mailer calls. The direction detection above is a sketch — adjust to match how the existing method derives direction from `get_post_meta`.

- [ ] **Step 6: Show a success notice when `bulk_promoted` query param is set**

In `render()`, near the top where the existing `promoted` notice is shown, add:

```php
if ( isset( $_GET['bulk_promoted'] ) ) {
    $n = (int) $_GET['bulk_promoted'];
    echo '<div class="notice notice-success is-dismissible"><p>'
        . sprintf(
            esc_html( _n( '%d passenger promoted from waitlist.', '%d passengers promoted from waitlist.', $n, 'nvf-bus-booking' ) ),
            $n
        )
        . '</p></div>';
}
```

- [ ] **Step 7: Verify in browser**

Open a Manifest with waitlist passengers. Check "Select all waitlist" — all waitlist checkboxes check. Button enables showing the count. Submit → redirects back with "N passengers promoted from waitlist." notice.

- [ ] **Step 8: Commit**

```bash
git add src/Admin/ManifestPage.php
git commit -m "feat(admin): bulk waitlist promote with select-all checkbox on manifest page"
```

---

## Task 8: ManualAddPage — show/hide pickup and override warning

**Files:**
- Modify: `src/Admin/ManualAddPage.php`

> The pickup locations are a fixed global list (`MetaBoxes::PICKUP_LOCATIONS`: `airport`, `casa_da_musica`). They do not vary per trip, so no AJAX is needed. The improvement is: hide the pickup select when no inbound trip is selected, and add a warning when override is checked.

- [ ] **Step 1: Wrap the pickup `<select>` in a `<span id="nvf-pickup-wrap">`**

Find the inline `<label style="margin-left:10px">` wrapping the `inbound_pickup` select (lines ~81–87). Replace that label wrapper:

```php
<span id="nvf-pickup-wrap" style="margin-left:10px;display:none;">
    <select name="inbound_pickup" id="nvf_inbound_pickup">
        <option value=""><?php esc_html_e( '— Pickup —', 'nvf-bus-booking' ); ?></option>
        <?php foreach ( \NVF\BusBooking\Domain\MetaBoxes::PICKUP_LOCATIONS as $key => $label ) : ?>
            <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
        <?php endforeach; ?>
    </select>
</span>
```

This replaces the hardcoded `airport` / `casa_da_musica` options with the canonical list from `MetaBoxes::PICKUP_LOCATIONS`, and wraps in the `nvf-pickup-wrap` span that the JS toggles.

- [ ] **Step 2: Add `id` to the override checkbox and add the warning paragraph**

Find the override checkbox (line ~104):
```php
<label><input type="checkbox" name="override_capacity" value="1" />
```

Replace with:
```php
<label>
    <input type="checkbox" name="override_capacity" value="1" id="nvf-override-capacity">
    <?php esc_html_e( 'Override capacity (admin/team override)', 'nvf-bus-booking' ); ?>
</label>
<p id="nvf-override-warning" class="nvf-override-warning">
    ⚠ <?php esc_html_e( 'This bypasses the seat limit. The booking will be confirmed even if the trip is full.', 'nvf-bus-booking' ); ?>
</p>
```

- [ ] **Step 3: Verify in browser**

Open Manual Add. With no trip selected: pickup is hidden. Select an inbound trip: pickup appears. Check Override: warning text appears below in red. Uncheck: warning hides.

- [ ] **Step 4: Commit**

```bash
git add src/Admin/ManualAddPage.php
git commit -m "feat(admin): show/hide pickup select on trip change and override capacity warning"
```

---

## Task 9: SettingsPage — unsaved-changes guard

**Files:**
- Modify: `src/Admin/SettingsPage.php`
- Modify: `assets/js/admin.js`

> **File renamed (2026-05-16):** target is `SettingsPage.php`, not the old `StringsSettingsPage.php`. The page now has tabs and the form is `class="nvf-settings__form"` (no `id`). The JS in admin.js (Task 2, Step 1) currently uses `document.getElementById('nvf-strings-form')` — update it to query by class.
>
> **Do not modify the page's inline `<style>` block** (~line 575+). Out of scope.

- [ ] **Step 1: Update the JS selector in `assets/js/admin.js`**

In the unsaved-changes IIFE (last block in admin.js), change:

```javascript
var form   = document.getElementById('nvf-strings-form');
```

to:

```javascript
var form   = document.querySelector('.nvf-settings__form');
```

Also rename the comment header from "StringsSettingsPage" to "SettingsPage" for clarity. The banner lookup (`document.getElementById('nvf-unsaved-banner')`) stays the same.

- [ ] **Step 2: Read SettingsPage.php to locate the form opening tag**

Find the line containing `<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvf-settings__form">` (around line 108). Note the lines that immediately follow (likely the nonce field and a hidden `action` input).

- [ ] **Step 3: Add the unsaved-changes banner inside the form**

After the form opening tag and the nonce/hidden-input lines, immediately before the `<div class="nvf-settings__tab-content">` block, add:

```php
<div class="nvf-unsaved-banner" id="nvf-unsaved-banner" role="status">
    <?php esc_html_e( 'You have unsaved changes — remember to save before leaving.', 'nvf-bus-booking' ); ?>
</div>
```

- [ ] **Step 4: Add `.nvf-admin` to the wrap div for token scoping**

The token-scoped CSS custom properties in `admin.css` live on `.nvf-admin`. The current wrap is `<div class="wrap nvf-settings">`. Change it to:

```php
<div class="wrap nvf-admin nvf-settings">
```

This activates the `--nvf-*` custom properties used by `.nvf-unsaved-banner` (and any future admin utilities) without altering existing `.nvf-settings__*` styles.

- [ ] **Step 5: Verify in browser**

Open Bus Booking → Settings. Edit any field on any tab → yellow banner appears. Try to navigate to another admin page → browser shows "Leave site?" dialog. Click Save → no dialog on next navigation.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/SettingsPage.php assets/js/admin.js
git commit -m "feat(admin): unsaved-changes guard with beforeunload on settings page"
```

---

## Task 10: DebugLogPage — danger zone styling for rotate-secret

**Files:**
- Modify: `src/Admin/DebugLogPage.php`

- [ ] **Step 1: Read DebugLogPage.php**

Read the file. Find:
- The rotate-secret form and button (look for `rotate_secret` or `nvf_rotate_secret` action name)
- The surrounding markup

- [ ] **Step 2: Wrap the rotate-secret form in a danger zone box**

Replace the existing rotate-secret button/form section with:

```php
<div class="nvf-danger-zone">
    <h3 class="nvf-danger-zone__title">⚠ <?php esc_html_e( 'Danger Zone', 'nvf-bus-booking' ); ?></h3>
    <p class="nvf-danger-zone__desc">
        <?php esc_html_e( 'Rotating the signing secret immediately invalidates all active magic links and logged-in sessions. Every user will need to re-authenticate.', 'nvf-bus-booking' ); ?>
    </p>
    <!-- keep the existing <form> here exactly as-is; only replace the <button> -->
    <form method="post" ...>  <!-- keep existing nonce and hidden fields -->
        <?php wp_nonce_field( '...existing nonce action...' ); ?>
        <input type="hidden" name="action" value="...existing action...">
        <button
            type="submit"
            class="button nvf-btn-danger"
            onclick="return confirm('<?php esc_attr_e( 'Rotate the signing secret? All sessions and magic links will be invalidated immediately. This cannot be undone.', 'nvf-bus-booking' ); ?>')"
        >
            <?php esc_html_e( 'Rotate signing secret', 'nvf-bus-booking' ); ?>
        </button>
    </form>
</div>
```

> Read the existing form carefully. Keep its `action`, nonce field, and hidden inputs intact. Only wrap it and replace the `<button>` element.

- [ ] **Step 3: Verify in browser**

Open Debug Log page. The rotate-secret section shows a red-bordered box labeled "Danger Zone" with descriptive warning text. The button renders with red styling, not default WP blue.

- [ ] **Step 4: Commit**

```bash
git add src/Admin/DebugLogPage.php
git commit -m "feat(admin): danger zone box and red button for rotate-signing-secret on debug log page"
```

---

## Self-Review

### Spec coverage

| Analysis item | Task |
|---|---|
| Shared admin.css with brand tokens | Task 1 |
| Enqueue admin.css + admin.js | Task 2 |
| Dashboard: capacity bars | Task 3 |
| Dashboard: status pills | Task 3 |
| Dashboard: remove inline styles | Task 3 |
| TripColumns: capacity bar + pill | Task 4 |
| BookingColumns: shared pills | Task 4 |
| ManifestPage: breadcrumb nav | Task 5 |
| ManifestPage: action bar | Task 5 |
| ManifestPage: responsive table | Task 5 |
| ManifestPage: shared status pills | Task 5 |
| ManifestPage: search filter | Task 6 |
| ManifestPage: bulk waitlist promote | Task 7 |
| ManualAddPage: pickup show/hide | Task 8 |
| ManualAddPage: override warning | Task 8 |
| SettingsPage: unsaved-changes guard | Task 9 |
| DebugLogPage: danger zone styling | Task 10 |

**Not included (intentionally deferred as very minor):**
- Empty state for the trips table (zero trips edge case — add a `<tr><td colspan="7">No trips found.</td></tr>` if desired, trivial one-liner)
- No-loading-state on form submits (full page reload pattern is WP standard; spinner would require JS intercept added per-form)
