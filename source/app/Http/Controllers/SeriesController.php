<?php

namespace App\Http\Controllers;

use App\Models\ArticleSeries;

/**
 * Public landing page for an editorially-defined article series. Pulls
 * published articles attached to the series, ordered by sort_in_series
 * then publish date, and renders them as a chronological investigation
 * / explainer index.
 */
class SeriesController extends Controller
{
    public function show(string $slug)
    {
        $series = ArticleSeries::active()->where('slug', $slug)->firstOrFail();
        $articles = $series->articles()->published()->with(['category', 'user'])->get();
        return view('pages.series', compact('series', 'articles'));
    }
}
