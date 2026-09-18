<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class StartQueueWorkers extends Command
{
    protected $signature = 'queue:start-all
        {--timeout-imports=1800 : Timeout for imports queue in seconds}
        {--timeout-exports=1800 : Timeout for exports queue in seconds}
        {--timeout-notifications=60 : Timeout for notifications queue in seconds}
        {--tries-imports=1 : Max tries for imports queue}
        {--tries-exports=1 : Max tries for exports queue}
        {--tries-notifications=3 : Max tries for notifications queue}';

    protected $description = 'Start all queue workers (imports, exports, notifications) simultaneously';

    private array $processes = [];

    public function handle(): int
    {
        $this->info('Starting all queue workers...');
        $this->newLine();

        $workers = [
            'imports' => [
                'timeout' => (int) $this->option('timeout-imports'),
                'tries' => (int) $this->option('tries-imports'),
            ],
            'exports' => [
                'timeout' => (int) $this->option('timeout-exports'),
                'tries' => (int) $this->option('tries-exports'),
            ],
            'notifications' => [
                'timeout' => (int) $this->option('timeout-notifications'),
                'tries' => (int) $this->option('tries-notifications'),
            ],
        ];

        // Register signal handling for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'shutdown']);
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
        }

        foreach ($workers as $queue => $config) {
            $this->startWorker($queue, $config['timeout'], $config['tries']);
        }

        $this->info('All queue workers started. Press Ctrl+C to stop.');
        $this->newLine();

        // Monitor processes and keep running
        $this->monitorProcesses();

        return self::SUCCESS;
    }

    private function startWorker(string $queue, int $timeout, int $tries): void
    {
        $command = [
            'php',
            'artisan',
            'queue:work',
            'database',
            "--queue={$queue}",
            "--timeout={$timeout}",
            "--tries={$tries}",
        ];

        $process = new Process($command);
        $process->setTimeout(null);
        $process->start();

        $this->processes[$queue] = $process;

        $this->line("  <fg=green>✓</> Worker started for <fg=yellow>{$queue}</> queue (timeout: {$timeout}s, tries: {$tries})");
    }

    private function monitorProcesses(): void
    {
        while (true) {
            foreach ($this->processes as $queue => $process) {
                if (!$process->isRunning()) {
                    $exitCode = $process->getExitCode();
                    $this->newLine();
                    $this->error("Worker for '{$queue}' queue stopped unexpectedly (exit code: {$exitCode})");

                    // Restart the worker
                    $this->line("Restarting worker for '{$queue}' queue...");
                    $config = match ($queue) {
                        'imports' => ['timeout' => (int) $this->option('timeout-imports'), 'tries' => (int) $this->option('tries-imports')],
                        'exports' => ['timeout' => (int) $this->option('timeout-exports'), 'tries' => (int) $this->option('tries-exports')],
                        'notifications' => ['timeout' => (int) $this->option('timeout-notifications'), 'tries' => (int) $this->option('tries-notifications')],
                    };
                    $this->startWorker($queue, $config['timeout'], $config['tries']);
                }

                // Output any new output from the process
                $output = $process->getIncrementalOutput();
                $errorOutput = $process->getIncrementalErrorOutput();

                if ($output) {
                    $this->line("<fg=cyan>[{$queue}]</> " . trim($output));
                }
                if ($errorOutput) {
                    $this->line("<fg=red>[{$queue}]</> " . trim($errorOutput));
                }
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            usleep(100000); // 100ms
        }
    }

    public function shutdown(): void
    {
        $this->newLine();
        $this->info('Shutting down all queue workers...');

        foreach ($this->processes as $queue => $process) {
            if ($process->isRunning()) {
                $this->line("  Stopping worker for '{$queue}' queue...");
                $process->stop(5);
            }
        }

        $this->info('All queue workers stopped.');
        exit(0);
    }
}