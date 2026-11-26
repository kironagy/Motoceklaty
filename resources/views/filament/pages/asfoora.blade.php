{{--
    Owner 2026-11-26: «عصفورة» - the owner's chat with the AI about the system. A history rail of his
    chats, a glowing bird that greets him, suggestion cards, bubbles (the answers in tables, request
    numbers as links), an Excel button under every answer with data, and a mic: tap to record, tap
    to send - the record is transcribed and asked like typed text.
--}}
<x-filament-panels::page>
    @php
        $messages = $chat?->messages ?? collect();
        $hour = (int) now()->format('H');
        $greeting = $hour < 12 ? 'صباح الفل' : ($hour < 18 ? 'نهارك سعيد' : 'مساء الفل');
        $name = trim(explode(' ', (string) auth()->user()?->name)[0] ?? '');
    @endphp

    <style>
        .mg-asf { --a1: #2dd4bf; --a2: #38bdf8; --a3: #a78bfa; --card: rgba(15, 23, 42, .72); --line: rgba(148, 163, 184, .14); --ink: #e2e8f0; --mute: #94a3b8;
            direction: rtl; position: relative; display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 18px; min-height: calc(100vh - 11rem); color: var(--ink); }
        html:not(.dark) .mg-asf { --card: rgba(255, 255, 255, .85); --line: rgba(15, 23, 42, .09); --ink: #0f172a; --mute: #64748b; }
        .fi-page:has(.mg-asf) .fi-header { display: none; }

        /* aurora behind everything */
        .mg-asf::before { content: ""; position: absolute; inset: -40px -20px auto; height: 340px; z-index: 0; pointer-events: none; filter: blur(60px); opacity: .35;
            background: radial-gradient(40% 60% at 80% 30%, var(--a1), transparent 70%), radial-gradient(35% 55% at 30% 20%, var(--a3), transparent 70%), radial-gradient(30% 50% at 55% 60%, var(--a2), transparent 70%);
            animation: mg-asf-aurora 14s ease-in-out infinite alternate; }
        @keyframes mg-asf-aurora { to { transform: translateX(-40px) scale(1.08); opacity: .5; } }
        .mg-asf > * { position: relative; z-index: 1; }

        /* ---------- history rail ---------- */
        .mg-asf-rail { background: var(--card); border: 1px solid var(--line); border-radius: 22px; padding: 14px; backdrop-filter: blur(14px); display: flex; flex-direction: column; gap: 8px; max-height: calc(100vh - 11rem); overflow: auto; position: sticky; top: 5.5rem; }
        .mg-asf-new { display: flex; align-items: center; justify-content: center; gap: 8px; border-radius: 14px; padding: 11px; font-weight: 700; color: #04201d; background: linear-gradient(120deg, var(--a1), var(--a2)); box-shadow: 0 10px 24px -12px var(--a1); transition: transform .2s, box-shadow .2s; }
        .mg-asf-new:hover { transform: translateY(-2px); box-shadow: 0 14px 30px -12px var(--a1); }
        .mg-asf-rail h4 { font-size: 11px; letter-spacing: .08em; color: var(--mute); margin: 8px 6px 0; }
        .mg-asf-item { display: flex; align-items: center; gap: 8px; border-radius: 12px; padding: 9px 10px; font-size: 13px; color: var(--ink); text-align: right; transition: background .2s; }
        .mg-asf-item:hover { background: rgba(45, 212, 191, .08); }
        .mg-asf-item.on { background: linear-gradient(90deg, transparent, rgba(45, 212, 191, .16)); box-shadow: inset -3px 0 0 var(--a1); }
        .mg-asf-item span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mg-asf-item small { color: var(--mute); font-size: 10px; }
        .mg-asf-del { opacity: 0; color: var(--mute); padding: 2px 4px; border-radius: 6px; }
        .mg-asf-item:hover .mg-asf-del { opacity: 1; }
        .mg-asf-del:hover { color: #fb7185; }

        /* ---------- main ---------- */
        .mg-asf-main { display: flex; flex-direction: column; min-width: 0; }
        .mg-asf-top { display: flex; align-items: center; gap: 14px; padding: 6px 4px 16px; }
        .mg-asf-top h1 { font-size: 26px; font-weight: 800; background: linear-gradient(100deg, var(--ink) 20%, var(--a1) 60%, var(--a3)); -webkit-background-clip: text; background-clip: text; color: transparent; line-height: 1.2; }
        .mg-asf-top p { color: var(--mute); font-size: 13px; }

        .mg-asf-bird { flex: none; width: 54px; height: 54px; border-radius: 18px; display: grid; place-items: center; position: relative;
            background: conic-gradient(from 200deg, var(--a1), var(--a2), var(--a3), var(--a1)); box-shadow: 0 10px 30px -10px var(--a1); animation: mg-asf-bob 4s ease-in-out infinite; }
        .mg-asf-bird::after { content: ""; position: absolute; inset: 2px; border-radius: 16px; background: #0b1324; }
        .mg-asf-bird svg { position: relative; z-index: 1; width: 64%; height: 64%; }
        .mg-asf-bird.sm { width: 34px; height: 34px; border-radius: 12px; animation: none; }
        .mg-asf-bird.sm::after { border-radius: 10px; }
        .mg-asf-bird.big { width: 96px; height: 96px; border-radius: 30px; }
        .mg-asf-bird.big::after { border-radius: 28px; }
        @keyframes mg-asf-bob { 50% { transform: translateY(-6px) rotate(-3deg); } }
        .mg-asf-wing { transform-origin: 55% 55%; animation: mg-asf-flap 1.6s ease-in-out infinite; }
        @keyframes mg-asf-flap { 50% { transform: rotate(-14deg); } }

        .mg-asf-feed { flex: 1; display: flex; flex-direction: column; gap: 18px; padding: 4px 2px 24px; }

        /* empty state */
        .mg-asf-hello { text-align: center; padding: 30px 10px 10px; display: flex; flex-direction: column; align-items: center; gap: 14px; animation: mg-asf-up .6s ease both; }
        .mg-asf-hello h2 { font-size: 24px; font-weight: 800; }
        .mg-asf-hello p { color: var(--mute); max-width: 460px; font-size: 14px; }
        .mg-asf-sugs { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; width: 100%; max-width: 860px; margin-top: 10px; }
        .mg-asf-sug { text-align: right; padding: 14px 16px; border-radius: 18px; background: var(--card); border: 1px solid var(--line); font-size: 13.5px; line-height: 1.6;
            transition: transform .25s, border-color .25s, box-shadow .25s; animation: mg-asf-up .6s ease both; animation-delay: calc(var(--i) * 70ms + 120ms); }
        .mg-asf-sug:hover { transform: translateY(-3px); border-color: rgba(45, 212, 191, .5); box-shadow: 0 16px 30px -20px var(--a1); }
        .mg-asf-sug i { font-style: normal; display: block; font-size: 18px; margin-bottom: 4px; }

        /* bubbles */
        .mg-asf-msg { display: flex; gap: 10px; align-items: flex-start; animation: mg-asf-up .45s ease both; }
        .mg-asf-msg.me { flex-direction: row-reverse; }
        .mg-asf-bubble { max-width: min(860px, 100%); min-width: 0; border-radius: 20px; padding: 13px 16px; font-size: 14.5px; line-height: 1.85; }
        .mg-asf-msg.me .mg-asf-bubble { background: linear-gradient(135deg, #0f766e, #0369a1); color: #ecfeff; border-top-left-radius: 6px; box-shadow: 0 12px 26px -16px #0ea5e9; white-space: pre-wrap; }
        .mg-asf-msg.ai .mg-asf-bubble { background: var(--card); border: 1px solid var(--line); border-top-right-radius: 6px; backdrop-filter: blur(12px); flex: 1; }
        .mg-asf-bubble p { margin: 0 0 .5em; } .mg-asf-bubble p:last-child { margin: 0; }
        .mg-asf-bubble ul, .mg-asf-bubble ol { padding-right: 1.3em; margin: .3em 0 .6em; } .mg-asf-bubble ul { list-style: disc; } .mg-asf-bubble ol { list-style: decimal; }
        .mg-asf-bubble strong { color: var(--a1); }
        .mg-asf-bubble h1, .mg-asf-bubble h2, .mg-asf-bubble h3 { font-weight: 800; margin: .4em 0; }
        .mg-asf-tbl { overflow-x: auto; margin: .6em 0; border-radius: 14px; border: 1px solid var(--line); }
        .mg-asf-bubble table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .mg-asf-bubble th { background: linear-gradient(90deg, rgba(45, 212, 191, .22), rgba(56, 189, 248, .18)); font-weight: 700; padding: 9px 12px; text-align: right; white-space: nowrap; }
        .mg-asf-bubble td { padding: 8px 12px; border-top: 1px solid var(--line); white-space: nowrap; }
        /* numbers, names and dates stay on one line; the reasons and notes after them wrap */
        .mg-asf-bubble td:nth-child(n + 7) { white-space: normal; min-width: 190px; max-width: 320px; }
        .mg-asf-bubble tr:nth-child(even) td { background: rgba(45, 212, 191, .04); }
        .mg-asf-bubble tbody tr:hover td { background: rgba(56, 189, 248, .08); }
        .mg-asf-req { display: inline-block; padding: 0 8px; border-radius: 999px; background: rgba(45, 212, 191, .14); color: var(--a1); font-weight: 700; text-decoration: none; transition: background .2s; }
        .mg-asf-req:hover { background: rgba(45, 212, 191, .3); }
        .mg-asf-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--line); font-size: 12px; color: var(--mute); }
        .mg-asf-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--line); font-weight: 600; color: var(--ink); transition: all .2s; }
        .mg-asf-btn:hover { border-color: var(--a1); color: var(--a1); }
        .mg-asf-btn.xl { background: linear-gradient(120deg, #15803d, #0d9488); color: #f0fdf4; border: 0; box-shadow: 0 8px 18px -10px #22c55e; }
        .mg-asf-btn.xl:hover { color: #fff; transform: translateY(-1px); }

        /* attachments and the «نفّذ» card */
        .mg-asf-file { display: block; margin-top: 6px; font-size: 12px; opacity: .85; }
        .mg-asf-attached { display: flex; align-items: center; gap: 8px; width: fit-content; margin: 0 8px 6px; padding: 5px 12px; border-radius: 999px; font-size: 12.5px;
            background: rgba(56, 189, 248, .12); border: 1px solid rgba(56, 189, 248, .35); }
        .mg-asf-attached button { color: var(--mute); }
        .mg-asf-act { margin-top: 12px; padding: 14px 16px; border-radius: 16px; border: 1px solid rgba(245, 158, 11, .45);
            background: linear-gradient(160deg, rgba(245, 158, 11, .1), rgba(45, 212, 191, .05)); }
        .mg-asf-act.applied { border-color: rgba(34, 197, 94, .5); background: rgba(34, 197, 94, .07); }
        .mg-asf-act.cancelled { opacity: .6; }
        .mg-asf-act-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .mg-asf-act-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
        .mg-asf-act-chips span { font-size: 12px; padding: 3px 10px; border-radius: 999px; background: rgba(148, 163, 184, .12); }
        .mg-asf-act p { font-size: 13px; color: var(--mute); margin: 6px 0; }
        .mg-asf-act-btns { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 8px; }
        .mg-asf-btn.go { background: linear-gradient(120deg, #d97706, #f59e0b); color: #1c1003; border: 0; font-weight: 800; box-shadow: 0 8px 18px -10px #f59e0b; }

        /* a running watch */
        .mg-asf-watch { display: flex; align-items: center; gap: 10px; margin: -4px 0 14px; padding: 8px 14px; border-radius: 14px; font-size: 13px;
            background: rgba(56, 189, 248, .1); border: 1px solid rgba(56, 189, 248, .35); }
        .mg-asf-watch span:nth-child(2) { flex: 1; }
        .mg-asf-watch .dot { width: 9px; height: 9px; border-radius: 50%; background: #38bdf8; box-shadow: 0 0 0 0 rgba(56, 189, 248, .7); animation: mg-asf-ping 1.6s infinite; }
        @keyframes mg-asf-ping { 70% { box-shadow: 0 0 0 9px rgba(56, 189, 248, 0); } 100% { box-shadow: 0 0 0 0 rgba(56, 189, 248, 0); } }

        /* memory */
        .mg-asf-mem { display: flex; align-items: flex-start; gap: 6px; font-size: 12px; line-height: 1.6; padding: 8px 10px; border-radius: 12px;
            background: rgba(167, 139, 250, .08); border: 1px dashed rgba(167, 139, 250, .3); }
        .mg-asf-mem span { flex: 1; }
        .mg-asf-mem:hover .mg-asf-del { opacity: 1; }

        /* follow-ups */
        .mg-asf-follow { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .mg-asf-follow button { font-size: 12.5px; padding: 7px 13px; border-radius: 999px; color: var(--a1); background: rgba(45, 212, 191, .08);
            border: 1px solid rgba(45, 212, 191, .28); transition: all .2s; animation: mg-asf-up .5s ease both; }
        .mg-asf-follow button:hover { background: rgba(45, 212, 191, .18); transform: translateY(-1px); }
        .mg-asf-follow button:disabled { opacity: .4; }

        /* charts (drawn on the server) */
        .mg-asf-chart { margin: .8em 0; padding: 14px 16px; border-radius: 16px; border: 1px solid var(--line); background: linear-gradient(160deg, rgba(45, 212, 191, .05), rgba(167, 139, 250, .05)); }
        .mg-asf-chart figcaption { font-weight: 800; font-size: 13.5px; margin-bottom: 10px; }
        .mg-asf-legend { display: flex; gap: 14px; font-size: 11.5px; color: var(--mute); margin-bottom: 8px; }
        .mg-asf-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-inline-end: 5px; vertical-align: -1px; }
        .mg-asf-legend i.now, .mg-asf-bar b.now { background: linear-gradient(90deg, var(--a2), var(--a1)); }
        .mg-asf-legend i.prev, .mg-asf-bar b.prev { background: rgba(167, 139, 250, .55); }
        .mg-asf-bars { display: flex; flex-direction: column; gap: 7px; }
        .mg-asf-bar { display: grid; grid-template-columns: minmax(70px, 130px) 1fr auto; align-items: center; gap: 10px; font-size: 12.5px; animation: mg-asf-up .5s ease both; animation-delay: calc(var(--i) * 45ms); }
        .mg-asf-bar .lbl { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mg-asf-bar .track { display: flex; flex-direction: column; gap: 3px; }
        .mg-asf-bar b { display: block; height: 12px; border-radius: 6px; min-width: 3px; transform-origin: right; animation: mg-asf-grow .9s cubic-bezier(.2, .8, .2, 1) both; animation-delay: calc(var(--i) * 45ms + 150ms); }
        .mg-asf-bar b.prev { height: 6px; }
        .mg-asf-bar .val { font-weight: 800; font-variant-numeric: tabular-nums; }
        .mg-asf-bar .val small { display: block; font-weight: 500; color: var(--mute); font-size: 10.5px; }
        @keyframes mg-asf-grow { from { transform: scaleX(0); } }
        .mg-asf-line { width: 100%; height: 180px; direction: ltr; }
        .mg-asf-line .now { fill: none; stroke: var(--a1); stroke-width: 2.5; vector-effect: non-scaling-stroke; }
        .mg-asf-line .prev { fill: none; stroke: #a78bfa; stroke-width: 1.8; stroke-dasharray: 5 4; vector-effect: non-scaling-stroke; }
        .mg-asf-line .area { fill: rgba(45, 212, 191, .12); }
        .mg-asf-line circle { fill: var(--a1); }
        .mg-asf-axis { display: flex; justify-content: space-between; direction: ltr; font-size: 11px; color: var(--mute); margin-top: 4px; }

        /* thinking */
        .mg-asf-think { display: flex; align-items: center; gap: 10px; color: var(--mute); font-size: 13px; }
        .mg-asf-dots { display: inline-flex; gap: 5px; }
        .mg-asf-dots b { width: 8px; height: 8px; border-radius: 50%; background: linear-gradient(var(--a1), var(--a2)); animation: mg-asf-dot 1.1s ease-in-out infinite; }
        .mg-asf-dots b:nth-child(2) { animation-delay: .15s; } .mg-asf-dots b:nth-child(3) { animation-delay: .3s; }
        @keyframes mg-asf-dot { 40% { transform: translateY(-6px); opacity: 1; } 0%, 80%, 100% { opacity: .35; } }
        @keyframes mg-asf-up { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }

        /* composer */
        .mg-asf-compose { position: sticky; bottom: 14px; z-index: 25; margin-top: auto; }
        .mg-asf-box { display: flex; align-items: flex-end; gap: 8px; padding: 8px; border-radius: 24px; background: var(--card); border: 1px solid var(--line); backdrop-filter: blur(18px) saturate(1.4);
            box-shadow: 0 20px 50px -24px rgba(0, 0, 0, .8), 0 0 0 1px rgba(45, 212, 191, .08); transition: box-shadow .25s; }
        .mg-asf-box:focus-within { box-shadow: 0 20px 50px -24px rgba(0, 0, 0, .8), 0 0 0 1.5px var(--a1), 0 0 30px -8px var(--a1); }
        .mg-asf-box textarea { flex: 1; resize: none; border: 0 !important; background: transparent !important; box-shadow: none !important; color: var(--ink); font-size: 15px; line-height: 1.6; padding: 9px 10px; max-height: 160px; outline: none; }
        .mg-asf-box textarea::placeholder { color: var(--mute); }
        .mg-asf-ico { flex: none; width: 44px; height: 44px; border-radius: 16px; display: grid; place-items: center; transition: transform .2s, background .2s, box-shadow .2s; }
        .mg-asf-ico svg { width: 22px; height: 22px; }
        .mg-asf-send { color: #04201d; background: linear-gradient(135deg, var(--a1), var(--a2)); box-shadow: 0 10px 22px -10px var(--a1); }
        .mg-asf-send:hover { transform: scale(1.06) rotate(-6deg); }
        .mg-asf-send:disabled { opacity: .45; transform: none; }
        .mg-asf-mic { color: var(--ink); background: rgba(148, 163, 184, .12); }
        .mg-asf-mic:hover { background: rgba(167, 139, 250, .22); }
        .mg-asf-mic.rec { color: #fff; background: #e11d48; box-shadow: 0 0 0 0 rgba(225, 29, 72, .6); animation: mg-asf-pulse 1.2s infinite; }
        @keyframes mg-asf-pulse { 70% { box-shadow: 0 0 0 14px rgba(225, 29, 72, 0); } 100% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0); } }
        .mg-asf-recbar { flex: 1; display: flex; align-items: center; gap: 10px; padding: 0 10px; height: 44px; color: #fda4af; font-weight: 700; font-variant-numeric: tabular-nums; }
        .mg-asf-wave { display: flex; align-items: center; gap: 3px; height: 26px; flex: 1; }
        .mg-asf-wave i { flex: 1; max-width: 4px; border-radius: 3px; background: linear-gradient(#fb7185, #a78bfa); height: 30%; animation: mg-asf-wave 0.9s ease-in-out infinite; }
        @keyframes mg-asf-wave { 50% { height: 100%; } }
        .mg-asf-hint { text-align: center; font-size: 11px; color: var(--mute); margin-top: 6px; }

        @media (max-width: 1023px) {
            .mg-asf { grid-template-columns: 1fr; }
            .mg-asf-rail { position: static; max-height: none; flex-direction: row; align-items: center; overflow-x: auto; padding: 8px; border-radius: 18px; }
            .mg-asf-rail h4 { display: none; }
            .mg-asf-new { flex: none; padding: 9px 14px; }
            .mg-asf-item { flex: none; max-width: 170px; padding: 7px 10px; border: 1px solid var(--line); }
            .mg-asf-item small, .mg-asf-del { display: none; }
            .mg-asf-compose { bottom: 8px; }
            .mg-asf-hint { display: none; }
            .mg-asf-top h1 { font-size: 21px; }
            .mg-asf-bubble { font-size: 14px; padding: 11px 13px; }
            .mg-asf-msg.ai .mg-asf-bird.sm { display: none; }
        }
        @media (prefers-reduced-motion: reduce) { .mg-asf *, .mg-asf::before { animation: none !important; } }
    </style>

    @php
        $bird = '<svg viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="mgasfg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2dd4bf"/><stop offset=".55" stop-color="#38bdf8"/><stop offset="1" stop-color="#a78bfa"/></linearGradient></defs>'
            .'<path d="M14 38c0-12 9-22 21-22 6 0 10 3 12 7l7 1-6 4c0 12-9 20-21 20-5 0-9-1-13-4l-6 3 2-6c-4-1-6-1-6-3z" fill="url(#mgasfg)"/>'
            .'<path class="mg-asf-wing" d="M24 36c5-1 10 1 13 5-6 3-13 3-18-1 1-2 3-3 5-4z" fill="#0b1324" opacity=".55"/>'
            .'<circle cx="42" cy="25" r="2.6" fill="#0b1324"/><circle cx="42.8" cy="24.2" r=".9" fill="#fff"/></svg>';
    @endphp

    <div class="mg-asf" x-data="asfoora()" @if ($thinking) wire:poll.2s="refreshAnswers" @elseif ($watches->isNotEmpty()) wire:poll.60s="refreshAnswers" @endif>
        {{-- ---------- chats ---------- --}}
        <aside class="mg-asf-rail">
            <button type="button" class="mg-asf-new" wire:click="newChat">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
                محادثة جديدة
            </button>
            @if ($chats->isNotEmpty())
                <h4>محادثاتك</h4>
            @endif
            @if ($memories->isNotEmpty())
                <h4>🧠 عصفورة فاكرة</h4>
                @foreach ($memories as $mem)
                    <div class="mg-asf-mem" wire:key="mem-{{ $mem->id }}">
                        <span>{{ $mem->content }}</span>
                        <button type="button" class="mg-asf-del" title="انسيها" wire:click="deleteMemory({{ $mem->id }})" wire:confirm="عصفورة تنسى دي؟">✕</button>
                    </div>
                @endforeach
            @endif
            @foreach ($chats as $c)
                <div class="mg-asf-item {{ $chat?->id === $c->id ? 'on' : '' }}" wire:key="chat-{{ $c->id }}">
                    <button type="button" class="flex flex-1 min-w-0 flex-col items-start" wire:click="openChat({{ $c->id }})">
                        <span class="w-full">{{ $c->title ?: 'محادثة' }}</span>
                        <small>{{ $c->updated_at?->locale('ar')->diffForHumans() }}</small>
                    </button>
                    <button type="button" class="mg-asf-del" title="امسح المحادثة" wire:click="deleteChat({{ $c->id }})" wire:confirm="تمسح المحادثة دي؟">✕</button>
                </div>
            @endforeach
        </aside>

        {{-- ---------- conversation ---------- --}}
        <section class="mg-asf-main">
            <header class="mg-asf-top">
                <div class="mg-asf-bird">{!! $bird !!}</div>
                <div>
                    <h1>عصفورة</h1>
                    <p>اسألها عن أي حاجة حصلت في السيستم - بالأرقام الحقيقية</p>
                </div>
            </header>

            @foreach ($watches as $w)
                <div class="mg-asf-watch" wire:key="watch-{{ $w->id }}">
                    <span class="dot"></span>
                    <span>🔔 بتابعلك {{ $w->describe() }} - الجاية {{ $w->next_run_at?->format('h:i A') }}</span>
                    <button type="button" class="mg-asf-btn" wire:click="stopWatch({{ $w->id }})">وقّف</button>
                </div>
            @endforeach

            <div class="mg-asf-feed" x-ref="feed">
                @if ($messages->isEmpty())
                    <div class="mg-asf-hello" x-show="! pending">
                        <div class="mg-asf-bird big">{!! $bird !!}</div>
                        <h2>{{ $greeting }}{{ $name ? ' يا '.$name : '' }} 👋</h2>
                        <p>أنا عصفورة، شايفة كل اللي بيحصل في السيستم. اسألني بالكتابة أو سجّلي صوت، وأي تقرير تقدر تنزّله إكسيل.</p>
                        <div class="mg-asf-sugs">
                            @foreach ($suggestions as $i => $s)
                                <button type="button" class="mg-asf-sug" style="--i: {{ $i }}" x-on:click="send(@js($s))">
                                    <i>{{ ['📊', '⏸️', '❌', '🏆', '🤖'][$i] ?? '✨' }}</i>{{ $s }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @foreach ($messages as $m)
                    @if ($m->role === 'user')
                        <div class="mg-asf-msg me" wire:key="m-{{ $m->id }}">
                            <div class="mg-asf-bubble">{{ $m->content }}@if (isset($m->meta['file']))<span class="mg-asf-file">📎 {{ $m->meta['file']['name'] }}</span>@endif</div>
                        </div>
                    @elseif ($m->meta['pending'] ?? false)
                        <div class="mg-asf-msg ai" wire:key="m-{{ $m->id }}-pending">
                            <div class="mg-asf-bird sm">{!! $bird !!}</div>
                            <div class="mg-asf-bubble"><div class="mg-asf-think"><span class="mg-asf-dots"><b></b><b></b><b></b></span><span x-text="thinkingLine"></span></div></div>
                        </div>
                    @else
                        <div class="mg-asf-msg ai" wire:key="m-{{ $m->id }}">
                            <div class="mg-asf-bird sm">{!! $bird !!}</div>
                            <div class="mg-asf-bubble">
                                {!! preg_replace(['/<table>/', '/<\/table>/'], ['<div class="mg-asf-tbl"><table>', '</table></div>'], \App\Filament\Pages\Asfoora::render_answer($m->content)) !!}
                                <div class="mg-asf-tools">
                                    @php $files = array_values($m->meta['tables'] ?? []); $sorted = collect($files)->contains(fn ($t) => $t['group'] ?? false); @endphp
                                    @if ($sorted)
                                        {{-- sorted groups: a file each («الميتة»، «ينفع نقاوح فيها») --}}
                                        @foreach ($files as $i => $t)
                                            <button type="button" class="mg-asf-btn xl" wire:click="exportExcel({{ $m->id }}, {{ $i }})" wire:loading.attr="disabled">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                                                {{ $t['title'] ?? 'ملف' }} ({{ count($t['rows'] ?? []) }})
                                            </button>
                                        @endforeach
                                        @if (count($files) > 1)
                                            <button type="button" class="mg-asf-btn" wire:click="exportExcel({{ $m->id }})">الكل في ملف واحد</button>
                                        @endif
                                    @elseif ($files !== [])
                                        <button type="button" class="mg-asf-btn xl" wire:click="exportExcel({{ $m->id }})" wire:loading.attr="disabled" wire:target="exportExcel({{ $m->id }})">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                                            نزّل إكسيل
                                        </button>
                                    @endif
                                    <button type="button" class="mg-asf-btn" x-on:click="copy($el)" data-text="{{ $m->content }}">نسخ</button>
                                    @if (! empty($m->meta['tools']))
                                        <span>🔎 {{ count($m->meta['tools']) }} {{ count($m->meta['tools']) === 1 ? 'بحث' : 'عمليات بحث' }} في السيستم</span>
                                    @endif
                                </div>
                                @if (($m->meta['action_id'] ?? null) && ($act = $actions[$m->meta['action_id']] ?? null))
                                    @php
                                        $changes = collect($act->payload['changes'] ?? []);
                                        $notified = $changes->filter(fn ($c) => in_array($c['to_code'], \App\Observers\InstallmentRequestObserver::NOTIFIABLE_STATUSES, true))->count();
                                    @endphp
                                    <div class="mg-asf-act {{ $act->status }}">
                                        <div class="mg-asf-act-head">
                                            <strong>⚡ تغيير حالات من شيت أمان</strong>
                                            <span>{{ $changes->count() }} طلب</span>
                                        </div>
                                        <div class="mg-asf-act-chips">
                                            @foreach ($changes->countBy('to') as $to => $n)
                                                <span>{{ $to }}: <b>{{ $n }}</b></span>
                                            @endforeach
                                        </div>
                                        @if ($act->status === 'pending' && $changes->isNotEmpty())
                                            <p>مفيش حاجة اتغيرت لسه. نزّل ملف «التغييرات اللي هتتنفذ» وبص عليه، وبعدين دوس نفّذ - كل طلب هيتعدل باسمك زي ما تكون غيرته بإيدك{{ $notified ? '، و'.$notified.' عميل هيوصله رسالة واتساب بحالته' : '' }}.</p>
                                            <div class="mg-asf-act-btns">
                                                <button type="button" class="mg-asf-btn go" wire:click="applyAction({{ $act->id }})" wire:loading.attr="disabled" wire:target="applyAction"
                                                    wire:confirm="هيتغير {{ $changes->count() }} طلب{{ $notified ? ' وهيتبعت '.$notified.' رسالة واتساب للعملاء' : '' }}. متأكد؟">✓ نفّذ {{ $changes->count() }} تغيير</button>
                                                <button type="button" class="mg-asf-btn" wire:click="cancelAction({{ $act->id }})">مش دلوقتي</button>
                                                <span wire:loading wire:target="applyAction">بنفّذ…</span>
                                            </div>
                                        @elseif ($act->status === 'applied')
                                            <p>✓ اتنفّذ {{ $act->applied_at?->locale('ar')->diffForHumans() }}: {{ $act->result['done'] ?? 0 }} طلب اتغير{{ ! empty($act->result['skipped']) ? '، و'.count($act->result['skipped']).' اتسابوا لأن حالتهم اتغيرت قبل التنفيذ' : '' }}{{ ! empty($act->result['failed']) ? '، و'.count($act->result['failed']).' فشلوا' : '' }}.</p>
                                        @elseif ($act->status === 'cancelled')
                                            <p>اتلغى - مفيش حاجة اتغيرت.</p>
                                        @endif
                                    </div>
                                @endif
                                @if (! empty($m->meta['followups']) && $loop->last)
                                    <div class="mg-asf-follow">
                                        @foreach ($m->meta['followups'] as $f)
                                            <button type="button" x-on:click="send(@js($f))" :disabled="busy">{{ $f }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach

                {{-- what he just asked, shown at once while the answer is being made --}}
                <template x-if="pending">
                    <div class="flex flex-col gap-[18px]">
                        <div class="mg-asf-msg me"><div class="mg-asf-bubble" x-text="pending"></div></div>
                        <div class="mg-asf-msg ai">
                            <div class="mg-asf-bird sm">{!! $bird !!}</div>
                            <div class="mg-asf-bubble"><div class="mg-asf-think"><span class="mg-asf-dots"><b></b><b></b><b></b></span><span x-text="thinkingLine"></span></div></div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- ---------- composer ---------- --}}
            <div class="mg-asf-compose">
                @if ($attached)
                    <div class="mg-asf-attached">📎 {{ $attached['name'] }} <button type="button" wire:click="removeAttachment" title="شيل الملف">✕</button></div>
                @endif
                <div class="mg-asf-attached" wire:loading.flex wire:target="sheet">⏳ بيرفع الشيت…</div>
                <form class="mg-asf-box" x-on:submit.prevent="send()">
                    <input type="file" x-ref="file" class="hidden" accept=".xlsx,.csv" wire:model="sheet">
                    <button type="button" class="mg-asf-ico mg-asf-mic" x-show="! recording" x-on:click="$refs.file.click()" :disabled="busy" title="ارفع شيت إكسيل">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21 11-8.6 8.6a5.5 5.5 0 0 1-7.8-7.8l9.2-9.2a3.7 3.7 0 0 1 5.2 5.2l-9.2 9.2a1.8 1.8 0 0 1-2.6-2.6l8.5-8.5"/></svg>
                    </button>
                    <template x-if="! recording">
                        <textarea x-ref="input" rows="1" x-model="text" placeholder="اسأل عصفورة…"
                            x-on:input="grow()" x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); send(); }" :disabled="busy"></textarea>
                    </template>
                    <template x-if="recording">
                        <div class="mg-asf-recbar">
                            <span x-text="clock"></span>
                            <span class="mg-asf-wave">@for ($i = 0; $i < 22; $i++)<i style="animation-delay: {{ ($i * 37) % 900 }}ms"></i>@endfor</span>
                            <button type="button" class="mg-asf-btn" x-on:click="cancelRec()">إلغاء</button>
                        </div>
                    </template>
                    <button type="button" class="mg-asf-ico mg-asf-mic" :class="recording && 'rec'" x-on:click="recording ? stopRec() : startRec()" :disabled="busy" :title="recording ? 'ابعت التسجيل' : 'سجّل صوت'">
                        <svg x-show="! recording" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="3" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg>
                        <svg x-show="recording" viewBox="0 0 24 24" fill="currentColor"><path d="M3 20.5 21 12 3 3.5v6.6L15 12 3 13.9z" transform="scale(-1,1) translate(-24,0)"/></svg>
                    </button>
                    <button type="submit" class="mg-asf-ico mg-asf-send" x-show="! recording" :disabled="busy || (! text.trim() && ! $wire.attached)" title="ابعت">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 20.5 21 12 3 3.5v6.6L15 12 3 13.9z" transform="scale(-1,1) translate(-24,0)"/></svg>
                    </button>
                </form>
                <div class="mg-asf-hint">Enter للإرسال · Shift+Enter لسطر جديد · 🎙️ دوسة تبدأ التسجيل ودوسة تبعته</div>
            </div>
        </section>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            if (window.__asfoora) return;
            window.__asfoora = true;
            Alpine.data('asfoora', () => ({
                text: '', pending: null, recording: false, clock: '0:00', thinkingLine: 'عصفورة بتدوّر في السيستم…',
                rec: null, chunks: [], started: 0, timer: null, cancelled: false,
                // busy while his question is on its way, or while the server is making the answer
                get busy() { return !! this.pending || this.$wire.thinking; },
                init() {
                    const lines = ['عصفورة بتدوّر في السيستم…', 'بتجمع الأرقام…', 'بتراجع الطلبات…', 'بترتب التقرير…'];
                    let i = 0;
                    setInterval(() => { i = (i + 1) % lines.length; this.thinkingLine = lines[i]; }, 2200);
                    this.$watch('$wire.thinking', () => this.$nextTick(() => setTimeout(() => this.bottom(), 80)));
                    this.$nextTick(() => this.bottom());
                },
                bottom() { window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }); },
                grow() { const t = this.$refs.input; if (! t) return; t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 160) + 'px'; },
                send(preset = null) {
                    const q = (preset ?? this.text).trim() || (this.$wire.attached ? 'شوفي الشيت ده' : '');
                    if (! q || this.busy) return;
                    this.pending = q; this.text = '';
                    this.$nextTick(() => { this.grow(); this.bottom(); });
                    this.$wire.ask(q).finally(() => this.answered());
                },
                answered() { this.pending = null; this.$nextTick(() => setTimeout(() => this.bottom(), 80)); },
                copy(el) { navigator.clipboard?.writeText(el.dataset.text); el.textContent = 'اتنسخ ✓'; setTimeout(() => el.textContent = 'نسخ', 1500); },
                async startRec() {
                    if (! navigator.mediaDevices?.getUserMedia || ! window.MediaRecorder) { alert('المتصفح ده مش بيسجل صوت'); return; }
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        const type = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/webm', 'audio/ogg'].find((t) => MediaRecorder.isTypeSupported?.(t)) || '';
                        this.rec = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
                        this.chunks = []; this.cancelled = false;
                        this.rec.ondataavailable = (e) => e.data.size && this.chunks.push(e.data);
                        this.rec.onstop = () => { stream.getTracks().forEach((t) => t.stop()); if (! this.cancelled) this.upload(); };
                        this.rec.start(250);
                        this.recording = true; this.started = Date.now();
                        this.timer = setInterval(() => { const s = Math.floor((Date.now() - this.started) / 1000); this.clock = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); if (s >= 180) this.stopRec(); }, 250);
                    } catch (e) { alert('محتاج إذن المايك عشان أسمعك'); }
                },
                stopRec() { clearInterval(this.timer); this.recording = false; this.clock = '0:00'; this.rec?.state !== 'inactive' && this.rec.stop(); },
                cancelRec() { this.cancelled = true; this.stopRec(); },
                upload() {
                    const type = (this.rec.mimeType || 'audio/webm').split(';')[0];
                    const ext = type.includes('mp4') ? 'm4a' : (type.includes('ogg') ? 'ogg' : 'webm');
                    const file = new File(this.chunks, 'asfoora.' + ext, { type });
                    if (file.size < 1200) return;
                    this.pending = '🎙️ تسجيل صوتي…'; this.$nextTick(() => this.bottom());
                    this.$wire.upload('voice', file, () => this.$wire.askVoice().finally(() => this.answered()), () => { this.answered(); alert('التسجيل ما اترفعش، جرّب تاني'); });
                },
            }));
        });
    </script>
</x-filament-panels::page>
