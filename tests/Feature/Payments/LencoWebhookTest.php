<?php

use App\Domain\Payments\Lenco\LencoSignature;
use App\Domain\Payments\PaymentIntentService;
use App\Enums\PaymentStatus;
use App\Jobs\Payments\PostSettledPayment;
use App\Jobs\Payments\ProcessLencoWebhook;
use App\Models\Cycle;
use App\Models\LencoWebhookEvent;
use App\Models\Member;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('payments.gateways.lenco.api_token', 'test-token');
    $this->cycle = Cycle::factory()->create();
    $this->member = Member::factory()->for($this->cycle)->create();
});

function webhookPayload(string $event, string $reference, string $id = 'txn-1', string $status = 'successful'): array
{
    return [
        'event' => $event,
        'data' => [
            'id' => $id,
            'reference' => $reference,
            'amount' => '500.00',
            'fee' => '12.50',
            'status' => $status,
        ],
    ];
}

function postWebhook(array $payload, ?string $token = 'test-token', ?string $signature = null)
{
    $raw = json_encode($payload);

    return test()->call(
        'POST',
        route('webhooks.lenco'),
        server: ['HTTP_X_LENCO_SIGNATURE' => $signature ?? LencoSignature::expected($raw, (string) $token)],
        content: $raw,
    );
}

it('turns away a webhook that is not signed by the provider', function (): void {
    Queue::fake();

    postWebhook(webhookPayload('collection.successful', 'usg-sav-00001-1'), token: 'somebody-elses-token')
        ->assertUnauthorized();

    expect(LencoWebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('turns away an unsigned webhook', function (): void {
    $this->postJson(route('webhooks.lenco'), webhookPayload('collection.successful', 'usg-sav-00001-1'))
        ->assertUnauthorized();
});

it('writes the event down and queues the work, without doing it inline', function (): void {
    Queue::fake();

    postWebhook(webhookPayload('collection.successful', 'usg-sav-00001-1'))->assertOk();

    expect(LencoWebhookEvent::count())->toBe(1)
        ->and(LencoWebhookEvent::first()->event)->toBe('collection.successful');

    Queue::assertPushed(ProcessLencoWebhook::class);
});

it('acknowledges a redelivery without queueing the work twice', function (): void {
    Queue::fake();
    $payload = webhookPayload('collection.successful', 'usg-sav-00001-1');

    postWebhook($payload)->assertOk();
    postWebhook($payload)->assertOk();

    expect(LencoWebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessLencoWebhook::class, 1);
});

it('keeps two events about one payment apart', function (): void {
    Queue::fake();

    postWebhook(webhookPayload('collection.successful', 'usg-sav-00001-1'))->assertOk();
    postWebhook(webhookPayload('collection.settled', 'usg-sav-00001-1'))->assertOk();

    expect(LencoWebhookEvent::count())->toBe(2);
});

it('refuses a body that is not an event at all', function (): void {
    postWebhook(['nonsense' => true])->assertStatus(400);
});

it('asks the provider rather than believing the webhook about the money', function (): void {
    $gateway = fakeGateway()->willAnswer(PaymentStatus::Successful);

    $intent = PaymentIntent::factory()->for($this->cycle)->for($this->member)->create([
        'reference' => 'usg-sav-00042-1',
        'status' => PaymentStatus::AwaitingAuthorization,
    ]);

    $event = LencoWebhookEvent::factory()->create([
        'reference' => 'usg-sav-00042-1',
        'payload' => webhookPayload('collection.successful', 'usg-sav-00042-1'),
    ]);

    Queue::fake();
    app(ProcessLencoWebhook::class, ['eventId' => $event->id])->handle(
        app(PaymentIntentService::class),
        $gateway,
    );

    expect($intent->refresh()->status)->toBe(PaymentStatus::Successful)
        ->and($event->refresh()->processed_at)->not->toBeNull();

    Queue::assertPushed(PostSettledPayment::class);
});

it('leaves alone a payment this system never minted', function (): void {
    $gateway = fakeGateway();

    $event = LencoWebhookEvent::factory()->create([
        'reference' => 'someone-elses-ref',
        'payload' => webhookPayload('transfer.successful', 'someone-elses-ref'),
    ]);

    app(ProcessLencoWebhook::class, ['eventId' => $event->id])->handle(
        app(PaymentIntentService::class),
        $gateway,
    );

    expect($event->refresh()->processed_at)->not->toBeNull()
        ->and($event->error)->toBeNull();
});

it('records a reference it cannot find rather than losing it', function (): void {
    $gateway = fakeGateway();

    $event = LencoWebhookEvent::factory()->create([
        'reference' => 'usg-sav-99999-1',
        'payload' => webhookPayload('collection.successful', 'usg-sav-99999-1'),
    ]);

    app(ProcessLencoWebhook::class, ['eventId' => $event->id])->handle(
        app(PaymentIntentService::class),
        $gateway,
    );

    expect($event->refresh()->processed_at)->toBeNull()
        ->and($event->error)->toContain('usg-sav-99999-1');
});

it('does nothing at all the second time it processes an event', function (): void {
    $gateway = fakeGateway()->willAnswer(PaymentStatus::Successful);

    $event = LencoWebhookEvent::factory()->create([
        'reference' => 'usg-sav-00042-1',
        'processed_at' => now(),
    ]);

    Queue::fake();
    app(ProcessLencoWebhook::class, ['eventId' => $event->id])->handle(
        app(PaymentIntentService::class),
        $gateway,
    );

    Queue::assertNothingPushed();
});
