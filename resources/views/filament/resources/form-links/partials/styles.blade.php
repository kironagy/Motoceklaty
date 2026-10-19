<style>
    /* Dynamic Links pages - follow the dashboard theme. Orange = MotoGate / action,
       green = sent / OK, amber = waiting on the customer, red = closed or a warning. */
    .lk {
        --brand: #ff6100; --brand-soft: rgba(255, 97, 0, .12); --brand-ink: #b84500;
        --ok: #12805c; --ok-soft: rgba(18, 128, 92, .12);
        --wait: #a86300; --wait-soft: rgba(214, 135, 0, .14);
        --bad: #c62828; --bad-soft: rgba(198, 40, 40, .1);
        --panel: #fff; --panel-2: rgb(var(--gray-50)); --line: rgb(var(--gray-200)); --ink: rgb(var(--gray-900)); --muted: rgb(var(--gray-500));
        color: var(--ink);
    }
    .dark .lk { --panel: rgb(var(--gray-900)); --panel-2: rgb(var(--gray-800)); --line: rgb(var(--gray-700)); --ink: rgb(var(--gray-100)); --muted: rgb(var(--gray-400)); --brand-ink: #ff8a3d; --ok: #3cc795; --wait: #f0a63a; --bad: #f26b6b; }

    .lk-pill { display: inline-flex; align-items: center; gap: 6px; font-size: .75rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; white-space: nowrap; }
    .lk-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .lk-pill.sent { background: var(--panel-2); color: var(--muted); }
    .lk-pill.opened, .lk-pill.in_progress { background: var(--wait-soft); color: var(--wait); }
    .lk-pill.submitted { background: var(--ok-soft); color: var(--ok); }
    .lk-pill.expired, .lk-pill.cancelled { background: var(--bad-soft); color: var(--bad); }
    .lk-pill.fix { background: var(--brand-soft); color: var(--brand-ink); }
    .lk-pill.fix::before { display: none; }

    .lk-bar { height: 6px; border-radius: 6px; background: var(--panel-2); overflow: hidden; }
    .lk-bar span { display: block; height: 100%; border-radius: 6px; background: var(--wait); }
    .lk-bar.done span { background: var(--ok); }
</style>
