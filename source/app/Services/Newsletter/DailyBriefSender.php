<?php

namespace App\Services\Newsletter;

use App\Models\AgentRun;
use App\Models\Subscription;
use App\Services\Ai\Agent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Composes the daily brief once via the editorial.daily_brief agent
 * and fans it out to every confirmed newsletter subscriber.
 *
 * Two entry points share this code:
 *   - the Filament action under AI → Run daily brief (preview + send)
 *   - the newsletter:daily-brief Artisan command (CRON)
 *
 * The brief is generated synchronously and the final markdown is the
 * source of truth for both preview and send. We never call the agent
 * twice for one brief — the AgentRun id ties the two flows together.
 */
class DailyBriefSender
{
    public const AGENT_KEY = 'editorial.daily_brief';

    public function __construct(private readonly Agent $agent) {}

    /**
     * Generate the brief and return the AgentRun. Caller decides whether
     * to send or just preview.
     */
    public function generate(?int $userId = null): AgentRun
    {
        return $this->agent->run(
            agentKey: self::AGENT_KEY,
            input: 'Compose today\'s daily brief.',
            userId: $userId,
        );
    }

    /**
     * Send a previously-generated brief to every confirmed subscriber.
     * Returns ['sent' => int, 'failed' => int].
     */
    public function send(string $bodyMarkdown, ?string $subject = null, ?array $subjectVariants = null): array
    {
        $subject ??= sprintf(
            "%s · Today's brief",
            getcong('site_name') ?: config('app.name'),
        );

        $product = \App\Models\NewsletterProduct::default();

        // A/B subject-line testing. When the caller passes ≥2
        // variants, recipients get round-robin-bucketed across
        // them and each send row is stamped with the chosen index
        // so per-variant open rates can be reported via the
        // existing webhook flow.
        $variants = is_array($subjectVariants) && count($subjectVariants) >= 2
            ? array_values(array_filter(array_map('strval', $subjectVariants), fn ($s) => trim($s) !== ''))
            : null;
        if ($variants !== null && count($variants) < 2) $variants = null;

        // Create the issue row up front. Counters update as we walk
        // the recipient list; the analytics view reads from this row
        // until webhook events flesh out the per-recipient sends.
        $issue = \App\Models\NewsletterIssue::create([
            'product_id'         => $product?->id,
            'subject'            => mb_substr($subject, 0, 255),
            'subject_variants'   => $variants,
            'sender_email'       => getcong('site_email') ?: config('mail.from.address'),
            'body_summary'       => mb_substr(strip_tags($bodyMarkdown), 0, 600),
            'created_by_user_id' => auth()->id(),
            'queued_count'       => 0,
            'sent_count'         => 0,
            'failed_count'       => 0,
        ]);

        $providerName = (string) config('mail.default', 'smtp');
        $sent   = 0;
        $failed = 0;
        $rotation = 0;

        Subscription::query()
            ->active()
            ->when($product, fn ($q) => $q->where('product_id', $product->id))
            ->orderBy('id')
            ->chunk(200, function ($subs) use ($bodyMarkdown, $subject, $variants, $issue, $providerName, &$sent, &$failed, &$rotation) {
                foreach ($subs as $sub) {
                    // Pick the subject line. Single-subject issues
                    // use $subject; A/B issues round-robin through
                    // $variants and stamp the chosen index on the
                    // send row.
                    $variantIndex = null;
                    $useSubject   = $subject;
                    if ($variants !== null) {
                        $variantIndex = $rotation % count($variants);
                        $useSubject   = $variants[$variantIndex];
                    }
                    $rotation++;

                    // Allocate the send row up front so we have a stable
                    // id to embed in the message metadata. Mail-provider
                    // webhooks correlate back via this id.
                    $sendRow = \App\Models\NewsletterSend::create([
                        'issue_id'              => $issue->id,
                        'subscription_id'       => $sub->id,
                        'email'                 => $sub->email,
                        'provider'              => $providerName,
                        'status'                => 'queued',
                        'subject_variant_index' => $variantIndex,
                    ]);

                    try {
                        Mail::send('emails.daily-brief', [
                            'bodyMarkdown'   => $bodyMarkdown,
                            'subject'        => $useSubject,
                            'unsubscribeUrl' => url('/newsletter/unsubscribe/'.$sub->token),
                        ], function ($m) use ($sub, $useSubject, $sendRow) {
                            $m->to($sub->email)->subject($useSubject);

                            // Stamp the Symfony Mailer message with
                            // identifiers the receiver-side webhooks
                            // can decode. Postmark passes any header
                            // starting with "X-PM-Metadata-" through to
                            // its event payload's `Metadata` map;
                            // Mailgun does the same with `v:` prefixed
                            // headers (which it converts to
                            // user-variables on the event side).
                            $h = $m->getSymfonyMessage()->getHeaders();
                            $h->addTextHeader('X-PM-Metadata-newsletter_send_id', (string) $sendRow->id);
                            $h->addTextHeader('v:newsletter_send_id', (string) $sendRow->id);
                        });

                        $sendRow->forceFill([
                            'status'  => 'sent',
                            'sent_at' => now(),
                        ])->save();
                        $sent++;
                    } catch (\Throwable $e) {
                        $sendRow->forceFill([
                            'status'        => 'failed',
                            'error_message' => mb_substr($e->getMessage(), 0, 1000),
                        ])->save();
                        $failed++;
                        Log::warning('Daily brief send failed', [
                            'email' => $sub->email,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $issue->forceFill([
            'sent_count'   => $sent,
            'failed_count' => $failed,
            'queued_count' => $sent + $failed,
            'sent_at'      => now(),
        ])->save();

        return ['sent' => $sent, 'failed' => $failed, 'issue_id' => $issue->id];
    }

    /**
     * Convenience: generate + send in one call. Use for fully-automated
     * CRON dispatch where there's no human preview step.
     */
    public function generateAndSend(?int $userId = null): array
    {
        $run = $this->generate($userId);
        if ($run->status !== AgentRun::STATUS_DONE || empty($run->final_output)) {
            return [
                'run_id'  => $run->id,
                'status'  => $run->status,
                'sent'    => 0,
                'failed'  => 0,
                'error'   => $run->error_message,
            ];
        }
        $result = $this->send($run->final_output);
        return ['run_id' => $run->id, 'status' => 'done', ...$result, 'error' => null];
    }
}
