<?php

namespace App\Providers;

use App\Models\News;
use App\Observers\NewsAutoClassifyObserver;
use App\Services\Ai\Tools\AddSourceTool;
use App\Services\Ai\Tools\AppendCorrectionTool;
use App\Services\Ai\Tools\AssignTopicTool;
use App\Services\Ai\Tools\CreateDraftTool;
use App\Services\Ai\Tools\FetchUrlTool;
use App\Services\Ai\Tools\FindSimilarArticlesTool;
use App\Services\Ai\Tools\ListMissingAltTextTool;
use App\Services\Ai\Tools\ListTopicsTool;
use App\Services\Ai\Tools\ReadArticleTool;
use App\Services\Ai\Tools\SearchArticlesTool;
use App\Services\Ai\Tools\SemanticSearchTool;
use App\Services\Ai\Tools\SetAltTextTool;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\TopRecentArticlesTool;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the agent tool registry. New tools land here; agents can then
 * opt in to specific tools through their tool_keys array. Keeping the
 * registry in a provider (rather than auto-discovered) makes the full
 * inventory grep-able from one place.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, function () {
            return new ToolRegistry([
                new SearchArticlesTool(),
                new ReadArticleTool(),
                new FetchUrlTool(),
                new CreateDraftTool(),
                new ListTopicsTool(),
                new AssignTopicTool(),
                new TopRecentArticlesTool(),
                new FindSimilarArticlesTool(),
                new AppendCorrectionTool(),
                new ListMissingAltTextTool(),
                new SetAltTextTool(),
                new SemanticSearchTool(),
                new AddSourceTool(),
            ]);
        });
    }

    public function boot(): void
    {
        // Auto-classify articles into topics when they transition to
        // published. Listener gates on the ai_auto_classify_enabled
        // setting and silently no-ops when AI isn't configured.
        News::observe(NewsAutoClassifyObserver::class);
    }
}
