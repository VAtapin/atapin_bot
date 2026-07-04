<section class="home-section public-section public-wrap">
    <div class="story-panel cms-content @if($section->image_path) story-panel--with-image @endif">
        @if($section->image_path)
            <img class="story-panel__image" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($section->image_path) }}" alt="{{ $translation->image_alt }}" loading="lazy" decoding="async">
        @endif
        {!! $translation->content !!}
    </div>
</section>
