<section class="home-section public-section public-wrap">
    @if($translation->title)<h2>{{ $translation->title }}</h2>@endif
    @if($translation->lead)<p class="section-lead">{{ $translation->lead }}</p>@endif
    <div class="rich-home-block rich-home-block--{{ $section->image_position }}">
        @if($section->image_path)
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($section->image_path) }}" alt="{{ $translation->image_alt }}" loading="lazy" decoding="async">
        @endif
        <div class="cms-content">{!! $translation->content !!}</div>
    </div>
</section>
