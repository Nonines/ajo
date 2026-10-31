<?php

namespace App\Console\Commands;

use App\Models\Staff;
use Database\Seeders\StaffPermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('staff:create-initial-admin {--name=} {--email=}')]
#[Description('Create the first staff administrator; manage subsequent staff in the admin panel')]
class CreateInitialAdmin extends Command
{
    public function handle(StaffPermissionSeeder $permissions): int
    {
        if (Staff::query()->exists()) {
            $this->error('Staff already exist. Use the admin panel to manage staff.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Run interactively so the password can be entered securely.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?: $this->ask('Name'),
            'email' => $this->option('email') ?: $this->ask('Email'),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:staff,email'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($data, $permissions): void {
            // Recheck inside the transaction to reject a competing bootstrap.
            abort_if(Staff::query()->exists(), 409, 'Staff already exist.');
            $permissions->run();
            $admin = Staff::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $admin->assignRole('admin');
        });

        $this->info('Initial administrator created. Sign in to /admin to configure mandatory two-factor authentication.');

        return self::SUCCESS;
    }
}
