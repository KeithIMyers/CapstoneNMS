<?php

namespace App\Console\Commands;

use App\Services\Wire\WireIngestor;
use Illuminate\Console\Command;

/**
 * CRON-driven ingestion of every active wire source. Idempotent:
 * already-seen GUIDs are skipped, so running this every 15 minutes
 * just picks up whatever's new.
 *
 *   /usr/local/php83/bin/php /path/to/install/artisan wire:ingest
 */
class WireIngestCommand extends Command
{
    protected $signature = 'wire:ingest {--source= : Run only against one wire source by id}';
    protected $description = 'Pull active RSS / Atom wire sources and land new items as draft articles';

    public function handle(WireIngestor $ingestor): int
    {
        if ($id = $this->option('source')) {
            $source = \App\Models\WireSource::find((int) $id);
            if (! $source) {
                $this->error("No wire source with id {$id}");
                return self::FAILURE;
            }
            $stats = $ingestor->ingestOne($source);
            $this->info(sprintf('  %s · fetched=%d · created=%d · errors=%d',
                $source->name, $stats['fetched'], $stats['created'], $stats['errors']));
            return $stats['errors'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $allStats = $ingestor->ingestAll();
        if (empty($allStats)) {
            $this->info('No active wire sources configured.');
            return self::SUCCESS;
        }

        $errors = 0;
        foreach ($allStats as $row) {
            $this->line(sprintf('  %s · fetched=%d · created=%d · errors=%d',
                $row['name'], $row['fetched'], $row['created'], $row['errors']));
            $errors += $row['errors'];
        }
        $this->info(sprintf('Done. %d source(s) processed.', count($allStats)));
        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
