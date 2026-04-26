<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
{{-- Google News sitemap. Only articles published in the last 48 hours
     should appear here per Google's spec; the controller handles that. --}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
@foreach($news_list as $news_data)
   <url>
      <loc>{{ route('news.details', ['slug' => $news_data->slug]) }}</loc>
      <news:news>
         <news:publication>
            <news:name>{{ getcong('site_name') ?: config('app.name') }}</news:name>
            <news:language>{{ str_replace('_', '-', app()->getLocale()) }}</news:language>
         </news:publication>
         <news:publication_date>{{ optional($news_data->effectivePublishedAt())->toAtomString() }}</news:publication_date>
         <news:title>{{ strip_tags(stripslashes($news_data->title)) }}</news:title>
         @if(!empty($news_data->tags))
         <news:keywords>{{ $news_data->tags }}</news:keywords>
         @endif
      </news:news>
   </url>
@endforeach
</urlset>
