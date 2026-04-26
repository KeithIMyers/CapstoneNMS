@extends('layouts.site')

@section('head_title', $cat_info->name.' · '.getcong('site_name'))
@section('head_description', $cat_info->description ?: ('Latest articles in '.$cat_info->name))

@section('content')
@include('partials.site.article-list', [
    'items' => $news,
    'title' => $cat_info->name,
    'subtitle' => $cat_info->description ?? null,
    'emptyMessage' => 'No articles in this section yet.',
])

<div class="container">
    @include('partials.site.trending')
</div>
@endsection
