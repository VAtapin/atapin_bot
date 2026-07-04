<section class="home-section public-section public-wrap">
    <div class="privacy-panel">
        @if($section->image_path)
            <img class="panel-image" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($section->image_path) }}" alt="{{ $translation->image_alt }}" loading="lazy" decoding="async">
        @endif
        <span class="privacy-panel__mark" aria-hidden="true">🔒</span>
        <h2>{{ $translation->title }}</h2>
        <div class="cms-content">{!! $translation->content !!}</div>
    </div>
</section>
