{{-- Shared look of the advance / vacation forms: the hero on top and the live preview beside the form. --}}
@once
<style>
    .sx { --sx-card: #fff; --sx-line: #e9ecf2; --sx-soft: #f6f7fb; --sx-text: #0f172a; --sx-muted: #64748b; color: var(--sx-text); }
    .dark .sx { --sx-card: #182235; --sx-line: rgba(255,255,255,.07); --sx-soft: rgba(255,255,255,.04); --sx-text: #f1f5f9; --sx-muted: rgba(226,232,240,.6); }
    .sx-hero { position: relative; overflow: hidden; border-radius: 24px; padding: 1.4rem 1.5rem; color: #fff; display: flex; align-items: center; gap: 1.2rem;
               background: var(--g); box-shadow: 0 18px 40px -18px var(--gs); }
    .sx-hero::before, .sx-hero::after { content: ''; position: absolute; border-radius: 50%; background: rgba(255,255,255,.12); }
    .sx-hero::before { width: 14rem; height: 14rem; inset-inline-start: -4rem; top: -6rem; }
    .sx-hero::after { width: 8rem; height: 8rem; inset-inline-start: 6rem; bottom: -5rem; }
    .sx-hero-art { font-size: 3.2rem; line-height: 1; flex: none; filter: drop-shadow(0 8px 14px rgba(0,0,0,.2)); animation: sx-float 3.5s ease-in-out infinite; position: relative; z-index: 1; }
    @keyframes sx-float { 50% { transform: translateY(-6px) rotate(-4deg); } }
    .sx-hero-txt { position: relative; z-index: 1; flex: 1; }
    .sx-hero h2 { font-size: 1.35rem; font-weight: 900; }
    .sx-hero p { font-size: .88rem; opacity: .9; margin-top: .2rem; }
    .sx-steps { position: relative; z-index: 1; display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .8rem; }
    .sx-steps span { font-size: .72rem; font-weight: 700; padding: .3rem .65rem; border-radius: 999px; background: rgba(255,255,255,.18); backdrop-filter: blur(4px); }
    .sx-vac { --g: linear-gradient(120deg, #0ea5e9, #6366f1 60%, #a855f7); --gs: rgba(99,102,241,.7); }
    .sx-adv { --g: linear-gradient(120deg, #10b981, #0d9488 55%, #0369a1); --gs: rgba(13,148,136,.7); }

    .sx-card { background: var(--sx-card); border: 1px solid var(--sx-line); border-radius: 22px; overflow: hidden; box-shadow: 0 12px 30px -16px rgba(15,23,42,.25); }
    .sx-ticket { padding: 1.2rem; display: grid; gap: 1rem; position: relative; }
    .sx-label { font-size: .72rem; font-weight: 800; letter-spacing: .02em; color: var(--sx-muted); }
    .sx-big { font-size: 3rem; font-weight: 900; line-height: 1; background: var(--g); -webkit-background-clip: text; background-clip: text; color: transparent; }
    .sx-big small { font-size: 1rem; font-weight: 800; -webkit-text-fill-color: var(--sx-muted); margin-inline-start: .3rem; }
    .sx-cut { height: 0; border-top: 2px dashed var(--sx-line); margin: 0 -1.2rem; position: relative; }
    .sx-cut::before, .sx-cut::after { content: ''; position: absolute; top: -.75rem; width: 1.5rem; height: 1.5rem; border-radius: 50%; background: var(--sx-page, #f3f4f6); border: 1px solid var(--sx-line); }
    .dark .sx-cut::before, .dark .sx-cut::after { background: #0f1729; }
    .sx-cut::before { inset-inline-start: -.75rem; } .sx-cut::after { inset-inline-end: -.75rem; }
    .sx-range { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: .5rem; text-align: center; }
    .sx-day { background: var(--sx-soft); border: 1px solid var(--sx-line); border-radius: 16px; padding: .6rem .3rem; }
    .sx-day b { display: block; font-size: 1.6rem; font-weight: 900; line-height: 1.1; }
    .sx-day span { display: block; font-size: .72rem; color: var(--sx-muted); }
    .sx-arrow { font-size: 1.2rem; color: var(--sx-muted); }
    .sx-chip { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .75rem; border-radius: 999px; font-size: .78rem; font-weight: 800;
               background: color-mix(in srgb, var(--c) 14%, transparent); color: var(--c); }
    .sx-line { display: flex; justify-content: space-between; gap: .5rem; font-size: .82rem; color: var(--sx-muted); }
    .sx-line b { color: var(--sx-text); }
    .sx-bar { height: .7rem; border-radius: 999px; background: var(--sx-soft); border: 1px solid var(--sx-line); overflow: hidden; display: flex; }
    .sx-bar i { display: block; height: 100%; }
    .sx-tip { font-size: .78rem; padding: .6rem .75rem; border-radius: 14px; background: var(--sx-soft); border: 1px solid var(--sx-line); color: var(--sx-muted); }
    .sx-tip.warn { color: #b45309; background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.35); }
    .dark .sx-tip.warn { color: #fcd34d; }
    .sx-quick { display: flex; flex-wrap: wrap; gap: .4rem; }
    .sx-quick button { font-size: .78rem; font-weight: 800; padding: .35rem .75rem; border-radius: 999px; border: 1px solid var(--sx-line); background: var(--sx-soft); color: var(--sx-text); transition: all .15s; }
    .sx-quick button:hover { border-color: #10b981; color: #10b981; transform: translateY(-1px); }
</style>
@endonce
