/**
 * Website installment calculator. Options come from the catalog JSON the
 * page embeds (#site-calc-catalog); every number shown is computed on the
 * server by InstallmentCalculator via POST /installment/calculate.
 */
(function () {
    'use strict';

    var catalogEl = document.getElementById('site-calc-catalog');
    if (!catalogEl) return;

    var catalog = JSON.parse(catalogEl.textContent || '{}');
    var machines = catalog.machines || [];
    var brands = catalog.brands || [];
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

    function fill(select, placeholder, options) {
        select.innerHTML = '';
        select.appendChild(new Option(placeholder || '', ''));
        options.forEach(function (o) { select.appendChild(new Option(o.label, o.value)); });
        select.disabled = options.length === 0;
    }

    function format(n) {
        return Math.round(Number(n) || 0).toLocaleString('en-US');
    }

    function template(text, values) {
        return String(text || '').replace(/:(\w+)/g, function (m, key) {
            return values[key] !== undefined ? values[key] : m;
        });
    }

    function init(form) {
        var config = JSON.parse(form.getAttribute('data-config') || '{}');
        var labels = config.labels || {};
        var resultLabels = config.result || {};
        var field = function (name) { return form.querySelector('[data-field="' + name + '"]'); };
        var brandSel = field('brand'), machineSel = field('machine'), systemSel = field('system'), monthsSel = field('months');
        var downInput = field('down_payment');
        var resultBox = form.querySelector('[data-calc-result]');
        var errorBox = form.querySelector('[data-calc-error]');
        var widget = form.closest('.twm-banner-search-tabs');
        var filter = {
            categories: JSON.parse(form.getAttribute('data-categories') || '[]'),
            brands: JSON.parse(form.getAttribute('data-brands') || '[]')
        };

        function matches(m) {
            if (filter.categories.length || filter.brands.length) {
                return filter.categories.indexOf(m.category) !== -1 || filter.brands.indexOf(m.brand_id) !== -1;
            }
            return true;
        }

        function currentMachine() {
            var id = config.machineId || (machineSel && machineSel.value);
            return machines.find(function (m) { return String(m.id) === String(id); });
        }

        function currentSystem() {
            var m = currentMachine();
            return m && m.systems.find(function (s) { return String(s.id) === String(systemSel.value); });
        }

        function hideOutput() {
            resultBox.hidden = true;
            errorBox.hidden = true;
        }

        function refreshBrands() {
            if (!brandSel) return;
            var visible = machines.filter(matches);
            fill(brandSel, labels.brand_placeholder, brands
                .filter(function (b) { return visible.some(function (m) { return m.brand_id === b.id; }); })
                .map(function (b) { return { value: b.id, label: b.name }; }));
            refreshMachines();
        }

        function refreshMachines() {
            if (!machineSel) return;
            var brandId = brandSel.value;
            fill(machineSel, labels.machine_placeholder, !brandId ? [] : machines
                .filter(function (m) { return matches(m) && String(m.brand_id) === String(brandId); })
                .map(function (m) { return { value: m.id, label: m.name }; }));
            refreshSystems();
        }

        function refreshSystems() {
            var m = currentMachine();
            fill(systemSel, labels.system_placeholder, (m ? m.systems : []).map(function (s) {
                return { value: s.id, label: s.name };
            }));
            refreshMonths();
        }

        function refreshMonths() {
            var s = currentSystem();
            fill(monthsSel, labels.months_placeholder, (s ? s.plans : []).map(function (p) {
                return { value: p.months, label: template(labels.months_option, p) };
            }));
            hideOutput();
        }

        function showError(message) {
            resultBox.hidden = true;
            errorBox.textContent = message;
            errorBox.hidden = false;
        }

        function esc(text) {
            var d = document.createElement('div');
            d.textContent = text == null ? '' : String(text);
            return d.innerHTML;
        }

        function showResult(r) {
            var m = currentMachine();
            var system = currentSystem();
            var applyUrl = config.applyUrl + (m ? '?machine_id=' + m.id : '');
            var L = resultLabels;
            var cur = esc(L.currency || '');
            var money = function (n) { return format(n) + ' <small>' + cur + '</small>'; };
            var tile = function (icon, label, value, extra) {
                return '<div class="mg-calc-tile' + (extra || '') + '"><i class="fa-solid ' + icon + '" aria-hidden="true"></i>' +
                    '<span>' + esc(label) + '</span><strong>' + value + '</strong></div>';
            };

            resultBox.innerHTML =
                '<div class="mg-calc-result__head">' +
                    '<span class="mg-calc-result__icon"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span>' +
                    '<div><h4>' + esc(L.title) + '</h4>' +
                    '<p>' + esc([m && m.name, system && system.name].filter(Boolean).join(' • ')) + '</p></div>' +
                '</div>' +
                '<div class="mg-calc-result__hero">' +
                    '<span>' + esc(L.monthly) + '</span>' +
                    '<strong>' + format(r.monthly_installment) + ' <small>' + cur + ' / ' + esc(L.per_month || '') + '</small></strong>' +
                    '<em>' + esc(template(L.duration, { months: r.months })) + '</em>' +
                '</div>' +
                '<div class="mg-calc-result__grid">' +
                    tile('fa-wallet', L.down_payment, money(r.down_payment)) +
                    tile('fa-coins', L.financed, money(r.financed_amount)) +
                    tile('fa-percent', L.interest, esc(r.interest_percent) + '%') +
                    tile('fa-sack-dollar', L.total, money(r.total_with_interest)) +
                    tile('fa-file-invoice', L.fees, money(r.administrative_fees), ' mg-calc-tile--wide') +
                '</div>' +
                (L.note ? '<p class="mg-calc-result__note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> ' + esc(L.note) + '</p>' : '') +
                '<a class="site-button mg-calc-result__apply" href="' + applyUrl + '"><em>' + esc(L.apply) + ' <i class="fa-solid fa-arrow-left"></i></em></a>';

            errorBox.hidden = true;
            resultBox.hidden = false;
            resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        if (brandSel) brandSel.addEventListener('change', refreshMachines);
        if (machineSel) machineSel.addEventListener('change', refreshSystems);
        systemSel.addEventListener('change', refreshMonths);
        monthsSel.addEventListener('change', hideOutput);

        if (widget) {
            widget.querySelectorAll('[data-calc-tab]').forEach(function (tab) {
                var activate = function () {
                    widget.querySelectorAll('[data-calc-tab]').forEach(function (t) { t.classList.remove('active'); });
                    tab.classList.add('active');
                    filter.categories = JSON.parse(tab.getAttribute('data-categories') || '[]');
                    filter.brands = JSON.parse(tab.getAttribute('data-brands') || '[]');
                    var browse = form.querySelector('[data-calc-browse]');
                    if (browse && tab.getAttribute('data-browse-text')) {
                        browse.firstChild.textContent = tab.getAttribute('data-browse-text') + ' ';
                    }
                    refreshBrands();
                };
                tab.addEventListener('click', activate);
                tab.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); } });
                if (tab.classList.contains('active')) {
                    filter.categories = JSON.parse(tab.getAttribute('data-categories') || '[]');
                    filter.brands = JSON.parse(tab.getAttribute('data-brands') || '[]');
                }
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var m = currentMachine();
            if (!m || !systemSel.value || !monthsSel.value) {
                showError(resultLabels.missing_fields || '');
                return;
            }
            var button = form.querySelector('button[type="submit"]');
            button.disabled = true;

            fetch(config.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({
                    machine_id: m.id,
                    installment_system_id: systemSel.value,
                    months: monthsSel.value,
                    down_payment: downInput.value || 0
                })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.ok) showResult(data.result);
                    else showError(data.message || resultLabels.missing_fields || '');
                })
                .catch(function () { showError(resultLabels.missing_fields || ''); })
                .finally(function () { button.disabled = false; });
        });

        if (brandSel) {
            refreshBrands();
        } else {
            refreshSystems();
        }
    }

    document.querySelectorAll('form[data-installment-calculator]').forEach(init);
})();
