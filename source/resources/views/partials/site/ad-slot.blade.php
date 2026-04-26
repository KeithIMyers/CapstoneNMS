{{-- Ad slot placeholder. Server output is just a container — the actual
     creative is fetched client-side on DOMContentLoaded so that cached HTML
     (CacheGuestResponses, 5 min TTL) doesn't freeze one ad for everyone.
     Expects: $placement (string). --}}
<div class="ad-slot ad-slot--{{ $placement }}"
     data-ad-placement="{{ $placement }}"
     aria-label="Advertisement"></div>
