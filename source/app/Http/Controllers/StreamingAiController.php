<?php

namespace App\Http\Controllers;

use App\Models\AiPrompt;
use App\Models\News;
use App\Services\Ai\AiClient;
use App\Services\Ai\Assistant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-Sent Events endpoints for in-editor assistants. Each route
 * runs an admin-authenticated streaming completion against an article
 * and emits text deltas as data: lines so a browser EventSource can
 * paint the result progressively.
 *
 * The protocol is intentionally minimal:
 *   data: "<JSON-encoded text chunk>"\n\n   ... repeat until done ...
 *   event: end\ndata: {}\n\n                ... terminator ...
 *   event: error\ndata: "<message>"\n\n     ... only on failure ...
 *
 * JSON-encoding the chunk keeps newlines and other control chars from
 * confusing the SSE parser. The browser-side handler JSON.parses each
 * data field before appending.
 */
class StreamingAiController extends Controller
{
    /**
     * Stream a copyedited version of the article's body. The editor
     * watches text appear in real time and clicks Apply to commit.
     */
    public function copyedit(Request $request, News $news): StreamedResponse
    {
        // Editor permission: mirror NewsResource::canEdit — Editors+ can
        // copyedit any article; Authors can copyedit only their own.
        $u = auth()->user();
        if (! $u || ! $u->isAuthor()) abort(403);
        if (! $u->isEditor() && (int) $news->user_id !== (int) $u->id) abort(403);

        $body = (string) $news->content;
        $bodyText = trim(strip_tags($body));
        if ($bodyText === '') {
            return $this->errorStream('Article has no body to copyedit.');
        }

        // Resolve the article.copyedit prompt the same way the sync
        // assistant does — keeps voice / temperature / model overrides
        // in sync with the rest of the assistant surface.
        $prompt = AiPrompt::forKey(
            Assistant::KEY_COPYEDIT,
            Assistant::DEFAULT_PROMPTS[Assistant::KEY_COPYEDIT],
        );

        $messages = [
            ['role' => 'system', 'content' => $prompt->system_prompt],
            ['role' => 'user',   'content' => $body],
        ];

        $options = [
            'temperature' => $prompt->temperature,
            'max_tokens'  => max($prompt->max_tokens, 4096),
        ];
        if ($prompt->model_override) {
            $options['model'] = $prompt->model_override;
        }

        $provider = $prompt->provider_id
            ? \App\Models\AiProvider::find($prompt->provider_id)
            : null;

        // Capture the authoring user so the streaming generator can
        // re-check that their session is still authenticated on every
        // chunk — a logout / role downgrade / banned flag should
        // interrupt the stream rather than burn through the editor's
        // AI budget after the privilege is gone.
        $userId = (int) $u->id;

        return new StreamedResponse(function () use ($messages, $options, $provider, $userId, $news) {
            // Make the chunks reach the browser immediately. PHP's
            // output buffering layers — and any nginx fastcgi_buffer in
            // front — will otherwise hold the response until the script
            // finishes.
            @ini_set('output_buffering', '0');
            @ini_set('zlib.output_compression', '0');
            ob_implicit_flush(true);
            while (ob_get_level() > 0) ob_end_flush();

            try {
                foreach (app(AiClient::class)->stream(
                    messages: $messages,
                    provider: $provider,
                    purpose: Assistant::KEY_COPYEDIT,
                    options: $options,
                ) as $delta) {
                    // Per-chunk auth recheck: bail if the user logged
                    // out, was banned, lost role, or had their AI
                    // budget exhausted while the stream was open.
                    $live = \App\Models\User::find($userId);
                    if (! $live || ! $live->canLogIn() || ! $live->isAuthor()
                        || (! $live->isEditor() && (int) $news->user_id !== (int) $live->id)) {
                        echo 'event: error'."\n".'data: '.json_encode('Session ended; sign in again to resume.')."\n\n";
                        @flush();
                        return;
                    }
                    if ($live->aiBlockReason()) {
                        echo 'event: error'."\n".'data: '.json_encode($live->aiBlockReason())."\n\n";
                        @flush();
                        return;
                    }

                    echo 'data: '.json_encode($delta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                    @flush();
                    // If the client disconnected, stop spending tokens.
                    if (connection_aborted()) {
                        return;
                    }
                }
                echo "event: end\ndata: {}\n\n";
                @flush();
            } catch (\Throwable $e) {
                echo 'event: error'."\n".'data: '.json_encode(\Illuminate\Support\Str::limit($e->getMessage(), 600))."\n\n";
                @flush();
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no', // disable nginx buffering when proxied
        ]);
    }

    /**
     * Apply a previously-streamed copyedit. The editor reviews the
     * output in the modal, clicks Apply, and the modal POSTs the
     * accumulated text back here. We treat this as the source of
     * truth and overwrite content directly — original is in the
     * activitylog from the existing News model trait.
     */
    public function applyCopyedit(Request $request, News $news)
    {
        $u = auth()->user();
        if (! $u || ! $u->isAuthor()) abort(403);
        if (! $u->isEditor() && (int) $news->user_id !== (int) $u->id) abort(403);

        $request->validate(['body' => 'required|string|max:200000']);

        $news->forceFill(['content' => trim((string) $request->input('body'))])->save();

        return redirect(\App\Filament\Resources\News\NewsResource::getUrl('edit', ['record' => $news->id]))
            ->with('flash_message', 'Streamed copyedit applied to article body.');
    }

    /** Open + immediately close an SSE response carrying a single error. */
    private function errorStream(string $message): StreamedResponse
    {
        return new StreamedResponse(function () use ($message) {
            ob_implicit_flush(true);
            while (ob_get_level() > 0) ob_end_flush();
            echo 'event: error'."\n".'data: '.json_encode($message)."\n\n";
            @flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
