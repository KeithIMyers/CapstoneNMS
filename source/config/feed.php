<?php

// Feed titles default to the customer's site_name (admin-editable in
// Site Settings) and fall back to the env-configured app name. The
// product name "CapstoneNMS" deliberately doesn't appear in feeds —
// readers see the publication's own brand.
$siteName = function_exists('getcong')
    ? ((string) getcong('site_name') ?: (string) env('APP_NAME', 'CapstoneNMS'))
    : (string) env('APP_NAME', 'CapstoneNMS');
$siteDesc = function_exists('getcong')
    ? ((string) getcong('site_description') ?: 'Latest articles from '.$siteName)
    : 'Latest articles from '.$siteName;

return [
    'feeds' => [
        'main' => [
            'items' => [\App\Models\News::class, 'getFeedItems'],
            'url' => '/feed',
            'title' => $siteName,
            'description' => $siteDesc,
            'language' => 'en-US',
            'image' => '',
            'format' => 'atom',
            'view' => 'feed::atom',
            'type' => '',
            'contentType' => '',
        ],

        'rss' => [
            'items' => [\App\Models\News::class, 'getFeedItems'],
            'url' => '/feed.xml',
            'title' => $siteName,
            'description' => $siteDesc,
            'language' => 'en-US',
            'image' => '',
            'format' => 'rss',
            'view' => 'feed::rss',
            'type' => '',
            'contentType' => '',
        ],

        'json' => [
            'items' => [\App\Models\News::class, 'getFeedItems'],
            'url' => '/feed.json',
            'title' => $siteName,
            'description' => $siteDesc,
            'language' => 'en-US',
            'image' => '',
            'format' => 'json',
            'view' => 'feed::json',
            'type' => '',
            'contentType' => '',
        ],
    ],
];
