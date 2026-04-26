@extends('layouts.site')

@section('head_title', '#'.$tag.' · '.getcong('site_name'))
@section('head_description', 'Articles tagged with #'.$tag)

@section('content')
@include('partials.site.article-list', [
    'items' => $news,
    'title' => '#'.$tag,
    'subtitle' => 'Articles tagged with #'.$tag,
    'emptyMessage' => 'No articles match this tag.',
])
@endsection
