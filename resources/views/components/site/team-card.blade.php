{{-- variant: team (team page) | grid (about page) --}}
@props(['member', 'variant' => 'team'])

@php
    $photo = $member->imageUrl()
        ? '<img src="' . e($member->imageUrl()) . '" alt="' . e($member->name) . '" loading="lazy">'
        : '<div class="site-team-initial">' . e(mb_substr(trim(preg_replace('/^(م|أ|د|كابتن)\.?\s+/u', '', $member->name)), 0, 1)) . '</div>';
@endphp

@if ($variant === 'grid')
    <div class="twm-team-grid-card">
        <div class="twm-team-grid-media">
            {!! $photo !!}
        </div>
        <div class="twm-team-grid-info">
            <h3 class="member-name">{{ $member->name }}</h3>
            <p class="member-role">{{ $member->role }}</p>
        </div>
    </div>
@else
    <div class="twm-team-section">
        <div class="twm-team-info">
            <h3 class="twm-title">{{ $member->name }}</h3>
            <div class="twm-s-title">{{ $member->role }}</div>
        </div>
        <div class="twm-team-media">
            {!! $photo !!}
        </div>
    </div>
@endif
