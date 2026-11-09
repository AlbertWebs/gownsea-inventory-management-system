<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('gownsea:create-staff-user {email} {name} {--role=storekeeper} {--location=*}')]
#[Description('Create an authorized GOWNSEA staff account and optionally assign locations.')]
class CreateStaffUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $roles = ['super_admin', 'management', 'storekeeper', 'sales_crm', 'finance', 'production'];
        $role = (string) $this->option('role');

        if (! in_array($role, $roles, true)) {
            $this->error('Role must be one of: '.implode(', ', $roles));

            return self::FAILURE;
        }

        $password = $this->secret('Password (not echoed)');
        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Use a password with at least 12 characters.');

            return self::FAILURE;
        }

        if ($password !== $this->secret('Confirm password')) {
            $this->error('The passwords did not match.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $this->argument('email'))->exists()) {
            $this->error('A user with that email already exists.');

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => Hash::make($password),
            'role' => $role,
            'is_active' => true,
        ]);

        $codes = $this->option('location');
        $locations = Location::query()->whereIn('code', $codes)->get();
        if ($locations->count() !== count($codes)) {
            $user->delete();
            $this->error('One or more location codes do not exist.');

            return self::FAILURE;
        }

        foreach ($locations as $index => $location) {
            $user->locations()->attach($location->id, ['is_primary' => $index === 0]);
        }

        $this->info("Created {$user->email} with role {$user->role}.");

        return self::SUCCESS;
    }
}
