/* LB Swing — Bus Booking admin JS. */

/* ── Manifest: sortable column headers ─────────────────── */
(function () {
    var table = document.querySelector('.nvf-manifest-table');
    if (!table) return;

    var tbody   = table.tBodies[0];
    var headers = table.querySelectorAll('th.nvf-sortable');
    if (!headers.length) return;

    headers.forEach(function (th) {
        th.addEventListener('click', sort);
        th.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort.call(th); }
        });
    });

    function sort() {
        var th        = this;
        var col       = parseInt(th.dataset.col, 10);
        var ascending = th.getAttribute('aria-sort') !== 'ascending';

        headers.forEach(function (h) { h.setAttribute('aria-sort', 'none'); });
        th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');

        var rows = Array.from(tbody.querySelectorAll('tr.nvf-manifest-row'));
        rows.sort(function (a, b) {
            var aVal = a.cells[col] ? a.cells[col].textContent.trim().toLowerCase() : '';
            var bVal = b.cells[col] ? b.cells[col].textContent.trim().toLowerCase() : '';
            if (aVal < bVal) return ascending ? -1 : 1;
            if (aVal > bVal) return ascending ?  1 : -1;
            return 0;
        });
        rows.forEach(function (row) { tbody.appendChild(row); });
    }
}());

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
    toggle();
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

/* ── SettingsPage: unsaved-changes guard ─────────────────── */
(function () {
    var form   = document.querySelector('.nvf-settings__form');
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
