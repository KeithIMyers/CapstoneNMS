<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The User model's $fillable list intentionally excludes role,
     * status, and the AI quota columns so a future controller can't
     * mass-assign them from request input. Filament's admin user form
     * is a trusted boundary (UserResource::canViewAny is admin-only),
     * so we bypass the guard here via forceFill / save().
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = new User;
        $user->forceFill($data)->save();
        return $user;
    }
}
