<?php

namespace App\Console\Commands;

use App\Models\News;
use Illuminate\Console\Command;

class PublishDueArticles extends Command
{
    protected $signature = 'news:publish-due';

    protected $description = 'Flip scheduled articles whose publish_at has arrived to "published", and unpublish articles whose unpublish_at has passed.';

    public function handle(): int
    {
        $publishedCount = 0;
        News::readyToPublish()->cursor()->each(function (News $article) use (&$publishedCount) {
            $article->editorial_status = News::STATUS_PUBLISHED;
            $article->save();
            $publishedCount++;
        });

        $unpublishedCount = 0;
        News::readyToUnpublish()->cursor()->each(function (News $article) use (&$unpublishedCount) {
            $article->editorial_status = News::STATUS_UNPUBLISHED;
            $article->save();
            $unpublishedCount++;
        });

        if ($publishedCount || $unpublishedCount) {
            $this->info("Published {$publishedCount}, unpublished {$unpublishedCount}.");
        }
        return self::SUCCESS;
    }
}
