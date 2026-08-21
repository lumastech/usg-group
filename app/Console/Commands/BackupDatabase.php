<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * The nightly dump.
 *
 * The group's whole financial record lives in one database on one server, and the
 * ledgers are immutable by design — a restore is the only correction that exists
 * for a lost table. This writes a timestamped dump to the configured disk and
 * prunes anything past the retention window.
 *
 * See docs/STORAGE.md for where the files land, what they weigh and how to restore.
 */
class BackupDatabase extends Command
{
    protected $signature = 'unity:backup-database
        {--disk= : Filesystem disk to write to, defaulting to config}
        {--keep= : Days to retain, defaulting to config}';

    protected $description = 'Dump the database to the backup disk and prune old dumps';

    public function handle(): int
    {
        $disk = (string) ($this->option('disk') ?? config('notifications.backups.disk'));
        $keep = (int) ($this->option('keep') ?? config('notifications.backups.retention_days'));
        $directory = trim((string) config('notifications.backups.directory'), '/');

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");

        $path = $directory.'/'.$this->filenameFor($driver);

        $contents = match ($driver) {
            'sqlite' => $this->dumpSqlite($connection),
            'mysql', 'mariadb' => $this->dumpMysql($connection),
            default => null,
        };

        if ($contents === null) {
            $this->components->error("No backup strategy for the [{$driver}] driver.");

            return self::FAILURE;
        }

        Storage::disk($disk)->put($path, $contents);

        $pruned = $this->prune($disk, $directory, $keep);

        $this->components->twoColumnDetail('Written to', $disk.':'.$path);
        $this->components->twoColumnDetail('Size', number_format(strlen($contents) / 1024, 1).' KB');
        $this->components->twoColumnDetail('Pruned', $pruned.' dump'.($pruned === 1 ? '' : 's'));
        $this->components->info('Backup complete.');

        return self::SUCCESS;
    }

    protected function filenameFor(string $driver): string
    {
        $extension = $driver === 'sqlite' ? 'sqlite' : 'sql';

        return 'unity-'.Carbon::now()->format('Y-m-d-His').'.'.$extension;
    }

    /** SQLite is a single file, so the dump is a copy of it. */
    protected function dumpSqlite(string $connection): ?string
    {
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === ':memory:' || ! is_file($database)) {
            $this->components->warn('The SQLite database is in memory — nothing to back up.');

            return '';
        }

        return (string) file_get_contents($database);
    }

    /**
     * Shells out to mysqldump rather than reading the schema through PHP.
     *
     * The password is passed on stdin through a defaults file, never on the command
     * line, so it does not show up in the server's process list.
     */
    protected function dumpMysql(string $connection): ?string
    {
        $config = (array) config("database.connections.{$connection}");

        $defaults = tempnam(sys_get_temp_dir(), 'unity-dump-');

        file_put_contents($defaults, sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=\"%s\"\n",
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 3306,
            $config['username'] ?? '',
            $config['password'] ?? '',
        ));

        try {
            $process = new Process([
                'mysqldump',
                '--defaults-extra-file='.$defaults,
                '--single-transaction',
                '--quick',
                '--routines',
                '--no-tablespaces',
                (string) ($config['database'] ?? ''),
            ]);

            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->components->error('mysqldump failed: '.trim($process->getErrorOutput()));

                return null;
            }

            return $process->getOutput();
        } finally {
            @unlink($defaults);
        }
    }

    /** Drops dumps older than the retention window. Returns how many went. */
    protected function prune(string $disk, string $directory, int $keep): int
    {
        if ($keep <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($keep)->getTimestamp();
        $filesystem = Storage::disk($disk);
        $pruned = 0;

        foreach ($filesystem->files($directory) as $file) {
            if (! str_starts_with(basename($file), 'unity-')) {
                continue;
            }

            if ($filesystem->lastModified($file) >= $cutoff) {
                continue;
            }

            $filesystem->delete($file);
            $pruned++;
        }

        return $pruned;
    }
}
