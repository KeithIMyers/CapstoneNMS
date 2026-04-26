{{-- Reusable list view for category/tag/search results.
     Expects: $items (paginator), $title, optional $subtitle. --}}
<div class="container">
    <div class="page-intro">
        <h1>{{ $title }}</h1>
        @if (!empty($subtitle))
            <p>{{ $subtitle }}</p>
        @endif
    </div>

    @if ($items->count())
        <div class="grid-cards">
            @foreach ($items as $item)
                @include('partials.site.card', ['item' => $item])
            @endforeach
        </div>
        @if (method_exists($items, 'links'))
            <div>{{ $items->links('partials.site.pagination') }}</div>
        @endif
    @else
        <p class="text-muted" style="padding: 2rem 0;">{{ $emptyMessage ?? 'No articles found.' }}</p>
    @endif
</div>
