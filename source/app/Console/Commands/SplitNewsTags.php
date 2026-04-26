<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Models\Tag;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SplitNewsTags extends Command
{
    protected $signature = 'news:split-tags
                            {--dry-run : Show what would happen without writing}';

    protected $description = 'One-shot migration: split each news.tags CSV into the new tags + news_tag pivot. Idempotent — re-running only creates missing relationships.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $createdTags = 0;
        $linkedTags = 0;

        News::query()
            ->whereNotNull('tags')
            ->where('tags', '!=', '')
            ->cursor()
            ->each(function (News $article) use (&$createdTags, &$linkedTags, $dry) {
                $names = collect(explode(',', (string) $article->tags))
                    ->map(fn ($n) => trim($n))
                    ->filter(fn ($n) => $n !== '')
                    ->unique()
                    ->values();

                $tagIds = [];
                foreach ($names as $name) {
                    $slug = Str::slug($name);
                    $tag = Tag::where('slug', $slug)->first();
                    if (! $tag) {
                        $createdTags++;
                        if ($dry) continue;
                        $tag = Tag::findOrCreateByName($name);
                    }
                    $tagIds[] = $tag->id ?? null;
                }

                $tagIds = array_filter($tagIds);
                if (! $dry && ! empty($tagIds)) {
                    $existing = $article->tagsRelation()->pluck('tags.id')->toArray();
                    $toAttach = array_diff($tagIds, $existing);
                    if (! empty($toAttach)) {
                        $article->tagsRelation()->attach($toAttach);
                        $linkedTags += count($toAttach);
                    }
                }
            });

        $this->info($dry
            ? "[dry-run] Would create {$createdTags} tags, link to articles."
            : "Created {$createdTags} tags, linked {$linkedTags} relationships."
        );

        return self::SUCCESS;
    }
}
