<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('writes a timestamped dump to the backup disk', function () {
    $this->travelTo(Carbon::parse('2026-08-21 01:30'));

    $this->artisan('unity:backup-database')->assertSuccessful();

    expect(Storage::disk('local')->files('backups'))
        ->toContain('backups/unity-2026-08-21-013000.sqlite');
});

it('prunes dumps past the retention window and keeps the rest', function () {
    $disk = Storage::disk('local');

    $disk->put('backups/unity-2026-01-01-013000.sqlite', 'old');
    touch($disk->path('backups/unity-2026-01-01-013000.sqlite'), Carbon::now()->subDays(40)->getTimestamp());

    $disk->put('backups/unity-2026-08-19-013000.sqlite', 'recent');
    $disk->put('backups/notes.txt', 'not a dump');

    $this->artisan('unity:backup-database', ['--keep' => 30])->assertSuccessful();

    $disk->assertMissing('backups/unity-2026-01-01-013000.sqlite');
    $disk->assertExists('backups/unity-2026-08-19-013000.sqlite');
    $disk->assertExists('backups/notes.txt');
});

it('keeps everything when retention is switched off', function () {
    $disk = Storage::disk('local');

    $disk->put('backups/unity-2020-01-01-013000.sqlite', 'ancient');
    touch($disk->path('backups/unity-2020-01-01-013000.sqlite'), Carbon::now()->subYears(5)->getTimestamp());

    $this->artisan('unity:backup-database', ['--keep' => 0])->assertSuccessful();

    $disk->assertExists('backups/unity-2020-01-01-013000.sqlite');
});

it('refuses a driver it has no dump strategy for', function () {
    config()->set('database.connections.'.config('database.default').'.driver', 'oracle');

    $this->artisan('unity:backup-database')->assertFailed();
});
