<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Organizations\Models\Team;

class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    public function definition(): array
    {
        return ['name' => fake()->words(2, true), 'url' => 'https://hooks.example.com/endpoint', 'events' => ['contact.created'], 'secret' => fake()->sha256(), 'team_id' => Team::factory(), 'is_active' => true];
    }
}
