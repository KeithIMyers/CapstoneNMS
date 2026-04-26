<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class MakeAdminCommand extends Command
{
    protected $signature = 'make:admin
                            {email? : Admin email address}
                            {name? : Admin display name}';

    protected $description = 'Create a new admin user interactively (replaces the deleted default admin@admin.com seeder).';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email');
        $name = $this->argument('name') ?: $this->ask('Name');
        $password = $this->secret('Password (min 12 chars)');
        $confirm = $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');
            return self::FAILURE;
        }

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => 'required|email|unique:users,email',
            'name' => 'required|string|min:2|max:100',
            'password' => 'required|string|min:12',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        // forceCreate bypasses the User model's safe-by-default $fillable
        // list — `role` and `status` are intentionally unguardable from
        // request input and only set here at the CLI trust boundary.
        User::forceCreate([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'status' => 1,
        ]);

        $this->info("Admin user {$email} created.");
        return self::SUCCESS;
    }
}
