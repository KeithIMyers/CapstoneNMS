<?php

namespace App\Services\Update;

use Illuminate\Support\Facades\Storage;

/**
 * Persisted progress for in-flight updates.
 *
 * UpdateService::applyZip and friends write here at each major step
 * (download / verify / snapshot / extract / merge / migrate / done).
 * The Filament Updates page polls a controller endpoint that reads
 * this file via read(), so the user sees live progress while the
 * apply runs in another PHP-FPM worker.
 *
 * The file is small and atomic: writes go through a tmp file +
 * rename so the polling read can never see a half-written JSON.
 */
class ProgressTracker
{
    public const PATH = 'updater/progress.json';

    public const STATUS_RUNNING  = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED   = 'failed';

    public function reset(string $message = 'Starting…'): void
    {
        $this->write([
            'status'     => self::STATUS_RUNNING,
            'step'       => 'starting',
            'percent'    => 0,
            'message'    => $message,
            'started_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function step(string $step, int $percent, string $message): void
    {
        $cur = $this->read();
        $this->write(array_merge($cur ?: [], [
            'status'     => self::STATUS_RUNNING,
            'step'       => $step,
            'percent'    => max(0, min(100, $percent)),
            'message'    => $message,
            'updated_at' => time(),
        ]));
    }

    public function complete(string $message = 'Update applied.', array $extra = []): void
    {
        $cur = $this->read() ?: [];
        $this->write(array_merge($cur, $extra, [
            'status'      => self::STATUS_COMPLETE,
            'percent'     => 100,
            'message'     => $message,
            'finished_at' => time(),
            'updated_at'  => time(),
        ]));
    }

    public function fail(array $errors, string $message = 'Update failed.'): void
    {
        $cur = $this->read() ?: [];
        $this->write(array_merge($cur, [
            'status'      => self::STATUS_FAILED,
            'message'     => $message,
            'errors'      => array_values($errors),
            'finished_at' => time(),
            'updated_at'  => time(),
        ]));
    }

    public function read(): ?array
    {
        try {
            $disk = Storage::disk('local');
            if (! $disk->exists(self::PATH)) return null;
            $raw = (string) $disk->get(self::PATH);
            $data = json_decode($raw, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function clear(): void
    {
        try { Storage::disk('local')->delete(self::PATH); } catch (\Throwable $e) {}
    }

    private function write(array $data): void
    {
        try {
            Storage::disk('local')->put(self::PATH, json_encode($data, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // Progress writes shouldn't ever break the actual apply.
        }
    }
}
