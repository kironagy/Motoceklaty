<x-filament-widgets::widget>
    <style>
        .rbt { direction: rtl; display: grid; gap: 20px; }
        .rbt-type h3 { font-size: 20px; font-weight: 900; margin: 0 0 12px; color: #0f172a; display: flex; justify-content: space-between; align-items: center; }
        .rbt-type h3 span { font-size: 14px; font-weight: 800; color: #4311A5; }
        .rbt-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 12px; }
        .rbt-card { display: block; border-radius: 18px; padding: 14px 12px; background: linear-gradient(145deg, #ffffff, #f1f5f9); border: 1px solid rgba(148, 163, 184, .28); box-shadow: 0 10px 25px rgba(15, 23, 42, .08); border-top: 6px solid var(--c); transition: .2s ease; text-decoration: none; }
        .rbt-card:hover { transform: translateY(-3px); box-shadow: 0 18px 40px rgba(67, 17, 165, .16); }
        .rbt-card .n { font-size: 32px; font-weight: 900; color: #0f172a; line-height: 1.1; }
        .rbt-card .l { font-size: 14px; font-weight: 800; color: var(--c); margin-top: 6px; }
        .dark .rbt-type h3 { color: #fff; }
        .dark .rbt-type h3 span { color: #93c5fd; }
        .dark .rbt-card { background: linear-gradient(145deg, #101827, #1C293E); border-color: rgba(148, 163, 184, .18); }
        .dark .rbt-card .n { color: #fff; }
        @media (max-width: 1280px) { .rbt-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        @media (max-width: 640px) { .rbt-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>

    <div class="rbt">
        @foreach ($types as $type)
            <div class="rbt-type">
                <h3>{{ $type['label'] }} <span>الإجمالي: {{ number_format($type['total']) }}</span></h3>
                <div class="rbt-grid">
                    @foreach ($type['rows'] as $row)
                        <a class="rbt-card" style="--c: {{ $row['color'] }}" href="{{ $row['url'] }}">
                            <div class="n">{{ number_format($row['count']) }}</div>
                            <div class="l">{{ $row['label'] }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
