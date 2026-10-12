/* SleekAI — signed-in dashboard shell behaviour. */

(function () {
    'use strict';

    var DOCKED = window.matchMedia('(min-width: 1024px)');

    var toggle  = document.getElementById('navToggle');
    var overlay = document.getElementById('navOverlay');
    var sidebar = document.getElementById('sidebar');

    /* The button means two different things either side of the lg breakpoint:
       docked, it collapses the sidebar; as a drawer, it slides it in. */
    function openDrawer() {
        document.body.classList.add('nav-open');
        if (overlay) overlay.hidden = false;
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
    }

    function closeDrawer() {
        document.body.classList.remove('nav-open');
        if (overlay) overlay.hidden = true;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (DOCKED.matches) {
                var collapsed = document.body.classList.toggle('nav-collapsed');
                toggle.setAttribute('aria-expanded', String(!collapsed));
            } else {
                document.body.classList.contains('nav-open') ? closeDrawer() : openDrawer();
            }
        });
    }

    if (overlay) overlay.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && document.body.classList.contains('nav-open')) closeDrawer();
    });

    /* Tapping a nav link inside the drawer should close it on the way out. */
    if (sidebar) {
        sidebar.addEventListener('click', function (ev) {
            if (!DOCKED.matches && ev.target.closest('a')) closeDrawer();
        });
    }

    /* Crossing the breakpoint leaves the drawer state stale — reset it. */
    var onChange = function () {
        if (DOCKED.matches) {
            closeDrawer();
        } else {
            document.body.classList.remove('nav-collapsed');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }
    };
    DOCKED.addEventListener ? DOCKED.addEventListener('change', onChange) : DOCKED.addListener(onChange);
    onChange();

    /* Tool upload boxes: drag & drop onto the label, and show what was picked. */
    document.querySelectorAll('[data-drop]').forEach(function (zone) {
        var input = document.getElementById(zone.getAttribute('for'));
        var label = zone.querySelector('[data-drop-label]');
        if (!input || !label) return;
        var original = label.textContent;

        var show = function () {
            var names = Array.prototype.map.call(input.files || [], function (f) { return f.name; });
            label.textContent = names.length ? names.join(', ') : original;
            zone.classList.toggle('border-signal/60', names.length > 0);
        };
        input.addEventListener('change', show);

        ['dragenter', 'dragover'].forEach(function (t) {
            zone.addEventListener(t, function (ev) { ev.preventDefault(); zone.classList.add('border-signal/60'); });
        });
        zone.addEventListener('dragleave', function () { if (!input.files.length) zone.classList.remove('border-signal/60'); });
        zone.addEventListener('drop', function (ev) {
            ev.preventDefault();
            if (ev.dataTransfer && ev.dataTransfer.files.length) {
                input.files = ev.dataTransfer.files;
                show();
            }
        });
    });

    /* Live character count for textareas marked data-counted. */
    document.querySelectorAll('textarea[data-counted]').forEach(function (ta) {
        var out = ta.closest('label') && ta.closest('label').querySelector('[data-count]');
        if (!out) return;
        ta.addEventListener('input', function () { out.textContent = ta.value.length; });
    });

    /* Tool forms: one submit only, and say that it's working — a job can take a minute. */
    document.querySelectorAll('form[data-job-form]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var b = form.querySelector('button[type="submit"]');
            if (!b) return;
            b.dataset.label = b.dataset.label || b.innerHTML;
            b.disabled = true;
            b.textContent = 'Working… this can take a minute';
        });
    });
    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted) return;
        document.querySelectorAll('form[data-job-form] button[type="submit"]').forEach(function (b) {
            if (b.dataset.label) { b.innerHTML = b.dataset.label; b.disabled = false; }
        });
    });

    /* Jobs still rendering at a provider: check in until they finish, then reload so
       the result (or the refund) shows. Backs off, and pauses in background tabs. */
    var polling = Array.prototype.map.call(document.querySelectorAll('[data-job-poll]'), function (el) {
        return el.getAttribute('data-job-poll');
    });
    if (polling.length) {
        var delay = 5000;
        var check = function () {
            if (document.hidden) { setTimeout(check, delay); return; }
            Promise.all(polling.map(function (id) {
                return fetch('job-status?job=' + encodeURIComponent(id), { credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .catch(function () { return null; });
            })).then(function (results) {
                var done = results.some(function (r) { return r && r.status && r.status !== 'processing'; });
                if (done) { location.reload(); return; }
                delay = Math.min(delay + 2500, 20000);
                setTimeout(check, delay);
            });
        };
        setTimeout(check, delay);
    }

    /* Voice picker: play the selected voice's sample. */
    document.querySelectorAll('[data-voice-preview]').forEach(function (btn) {
        var select = document.getElementById('voice');
        var audio = null;
        btn.addEventListener('click', function () {
            var opt = select && select.selectedOptions[0];
            var url = opt && opt.getAttribute('data-preview');
            if (!url) return;
            if (audio) audio.pause();
            audio = new Audio(url);
            audio.play().catch(function () { /* blocked or unavailable — nothing to do */ });
        });
    });

    /* Copy-to-clipboard, for the referral link. */
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.querySelector(btn.getAttribute('data-copy'));
            if (!target) return;

            var done = function () {
                var original = btn.getAttribute('data-label') || btn.textContent;
                btn.setAttribute('data-label', original);
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = original; }, 1600);
            };

            // navigator.clipboard needs a secure context; select+execCommand covers
            // plain http://localhost mirrors of this page.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(target.value).then(done).catch(function () {
                    target.select();
                    document.execCommand('copy');
                    done();
                });
            } else {
                target.select();
                target.setSelectionRange(0, 99999);
                document.execCommand('copy');
                done();
            }
        });
    });
})();
