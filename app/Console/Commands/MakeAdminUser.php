<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

#[Signature('user:admin {name} {email} {password}')]
#[Description('Create or promote an admin user')]
class MakeAdminUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $validated = validator([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => $this->argument('password'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore(User::where('email', $this->argument('email'))->value('id'))],
            'password' => ['required', 'string', Password::defaults()],
        ])->validate();

        $user = User::updateOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
                'role' => UserRole::Admin,
            ]
        );

        $this->info("Admin user created: {$user->email}");

        return self::SUCCESS;
    }
}
