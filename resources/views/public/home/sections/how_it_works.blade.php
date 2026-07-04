<section class="home-section public-section public-wrap" id="how-it-works">
    <h2>{{ $translation->title }}</h2>
    @if($translation->lead)<p class="section-lead">{{ $translation->lead }}</p>@endif
    <div class="step-grid">
        @foreach($section->items as $item)
            @if($itemTranslation = $item->translation())
                <article class="step-card">
                    @if($item->image_path)
                        <img class="step-card__image" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item->image_path) }}" alt="{{ $itemTranslation->image_alt }}" loading="lazy" decoding="async">
                    @endif
                    <h3>{{ $itemTranslation->title }}</h3>
                    <p>{{ $itemTranslation->text }}</p>
                </article>
            @endif
        @endforeach
    </div>
</section>
