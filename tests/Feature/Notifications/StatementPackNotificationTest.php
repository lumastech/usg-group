<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Trading\TradingConcluder;
use App\Domain\Trading\TradingSessionService;
use App\Enums\MemberRole;
use App\Events\TradingSessionConcluded;
use App\Models\Cycle;
use App\Notifications\MemberStatementReady;
use App\Notifications\StatementPackCompiled;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    Storage::fake('local');

    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    app(CurrentCycle::class)->set($this->cycle);

    $this->january = $this->months->firstWhere('sequence', 2);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer, ['member_number' => 1]);
    $this->member = memberWithRole($this->cycle, MemberRole::Member, ['member_number' => 2]);
});

/** Declare, receive and conclude January in one go. */
function concludeJanuary($test): void
{
    foreach ([$test->treasurer, $test->member] as $member) {
        app(DeclarationService::class)->submit(
            $member,
            $test->january,
            Kwacha::of(500),
            Kwacha::zero(),
            Kwacha::zero(),
            actor: $member,
            at: Carbon::parse('2026-01-02 10:00'),
        );
    }

    $sessions = app(TradingSessionService::class);
    $session = $sessions->openFor($test->january);
    $sessions->syncEntries($session);

    foreach ($session->entries()->with('declaration')->get() as $entry) {
        $sessions->markReceived(
            $entry,
            Kwacha::of(500),
            Carbon::parse('2026-01-07 11:00'),
            $test->treasurer,
        );
    }

    app(TradingConcluder::class)->conclude($session, $test->treasurer);
}

it('raises the conclusion event only after the month has committed', function () {
    Event::fake([TradingSessionConcluded::class]);

    concludeJanuary($this);

    Event::assertDispatched(
        TradingSessionConcluded::class,
        fn (TradingSessionConcluded $event): bool => $event->session->cycle_month_id === $this->january->id
            && $event->session->concluded_at !== null,
    );
});

it('builds the pack and sends every member their own statement', function () {
    Notification::fake();

    concludeJanuary($this);

    Notification::assertSentTo($this->member, MemberStatementReady::class);
    Notification::assertSentTo($this->treasurer, MemberStatementReady::class);

    Storage::disk('local')->assertExists('statement-packs/'.$this->cycle->id.'/2026-01/savings.pdf');
    Storage::disk('local')->assertExists('statement-packs/'.$this->cycle->id.'/2026-01/members/002-'
        .Str::slug($this->member->full_name).'.pdf');
});

it('attaches the member their own statement and nobody else', function () {
    Notification::fake();

    concludeJanuary($this);

    Notification::assertSentTo(
        $this->member,
        MemberStatementReady::class,
        fn (MemberStatementReady $notification): bool => str_contains(
            (string) ($notification->statement['path'] ?? ''),
            '/members/002-',
        ),
    );
});

it('sends the full pack to the committee and not to ordinary members', function () {
    Notification::fake();

    concludeJanuary($this);

    Notification::assertSentTo(
        $this->treasurer,
        StatementPackCompiled::class,
        fn (StatementPackCompiled $notification): bool => $notification->manifest['member_count'] === 2,
    );
    Notification::assertNotSentTo($this->member, StatementPackCompiled::class);
});
