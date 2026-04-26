<?php

namespace App\Http\Controllers;

use App\Models\PodcastEpisode;
use App\Models\PodcastShow;
use Illuminate\Http\Response;

class PodcastController extends Controller
{
    /** Index: all active shows. */
    public function index()
    {
        $shows = PodcastShow::active()->orderBy('title')->get();
        return view('pages.podcasts.index', compact('shows'));
    }

    /** Single show + its published episodes. */
    public function show(string $slug)
    {
        $show = PodcastShow::active()->where('slug', $slug)->firstOrFail();
        $episodes = $show->publishedEpisodes()->paginate(20);
        return view('pages.podcasts.show', compact('show', 'episodes'));
    }

    /** Single episode page with the inline audio player. */
    public function episode(string $showSlug, string $episodeSlug)
    {
        $show = PodcastShow::active()->where('slug', $showSlug)->firstOrFail();
        $episode = $show->episodes()->where('slug', $episodeSlug)->firstOrFail();

        if (! $episode->published_at || $episode->published_at->isFuture()) {
            abort(404);
        }

        return view('pages.podcasts.episode', compact('show', 'episode'));
    }

    /**
     * iTunes / Apple Podcasts compatible RSS 2.0 feed. Served with the
     * appropriate content-type. Apple and Spotify both consume this format.
     */
    public function feed(string $slug): Response
    {
        $show = PodcastShow::active()->where('slug', $slug)->firstOrFail();
        $episodes = $show->publishedEpisodes()->limit(200)->get();

        $xml = view('feeds.podcast', compact('show', 'episodes'))->render();

        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
        ]);
    }
}
