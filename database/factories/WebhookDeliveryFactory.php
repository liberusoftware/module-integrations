<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Integrations\Models\WebhookDelivery;

class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'webhook_id' => Webhook::factory(),
            'team_id' => fn (array $attributes) => Webhook::withoutGlobalScopes()->findOrFail($attributes['webhook_id'])->team_id,
            'event' => 'contact.created', 'success' => true, 'status_code' => 200,
        ];
    }
}
