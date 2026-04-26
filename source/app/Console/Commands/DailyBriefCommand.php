<?php

namespace App\Console\Commands;

use App\Services\Newsletter\DailyBriefSender;
use Illuminate\Console\Command;

/**
 * Generate today's brief and send it to confirmed subscribers in one
 * call. Designed to run from CRON; the editor preview workflow lives
 * in the Filament Daily brief page.
 *
 * On DreamHost shared hosting:
 *
 *   /usr/local/php83/bin/php /path/to/install/artisan newsletter:daily-brief >> /var/log/daily-brief.log 2>&1
 *
 * Schedule from the panel under Scheduled Tasks; once a day at 06:00 PT
 * is a reasonable starting point.
 */
class DailyBriefCommand extends Command
{
    protected $signature = 'newsletter:daily-brief
                            {--dry-run : Generate the brief but do not send}';

    protected $description = 'Compose and send the daily AI-written newsletter brief to confirmed subscribers';

    public function handle(DailyBriefSender $sender): int
    {
        $this->info('Generating daily brief…');

        if ($this->option('dry-run')) {
            $run = $sender->generate();
            $this->line(str_repeat('─', 72));
            $this->line($run->final_output ?? '(no output)');
            $this->line(str_repeat('─', 72));
            $this->info("Status: {$run->status} · run #{$run->id}");
            return $run->status === 'done' ? self::SUCCESS : self::FAILURE;
        }

        $result = $sender->generateAndSend();
        if ($result['status'] !== 'done') {
            $this->error('Generation failed: '.($result['error'] ?? 'unknown'));
            return self::FAILURE;
        }
        $this->info("Sent {$result['sent']} · failed {$result['failed']} · run #{$result['run_id']}");
        return self::SUCCESS;
    }
}
