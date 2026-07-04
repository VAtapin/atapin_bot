@php
    $primaryUrl = $homepage->actionUrl($translation->primary_action, $translation->primary_url);
    $secondaryUrl = $homepage->actionUrl($translation->secondary_action, $translation->secondary_url);
    $imageUrl = $section->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($section->image_path) : null;
@endphp
<section class="home-section home-section--hero public-wrap">
    <div class="hero-copy">
        @if($translation->eyebrow)<p class="eyebrow">🌿 {{ $translation->eyebrow }}</p>@endif
        <h1>{{ $translation->title }}</h1>
        @if($translation->lead)<p class="public-hero__lead">{{ $translation->lead }}</p>@endif
        <div class="hero-actions">
            @if($primaryUrl && $translation->primary_label && ($translation->primary_action !== 'register' || $registrationEnabled))
                <a class="button" data-analytics-click="cta_register_click" href="{{ $primaryUrl }}">{{ $translation->primary_label }}</a>
            @endif
            @if($secondaryUrl && $translation->secondary_label)
                <a class="button secondary" href="{{ $secondaryUrl }}">{{ $translation->secondary_label }}</a>
            @endif
        </div>
        @if($section->items->isNotEmpty())
            <div class="trust-row" aria-label="{{ __('public.home.trust_label') }}">
                @foreach($section->items as $item)
                    @if($itemTranslation = $item->translation())
                        <span>{{ $itemTranslation->title }}</span>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
    <div class="hero-visual" aria-hidden="{{ $imageUrl ? 'false' : 'true' }}">
        @if($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $translation->image_alt }}" width="640" height="480" fetchpriority="high">
        @else
            <div class="archive-collage">
                <div class="archive-photo archive-photo--old"><span>1908</span></div>
                <div class="archive-tree-mark">🌳</div>
                <div class="archive-photo archive-photo--new"><span>{{ date('Y') }}</span></div>
                <div class="archive-line"></div>
                <p>{{ __('public.home.visual_caption') }}</p>
            </div>
        @endif
    </div>
</section>
