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
        return Math.round(Number(n) || 0).toLocaleString('ar-EG');
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
        var resultBox = form.querySelector('[data-calc-result]') || document.getElementById('resultNote');
        var errorBox = form.querySelector('[data-calc-error]');
        // No error box in the approved design: messages go where the result goes.
        if (!errorBox) errorBox = resultBox;
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
            if (resultBox.tagName === 'OUTPUT') resultBox.textContent = '';
            if (errorBox !== resultBox) errorBox.hidden = true;
            ['monthlyAmount', 'resultMonths', 'resultDeposit', 'resultTotal', 'resultFees'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.textContent = '—';
            });
            var contact = document.getElementById('resultContact');
            if (contact) contact.hidden = true;
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
            var name = document.getElementById('selectedBikeName');
            var bike = document.getElementById('financeBike');
            var price = document.getElementById('financePrice');
            if (name && m) name.textContent = m.name;
            if (bike && m && m.image) { bike.src = m.image; bike.alt = m.name; }
            if (price) price.textContent = m ? 'سعر الكاش: ' + format(m.cash_price) + ' جنيه' : 'اختر الماركة والموديل للبدء';
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
            var applyUrl = config.applyUrl + '?' + new URLSearchParams({machine_id:m.id, installment_type:system.name, months:r.months, deposit:r.down_payment}).toString();
            var L = resultLabels;
            var financeDashboard = document.querySelector('.finance-dashboard');
            if (financeDashboard) {
                // Calculator page: the approved design shows the result in its dashboard.
                document.getElementById('monthlyAmount').textContent = format(r.monthly_installment);
                document.getElementById('resultMonths').textContent = format(r.months);
                document.getElementById('resultDeposit').textContent = format(r.down_payment);
                document.getElementById('resultTotal').textContent = format(r.total_with_interest);
                document.getElementById('resultFees').textContent = format(r.administrative_fees);
                document.getElementById('resultNote').innerHTML = esc(L.note || '') + ' <a href="' + esc(applyUrl) + '">' + esc(L.apply) + '</a>';
                return;
            }
            // Home: same sentence as the design, then its #paymentResult dialog.
            resultBox.innerHTML = 'القسط الشهري: <strong>' + format(r.monthly_installment) + ' جنيه</strong> لمدة ' + format(r.months) + ' شهر.<br><small>إجمالي الأقساط ' + format(r.total_with_interest) + ' جنيه.<br>المصاريف الإدارية: <b>' + format(r.administrative_fees) + ' جنيه</b> — تدفع مرة واحدة عند الاستلام.<br>' + esc(L.note || '') + '</small>';
            var dialog = document.getElementById('paymentResult');
            if (!dialog) return;
            var summary = document.getElementById('paymentSummary');
            var paymentValues = {
                machine: m.name,
                system: system.name,
                monthly: format(r.monthly_installment),
                months: format(r.months),
                deposit: format(r.down_payment),
                total: format(r.total_with_interest),
                fees: format(r.administrative_fees)
            };
            summary.querySelectorAll('[data-payment]').forEach(function (element) {
                element.textContent = paymentValues[element.dataset.payment];
            });
            summary.querySelector('[data-payment-apply]').href = applyUrl;
            var show = function () { bootstrap.Modal.getOrCreateInstance(dialog).show(); };
            var calculator = form.closest('.modal.show');
            if (calculator) {
                calculator.addEventListener('hidden.bs.modal', show, { once: true });
                bootstrap.Modal.getInstance(calculator).hide();
            } else {
                show();
            }
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

            function sendCalculation() { return fetch(new URL(config.endpoint, location.href).pathname, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({
                    machine_id: m.id,
                    installment_system_id: systemSel.value,
                    months: monthsSel.value,
                    down_payment: downInput.value || 0
                })
            }); }
            sendCalculation().then(function (res) {
                if (res.status !== 419) return res;
                return fetch('/session/csrf', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
                    .then(function (refresh) { if (!refresh.ok) throw new Error('Session refresh failed'); return refresh.json(); })
                    .then(function (session) { csrf = session.token; return sendCalculation(); });
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.ok) showResult(data.result);
                    else showError(data.message || resultLabels.missing_fields || '');
                })
                .catch(function () { showError(resultLabels.missing_fields || ''); })
                .finally(function () { button.disabled = false; });
        });

        downInput.addEventListener('input', hideOutput);
        form.selectMachine = function (id) {
            var selected = machines.find(function (m) { return String(m.id) === String(id); });
            if (!selected || !brandSel) return false;
            filter = {categories: [], brands: []};
            refreshBrands();
            brandSel.value = selected.brand_id;
            refreshMachines();
            machineSel.value = selected.id;
            refreshSystems();
            return true;
        };
        if (brandSel) {
            refreshBrands();
            var selectedId = new URLSearchParams(location.search).get('machine_id');
            var selected = machines.find(function (m) { return String(m.id) === selectedId; });
            if (selected) {
                filter = {categories: [], brands: []};
                refreshBrands();
                brandSel.value = selected.brand_id;
                refreshMachines();
                machineSel.value = selected.id;
                refreshSystems();
            }
        } else {
            refreshSystems();
        }
    }

    document.querySelectorAll('form[data-installment-calculator]').forEach(init);
})();
