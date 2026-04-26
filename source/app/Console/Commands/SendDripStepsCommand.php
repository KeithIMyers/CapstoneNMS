<?php

namespace App\Console\Commands;

use App\Services\Newsletter\SequenceDispatcher;
use Illuminate\Console\Command;

/**
 * Hourly drip-campaign worker. Walks every active
 * email_sequence_runs row, sends due steps, marks completed runs.
 * Scheduled in bootstrap/app.php at the top of every hour.
 */
class SendDripStepsCommand extends Command
{
    protected $signature = 'newsletter:drip-step';
    protected $description = 'Dispatch any due step in active email-sequence runs.';

    public function handle(SequenceDispatcher $dispatcher): int
    {
        $r = $dispatcher->sendDueSteps();
        $this->info(sprintf('drip: sent=%d completed=%d failed=%d', $r['sent'], $r['completed'], $r['failed']));
        return self::SUCCESS;
    }
}
