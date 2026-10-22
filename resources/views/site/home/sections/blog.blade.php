<section aria-labelledby="journalTitle" class="journal" id="articles">
<div class="journal-heading">
<p>{{ site('home.design.journal.text_01') }} <span dir="ltr">{{ site('home.design.journal.text_02') }}</span></p>
<h2 id="journalTitle">{{ site('home.design.journal.text_03') }} <em>{{ site('home.design.journal.text_04') }}</em></h2>
<p class="journal-subtitle">{{ site('home.design.journal.text_05') }}</p>
<a class="button button-outline" href="{{ route('blog.index') }}">{{ site('home.design.journal.text_06') }}<img alt="" aria-hidden="true" class="icon" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-left.svg') }}" width="24"/>
</a>
</div>
<img alt="" aria-hidden="true" class="journal-decoration" loading="lazy" src="{{ asset('motogate-v2/assets/images/blog/decoration.webp') }}"/>
<div aria-label="مقالات مجلة موتوجيت" aria-roledescription="عرض شرائح" class="journal-carousel" data-carousel="journal" role="region" tabindex="0">
<div class="journal-slides">@foreach ($posts->chunk(3) as $postGroup)<div class="journal-slide {{ $loop->first ? 'is-active' : '' }}" data-slide aria-hidden="{{ $loop->first ? 'false' : 'true' }}">@foreach ($postGroup as $post)<a class="journal-card {{ $loop->first ? 'journal-featured' : 'journal-small' }}" href="{{ route('blog.show', $post) }}"><img alt="{{ $post->title }}" class="journal-photo" loading="lazy" src="{{ $post->coverUrl() }}"/>
<div class="journal-copy">
<span class="journal-tag">{{ ($post->published_at ?? $post->created_at)->format('Y/m/d') }}</span>
<h3>{{ $post->title }}</h3>
<p>{{ $post->excerptText() }}</p>
<span class="journal-read"><span><img alt="" aria-hidden="true" class="icon" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-left.svg') }}" width="24"/></span>اقرأ المقال</span>
</div></a>
@endforeach
</div>
@endforeach
</div>
<div class="carousel-controls journal-controls">
<button aria-label="المقالات السابقة" data-prev="" type="button">
<img alt="" aria-hidden="true" class="icon" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-left.svg') }}" width="24"/>
</button>
<div class="carousel-dots">@foreach ($posts->chunk(3) as $postGroup)<button type="button" data-dot="{{ $loop->index }}" aria-label="عرض مجموعة المقالات {{ $loop->iteration }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}"></button>@endforeach</div>
<button aria-label="المقالات التالية" data-next="" type="button">
<img alt="" aria-hidden="true" class="icon" height="24" src="{{ asset('motogate-v2/assets/images/icons/chevron-left.svg') }}" width="24"/></button><button aria-label="إيقاف التبديل التلقائي" aria-pressed="false" data-pause="" type="button">
<svg aria-hidden="true" height="15" viewBox="0 0 20 20" width="15">
<path d="M6 4V16M14 4V16" stroke="currentColor" stroke-width="2"></path>
</svg>
</button>
</div>
<span aria-live="polite" class="visually-hidden" data-carousel-status=""></span>
</div>
</section>