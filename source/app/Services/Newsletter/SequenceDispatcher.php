<?php

namespace App\Services\Newsletter;

use App\Models\EmailSequence;
use App\Models\EmailSequenceRun;
use App\Models\EmailSequenceStep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Drip-campaign engine. Two entry points:
 *
 *   start(event, email, userId?)
 *       Spawns runs for every active sequence listening for `event`.
 *       No-op when the recipient already has a run for that
 *       sequence (uniqueness via the (sequence_id, email) index).
 *
 *   sendDueSteps()
 *       Walks every active run, computes whether its next step is
 *       due based on delay_hours + last_sent_at (or started_at for
 *       step 0), and dispatches the matching email when so.
 *       Marks the run completed_at when it runs out of steps.
 *
 * The sender uses the same Mail::send pipeline as the daily brief
 * so existing Postmark/Mailgun analytics + headers apply.
 */
class SequenceDispatcher
{
    public function start(string $event, string $email, ?int $userId = null): void
    {
        $email = strtolower(trim($email));
        if ($email === '') return;

        foreach (EmailSequence::listeningFor($event) as $seq) {
            // First-step uniqueness via the unique index — duplicate
            // creates fail silently and stay idempotent on
            // re-entry from observers / event listeners.
            try {
                EmailSequenceRun::create([
                    'sequence_id'  => $seq->id,
                    'user_id'      => $userId,
                    'email'        => $email,
                    'current_step' => 0,
                    'started_at'   => Carbon::now(),
                ]);
            } catch (\Throwable $e) {
                // Most likely a duplicate-key violation; that's fine.
                if (! str_contains($e->getMessage(), 'Duplicate')) {
                    Log::warning('Sequence start failed', [
                        'sequence_id' => $seq->id,
                        'email'       => $email,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Walk active runs and dispatch any due step. Returns
     * ['sent' => int, 'completed' => int, 'failed' => int].
     */
    public function sendDueSteps(): array
    {
        $sent = 0; $completed = 0; $failed = 0;

        EmailSequenceRun::query()
            ->active()
            ->with('sequence.steps')
            ->orderBy('id')
            ->chunk(100, function ($runs) use (&$sent, &$completed, &$failed) {
                foreach ($runs as $run) {
                    try {
                        $step = $this->nextDueStep($run);
                        if ($step === null) continue;
                        if ($step === 'completed') {
                            $run->forceFill(['completed_at' => Carbon::now()])->save();
                            $completed++;
                            continue;
                        }

                        $this->sendStep($run, $step);
                        $run->forceFill([
                            'current_step' => $run->current_step + 1,
                            'last_sent_at' => Carbon::now(),
                        ])->save();
                        $sent++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('Sequence step send failed', [
                            'run_id' => $run->id,
                            'error'  => $e->getMessage(),
                        ]);
                    }
                }
            });

        return ['sent' => $sent, 'completed' => $completed, 'failed' => $failed];
    }

    /**
     * Decide what the run's next due step is. Returns:
     *   the EmailSequenceStep when one is due now,
     *   the literal string 'completed' when there are no more
     *     steps (caller marks the run completed),
     *   or null when the next step exists but isn't due yet.
     */
    private function nextDueStep(EmailSequenceRun $run): EmailSequenceStep|string|null
    {
        $steps = $run->sequence?->steps;
        if (! $steps || $steps->isEmpty()) return 'completed';

        $next = $steps->skip($run->current_step)->first();
        if (! $next) return 'completed';

        $reference = $run->last_sent_at ?: $run->started_at ?: $run->created_at;
        if (! $reference) return null;
        $dueAt = $reference->copy()->addHours((int) $next->delay_hours);
        if ($dueAt->isFuture()) return null;

        return $next;
    }

    private function sendStep(EmailSequenceRun $run, EmailSequenceStep $step): void
    {
        Mail::send('emails.sequence_step', [
            'subject'      => $step->subject,
            'bodyMarkdown' => $step->body_markdown,
            'recipientName' => $run->user?->name,
        ], function ($message) use ($run, $step) {
            $message->to($run->email)->subject($step->subject);
            // Mirror the daily-brief metadata so webhook receivers
            // can fold open/click events back into a useful surface.
            $h = $message->getSymfonyMessage()->getHeaders();
            $h->addTextHeader('X-PM-Metadata-sequence_run_id', (string) $run->id);
            $h->addTextHeader('X-PM-Metadata-sequence_step_id', (string) $step->id);
            $h->addTextHeader('v:sequence_run_id',  (string) $run->id);
            $h->addTextHeader('v:sequence_step_id', (string) $step->id);
        });
    }
}
