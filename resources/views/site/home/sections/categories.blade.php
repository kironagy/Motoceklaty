<section class="categories" id="discover">
<div class="section-heading">
<h2>{{ site('home.design.categories.text_01') }} <em>{{ site('home.design.categories.text_02') }}</em> {{ site('home.design.categories.text_03') }}</h2>
<a class="button button-outline" href="{{ route('machines.index') }}">{{ site('home.design.categories.text_04') }}<img alt="" aria-hidden="true" class="icon bi bi-chevron-right" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-right.svg') }}" width="24"/></a>
</div>
<div class="categories-grid">@foreach (site('home.categories.items', []) as $item)<a class="category-card" href="{{ route('machines.index', ['category' => $item['category'] ?? null]) }}"><img src="{{ site_url($item['image'] ?? '') }}" alt="{{ $item['label'] ?? '' }}" loading="lazy"><h3>{{ $item['label'] ?? '' }}</h3><span class="button button-orange">استعرض الآن<img class="icon bi bi-chevron-right" src="{{ asset('motogate-v2/assets/images/icons/chevron-right.svg') }}" alt="" aria-hidden="true" width="24" height="24"></span></a>
@endforeach
</div>
</section>