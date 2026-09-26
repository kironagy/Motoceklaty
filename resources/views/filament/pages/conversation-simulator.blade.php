<x-filament-panels::page>
    <style>
        .sim { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 1rem; direction: rtl; }
        @media (max-width: 1024px) { .sim { grid-template-columns: minmax(0, 1fr); } }
        .sim-chat { display: flex; flex-direction: column; height: calc(100vh - 14rem); min-height: 520px; border-radius: 0.75rem; overflow: hidden; border: 1px solid rgb(229 231 235); background: #efeae2; }
        .dark .sim-chat { border-color: rgb(255 255 255 / 0.1); background: #0b141a; }
        .sim-scroll { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
        .sim-row { display: flex; }
        .sim-row--in { justify-content: flex-start; }
        .sim-row--out { justify-content: flex-end; }
        .sim-bubble { max-width: 78%; padding: 0.5rem 0.75rem; border-radius: 0.6rem; white-space: pre-wrap; line-height: 1.8; font-size: 0.95rem; box-shadow: 0 1px 0.5px rgb(0 0 0 / 0.13); color: #111b21; }
        .sim-bubble--in { background: #fff; border-top-right-radius: 0; }
        .sim-bubble--out { background: #d9fdd3; border-top-left-radius: 0; }
        .dark .sim-bubble { color: #e9edef; }
        .dark .sim-bubble--in { background: #202c33; }
        .dark .sim-bubble--out { background: #005c4b; }
        .sim-bubble img { max-width: 240px; border-radius: 0.4rem; display: block; margin-bottom: 0.25rem; }
        .sim-debug { align-self: flex-end; max-width: 78%; font-size: 0.75rem; color: rgb(75 85 99); background: rgb(255 255 255 / 0.7); border-radius: 0.5rem; padding: 0.35rem 0.6rem; }
        .dark .sim-debug { color: rgb(156 163 175); background: rgb(0 0 0 / 0.35); }
        .sim-debug summary { cursor: pointer; }
        .sim-tool { display: flex; justify-content: space-between; gap: 0.5rem; font-family: ui-monospace, monospace; direction: ltr; padding: 0.1rem 0; }
        .sim-tool--bad { color: rgb(220 38 38); }
        .sim-compose { display: flex; gap: 0.5rem; padding: 0.6rem; background: #f0f2f5; align-items: flex-end; }
        .dark .sim-compose { background: #202c33; }
        .sim-compose textarea { flex: 1; resize: none; border-radius: 0.6rem; border: none; padding: 0.55rem 0.8rem; background: #fff; color: #111b21; min-height: 42px; max-height: 140px; }
        .dark .sim-compose textarea { background: #2a3942; color: #e9edef; }
        .sim-typing { align-self: flex-end; background: #d9fdd3; border-radius: 0.6rem; padding: 0.4rem 0.8rem; font-size: 0.85rem; color: #54656f; }
        .dark .sim-typing { background: #005c4b; color: #cfd8dc; }
        .sim-chips { display: flex; flex-wrap: wrap; gap: 0.35rem; padding: 0.5rem 0.6rem 0; background: #f0f2f5; }
        .dark .sim-chips { background: #202c33; }
        .sim-chip { font-size: 0.8rem; padding: 0.2rem 0.6rem; border-radius: 999px; background: #fff; border: 1px solid rgb(209 213 219); color: #111b21; }
        .dark .sim-chip { background: #2a3942; border-color: transparent; color: #e9edef; }
        .sim-teach { align-self: stretch; margin: 0.25rem 2rem; border-radius: 0.6rem; padding: 0.6rem 0.8rem; background: #fff7e6; border: 1px solid #f5c26b; color: #3b2a07; font-size: 0.9rem; line-height: 1.7; }
        .dark .sim-teach { background: #2b2210; border-color: #7a5a1c; color: #f3e3c2; }
        .sim-teach-owner { font-size: 0.8rem; opacity: 0.75; margin-bottom: 0.3rem; white-space: pre-wrap; }
        .sim-card { margin-top: 0.4rem; border-radius: 0.5rem; padding: 0.45rem 0.6rem; background: rgb(255 255 255 / 0.7); border: 1px solid rgb(0 0 0 / 0.08); }
        .dark .sim-card { background: rgb(0 0 0 / 0.25); border-color: rgb(255 255 255 / 0.08); }
        .sim-card pre { white-space: pre-wrap; direction: ltr; font-size: 0.72rem; margin: 0.25rem 0 0; max-height: 220px; overflow: auto; }
        .sim-badge { font-size: 0.72rem; padding: 0.05rem 0.45rem; border-radius: 999px; background: rgb(0 0 0 / 0.07); }
        .sim-ok { color: rgb(22 163 74); } .sim-bad { color: rgb(220 38 38); }
        .sim-correct { align-self: flex-end; font-size: 0.75rem; color: #b26b00; text-decoration: underline; }
        .sim-correct-box { align-self: stretch; margin: 0 2rem; display: flex; flex-direction: column; gap: 0.35rem; }
        .sim-correct-box textarea { border-radius: 0.5rem; border: 1px solid #f5c26b; padding: 0.5rem; min-height: 70px; background: #fff; color: #111b21; }
        .dark .sim-correct-box textarea { background: #2a3942; color: #e9edef; }
        .sim-side pre { white-space: pre-wrap; direction: ltr; font-size: 0.72rem; max-height: 360px; overflow: auto; }
    </style>

    <div class="sim">
        <div class="sim-chat">
            <div class="sim-scroll" id="sim-scroll" x-data x-init="$el.scrollTop = $el.scrollHeight" @simulator-scrolled.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
                @php $turns = $this->getTurns(); @endphp

                @if ($turns === [])
                    <div class="m-auto max-w-md text-center text-sm text-gray-600 dark:text-gray-400">
                        <p class="font-semibold text-base mb-2">اكتب كأنك عميل على واتساب</p>
                        <p>البوت هيرد بجد: نفس الموديل والتعليمات والأسعار والأدوات اللي شغالة مع العملاء، بس مفيش أي رسالة بتتبعت على واتساب.</p>
                        <p class="mt-2">تحت كل رد هتلاقي "إيه اللي البوت عمله" - الأدوات اللي استخدمها والوقت والتوكنز - عشان تعرف ليه رد كده.</p>
                    </div>
                @endif

                @foreach ($turns as $turn)
                    @foreach ($turn['in'] as $msg)
                        <div class="sim-row sim-row--in">
                            <div class="sim-bubble sim-bubble--in">
                                @foreach ($msg->media as $media)
                                    @if (str_starts_with((string) $media->mime, 'image/'))
                                        <img src="{{ $this->mediaUrl($media) }}" alt="">
                                    @else
                                        <div class="text-xs">📎 {{ $media->original_filename }}</div>
                                    @endif
                                @endforeach
                                {{ $msg->text }}
                            </div>
                        </div>
                    @endforeach

                    @foreach ($turn['out'] as $msg)
                        <div class="sim-row sim-row--out">
                            <div class="sim-bubble sim-bubble--out">
                                @php $item = data_get($msg->payload, 'saved_media_items.0'); @endphp
                                @if ($msg->type === 'image' && $item)
                                    <img src="{{ $item['url'] ?? '' }}" alt="">
                                @endif
                                {{ $msg->text }}
                            </div>
                        </div>
                    @endforeach


                    @php $lastOut = $turn['out']->last(); @endphp
                    @if ($teachMode && $lastOut)
                        @if ($correctingMessageId === $lastOut->id)
                            <form class="sim-correct-box" wire:submit="submitCorrection">
                                <textarea wire:model="teachText" placeholder="إيه الغلط وإيه الصح؟ اكتب الرد الصح أو اشرح بكلامك - البوت هياخد الفكرة مش الكلام بالنص"></textarea>
                                <div class="flex gap-2">
                                    <x-filament::button type="submit" size="sm" color="warning" wire:loading.attr="disabled" wire:target="submitCorrection">علّمه</x-filament::button>
                                    <x-filament::button size="sm" color="gray" wire:click="cancelCorrection">إلغاء</x-filament::button>
                                </div>
                            </form>
                        @else
                            <button type="button" class="sim-correct" wire:click="startCorrection({{ $lastOut->id }})">✏️ صحّح الرد ده</button>
                        @endif
                    @endif

                    @foreach ($turn['teaching'] as $session)
                        @include('filament.pages.partials.teaching-session', ['session' => $session])
                    @endforeach

                    @if ($showDebug && $turn['trace'])
                        @php
                            $trace = $turn['trace'];
                            $failed = $turn['tools']->filter(fn ($s) => $s->result_code && ! in_array($s->result_code, ['OK', 'ok'], true));
                        @endphp
                        <details class="sim-debug">
                            <summary>
                                إيه اللي البوت عمله:
                                {{ $turn['tools']->pluck('tool_name')->reject(fn ($n) => $n === 'send_reply')->implode(' · ') ?: 'رد مباشر' }}
                                — {{ number_format(($trace->latency_ms ?? 0) / 1000, 1) }} ث
                                @if ($trace->status !== 'done') <span class="text-danger-600">({{ $trace->status }})</span> @endif
                                @if ($failed->isNotEmpty()) <span class="text-warning-600">⚠ {{ $failed->count() }}</span> @endif
                                @if (! empty($trace->guard_events)) <span class="text-warning-600">🛡 {{ count($trace->guard_events) }}</span> @endif
                            </summary>
                            <div class="mt-1 space-y-0.5">
                                <div>الموديل: <span dir="ltr">{{ $trace->model ?? '—' }}</span> · توكنز: {{ $trace->input_tokens ?? '—' }} داخل / {{ $trace->output_tokens ?? '—' }} خارج · تعليمات {{ $trace->prompt_version }}</div>
                                @foreach ($turn['tools'] as $step)
                                    <details>
                                        <summary class="sim-tool {{ $step->result_code && ! in_array($step->result_code, ['OK', 'ok'], true) ? 'sim-tool--bad' : '' }}">
                                            <span>{{ $step->tool_name }}</span>
                                            <span>{{ $step->result_code ?? 'ok' }} · {{ $step->latency_ms }}ms</span>
                                        </summary>
                                        <pre dir="ltr" class="whitespace-pre-wrap text-[11px]">args: {{ json_encode($step->args_redacted, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}
result: {{ \Illuminate\Support\Str::limit(json_encode($step->result_redacted, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 1500) }}</pre>
                                    </details>
                                @endforeach
                                @foreach ($trace->guard_events ?? [] as $event)
                                    <div class="text-warning-700" dir="ltr">🛡 {{ is_array($event) ? json_encode($event, JSON_UNESCAPED_UNICODE) : $event }}</div>
                                @endforeach
                                @if ($trace->error_code)
                                    <div class="text-danger-600" dir="ltr">{{ $trace->error_code }}</div>
                                @endif
                                <a class="underline" href="{{ \App\Filament\Resources\AiTraceResource::getUrl('view', ['record' => $trace]) }}" target="_blank">التفاصيل الكاملة</a>
                            </div>
                        </details>
                    @endif
                @endforeach

                @if ($this->isHandedOff())
                    <div class="mx-auto rounded-lg bg-warning-50 px-3 py-2 text-center text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                        المحادثة اتحولت لموظف - البوت ساكت دلوقتي زي ما هيحصل على واتساب.
                        <button type="button" class="font-semibold underline" wire:click="returnToBot">رجّعها للبوت</button>
                    </div>
                @endif

                <div wire:loading.flex wire:target="send,quick" class="sim-typing">بيكتب…</div>
                <div wire:loading.flex wire:target="submitCorrection,approveChange" class="sim-typing" style="background:#fff7e6;color:#7a5a1c">المدرّب بيفهم التصحيح ويجربه…</div>
            </div>

            <div class="sim-chips">
                @foreach (self::QUICK_MESSAGES as $quick)
                    <button type="button" class="sim-chip" wire:click="quick(@js($quick))" wire:loading.attr="disabled">{{ $quick }}</button>
                @endforeach
            </div>

            @if ($attachments)
                <div class="flex flex-wrap gap-2 px-3 pt-2 text-xs" style="background: inherit">
                    @foreach ($attachments as $i => $file)
                        <span class="sim-chip">📎 {{ $file->getClientOriginalName() }} <button type="button" wire:click="removeAttachment({{ $i }})">✕</button></span>
                    @endforeach
                </div>
            @endif

            <form class="sim-compose" wire:submit="send">
                <label class="cursor-pointer p-2 text-gray-500" title="صورة مستند أو موتوسيكل">
                    <x-filament::icon icon="heroicon-o-paper-clip" class="h-5 w-5" />
                    <input type="file" class="hidden" wire:model="attachments" multiple accept="image/*,application/pdf">
                </label>
                <textarea
                    wire:model="message"
                    rows="1"
                    placeholder="{{ $teachMode ? 'اكتب رسالة العميل… أو /علّم وبعدها أي حاجة عايز البوت يتعلمها' : 'اكتب رسالة العميل…' }}"
                    x-data
                    @keydown.enter.prevent="if (! $event.shiftKey) { $wire.send() } else { $el.value += '\n' }"
                ></textarea>
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled" wire:target="send,quick,attachments">
                    إرسال
                </x-filament::button>
            </form>
        </div>

        <div class="sim-side space-y-4">
            <x-filament::section>
                <div class="flex flex-col gap-2">
                    <x-filament::button wire:click="newConversation" icon="heroicon-o-plus">محادثة جديدة</x-filament::button>
                    <label class="flex items-center gap-2 text-sm font-semibold text-warning-700 dark:text-warning-400">
                        <input type="checkbox" wire:model.live="teachMode" class="rounded">
                        وضع التعليم
                    </label>
                    @if ($teachMode)
                        <p class="text-xs text-gray-500">تحت كل رد هتلاقي "صحّح". أو اكتب <b>/علّم</b> وبعدها أي معلومة أو طريقة. الأسلوب بيتطبق على طول بعد ما يتجرب، وأي تعديل في الأسعار أو الشروط أو التعليمات بيستنى موافقتك. <a class="underline" href="{{ \App\Filament\Resources\BotLessonResource::getUrl() }}" target="_blank">كل الدروس</a></p>
                    @endif
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="showDebug" class="rounded">
                        اعرض اللي البوت عمله تحت كل رد
                    </label>
                </div>
                <p class="mt-3 text-xs text-gray-500">
                    كل محادثة جديدة = عميل جديد ما يعرفش البوت عنه حاجة. لو قدّمت طلب تقسيط كامل هنا هيتسجل كطلب تجريبي باسم "محاكي المحادثات".
                </p>
            </x-filament::section>

            <x-filament::section collapsible>
                <x-slot name="heading">محادثات سابقة</x-slot>
                <div class="space-y-1 text-sm">
                    @forelse ($this->simulatedConversations() as $conv)
                        <button type="button" wire:click="openConversation({{ $conv->id }})" @class([
                            'block w-full rounded px-2 py-1 text-right hover:bg-gray-100 dark:hover:bg-white/5',
                            'bg-primary-50 font-semibold dark:bg-primary-500/10' => $conv->id === $conversationId,
                        ])>
                            #{{ $conv->id }} · {{ $conv->created_at?->diffForHumans() }} · {{ $conv->messages_count }} رسالة
                        </button>
                    @empty
                        <p class="text-gray-500">لسه مفيش</p>
                    @endforelse
                </div>
            </x-filament::section>

            @php $snapshot = $this->getApplicationSnapshot(); @endphp
            @if ($snapshot)
                <x-filament::section collapsible collapsed>
                    <x-slot name="heading">طلب التقسيط (زي ما البوت شايفه)</x-slot>
                    <pre>{{ json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
