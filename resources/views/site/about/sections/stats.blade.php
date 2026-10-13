@php($stats = site('about.stats.items', []))
<div class="section-full p-t80 p-b80 site-bg-white">
    <div class="container">
        <div class="twm-about-stats-bar" dir="rtl">
            <div class="row justify-content-center">
                @foreach ($stats as $stat)
                    <div class="col-lg-{{ max(2, intdiv(12, max(1, count($stats)))) }} col-md-6 col-6 m-b20">
                        <div class="twm-stat-item">
                            <div class="twm-stat-number"><span class="counter">{{ $stat['number'] ?? '' }}</span>{{ $stat['suffix'] ?? '' }}</div>
                            <h4 class="twm-stat-label">{{ $stat['label'] ?? '' }}</h4>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
