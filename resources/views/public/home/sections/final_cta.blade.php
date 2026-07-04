<section class="home-section public-section public-wrap">
    <div class="final-cta">
        @if($section->image_path)
            <img class="panel-image" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($section->image_path) }}" alt="{{ $translation->image_alt }}" loading="lazy" decoding="async">
        @endif
        <h2>{{ $translation->title }}</h2>
        <div class="cms-content">{!! $translation->content !!}</div>
        <div class="hero-actions">
            @if(($url = $homepage->actionUrl($translation->primary_action, $translation->primary_url)) && ($translation->primary_action !== 'register' || $registrationEnabled))
                <a class="button" data-analytics-click="cta_register_click" href="{{ $url }}">{{ $translation->primary_label }}</a>
            @endif
            @if($url = $homepage->actionUrl($translation->secondary_action, $translation->secondary_url))
                <a class="button secondary" href="{{ $url }}">{{ $translation->secondary_label }}</a>
            @endif
        </div>
    </div>
</section>
