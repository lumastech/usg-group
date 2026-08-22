<?php

namespace Database\Factories;

use App\Models\LencoWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LencoWebhookEvent> */
class LencoWebhookEventFactory extends Factory
{
    protected $model = LencoWebhookEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event' => 'collection.successful',
            'event_key' => fake()->unique()->uuid(),
            'reference' => 'usg-tst-sav-00001-1',
            'signature' => str_repeat('a', 128),
            'payload' => ['event' => 'collection.successful', 'data' => []],
            'received_at' => now(),
        ];
    }
}
