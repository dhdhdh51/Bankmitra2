/* ==========================================================================
   LRMS admin panel behaviour.
   Vanilla JS - Bootstrap's bundle (CDN) provides dropdowns/modals/tooltips.
   ========================================================================== */
(function () {
    'use strict';

    /* ----------------------------------------------------------------------
       Theme (light / dark)
       The <html data-theme> attribute is set by an inline script in the layout
       before paint; this only handles the toggle and persistence.
       ---------------------------------------------------------------------- */
    var THEME_KEY = 'lrms-theme';

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem(THEME_KEY, theme); } catch (e) { /* private mode */ }

        document.querySelectorAll('[data-theme-icon]').forEach(function (el) {
            el.classList.toggle('d-none', el.getAttribute('data-theme-icon') !== theme);
        });
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-theme-toggle]');
        if (!toggle) return;
        event.preventDefault();
        applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
    });

    applyTheme(currentTheme());

    /* ----------------------------------------------------------------------
       Sidebar (mobile)
       ---------------------------------------------------------------------- */
    var sidebar = document.querySelector('.lrms-sidebar');
    var backdrop = document.querySelector('.lrms-sidebar-backdrop');

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-sidebar-toggle]')) {
            event.preventDefault();
            if (sidebar) sidebar.classList.toggle('open');
            if (backdrop) backdrop.classList.toggle('show', sidebar.classList.contains('open'));
            return;
        }
        if (event.target === backdrop) closeSidebar();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeSidebar();
    });

    /* ----------------------------------------------------------------------
       Bulk selection on the leads table
       ---------------------------------------------------------------------- */
    function initBulk() {
        var form = document.querySelector('[data-bulk-form]');
        if (!form) return;

        var master = form.querySelector('[data-bulk-master]');
        var bar = form.querySelector('[data-bulk-bar]');
        var counter = form.querySelector('[data-bulk-count]');

        function boxes() {
            return Array.prototype.slice.call(form.querySelectorAll('[data-bulk-item]'));
        }

        function refresh() {
            var all = boxes();
            var checked = all.filter(function (b) { return b.checked; });

            if (counter) {
                counter.textContent = checked.length + (checked.length === 1 ? ' lead selected' : ' leads selected');
            }
            if (bar) bar.classList.toggle('show', checked.length > 0);
            if (master) {
                master.checked = all.length > 0 && checked.length === all.length;
                master.indeterminate = checked.length > 0 && checked.length < all.length;
            }
        }

        if (master) {
            master.addEventListener('change', function () {
                boxes().forEach(function (b) { b.checked = master.checked; });
                refresh();
            });
        }

        form.addEventListener('change', function (event) {
            if (event.target.matches('[data-bulk-item]')) refresh();
        });

        // Shift-click range selection, which matters when assigning hundreds of leads.
        var lastIndex = null;
        form.addEventListener('click', function (event) {
            var box = event.target.closest('[data-bulk-item]');
            if (!box) return;
            var all = boxes();
            var index = all.indexOf(box);

            if (event.shiftKey && lastIndex !== null && lastIndex !== index) {
                var start = Math.min(lastIndex, index);
                var end = Math.max(lastIndex, index);
                for (var i = start; i <= end; i++) all[i].checked = box.checked;
                refresh();
            }
            lastIndex = index;
        });

        // The bulk action needs an explicit intent before submitting.
        form.addEventListener('submit', function (event) {
            var action = form.querySelector('[data-bulk-action]');
            if (!action) return;

            var selected = boxes().filter(function (b) { return b.checked; }).length;
            if (selected === 0) {
                event.preventDefault();
                alert('Select at least one lead first.');
                return;
            }

            var value = action.value;
            if (!value) {
                event.preventDefault();
                alert('Choose an action to apply.');
                return;
            }

            var needsAgent = value === 'assign' || value === 'reassign';
            var needsBranch = value === 'transfer';
            var agent = form.querySelector('[data-bulk-agent]');
            var branch = form.querySelector('[data-bulk-branch]');

            if (needsAgent && agent && !agent.value) {
                event.preventDefault();
                alert('Choose the agent to assign these leads to.');
                return;
            }
            if (needsBranch && branch && !branch.value) {
                event.preventDefault();
                alert('Choose the destination branch.');
                return;
            }

            var label = action.options[action.selectedIndex].text;
            if (!confirm('Apply "' + label + '" to ' + selected + ' lead(s)?')) {
                event.preventDefault();
            }
        });

        // Only show the inputs the chosen action actually needs.
        var actionSelect = form.querySelector('[data-bulk-action]');
        if (actionSelect) {
            var sync = function () {
                var value = actionSelect.value;
                form.querySelectorAll('[data-bulk-when]').forEach(function (el) {
                    var when = el.getAttribute('data-bulk-when').split(',');
                    el.classList.toggle('d-none', when.indexOf(value) === -1);
                });
            };
            actionSelect.addEventListener('change', sync);
            sync();
        }

        refresh();
    }

    /* ----------------------------------------------------------------------
       Confirm-before-submit for destructive actions
       ---------------------------------------------------------------------- */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;

        var message = form.getAttribute('data-confirm');
        if (message && !confirm(message)) {
            event.preventDefault();
            return;
        }

        // Guard against double submission on slow shared hosting.
        if (form.hasAttribute('data-no-double-submit')) {
            var submitter = form.querySelector('[type="submit"]');
            if (submitter) {
                setTimeout(function () {
                    submitter.disabled = true;
                    if (!submitter.dataset.originalText) {
                        submitter.dataset.originalText = submitter.innerHTML;
                    }
                    submitter.innerHTML = 'Working...';
                }, 10);
            }
        }
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-confirm-link]');
        if (!link) return;
        if (!confirm(link.getAttribute('data-confirm-link'))) event.preventDefault();
    });

    /* ----------------------------------------------------------------------
       Auto-submitting filter controls
       ---------------------------------------------------------------------- */
    document.querySelectorAll('[data-auto-submit]').forEach(function (el) {
        el.addEventListener('change', function () {
            var form = el.closest('form');
            if (form) form.submit();
        });
    });

    /* ----------------------------------------------------------------------
       Debounced search box
       ---------------------------------------------------------------------- */
    document.querySelectorAll('[data-search-input]').forEach(function (input) {
        var timer = null;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var form = input.closest('form');
                if (form) form.submit();
            }, 550);
        });
    });

    /* ----------------------------------------------------------------------
       Visit report form: mutually exclusive contact outcomes and
       conditionally required fields.
       ---------------------------------------------------------------------- */
    function initVisitForm() {
        var form = document.querySelector('[data-visit-form]');
        if (!form) return;

        // Only one "how did contact go" outcome makes sense at a time.
        var exclusive = ['customer_met', 'family_member_met', 'house_locked', 'phone_switched_off'];
        exclusive.forEach(function (name) {
            var box = form.querySelector('[name="' + name + '"]');
            if (!box) return;
            box.addEventListener('change', function () {
                if (!box.checked) return;
                exclusive.forEach(function (other) {
                    if (other === name) return;
                    var el = form.querySelector('[name="' + other + '"]');
                    if (el) el.checked = false;
                });
                toggleFamily();
            });
        });

        function toggleFamily() {
            var familyBox = form.querySelector('[name="family_member_met"]');
            var wrap = form.querySelector('[data-family-fields]');
            if (wrap) wrap.classList.toggle('d-none', !(familyBox && familyBox.checked));
        }
        toggleFamily();

        // Ready-to-pay and not-ready are opposites.
        var ready = form.querySelector('[name="ready_to_pay"]');
        var notReady = form.querySelector('[name="not_ready"]');
        if (ready && notReady) {
            ready.addEventListener('change', function () { if (ready.checked) notReady.checked = false; });
            notReady.addEventListener('change', function () { if (notReady.checked) ready.checked = false; });
        }

        // A promise needs both an amount and a date to become a promise case.
        var amount = form.querySelector('[name="promise_amount"]');
        var date = form.querySelector('[name="promise_date"]');
        var hint = form.querySelector('[data-promise-hint]');

        function syncPromise() {
            if (!amount || !date) return;
            var hasAmount = parseFloat(amount.value || '0') > 0;
            var hasDate = !!date.value;
            date.required = hasAmount;
            amount.required = hasDate;
            if (hint) hint.classList.toggle('d-none', !(hasAmount !== hasDate));
        }
        if (amount) amount.addEventListener('input', syncPromise);
        if (date) date.addEventListener('change', syncPromise);
        syncPromise();

        // "Others" free-text fields only appear when their box is ticked.
        [['reason_others', 'data-reason-other'], ['rec_others', 'data-rec-other'], ['occupation', 'data-occupation-other']]
            .forEach(function (pair) {
                var control = form.querySelector('[name="' + pair[0] + '"]');
                var wrap = form.querySelector('[' + pair[1] + ']');
                if (!control || !wrap) return;

                var sync = function () {
                    var on = control.type === 'checkbox' ? control.checked : control.value === 'others';
                    wrap.classList.toggle('d-none', !on);
                };
                control.addEventListener('change', sync);
                sync();
            });
    }

    /* ----------------------------------------------------------------------
       Image preview for file inputs
       ---------------------------------------------------------------------- */
    document.querySelectorAll('[data-preview-for]').forEach(function (input) {
        input.addEventListener('change', function () {
            var target = document.querySelector(input.getAttribute('data-preview-for'));
            if (!target) return;

            var file = input.files && input.files[0];
            if (!file) { target.innerHTML = ''; return; }

            if (!/^image\//.test(file.type)) {
                target.innerHTML = '<span class="text-muted small">' + file.name + '</span>';
                return;
            }

            var url = URL.createObjectURL(file);
            target.innerHTML = '<img src="' + url + '" alt="" style="max-height:110px;border-radius:7px;border:1px solid var(--lrms-border)">';
        });
    });

    /* ----------------------------------------------------------------------
       Permission matrix: tick/untick a whole module
       ---------------------------------------------------------------------- */
    document.querySelectorAll('[data-module-toggle]').forEach(function (master) {
        var module = master.getAttribute('data-module-toggle');
        var scope = document.querySelectorAll('[data-module="' + module + '"]');

        master.addEventListener('change', function () {
            scope.forEach(function (box) { box.checked = master.checked; });
        });

        var refresh = function () {
            var all = Array.prototype.slice.call(scope);
            var checked = all.filter(function (b) { return b.checked; });
            master.checked = all.length > 0 && checked.length === all.length;
            master.indeterminate = checked.length > 0 && checked.length < all.length;
        };
        scope.forEach(function (box) { box.addEventListener('change', refresh); });
        refresh();
    });

    /* ----------------------------------------------------------------------
       Auto-dismiss transient flash messages
       ---------------------------------------------------------------------- */
    document.querySelectorAll('[data-auto-dismiss]').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .4s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 420);
        }, parseInt(el.getAttribute('data-auto-dismiss'), 10) || 6000);
    });

    /* ----------------------------------------------------------------------
       Copy-to-clipboard (loan account numbers)
       ---------------------------------------------------------------------- */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-copy]');
        if (!trigger) return;
        event.preventDefault();

        var text = trigger.getAttribute('data-copy');
        var done = function () {
            var original = trigger.getAttribute('title') || '';
            trigger.setAttribute('title', 'Copied');
            trigger.classList.add('text-success');
            setTimeout(function () {
                trigger.setAttribute('title', original);
                trigger.classList.remove('text-success');
            }, 1200);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
        } else {
            var area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
            document.body.removeChild(area);
        }
    });

    /* ----------------------------------------------------------------------
       Bootstrap tooltips, when the bundle is present
       ---------------------------------------------------------------------- */
    if (window.bootstrap && window.bootstrap.Tooltip) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new window.bootstrap.Tooltip(el);
        });
    }

    initBulk();
    initVisitForm();
})();
