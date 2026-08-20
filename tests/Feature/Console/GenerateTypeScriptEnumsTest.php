<?php

use App\Console\Commands\GenerateTypeScriptEnums;
use Illuminate\Support\Facades\File;

/**
 * The generated file is committed, so the risk is it silently drifting from the
 * PHP enums. The --check mode exists to catch that in CI.
 */
it('writes a TypeScript file mirroring the PHP enums', function () {
    $this->artisan('unity:generate-ts-enums')->assertSuccessful();

    $contents = File::get(base_path(GenerateTypeScriptEnums::OUTPUT_PATH));

    expect($contents)
        ->toContain('export const MemberRole = {')
        ->toContain("Chairperson: 'chairperson',")
        ->toContain('export const Permission = {')
        ->toContain("LoansApprove: 'loans.approve',");
});

it('marks the file as generated so nobody edits it by hand', function () {
    $this->artisan('unity:generate-ts-enums')->assertSuccessful();

    expect(File::get(base_path(GenerateTypeScriptEnums::OUTPUT_PATH)))
        ->toContain('do not edit by hand');
});

it('exports a union type and a values array for each enum', function () {
    $this->artisan('unity:generate-ts-enums')->assertSuccessful();

    expect(File::get(base_path(GenerateTypeScriptEnums::OUTPUT_PATH)))
        ->toContain('export type CycleStatus = (typeof CycleStatus)[keyof typeof CycleStatus];')
        ->toContain('export const cycleStatusValues: readonly CycleStatus[] =');
});

it('passes the check when the committed file is current', function () {
    $this->artisan('unity:generate-ts-enums')->assertSuccessful();

    $this->artisan('unity:generate-ts-enums', ['--check' => true])->assertSuccessful();
});

it('fails the check when the committed file has drifted', function () {
    $path = base_path(GenerateTypeScriptEnums::OUTPUT_PATH);
    $original = File::get($path);

    File::put($path, "// stale\n");

    try {
        $this->artisan('unity:generate-ts-enums', ['--check' => true])->assertFailed();
    } finally {
        File::put($path, $original);
    }
});
