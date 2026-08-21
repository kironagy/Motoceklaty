<x-filament-panels::page>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <div
        wire:poll.5s="$refresh"
        dir="rtl"
        class="motoceklaty-chat"
    >
        {{-- الهيدر: هوية العميل وحالة التحويل --}}
        <div class="mc-header">
            <div class="mc-header__identity">
                <div class="mc-avatar">{{ mb_substr((string) ($this->record->real_phone ?: $this->record->phone), -2) }}</div>
                <div>
                    <div class="mc-header__phone">{{ $this->record->real_phone ?: $this->record->phone }}</div>
                    <div class="mc-header__status">
                        <span class="mc-status-dot"></span>
                        بانتظار رد الموظف
                        @if ($this->record->last_topic)
                            <span class="mc-header__topic">· {{ $this->record->last_topic }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- منطقة الرسائل - المنطقة الوحيدة اللي بتعمل scroll --}}
        <div class="mc-canvas" id="chat-scroll">
            @php $lastDate = null; @endphp

            @forelse ($this->messages as $message)
                @php
                    $isIncoming = $message->direction === 'incoming';
                    $mediaItems = collect(data_get($message->payload, 'saved_media_items', []))->filter(fn ($item) => is_array($item));
                    $messageDate = $message->created_at?->format('Y-m-d');
                @endphp

                @if ($messageDate && $messageDate !== $lastDate)
                    <div class="mc-date-divider"><span>{{ $message->created_at->translatedFormat('d M Y') }}</span></div>
                    @php $lastDate = $messageDate; @endphp
                @endif

                <div class="mc-row {{ $isIncoming ? 'mc-row--in' : 'mc-row--out' }}">
                    <div class="mc-bubble {{ $isIncoming ? 'mc-bubble--in' : 'mc-bubble--out' }}">
                        @foreach ($mediaItems as $item)
                            @php
                                $mime = strtolower($item['mime'] ?? '');
                                $type = strtolower($item['type'] ?? '');
                                $url = $this->mediaUrl($item['path'] ?? '');
                            @endphp

                            <div class="mc-media">
                                @if ($type === 'image' || str_starts_with($mime, 'image/'))
                                    <a href="{{ $url }}" target="_blank">
                                        <img src="{{ $url }}" loading="lazy" alt="{{ $item['filename'] ?? 'image' }}" />
                                    </a>
                                @elseif ($type === 'video' || str_starts_with($mime, 'video/'))
                                    <video src="{{ $url }}" controls preload="metadata"></video>
                                @elseif ($type === 'audio' || str_starts_with($mime, 'audio/'))
                                    <div class="mc-voice">
                                        <audio controls preload="metadata" src="{{ $url }}"></audio>
                                        <a href="{{ $url }}" download class="mc-voice__fallback">تحميل الرسالة الصوتية</a>
                                    </div>
                                @else
                                    <a href="{{ $url }}" target="_blank" class="mc-file">
                                        <x-heroicon-o-paper-clip class="w-4 h-4 shrink-0" />
                                        <span>{{ $item['filename'] ?? 'مرفق' }}</span>
                                    </a>
                                @endif
                            </div>
                        @endforeach

                        @if (trim((string) $message->message) !== '' && trim((string) $message->message) !== '[media]')
                            <div class="mc-text">{{ $message->message }}</div>
                        @endif

                        <div class="mc-time">{{ $message->created_at?->format('h:i A') }}</div>
                    </div>
                </div>
            @empty
                <div class="mc-empty">
                    <x-heroicon-o-chat-bubble-left-right class="w-8 h-8" />
                    <p>مفيش رسائل في المحادثة دي لسه</p>
                </div>
            @endforelse
        </div>

        {{-- الكتابة - ثابتة تحت وميظهرش برا الشاشة --}}
        <form
            wire:submit.prevent="sendMessage"
            class="mc-composer"
            x-data="{ uploading: false, progress: 0 }"
            x-on:livewire-upload-start="uploading = true; progress = 0"
            x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-error="uploading = false"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
        >
            <label class="mc-attach">
                <x-heroicon-o-paper-clip class="w-5 h-5" />
                <input type="file" wire:model="attachment" class="hidden" accept="image/*,video/*,audio/*,application/pdf" />
            </label>

            <div class="mc-composer__field">
                {{-- شريط تقدّم رفع المرفق من المتصفح --}}
                <div x-show="uploading" x-cloak class="mc-upload">
                    <div class="mc-upload__bar" :style="`width: ${progress}%`"></div>
                    <span class="mc-upload__label">جاري رفع المرفق... <span x-text="progress"></span>%</span>
                </div>

                @if ($attachment)
                    <div class="mc-composer__attachment" x-show="!uploading">
                        @if (str_starts_with((string) $attachment->getMimeType(), 'image/'))
                            <img src="{{ $attachment->temporaryUrl() }}" class="mc-composer__thumb" alt="preview" />
                        @else
                            <x-heroicon-o-paper-clip class="w-4 h-4 shrink-0" />
                        @endif
                        <span>{{ $attachment->getClientOriginalName() }}</span>
                        <button type="button" wire:click="$set('attachment', null)">إلغاء</button>
                    </div>
                @endif

                <textarea
                    wire:model="messageText"
                    rows="1"
                    placeholder="اكتب ردك هنا..."
                    onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); this.form.requestSubmit();}"
                ></textarea>
            </div>

            <button type="submit" class="mc-send" wire:loading.attr="disabled" wire:target="sendMessage,attachment">
                <span wire:loading.remove wire:target="sendMessage">
                    <x-heroicon-o-paper-airplane class="w-5 h-5 -scale-x-100" />
                </span>
                <span wire:loading wire:target="sendMessage" class="mc-spinner"></span>
            </button>
        </form>

        {{-- تنبيه واضح إن الإرسال شغال، مش هنج --}}
        <div wire:loading wire:target="sendMessage" class="mc-sending-toast">
            <span class="mc-spinner mc-spinner--sm"></span>
            جاري إرسال الرسالة للعميل...
        </div>
    </div>

    <style>
        .motoceklaty-chat {
            --ink: #101217;
            --ink-soft: #1a1d24;
            --canvas: #15171d;
            --card: #1e212a;
            --card-2: #262a35;
            --copper: #cc7a3f;
            --copper-deep: #a35a29;
            --amber: #e8ac4c;
            --text: #f2efe9;
            --muted: #9096a3;
            --border: #2c303b;

            font-family: 'Cairo', ui-sans-serif, system-ui, sans-serif;
            display: flex;
            flex-direction: column;
            height: 76vh;
            border-radius: 1rem;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow: 0 24px 50px -20px rgba(0, 0, 0, 0.55);
            background: var(--canvas);
            color: var(--text);
            position: relative;
        }

        [x-cloak] { display: none !important; }

        .mc-header {
            flex-shrink: 0;
            padding: .9rem 1.25rem;
            background: linear-gradient(135deg, var(--ink) 0%, var(--ink-soft) 100%);
            border-bottom: 1px solid var(--border);
        }

        .mc-header__identity { display: flex; align-items: center; gap: .75rem; }

        .mc-avatar {
            width: 2.5rem; height: 2.5rem; border-radius: 9999px;
            background: linear-gradient(135deg, var(--amber), var(--copper));
            color: #201205; font-weight: 800; font-family: 'JetBrains Mono', monospace;
            display: flex; align-items: center; justify-content: center; font-size: .8rem;
            flex-shrink: 0;
        }

        .mc-header__phone {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 500; font-size: .95rem; letter-spacing: .02em;
            color: var(--text);
        }

        .mc-header__status {
            display: flex; align-items: center; gap: .4rem;
            font-size: .75rem; color: var(--muted); margin-top: .15rem;
        }

        .mc-header__topic { color: #767c8a; }

        .mc-status-dot {
            width: .5rem; height: .5rem; border-radius: 9999px; background: var(--amber);
            animation: mc-pulse 1.8s infinite;
        }

        @keyframes mc-pulse {
            0% { box-shadow: 0 0 0 0 rgba(232, 172, 76, .55); }
            70% { box-shadow: 0 0 0 6px rgba(232, 172, 76, 0); }
            100% { box-shadow: 0 0 0 0 rgba(232, 172, 76, 0); }
        }

        .mc-canvas {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: .35rem;
            background:
                radial-gradient(circle at 15% 10%, rgba(204,122,63,.05), transparent 40%),
                var(--canvas);
        }

        .mc-date-divider { display: flex; justify-content: center; margin: .6rem 0; }
        .mc-date-divider span {
            font-size: .7rem; color: var(--muted); background: var(--card);
            border: 1px solid var(--border); padding: .2rem .75rem; border-radius: 9999px;
            font-family: 'JetBrains Mono', monospace;
        }

        .mc-row { display: flex; }
        .mc-row--in { justify-content: flex-start; }
        .mc-row--out { justify-content: flex-end; }

        .mc-bubble {
            max-width: 72%;
            padding: .55rem .8rem .4rem;
            border-radius: .9rem;
            box-shadow: 0 2px 6px rgba(0,0,0,.25);
        }

        .mc-bubble--in {
            background: var(--card);
            border: 1px solid var(--border);
            border-top-left-radius: .25rem;
        }

        .mc-bubble--out {
            background: linear-gradient(160deg, var(--copper), var(--copper-deep));
            border-top-right-radius: .25rem;
        }

        .mc-text { font-size: .92rem; line-height: 1.6; white-space: pre-wrap; word-break: break-word; color: var(--text); }
        .mc-bubble--out .mc-text { color: #fff8f0; }

        .mc-time {
            font-family: 'JetBrains Mono', monospace;
            font-size: .65rem; color: var(--muted); margin-top: .25rem; text-align: left;
        }
        .mc-bubble--out .mc-time { color: rgba(255,248,240,.75); }

        .mc-media { margin-bottom: .35rem; }
        .mc-media img { max-width: 260px; max-height: 260px; border-radius: .6rem; display: block; object-fit: cover; }
        .mc-media video { max-width: 260px; border-radius: .6rem; display: block; }

        .mc-voice { display: flex; flex-direction: column; gap: .3rem; }
        .mc-voice audio { width: 240px; height: 2.25rem; filter: invert(0); }
        .mc-voice__fallback { font-size: .7rem; text-decoration: underline; }
        .mc-bubble--out .mc-voice__fallback { color: #fff8f0; }
        .mc-bubble--in .mc-voice__fallback { color: var(--amber); }

        .mc-file {
            display: flex; align-items: center; gap: .4rem; font-size: .82rem;
            text-decoration: underline;
        }
        .mc-bubble--out .mc-file { color: #fff8f0; }
        .mc-bubble--in .mc-file { color: var(--amber); }

        .mc-empty {
            margin: auto; text-align: center; color: var(--muted);
            display: flex; flex-direction: column; align-items: center; gap: .5rem;
        }

        .mc-composer {
            flex-shrink: 0;
            display: flex;
            align-items: flex-end;
            gap: .5rem;
            padding: .75rem 1rem;
            background: var(--ink);
            border-top: 1px solid var(--border);
            position: relative;
        }

        .mc-attach {
            width: 2.35rem; height: 2.35rem; border-radius: 9999px;
            display: flex; align-items: center; justify-content: center;
            color: var(--muted); cursor: pointer; flex-shrink: 0;
            transition: background .15s, color .15s;
        }
        .mc-attach:hover { background: var(--card); color: var(--amber); }

        .mc-composer__field {
            flex: 1 1 auto;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 1.1rem;
            padding: .4rem .9rem;
        }

        .mc-composer__field textarea {
            width: 100%; border: none; background: transparent; resize: none;
            font-family: 'Cairo', sans-serif; font-size: .9rem; line-height: 1.5;
            padding: .3rem 0; max-height: 6rem; color: var(--text);
        }
        .mc-composer__field textarea::placeholder { color: var(--muted); }
        .mc-composer__field textarea:focus { outline: none; box-shadow: none; }

        .mc-composer__attachment {
            display: flex; align-items: center; gap: .4rem; justify-content: space-between;
            font-size: .72rem; color: var(--muted); padding: .3rem 0 .45rem;
        }
        .mc-composer__attachment span { flex: 1 1 auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mc-composer__attachment button { color: #e2685c; text-decoration: underline; flex-shrink: 0; }
        .mc-composer__thumb { width: 1.75rem; height: 1.75rem; border-radius: .35rem; object-fit: cover; flex-shrink: 0; }

        .mc-upload { padding: .3rem 0; }
        .mc-upload__bar {
            height: 3px; border-radius: 9999px;
            background: linear-gradient(90deg, var(--amber), var(--copper));
            transition: width .2s ease; margin-bottom: .3rem;
        }
        .mc-upload__label { font-size: .68rem; color: var(--amber); }

        .mc-send {
            width: 2.6rem; height: 2.6rem; border-radius: 9999px; flex-shrink: 0;
            background: linear-gradient(160deg, var(--amber), var(--copper));
            color: #201205; display: flex; align-items: center; justify-content: center;
            transition: transform .12s, box-shadow .12s;
            box-shadow: 0 6px 16px -6px rgba(204,122,63,.55);
        }
        .mc-send:hover { transform: translateY(-1px); }
        .mc-send:disabled { opacity: .55; transform: none; }

        .mc-spinner {
            width: 1.1rem; height: 1.1rem; border-radius: 9999px;
            border: 2px solid rgba(32,18,5,.35); border-top-color: #201205;
            display: inline-block; animation: mc-spin .7s linear infinite;
        }
        .mc-spinner--sm { width: .85rem; height: .85rem; border-color: rgba(255,248,240,.3); border-top-color: #fff8f0; }
        @keyframes mc-spin { to { transform: rotate(360deg); } }

        .mc-sending-toast {
            position: absolute; bottom: 5.2rem; left: 50%; transform: translateX(-50%);
            background: var(--copper-deep); color: #fff8f0;
            font-size: .75rem; padding: .4rem .9rem; border-radius: 9999px;
            display: flex; align-items: center; gap: .5rem;
            box-shadow: 0 8px 20px -8px rgba(0,0,0,.5);
        }

        @media (max-width: 640px) {
            .motoceklaty-chat { height: 82vh; border-radius: 0; }
            .mc-bubble { max-width: 85%; }
        }
    </style>

    <script>
        document.addEventListener('livewire:updated', function () {
            const el = document.getElementById('chat-scroll');
            if (el) el.scrollTop = el.scrollHeight;
        });

        document.addEventListener('DOMContentLoaded', function () {
            const el = document.getElementById('chat-scroll');
            if (el) el.scrollTop = el.scrollHeight;
        });
    </script>
</x-filament-panels::page>
