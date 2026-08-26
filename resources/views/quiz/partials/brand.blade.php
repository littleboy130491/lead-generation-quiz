@php
    $brandLabel = trim((string) ($branding->site_name ?: 'Quiz'));
    $brandInitial = mb_strtoupper(mb_substr($brandLabel, 0, 1));
@endphp
<div class="quiz-brand">
    @if ($branding->logo_url)
        <img src="{{ $branding->logo_url }}" alt="{{ $brandLabel }}" class="quiz-logo">
    @else
        <span class="quiz-brand-mark" aria-hidden="true">{{ $brandInitial }}</span>
    @endif
    <div class="quiz-brand-copy">
        <p class="quiz-brand-name">{{ $brandLabel }}</p>
        @if (filled($branding->eyebrow))
            <p class="quiz-brand-eyebrow">{{ $branding->eyebrow }}</p>
        @endif
    </div>
</div>
