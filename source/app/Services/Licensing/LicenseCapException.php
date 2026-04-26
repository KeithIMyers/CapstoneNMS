<?php

namespace App\Services\Licensing;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Thrown when a User-create / role-change would push the install past
 * the license's headcount cap for a role (admins / editors / authors)
 * or the agents cap. Filament's resource pages catch this in their
 * action handlers and surface the message via a Notification; CLI /
 * API paths see a regular exception.
 *
 * Carries the bucket name, the cap, and the current count so error
 * UI can show "5 of 5 admin seats used".
 */
class LicenseCapException extends \RuntimeException
{
    public function __construct(
        public readonly string $bucket,
        public readonly int $cap,
        public readonly int $current,
        string $message = '',
    ) {
        parent::__construct($message ?: "License cap reached for {$bucket}: {$current}/{$cap}");
    }

    /**
     * Convert this to a Laravel ValidationException so Filament's
     * form-level error handling shows it inline instead of as a flat
     * 500. Hooks the message onto the field most likely to be
     * relevant ('role' for users, 'is_agent' for agents).
     */
    public function toValidationException(): ValidationException
    {
        $field = $this->bucket === 'agents' ? 'is_agent' : 'role';
        return ValidationException::withMessages([$field => $this->getMessage()]);
    }

    /** Surface as a Filament toast notification. */
    public function notify(): void
    {
        Notification::make()
            ->title('License limit reached')
            ->body($this->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }
}
