<section class="home-section public-section public-wrap">
    <h2>{{ $translation->title }}</h2>
    @if($translation->lead)<p class="section-lead">{{ $translation->lead }}</p>@endif
    <div class="home-faq-grid">
        @foreach($homepage->faqItems(4, (array) data_get($section->settings, 'faq_item_ids', [])) as $faqItem)
            <article class="home-faq-card">
                <h3>{{ $faqItem->question }}</h3>
                <div class="home-faq-card__answer">{{ \Illuminate\Support\Str::limit(strip_tags($faqItem->answer), 160) }}</div>
            </article>
        @endforeach
    </div>
    @if($url = $homepage->actionUrl($translation->primary_action, $translation->primary_url))
        <div class="section-actions"><a class="button secondary" href="{{ $url }}">{{ $translation->primary_label }}</a></div>
    @endif
</section>
