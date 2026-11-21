<?php

namespace App\Console\Commands;

use App\Models\WhatsappBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Owner 2026-11-22: every active bot gets its own worker (whatsapp:process-jobs --bot=ID), so a
 * number with a big backlog never holds up another number's customers. A bot switched on or added
 * gets its worker within seconds; a bot switched off or deleted stops its worker between turns
 * (the worker checks that itself). Runs under pm2 as "whatsapp-queue".
 */
class SuperviseWhatsappWorkers extends Command
{
    protected $signature = 'whatsapp:supervise-jobs {--check=10 : seconds between looking at the bot list}';

    protected $description = 'Keep one WhatsApp reply worker running for each active bot';

    /** @var array<int, Process> bot id => its worker */
    private array $workers = [];

    /** @var array<int, int> bot id => when it may be started again after exiting */
    private array $retryAt = [];

    private bool $stopping = false;

    /** @var resource|null */
    private $lockHandle = null;

    public function handle(): int
    {
        $this->lockHandle = fopen(storage_path('app/whatsapp-supervise-jobs.lock'), 'c');
        if (! $this->lockHandle || ! flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            $this->error('whatsapp:supervise-jobs is already running. Exiting.');

            return self::FAILURE;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () {
                    $this->stopping = true;
                });
            }
        }

        $this->info('WhatsApp worker supervisor started');
        $nextCheck = 0;

        while (! $this->stopping) {
            if (time() >= $nextCheck) {
                $nextCheck = time() + max(2, (int) $this->option('check'));
                $this->reconcile();
            }
            foreach ($this->workers as $process) {
                // reading the pipes keeps the worker from blocking on a full output buffer
                $this->forward($process);
            }
            sleep(1);
        }

        foreach ($this->workers as $process) {
            $process->stop(5, SIGTERM);
        }
        $this->info('WhatsApp worker supervisor stopped');

        return self::SUCCESS;
    }

    private function reconcile(): void
    {
        try {
            $active = WhatsappBot::where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable $e) {
            Log::error('whatsapp:supervise-jobs could not read the bots', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($this->workers as $botId => $process) {
            if (! $process->isRunning()) {
                $this->forward($process);
                unset($this->workers[$botId]);
                // a worker that keeps dying is not restarted in a tight loop
                $this->retryAt[$botId] = time() + ($process->getExitCode() === 0 ? 0 : 15);
                if ($process->getExitCode() !== 0 && in_array($botId, $active, true)) {
                    Log::warning('WhatsApp worker exited', ['bot_id' => $botId, 'exit_code' => $process->getExitCode()]);
                }
            }
        }

        foreach ($active as $botId) {
            if (isset($this->workers[$botId]) || time() < ($this->retryAt[$botId] ?? 0)) {
                continue;
            }
            $process = new Process([PHP_BINARY, base_path('artisan'), 'whatsapp:process-jobs', '--bot='.$botId, '--sleep=1', '--parent='.getmypid()], base_path());
            $process->setTimeout(null);
            $process->start();
            $this->workers[$botId] = $process;
            $this->line("started worker for bot {$botId} (pid {$process->getPid()})");
        }
    }

    private function forward(Process $process): void
    {
        $out = trim($process->getIncrementalOutput().$process->getIncrementalErrorOutput());
        if ($out !== '') {
            $this->line($out);
        }
    }
}
