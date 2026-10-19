<style>
    /* Form builder. Panels follow the dashboard theme (light / dark); the phone in the middle is
       always the customer's light page, so what you see is what he gets. */
    .fb {
        --brand: #ff6100; --brand-soft: rgba(255, 97, 0, .12); --brand-ink: #b84500;
        --bind: #0f8f80; --bind-soft: rgba(15, 143, 128, .12);
        --cond: #7c5cf0; --cond-soft: rgba(124, 92, 240, .12);
        --rule: #5b6474; --rule-soft: rgba(91, 100, 116, .12);
        --danger: #d93025;
        --panel: #ffffff; --panel-2: rgb(var(--gray-50)); --line: rgb(var(--gray-200)); --ink: rgb(var(--gray-900)); --muted: rgb(var(--gray-500));
        --desk: rgb(var(--gray-100));
        color: var(--ink);
    }
    .dark .fb { --panel: rgb(var(--gray-900)); --panel-2: rgb(var(--gray-800)); --line: rgb(var(--gray-700)); --ink: rgb(var(--gray-100)); --muted: rgb(var(--gray-400)); --desk: rgb(var(--gray-950)); --brand-ink: #ff8a3d; }
    [x-cloak] { display: none !important; }

    /* top bar */
    .fb-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 10px 16px; background: var(--panel); border: 1px solid var(--line); border-radius: 14px; margin-bottom: 12px; position: sticky; top: 64px; z-index: 20; }
    .fb-bar-name { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; }
    .fb-title { font-size: 1.15rem; font-weight: 800; border: 1px solid transparent; border-radius: 8px; padding: 4px 8px; background: transparent; color: var(--ink); min-width: 0; flex: 0 1 420px; }
    .fb-title:hover { border-color: var(--line); }
    .fb-title:focus { outline: none; border-color: var(--brand); background: var(--panel-2); }
    .fb-state { font-size: .8rem; padding: 3px 10px; border-radius: 999px; white-space: nowrap; }
    .fb-state.ok { background: var(--bind-soft); color: var(--bind); }
    .fb-state.warn { background: var(--brand-soft); color: var(--brand-ink); }
    .fb-state.muted { background: var(--panel-2); color: var(--muted); }
    .fb-bar-actions { display: flex; align-items: center; gap: 8px; }
    .fb-saved { font-size: .8rem; color: var(--muted); }
    .fb-btn { border-radius: 10px; padding: 8px 18px; font-weight: 700; font-size: .9rem; border: 1px solid var(--line); background: var(--panel); color: var(--ink); cursor: pointer; }
    .fb-btn:hover:not(:disabled) { border-color: var(--muted); }
    .fb-btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
    .fb-btn.primary:hover:not(:disabled) { background: #e55700; }
    .fb-btn:disabled { opacity: .45; cursor: default; }
    .fb-btn:focus-visible, .fb button:focus-visible, .fb .ins-input:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }

    .fb-problems { position: relative; background: var(--brand-soft); border: 1px solid rgba(255, 97, 0, .35); border-radius: 12px; padding: 12px 16px 12px 40px; margin-bottom: 12px; font-size: .9rem; }
    .fb-problems ul { margin: 6px 0 0; padding-inline-start: 18px; list-style: disc; }
    .fb-x { position: absolute; top: 10px; left: 12px; background: none; border: 0; color: var(--muted); cursor: pointer; }

    /* three panes (RTL: palette right, inspector left) */
    .fb-grid { display: grid; grid-template-columns: 270px minmax(0, 1fr) 360px; gap: 12px; align-items: start; }
    .fb-palette, .fb-inspector { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; position: sticky; top: 132px; max-height: calc(100vh - 150px); overflow-y: auto; }
    @media (max-width: 1180px) { .fb-grid { grid-template-columns: 230px minmax(0, 1fr) 320px; } }
    @media (max-width: 980px) { .fb-grid { grid-template-columns: 1fr; } .fb-palette, .fb-inspector { position: static; max-height: none; } }

    /* palette */
    .fb-tabs { display: grid; grid-template-columns: 1fr 1fr; padding: 6px; gap: 4px; border-bottom: 1px solid var(--line); position: sticky; top: 0; background: var(--panel); z-index: 2; }
    .fb-tabs button { padding: 8px; border-radius: 8px; border: 0; background: transparent; color: var(--muted); font-weight: 700; font-size: .85rem; cursor: pointer; }
    .fb-tabs button.on { background: var(--panel-2); color: var(--ink); }
    .fb-palette-list { padding: 10px; }
    .fb-hint { font-size: .8rem; color: var(--muted); margin: 2px 4px 10px; line-height: 1.6; }
    .fb-pgroup { margin-bottom: 14px; }
    .fb-pgroup h4 { font-size: .78rem; color: var(--muted); font-weight: 700; margin: 0 4px 6px; }
    .fb-pitem { display: flex; align-items: center; gap: 8px; width: 100%; text-align: start; padding: 7px 8px; border-radius: 8px; border: 0; background: transparent; color: var(--ink); font-size: .85rem; cursor: pointer; }
    .fb-pitem:hover:not(:disabled) { background: var(--bind-soft); }
    .fb-pitem.used { color: var(--muted); cursor: default; }
    .fb-pitem .fb-check { margin-inline-start: auto; color: var(--bind); font-weight: 800; }
    .fb-pitem .fb-ic { color: var(--bind); }
    .fb-ic { display: inline-flex; width: 18px; height: 18px; flex: none; }
    .fb-ic svg { width: 100%; height: 100%; }
    .fb-ptiles { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .fb-tile { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 4px; border-radius: 10px; border: 1px solid var(--line); background: var(--panel); color: var(--ink); font-size: .8rem; cursor: pointer; }
    .fb-tile:hover { border-color: var(--brand); color: var(--brand-ink); }
    .fb-tile .fb-ic { width: 20px; height: 20px; }

    /* canvas: the customer's phone */
    .fb-canvas { background: var(--desk); border-radius: 14px; padding: 28px 16px 40px; min-height: 70vh; background-image: radial-gradient(rgba(127,127,127,.18) 1px, transparent 1px); background-size: 18px 18px; }
    .fb-phone { width: 100%; max-width: 440px; margin: 0 auto; background: #f4f5f7; color: #1d1d1f; border-radius: 28px; padding: 18px 14px 22px; box-shadow: 0 0 0 8px #1d1d1f, 0 24px 60px rgba(0,0,0,.25); font-family: 'Cairo', system-ui, sans-serif; }
    .fb-phone-head strong { font-size: 1rem; font-weight: 800; }
    .fb-phone-bar { height: 5px; background: #e6e6ea; border-radius: 5px; margin: 6px 0 12px; overflow: hidden; }
    .fb-phone-bar span { display: block; width: 30%; height: 100%; background: #ff6100; }
    .fb-phone-intro { background: #fff; border-radius: 12px; border-right: 3px solid #ff6100; padding: 10px 12px; font-size: .85rem; margin: 0 0 10px; cursor: pointer; }
    .fb-phone-intro.sel { box-shadow: 0 0 0 2px #ff6100; }
    .fb-step { background: #fff; border-radius: 14px; margin-bottom: 12px; border: 2px solid transparent; transition: border-color .15s; }
    .fb-step.sel { border-color: #ff6100; }
    .fb-step-head { display: flex; align-items: center; gap: 10px; padding: 12px 12px 4px; cursor: pointer; }
    .fb-step-n { width: 26px; height: 26px; border-radius: 50%; background: #1d1d1f; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 800; flex: none; }
    .fb-step-text { flex: 1; min-width: 0; }
    .fb-step-text h3 { font-size: .98rem; font-weight: 800; margin: 0; }
    .fb-step-text p { font-size: .78rem; color: #6b6b70; margin: 0; }
    .fb-items { padding: 4px 8px 10px; min-height: 30px; }
    .fb-item { position: relative; display: flex; gap: 6px; padding: 10px 8px; border-radius: 10px; border: 1.5px solid transparent; cursor: pointer; transition: background .12s, border-color .12s; }
    .fb-item:hover { background: #fafafa; border-color: #ececf0; }
    .fb-item.sel { border-color: #ff6100; background: #fff8f3; }
    .fb-item.dragging { opacity: .4; }
    .fb-item.drop-before { box-shadow: 0 -3px 0 0 #ff6100; }
    .fb-grip { color: #b5b5bb; cursor: grab; font-size: 12px; letter-spacing: -2px; padding-top: 2px; user-select: none; }
    .fb-item-body { flex: 1; min-width: 0; }
    .fb-tools { display: flex; gap: 2px; opacity: 0; transition: opacity .12s; align-self: flex-start; }
    .fb-item > .fb-tools { position: absolute; top: -12px; left: 8px; background: #fff; padding: 3px; border-radius: 9px; box-shadow: 0 2px 8px rgba(0,0,0,.12); z-index: 3; pointer-events: none; }
    .fb-item:hover > .fb-tools, .fb-item.sel > .fb-tools { pointer-events: auto; }
    .fb-item:hover .fb-tools, .fb-item.sel .fb-tools, .fb-step-head:hover .fb-tools, .fb-step.sel > .fb-step-head .fb-tools { opacity: 1; }
    .fb-tools button { width: 26px; height: 26px; border-radius: 7px; border: 1px solid #e6e6ea; background: #fff; color: #55555b; font-size: 12px; cursor: pointer; }
    .fb-tools button:hover:not(:disabled) { border-color: #ff6100; color: #ff6100; }
    .fb-tools button.danger:hover { border-color: var(--danger); color: var(--danger); }
    .fb-tools button:disabled { opacity: .35; }
    .fb-empty { border: 1.5px dashed #d8d8de; border-radius: 10px; padding: 16px; text-align: center; color: #8a8a90; font-size: .82rem; }
    .fb-add-step { width: 100%; padding: 12px; border-radius: 14px; border: 1.5px dashed #c9c9cf; background: transparent; color: #55555b; font-weight: 700; cursor: pointer; }
    .fb-add-step:hover { border-color: #ff6100; color: #ff6100; }
    .fb-phone-submit { margin-top: 12px; text-align: center; padding: 12px; border-radius: 12px; background: #ff6100; color: #fff; font-weight: 800; opacity: .9; }

    /* chips under a field */
    .fb-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
    .fb-chips:empty { display: none; }
    .fb-chip { font-size: .7rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .fb-chip.bind { background: var(--bind-soft); color: var(--bind); }
    .fb-chip.cond { background: var(--cond-soft); color: var(--cond); }
    .fb-chip.rule { background: var(--rule-soft); color: var(--rule); }
    .fb-chip.ai { background: var(--brand-soft); color: #b84500; }
    .fb-step-head .fb-chip { flex: none; max-width: 40%; }

    /* the customer's inputs, drawn */
    .pv-label { display: block; font-weight: 700; font-size: .88rem; margin-bottom: 4px; }
    .pv-label b { color: #c62828; margin-inline-start: 2px; }
    .pv-label .muted { color: #a0a0a6; }
    .pv-help { font-size: .75rem; color: #6b6b70; margin: -2px 0 5px; }
    .pv-input { border: 1.5px solid #e6e6ea; border-radius: 9px; padding: 8px 10px; font-size: .8rem; color: #a0a0a6; background: #fff; min-height: 36px; }
    .pv-input.tall { min-height: 64px; }
    .pv-input.small { padding: 6px 8px; min-height: 30px; font-size: .72rem; }
    .pv-select { display: flex; justify-content: space-between; }
    .pv-select::after { content: '⌄'; }
    .pv-choices { display: flex; flex-wrap: wrap; gap: 6px; }
    .pv-choice { border: 1.5px solid #e6e6ea; border-radius: 9px; padding: 6px 12px; font-size: .78rem; }
    .pv-choice.ghost { border-style: dashed; color: #a0a0a6; }
    .pv-address { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .pv-address .wide, .pv-group .wide { grid-column: 1 / -1; }
    .pv-upload { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    .pv-upload-btn { background: #ff6100; color: #fff; border-radius: 9px; padding: 6px 12px; font-size: .78rem; font-weight: 700; }
    .pv-upload-btn.ghost { background: #fff; color: #1d1d1f; border: 1.5px solid #e6e6ea; }
    .pv-upload small { color: #6b6b70; }
    .pv-machines { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
    .pv-machines span { height: 54px; border-radius: 9px; border: 1.5px solid #e6e6ea; background: linear-gradient(135deg, #f7f7f9, #ececf0); }
    .pv-plans { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
    .pv-plans span { border: 1.5px solid #e6e6ea; border-radius: 9px; padding: 8px 4px; font-size: .74rem; text-align: center; font-weight: 700; }
    .pv-group { display: grid; gap: 6px; border: 1.5px solid #e6e6ea; border-radius: 10px; padding: 8px; }
    .pv-add { font-size: .75rem; color: #ff6100; font-weight: 700; }
    .pv-note { background: #fff7f0; border-radius: 9px; padding: 8px 10px; font-size: .8rem; }
    .pv-stop { background: #fdecec; color: #c62828; border-radius: 9px; padding: 8px 10px; font-size: .8rem; font-weight: 700; }

    /* inspector */
    .ins { padding-bottom: 8px; }
    .ins-head { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--line); position: sticky; top: 0; background: var(--panel); z-index: 2; }
    .ins-head small { display: block; color: var(--muted); font-size: .75rem; }
    .ins-head strong { display: block; font-size: .98rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 250px; }
    .ins-head .fb-ic { width: 22px; height: 22px; color: var(--brand); }
    .ins-close { margin-inline-start: auto; background: none; border: 0; color: var(--muted); cursor: pointer; }
    .ins-block { padding: 14px 16px; border-bottom: 1px solid var(--line); }
    .ins-block:last-child { border-bottom: 0; }
    .ins-block h5 { display: flex; align-items: baseline; gap: 8px; font-size: .82rem; font-weight: 800; margin: 0 0 10px; }
    .ins-block h5 em { font-style: normal; font-weight: 400; color: var(--muted); font-size: .75rem; }
    .ins-stack { display: flex; flex-direction: column; gap: 12px; }
    .ins-field { display: flex; flex-direction: column; gap: 5px; font-size: .8rem; }
    .ins-field > span { font-weight: 700; color: var(--ink); }
    .ins-field em { font-style: normal; color: var(--muted); font-weight: 400; }
    .ins-input { width: 100%; border: 1px solid var(--line); border-radius: 9px; padding: 8px 10px; font-size: .86rem; background: var(--panel-2); color: var(--ink); }
    .ins-input:focus { outline: none; border-color: var(--brand); background: var(--panel); }
    .ins-input.big { font-size: .98rem; font-weight: 700; padding: 10px 12px; }
    .ins-input.slim { width: auto; flex: 0 0 auto; padding: 7px 8px; }
    .ins-input.mono { font-family: ui-monospace, monospace; font-size: .8rem; }
    textarea.ins-input { resize: vertical; line-height: 1.6; }
    select.ins-input { appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23888'%3E%3Cpath d='M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: left 8px center; background-size: 16px; padding-left: 30px; }
    .ins-note { color: var(--muted); font-size: .75rem; line-height: 1.6; margin: 0; }
    .ins-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 10px 0; }
    .ins-switch { display: flex; align-items: center; gap: 10px; font-size: .85rem; font-weight: 600; cursor: pointer; }
    .ins-switch input { position: absolute; opacity: 0; pointer-events: none; }
    .ins-track { width: 36px; height: 20px; border-radius: 999px; background: var(--line); position: relative; transition: background .15s; flex: none; }
    .ins-track span { position: absolute; top: 2px; right: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
    .ins-switch input:checked + .ins-track { background: var(--brand); }
    .ins-switch input:checked + .ins-track span { transform: translateX(-16px); }
    .ins-switch input:focus-visible + .ins-track { outline: 2px solid var(--brand); outline-offset: 2px; }
    .ins-option, .ins-sub { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; }
    .ins-option { flex-wrap: wrap; padding: 6px; border-radius: 10px; border: 1px solid transparent; }
    .ins-option:hover { border-color: var(--line); }
    .ins-option > .ins-input:first-child { flex: 1 1 calc(100% - 40px); }
    .ins-option > select.ins-input { order: 3; flex: 1 1 100%; font-size: .8rem; }
    .ins-worktypes { order: 4; flex: 1 1 100%; padding-top: 4px; }
    .ins-worktypes small { flex: 1 1 100%; color: var(--muted); font-size: .72rem; }
    .ins-multi { display: flex; flex-wrap: wrap; gap: 4px; }
    .ins-multi button { border-radius: 999px; padding: 4px 10px; font-size: .75rem; border: 1px solid var(--line); background: var(--panel); color: var(--muted); cursor: pointer; }
    .ins-multi button.on { border-color: var(--cond); background: var(--cond-soft); color: var(--cond); font-weight: 700; }
    .ins-sub { flex-wrap: wrap; padding: 8px; border: 1px solid var(--line); border-radius: 10px; }
    .ins-sub .ins-input:first-child { flex: 1 1 100%; }
    .ins-mini { font-size: .75rem; display: flex; gap: 4px; align-items: center; }
    .ins-icon { width: 30px; height: 30px; flex: none; border-radius: 8px; border: 1px solid var(--line); background: var(--panel); color: var(--muted); cursor: pointer; }
    .ins-icon:hover { color: var(--danger); border-color: var(--danger); }
    .ins-add { background: none; border: 0; color: var(--brand-ink); font-weight: 700; font-size: .85rem; padding: 4px 0; cursor: pointer; }
    .ins-sentence { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: .88rem; }
    .ins-num { width: 62px; border: 1px solid var(--line); border-radius: 8px; padding: 6px 8px; text-align: center; background: var(--panel-2); color: var(--ink); }
    .ins-num.wide { width: 100px; }
    .ins-num:focus { outline: none; border-color: var(--brand); }
    .ins-seg { display: inline-flex; border: 1px solid var(--line); border-radius: 9px; padding: 2px; gap: 2px; margin-bottom: 10px; }
    .ins-seg button { border: 0; background: transparent; padding: 6px 12px; border-radius: 7px; font-size: .8rem; color: var(--muted); cursor: pointer; }
    .ins-seg button.on { background: var(--panel-2); color: var(--ink); font-weight: 700; }
    .ins-seg.small button { padding: 4px 10px; font-size: .75rem; }
    .ins-cond { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; border-radius: 10px; background: var(--cond-soft); margin-bottom: 6px; }
    .ins-cond > * { order: 2; }
    .ins-cond > select:first-child { order: 0; flex: 1 1 calc(100% - 40px); }
    .ins-cond > .ins-icon { order: 1; }
    .ins-cond-value { flex: 1 1 100%; }
    .ins-cond > select.slim { flex: 1 1 0; }
    .ins-parts { display: flex; flex-wrap: wrap; gap: 6px; }
    .ins-part { border-radius: 999px; padding: 5px 12px; font-size: .78rem; border: 1px dashed var(--line); background: transparent; color: var(--muted); cursor: pointer; }
    .ins-part.shown { border-style: solid; color: var(--ink); background: var(--panel-2); }
    .ins-part.required { border-style: solid; border-color: var(--brand); color: var(--brand-ink); background: var(--brand-soft); font-weight: 700; }
    .ins-part b { margin-inline-start: 2px; }
    .ins-advanced { padding: 10px 16px; }
    .ins-advanced summary { cursor: pointer; font-size: .8rem; color: var(--muted); }
    .ins-advanced .ins-field { margin-top: 10px; }
    .ins-danger { background: none; border: 1px solid var(--line); color: var(--danger); border-radius: 9px; padding: 8px 12px; font-size: .82rem; cursor: pointer; width: 100%; }
    .ins-danger:hover { border-color: var(--danger); }
    .ins-tip { font-size: .82rem; line-height: 1.8; color: var(--muted); }
    .ins-tip strong { color: var(--ink); display: block; margin-bottom: 4px; }
    .ins-tip p { margin: 0; }

    @media (prefers-reduced-motion: reduce) { .fb * { transition: none !important; scroll-behavior: auto !important; } }
</style>
