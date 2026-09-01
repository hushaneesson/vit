<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Creates (or updates) the default admin user for local/dev setup and initial
 * production bootstrapping. Idempotent: re-running updates the existing user
 * matched by email instead of creating a duplicate.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'elink:create-user
        {--name=Admin : The admin user\'s name}
        {--email=admin@example.com : The admin user\'s email}
        {--password= : Password to set. Omit to generate a random one.}';

    protected $description = 'Create the default user for the admin panel';

    public function handle(): int
    {
        $name = (string) $this->option('name');
        $email = (string) $this->option('email');
        $password = $this->option('password') ?: Str::password(16);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', Password::min(8)],
            ]
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password],
        );

        $this->info("Admin user ready: {$user->email}");

        if (!$this->option('password')) {
            $this->warn("Generated password: {$password}");
            $this->warn('Store this password securely — it will not be shown again.');
        }

        return self::SUCCESS;
    }
}
