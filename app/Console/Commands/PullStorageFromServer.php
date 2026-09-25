<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class PullStorageFromServer extends Command
{
    protected $signature = 'storage:pull-from-server
        {--dir=* : Extra folders under storage/app/public to pull (e.g. --dir=ids --dir=installments)}
        {--only=* : Pull only these folders instead of the defaults}
        {--dry-run : Show what would be copied without copying}';

    protected $description = 'Pull public site images (brands, sliders, machines) from the production server into local storage';

    // Customer documents (installments, ids, stock, salary_slips, ...) stay on the server unless asked for with --dir.
    private const DEFAULT_DIRS = ['sliders', 'machines', 'machines-structured'];

    public function handle(): int
    {
        $config = config('services.storage_sync');

        if (blank($config['host']) || blank($config['path'])) {
            $this->error('Set STORAGE_SYNC_HOST and STORAGE_SYNC_PATH in .env (path = project root on the server).');

            return self::FAILURE;
        }

        $dirs = $this->option('only') ?: array_merge(self::DEFAULT_DIRS, $this->option('dir'));
        $includeRootFiles = ! $this->option('only');

        $remote = sprintf('%s@%s:%s/storage/app/public/', $config['user'], $config['host'], rtrim($config['path'], '/'));
        $local = storage_path('app/public').'/';

        $filters = [];
        if ($includeRootFiles) {
            $filters[] = '--include=/*.png';
            $filters[] = '--include=/*.jpg';
            $filters[] = '--include=/*.jpeg';
            $filters[] = '--include=/*.webp';
            $filters[] = '--include=/*.svg';
        }
        foreach (array_unique($dirs) as $dir) {
            $dir = trim($dir, '/');
            $filters[] = "--include=/{$dir}/";
            $filters[] = "--include=/{$dir}/**";
        }
        $filters[] = '--exclude=*';

        $ssh = "ssh -p {$config['port']} -o StrictHostKeyChecking=accept-new";

        $command = array_merge(
            getenv('SSHPASS') ? ['sshpass', '-e'] : [],
            ['rsync', '-rtz', '--ignore-existing', '--itemize-changes', '-e', $ssh],
            $this->option('dry-run') ? ['--dry-run'] : [],
            $filters,
            [$remote, $local],
        );

        $this->info('Pulling: '.($includeRootFiles ? 'root images, ' : '').implode(', ', array_unique($dirs)));

        $copied = 0;
        $env = getenv('SSHPASS') ? ['SSHPASS' => getenv('SSHPASS')] : null;
        $process = new Process($command, base_path(), $env, null, null);
        $process->run(function ($type, $buffer) use (&$copied) {
            if ($type === Process::ERR) {
                $this->getOutput()->write("<error>{$buffer}</error>");

                return;
            }
            foreach (preg_split('/\R/', trim($buffer)) as $line) {
                if (str_starts_with($line, '>f')) {
                    $copied++;
                    $this->line('  + '.(explode(' ', $line, 2)[1] ?? $line));
                }
            }
        });

        if (! $process->isSuccessful()) {
            $this->error('rsync failed (exit '.$process->getExitCode().').');

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? 'Would copy' : 'Copied')." {$copied} new file(s). Existing local files were left untouched.");

        return self::SUCCESS;
    }
}
