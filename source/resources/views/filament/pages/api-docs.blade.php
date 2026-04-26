<x-filament-panels::page>
    @php $base = $this->getBaseUrl(); @endphp

    <x-filament::section>
        <x-slot name="heading">Authentication</x-slot>
        <x-slot name="description">
            All endpoints require a bearer token. Create one on the
            <a href="{{ route('filament.admin.pages.api-tokens') }}" class="underline">API tokens</a> page
            and pass it in the <code>Authorization</code> header.
        </x-slot>

        <pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded overflow-x-auto">Authorization: Bearer YOUR_TOKEN_HERE
Accept: application/json</pre>

        <p class="text-sm mt-2">
            Tokens have abilities: <code>read</code>, <code>write</code>, <code>delete</code>. A token's
            capabilities are the intersection of its own abilities and the role of the user who created it
            (<code>admin</code> / <code>editor</code> / <code>author</code>). Authors can only edit their own drafts
            and cannot publish; editors can publish; admins have full access.
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">List articles</x-slot>
        <p class="text-sm"><code>GET {{ $base }}/api/articles</code></p>
        <p class="text-sm mt-2">Optional query parameters: <code>q</code>, <code>category</code> (slug), <code>status</code> (0 or 1), <code>per_page</code> (1-100).</p>
<pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded overflow-x-auto mt-3">curl -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  "{{ $base }}/api/articles?per_page=10"</pre>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Get a single article</x-slot>
        <p class="text-sm"><code>GET {{ $base }}/api/articles/{id}</code></p>
<pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded overflow-x-auto mt-3">curl -H "Authorization: Bearer $TOKEN" \
  "{{ $base }}/api/articles/42"</pre>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Create an article</x-slot>
        <p class="text-sm"><code>POST {{ $base }}/api/articles</code> — requires <code>write</code> ability</p>
<pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded overflow-x-auto mt-3">curl -X POST "{{ $base }}/api/articles" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "title": "My first API article",
    "excerpt": "A short blurb.",
    "content": "&lt;p&gt;Full HTML body.&lt;/p&gt;",
    "category_id": 1,
    "tags": ["news", "politics"],
    "status": 1,
    "is_featured": false
  }'</pre>
        <p class="text-sm mt-2">
            Notes: <code>content</code> is sanitized server-side (scripts, iframes, event handlers stripped).
            <code>slug</code> is generated from <code>title</code> if omitted. Authors always create drafts regardless of the <code>status</code> they send.
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Update an article</x-slot>
        <p class="text-sm"><code>PUT {{ $base }}/api/articles/{id}</code> — requires <code>write</code></p>
<pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded overflow-x-auto mt-3">curl -X PUT "{{ $base }}/api/articles/42" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Updated title","status":1}'</pre>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Delete an article</x-slot>
        <p class="text-sm"><code>DELETE {{ $base }}/api/articles/{id}</code> — requires <code>delete</code></p>
<pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded overflow-x-auto mt-3">curl -X DELETE "{{ $base }}/api/articles/42" \
  -H "Authorization: Bearer $TOKEN"</pre>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Taxonomies</x-slot>
        <p class="text-sm"><code>GET {{ $base }}/api/categories</code> — list published categories</p>
        <p class="text-sm"><code>GET {{ $base }}/api/tags</code> — list distinct tags in use</p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Errors</x-slot>
        <p class="text-sm">Standard Laravel JSON error envelope:</p>
<pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded overflow-x-auto mt-3">{"message": "...", "errors": {"field": ["validation message"]}}</pre>
        <p class="text-sm mt-2">
            <code>401</code> — missing or invalid token.
            <code>403</code> — token or role lacks the ability.
            <code>422</code> — validation failure.
            <code>429</code> — rate limit exceeded.
        </p>
    </x-filament::section>
</x-filament-panels::page>
