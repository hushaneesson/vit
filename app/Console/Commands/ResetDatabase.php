<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Wipes and reseeds the database. Gated by ALLOW_DB_RESET so this
 * can never run accidentally outside environments that explicitly opt in
 * (e.g. demo/staging), regardless of how it's scheduled or invoked.
 */
class ResetDatabase extends Command
{
    protected $signature = 'elink:reset-database {--force : Run even if ALLOW_DB_RESET is disabled}';

    protected $description = 'Reset the database to its seeded state (demo/staging environments only)';

    public function handle(): int
    {
        if (! config('catalog.allow_db_reset') && ! $this->option('force')) {
            $this->warn('Periodic database reset is disabled. Set ALLOW_DB_RESET=true to enable it, or pass --force.');

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        $this->info('Database reset complete.');

        return self::SUCCESS;
    }
}
