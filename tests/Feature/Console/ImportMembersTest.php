<?php

use App\Enums\NextOfKinRelationship;
use App\Models\Cycle;
use App\Models\Member;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->cycle = Cycle::factory()->create();
    $this->travelTo(Carbon::parse('2026-01-10'));

    $this->path = tempnam(sys_get_temp_dir(), 'members').'.csv';
});

afterEach(function () {
    @unlink($this->path);
});

function writeSheet(string $path, string $contents): void
{
    file_put_contents($path, $contents);
}

it('imports the commitment sheet columns', function () {
    writeSheet($this->path, <<<'CSV'
    Name,NRC,Address,NOK Name,NOK Phone,Relationship
    Bernadette Kashweka,188612/10/1,Meanwood Ibex,Pamela Kashweka,0977496538,Sister
    Bertha Chileshe,155110/10/1,Lilayi,Nobya Situmbeko,0979458327,Spouse
    CSV);

    $this->artisan('unity:import-members', ['file' => $this->path])->assertSuccessful();

    expect(Member::count())->toBe(2);

    $member = Member::firstWhere('full_name', 'Bernadette Kashweka');

    expect($member->nrc_number)->toBe('188612/10/1')
        ->and($member->physical_address)->toBe('Meanwood Ibex')
        ->and($member->joined_on->toDateString())->toBe($this->cycle->starts_on->toDateString())
        ->and($member->nextOfKin->first()->relationship)->toBe(NextOfKinRelationship::Sibling)
        ->and($member->nextOfKin->first()->relationship_label)->toBe('Sister');
});

it('writes nothing on a dry run', function () {
    writeSheet($this->path, "Name,NRC\nChanda Mwale,123456/78/9\n");

    $this->artisan('unity:import-members', ['file' => $this->path, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Member::count())->toBe(0);
});

it('skips members already in the register so a partial import can be re-run', function () {
    Member::factory()->for($this->cycle)->create(['nrc_number' => '123456/78/9']);

    writeSheet($this->path, "Name,NRC\nChanda Mwale,123456/78/9\nNew Person,654321/78/9\n");

    $this->artisan('unity:import-members', ['file' => $this->path])->assertSuccessful();

    expect(Member::count())->toBe(2)
        ->and(Member::where('full_name', 'New Person')->exists())->toBeTrue();
});

it('bucket-maps free-text relationships and keeps the original wording', function () {
    writeSheet($this->path, "Name,NRC,NOK Name,Relationship\nJessica Mudenda,567148/10/1,Jane Chintu,Aunt\n");

    $this->artisan('unity:import-members', ['file' => $this->path])->assertSuccessful();

    $kin = Member::firstWhere('full_name', 'Jessica Mudenda')->nextOfKin->first();

    expect($kin->relationship)->toBe(NextOfKinRelationship::Other)
        ->and($kin->relationship_label)->toBe('Aunt')
        ->and($kin->relationshipLabel())->toBe('Aunt');
});

it('fails on a file whose header row it cannot read', function () {
    writeSheet($this->path, "one,two,three\nfoo,bar,baz\n");

    $this->artisan('unity:import-members', ['file' => $this->path])->assertFailed();
});

it('fails when there is no file to read', function () {
    $this->artisan('unity:import-members', ['file' => '/no/such/sheet.csv'])->assertFailed();
});

it('registers imported members from the start of the cycle, whenever it is run', function () {
    $this->travelTo(Carbon::parse('2026-08-19'));

    writeSheet($this->path, "Name,NRC\nFounding Member,123456/78/9\n");

    $this->artisan('unity:import-members', ['file' => $this->path])->assertSuccessful();

    $member = Member::firstWhere('full_name', 'Founding Member');

    expect($member->joined_on->toDateString())->toBe($this->cycle->starts_on->toDateString())
        ->and($member->joining_month_sequence)->toBe(1);
});
