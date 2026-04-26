<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Privacy\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Hard-deletes accounts whose deletion_requested_at + GRACE_DAYS is
 * in the past. Schedule daily via app/Console/Kernel or
 * routes/console.php.
 */
class PurgeDeletedAccountsCommand extends Command
{
    protected $signature = 'accounts:purge {--dry-run : List candidates without deleting}';
    protected $description = 'Hard-delete accounts whose deletion grace window has lapsed.';

    public function handle(AccountDeletionService $deleter): int
    {
        $cutoff = Carbon::now()->subDays(AccountDeletionService::GRACE_DAYS);

        $candidates = User::query()
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<=', $cutoff)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No accounts past the grace window.');
            return self::SUCCESS;
        }

        $this->info("Found {$candidates->count()} accounts to purge (cutoff: {$cutoff->toDateTimeString()}).");

        if ($this->option('dry-run')) {
            foreach ($candidates as $u) {
                $this->line(" - id={$u->id} email={$u->email} requested_at={$u->deletion_requested_at}");
            }
            return self::SUCCESS;
        }

        $purged = 0;
        foreach ($candidates as $u) {
            try {
                $deleter->purge($u);
                $purged++;
                $this->line(" purged id={$u->id} email={$u->email}");
            } catch (\Throwable $e) {
                $this->error(" failed id={$u->id}: ".$e->getMessage());
            }
        }

        $this->info("Done — {$purged} purged.");
        return self::SUCCESS;
    }
}
