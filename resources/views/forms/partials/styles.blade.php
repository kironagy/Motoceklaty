<style>
    :root { --brand: #ff6100; --brand-dark: #8f2600; --ink: #1d1d1f; --muted: #6b6b70; --line: #e6e6ea; --bg: #f4f5f7; --ok: #138a4b; --warn: #b85c00; --bad: #c62828; }
    * { box-sizing: border-box; }
    [x-cloak] { display: none !important; }
    body { margin: 0; font-family: 'Cairo', system-ui, sans-serif; background: var(--bg); color: var(--ink); font-size: 16px; line-height: 1.6; }
    .page { max-width: 640px; margin: 0 auto; padding: 0 16px 48px; }
    .top { position: sticky; top: 0; z-index: 5; display: flex; gap: 12px; align-items: center; padding: 12px 0; background: var(--bg); }
    .logo { height: 44px; width: auto; }
    .top-text { flex: 1; min-width: 0; }
    .top h1 { font-size: 17px; margin: 0; font-weight: 800; }
    .top small { color: var(--muted); font-size: 12px; }
    .progress-line { height: 6px; border-radius: 6px; background: var(--line); overflow: hidden; margin: 4px 0 2px; }
    .progress-line span { display: block; height: 100%; background: linear-gradient(90deg, var(--brand-dark), var(--brand)); transition: width .3s; }
    .card { background: #fff; border-radius: 14px; padding: 18px 16px; margin: 12px 0; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
    .card h2 { margin: 0 0 4px; font-size: 18px; }
    .muted { color: var(--muted); font-size: 14px; margin: 0; }
    .notice { border-right: 4px solid var(--brand); }
    .notice p { margin: 4px 0 0; }
    .success { text-align: center; padding: 36px 16px; }
    .big-icon { font-size: 52px; }
    .machine-card { display: flex; gap: 12px; align-items: center; }
    .machine-card img { width: 84px; height: 64px; object-fit: contain; }
    .field { margin-top: 16px; }
    label { display: block; font-weight: 700; margin-bottom: 4px; }
    .req { color: var(--bad); }
    .help { margin: 0 0 6px; color: var(--muted); font-size: 13px; }
    .input { width: 100%; padding: 12px; border: 1.5px solid var(--line); border-radius: 10px; font: inherit; background: #fff; color: var(--ink); }
    .input:focus { outline: none; border-color: var(--brand); }
    .error { color: var(--bad); font-size: 13px; margin: 4px 0 0; }
    .note { background: #fff7f0; border-radius: 10px; padding: 10px 12px; font-size: 14px; }
    .stop { background: #fdecec; color: var(--bad); border-radius: 10px; padding: 12px; font-weight: 700; }
    .choices { display: flex; flex-wrap: wrap; gap: 8px; }
    .choice { padding: 10px 14px; border-radius: 10px; border: 1.5px solid var(--line); background: #fff; font: inherit; cursor: pointer; }
    .choice.on, .machine.on, .plan.on { border-color: var(--brand); background: #fff3eb; color: var(--brand-dark); font-weight: 700; }
    .address { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .address-part.wide { grid-column: 1 / -1; }
    .address-part small { color: var(--muted); }
    .machines { display:flex; gap:8px; margin-top:8px; overflow-x:auto; overflow-y:hidden; padding-bottom:8px; scroll-snap-type:x mandatory; }
    .machine { flex:0 0 140px; scroll-snap-align:start; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 8px; border: 1.5px solid var(--line); border-radius: 10px; background: #fff; font: inherit; font-size: 13px; cursor: pointer; }
    .machine img { width: 100%; height: 70px; object-fit: contain; }
    .plans { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
    .plan { display: flex; flex-direction: column; gap: 2px; padding: 10px; border: 1.5px solid var(--line); border-radius: 10px; background: #fff; font: inherit; cursor: pointer; text-align: right; }
    .link-btn { border: 0; background: none; color: var(--brand); font: inherit; font-weight: 700; padding: 0; cursor: pointer; text-decoration: underline; }
    .cap-note { background: #fff4e5; border: 1px solid #ffd3a8; color: #8a4300; border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 8px; line-height: 1.7; }
    .plan small.cash { color: #b84500; font-weight: 800; }
    .machine-cash { color: #b84500; font-weight: 700; }
    .remove { border: 0; background: none; color: var(--bad); font: inherit; cursor: pointer; }
    .actions { display: flex; gap: 8px; justify-content: space-between; margin-top: 22px; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 22px; border: 0; border-radius: 10px; background: var(--brand); color: #fff; font: inherit; font-weight: 800; cursor: pointer; flex: 1; }
    .btn.small { padding: 9px 14px; font-weight: 700; flex: 0 0 auto; }
    .btn.ghost { background: #fff; color: var(--ink); border: 1.5px solid var(--line); }
    .btn:disabled { opacity: .6; }
    .blocking { background: #fdecec; color: var(--bad); font-weight: 700; }
    .blocking p { margin: 0; }
    .missing ul { margin: 6px 0 0; padding-right: 18px; }
    .group { display: flex; flex-direction: column; gap: 10px; }
    .group-row { border: 1.5px solid var(--line); border-radius: 10px; padding: 10px; display: flex; flex-direction: column; gap: 6px; }
    .group-head { display: flex; justify-content: space-between; align-items: center; }
    .group-field small { color: var(--muted); }
    .saved { text-align: center; color: var(--muted); font-size: 12px; min-height: 18px; }
    .closed { text-align: center; padding: 40px 16px; }
    .wa { display: inline-flex; margin-top: 12px; background: #25d366; }

    /* steps: numbered, done = ✓, locked until the step before is complete */
    .steps { display: flex; align-items: center; justify-content: center; margin-top: 14px; flex-wrap: wrap; row-gap: 8px; }
    .step-wrap { display: flex; align-items: center; }
    .step-dot { width: 36px; height: 36px; border-radius: 50%; border: 2px solid var(--line); background: #fff; color: var(--muted); font: inherit; font-weight: 800; cursor: pointer; display: grid; place-items: center; transition: all .2s; padding: 0; }
    .step-dot svg { width: 15px; height: 15px; }
    .step-dot.active { border-color: var(--brand); background: var(--brand); color: #fff; box-shadow: 0 0 0 4px rgba(255,97,0,.15); }
    .step-dot.done { border-color: var(--ok); background: #eaf7ef; color: var(--ok); }
    .step-dot.locked { cursor: not-allowed; opacity: .55; background: var(--bg); }
    .step-dot.bad { border-color: var(--bad); color: var(--bad); }
    .step-line { width: 18px; height: 2px; background: var(--line); margin: 0 2px; }
    .step-line.on { background: var(--ok); }
    .step-title { text-align: center; color: var(--muted); font-size: 13px; margin: 6px 0 0; font-weight: 700; }

    /* uploads: drop zone + thumbnail grid */
    .up { display: flex; flex-direction: column; gap: 10px; }
    .up-zone { display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center; padding: 18px 14px; border: 2px dashed #ffc9a6; border-radius: 16px; background: linear-gradient(180deg, #fffaf6, #fff); transition: border-color .2s, background .2s; }
    .up-drag .up-zone { border-color: var(--brand); background: #fff3eb; }
    .up-has .up-zone { padding: 12px; }
    .up-has .up-zone .up-icon { display: none; }
    .up-icon { width: 52px; height: 52px; border-radius: 16px; display: grid; place-items: center; background: #fff1e6; color: var(--brand); margin-bottom: 4px; }
    .up-icon svg { width: 28px; height: 28px; }
    .up-zone strong { font-size: 15px; }
    .up-zone small { color: var(--muted); font-size: 12.5px; }
    .up-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; justify-content: center; }
    .up-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border-radius: 12px; border: 1.5px solid var(--line); background: #fff; font-weight: 700; font-size: 14px; cursor: pointer; margin: 0; }
    .up-btn svg { width: 18px; height: 18px; }
    .up-btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; box-shadow: 0 6px 16px rgba(255,97,0,.25); }
    .up-btn:active { transform: scale(.97); }
    .up-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px; }
    .up-tile { position: relative; display: flex; flex-direction: column; gap: 4px; }
    .up-thumb { position: relative; width: 100%; aspect-ratio: 1; border-radius: 14px; overflow: hidden; border: 2px solid var(--line); background: var(--bg); padding: 0; cursor: zoom-in; display: block; }
    .up-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .up-doc { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; color: var(--muted); padding: 6px; }
    .up-doc svg { width: 30px; height: 30px; }
    .up-doc small { font-size: 11px; word-break: break-all; line-height: 1.3; max-height: 2.6em; overflow: hidden; }
    .is-ok .up-thumb, .is-failed .up-thumb { border-color: #9fd9b6; }
    .is-warning .up-thumb { border-color: #f5b06f; }
    .is-checking .up-thumb img { filter: saturate(.6); }
    .is-uploading .up-thumb img { opacity: .55; }
    .up-badge { position: absolute; top: 6px; right: 6px; width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; background: #fff; font-size: 12px; font-weight: 900; box-shadow: 0 2px 6px rgba(0,0,0,.15); }
    .up-badge.ok { background: var(--ok); color: #fff; }
    .up-badge.warn { background: #f08a24; color: #fff; }
    .spin { width: 12px; height: 12px; border: 2px solid var(--line); border-top-color: var(--brand); border-radius: 50%; animation: spin .8s linear infinite; display: block; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .up-x { position: absolute; top: 6px; left: 6px; width: 24px; height: 24px; border-radius: 50%; border: 0; background: rgba(17,17,17,.65); color: #fff; font-size: 16px; line-height: 1; cursor: pointer; display: grid; place-items: center; padding: 0; }
    .up-msg { margin: 0; font-size: 11.5px; line-height: 1.4; color: var(--warn); font-weight: 700; }
    .up-msg.info { color: var(--muted); font-weight: 600; }
    .up-bar { position: absolute; left: 8px; right: 8px; bottom: 8px; height: 6px; border-radius: 6px; background: rgba(255,255,255,.8); overflow: hidden; }
    .up-bar span { display: block; height: 100%; background: var(--brand); transition: width .2s; }
    .up-count { display: flex; justify-content: space-between; font-size: 12.5px; color: var(--muted); font-weight: 700; }
    .up-count .warn { color: var(--warn); }
    .lightbox { position: fixed; inset: 0; z-index: 50; background: rgba(0,0,0,.85); display: grid; place-items: center; padding: 16px; }
    .lightbox img { max-width: 100%; max-height: 88vh; border-radius: 12px; }
    .lightbox button { position: absolute; top: 14px; left: 14px; width: 40px; height: 40px; border-radius: 50%; border: 0; background: #fff; font-size: 24px; cursor: pointer; }
    @media (prefers-reduced-motion: reduce) { .spin { animation-duration: 2s; } .step-dot, .up-zone { transition: none; } }
    .brand-slider {display:flex;gap:8px;overflow-x:auto;padding:8px 0 12px;scroll-snap-type:x mandatory;}
    .brand-pill {flex:0 0 auto;scroll-snap-align:start;border:1px solid #ddd;background:#fff;border-radius:22px;padding:8px 16px;font:inherit;cursor:pointer;}
    .brand-pill.on {background:#ff6b00;color:#fff;border-color:#ff6b00;}
    .voice-toggle {display:block;margin:10px auto 18px;background:#fff;border:1px solid #ddd;border-radius:20px;padding:9px 18px;font:inherit;cursor:pointer;}
    .cap-overlay {position:fixed;inset:0;z-index:1000;background:#0009;display:flex;align-items:center;justify-content:center;padding:18px;}
    .cap-dialog {max-width:480px;max-height:90vh;overflow:auto;box-shadow:0 12px 40px #0004;}
    @media(max-width:600px){.machines{max-height:none}.machine img{height:65px}.cap-dialog .actions{flex-wrap:wrap}}
</style>
