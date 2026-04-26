<?php

use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\TaxonomyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('me', fn () => request()->user()->only(['id', 'name', 'email', 'role']))
        ->name('api.me');

    // Articles — abilities are checked inside the controller based on
    // the authenticated user's role and the token's abilities.
    Route::get('articles', [ArticleController::class, 'index'])->name('api.articles.index');
    Route::post('articles', [ArticleController::class, 'store'])->name('api.articles.store');
    Route::get('articles/{article}', [ArticleController::class, 'show'])->name('api.articles.show');
    Route::put('articles/{article}', [ArticleController::class, 'update'])->name('api.articles.update');
    Route::patch('articles/{article}', [ArticleController::class, 'update']);
    Route::delete('articles/{article}', [ArticleController::class, 'destroy'])->name('api.articles.destroy');

    // Read-only taxonomies for API clients.
    Route::get('categories', [TaxonomyController::class, 'categories'])->name('api.categories.index');
    Route::get('tags', [TaxonomyController::class, 'tags'])->name('api.tags.index');
});
