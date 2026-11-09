<style>
    .wb-card { --ink: #0f172a; --muted: #64748b; --line: rgba(148, 163, 184, .22); --soft: #f8fafc; position: relative; padding: 4px 2px 2px; }
    .dark .wb-card { --ink: #f1f5f9; --muted: #94a3b8; --line: rgba(148, 163, 184, .14); --soft: rgba(30, 41, 59, .55); }
    .wb-card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 60px; border-radius: 18px; opacity: .9;
        background: linear-gradient(120deg, hsl(var(--hue) 85% 95%), hsl(calc(var(--hue) + 50) 85% 94%)); }
    .dark .wb-card::before { background: linear-gradient(120deg, hsl(var(--hue) 45% 18% / .7), hsl(calc(var(--hue) + 50) 45% 16% / .6)); }
    .wb-off { filter: grayscale(.55); opacity: .78; }
    .wb-top { position: relative; display: flex; align-items: center; gap: 12px; padding: 4px 4px 0; }
    .wb-av { position: relative; flex: none; width: 52px; height: 52px; border-radius: 18px; display: grid; place-items: center; font-size: 18px; font-weight: 900; color: #fff;
        background: linear-gradient(135deg, hsl(var(--hue) 70% 58%), hsl(calc(var(--hue) + 50) 70% 48%)); box-shadow: 0 10px 22px -10px hsl(var(--hue) 70% 45%); }
    .wb-dot { position: absolute; bottom: -2px; left: -2px; width: 15px; height: 15px; border-radius: 50%; border: 3px solid #fff; }
    .dark .wb-dot { border-color: #18181b; }
    .wb-dot.on::after { content: ""; position: absolute; inset: -5px; border-radius: 50%; border: 2px solid #22c55e; animation: wb-ping 1.8s ease-out infinite; }
    @keyframes wb-ping { from { transform: scale(.6); opacity: .9; } to { transform: scale(1.5); opacity: 0; } }
    .wb-id { min-width: 0; flex: 1; }
    .wb-name { font-size: 17px; font-weight: 900; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .wb-sub { display: flex; flex-wrap: wrap; gap: 8px; font-size: 12px; font-weight: 600; color: var(--muted); }
    .wb-status { flex: none; padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: 800; color: var(--c); background: color-mix(in srgb, var(--c) 13%, #fff); border: 1px solid color-mix(in srgb, var(--c) 25%, transparent); }
    .dark .wb-status { background: color-mix(in srgb, var(--c) 18%, transparent); }
    .wb-stats { position: relative; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-top: 18px; }
    .wb-stat { padding: 10px 6px; border-radius: 16px; text-align: center; background: var(--soft); border: 1px solid var(--line); }
    .wb-stat b { display: block; font-size: 22px; line-height: 1.1; font-weight: 900; color: hsl(var(--hue) 65% 45%); font-variant-numeric: tabular-nums; }
    .dark .wb-stat b { color: hsl(var(--hue) 75% 70%); }
    .wb-stat span { font-size: 11px; font-weight: 700; color: var(--muted); }
    .wb-foot { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 12px; }
    .wb-tag { padding: 2px 10px; border-radius: 99px; font-size: 11px; font-weight: 800; color: var(--muted); background: var(--soft); border: 1px solid var(--line); }
    .wb-tag-on { color: #047857; background: rgba(16, 185, 129, .1); border-color: rgba(16, 185, 129, .25); }
    .dark .wb-tag-on { color: #6ee7b7; }
    .wb-since { margin-right: auto; font-size: 11px; font-weight: 700; color: var(--muted); }
    .wb-qr { display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 8px; border-radius: 14px; border: 1px dashed var(--line); font-size: 12px; font-weight: 700; color: var(--muted); }
    .wb-qr img { width: 72px; height: 72px; border-radius: 10px; background: #fff; padding: 3px; }
</style>
