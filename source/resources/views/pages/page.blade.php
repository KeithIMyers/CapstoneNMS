@extends('layouts.site')

@section('head_title', stripslashes($page_info->page_title).' · '.getcong('site_name'))
@section('head_description', \Illuminate\Support\Str::limit(strip_tags(stripslashes($page_info->page_content)), 160))

@section('content')
<article class="container-prose article">
    <header class="article__header">
        <h1 class="article__title" style="font-size: var(--t-4xl);">{{ stripslashes($page_info->page_title) }}</h1>
    </header>
    <div class="prose">
        {!! sanitize_rich_html(stripslashes($page_info->page_content)) !!}
    </div>
</article>
@endsection
