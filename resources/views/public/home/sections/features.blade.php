<section class="home-section public-section public-wrap">
    <h2>{{ $translation->title }}</h2>
    @if($translation->lead)<p class="section-lead">{{ $translation->lead }}</p>@endif
    <div class="feature-grid">
        @foreach($section->items as $item)
            @if($itemTranslation = $item->translation())
                <article class="feature-card">
                    @if($item->image_path)
                        <img class="feature-card__image" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item->image_path) }}" alt="{{ $itemTranslation->image_alt }}" loading="lazy" decoding="async">
                    @endif
                    @if($item->icon)<span class="feature-card__icon" aria-hidden="true">{{ $item->icon }}</span>@endif
                    <h3>{{ $itemTranslation->title }}</h3>
                    <p>{{ $itemTranslation->text }}</p>
                </article>
            @endif
        @endforeach
    </div>
</section>
