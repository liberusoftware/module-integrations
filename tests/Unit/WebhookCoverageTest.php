<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Integrations\Http\Controllers\Api\WebhookController;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Integrations\Services\WebhookService;
use Liberu\Foundation\Organizations\Models\Team;

it('manages webhook lifecycle, signing, dispatch, failure tracking, and auto-disable in WebhookService', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $service = new WebhookService();

    $webhook = $service->register([
        'name' => 'CRM Hook',
        'url' => 'https://hooks.example.com/events',
        'events' => ['contact.created'],
        'team_id' => $team->id,
        'user_id' => $user->id,
    ]);

    // Inactive webhook should not be dispatched
    $service->register([
        'name' => 'Inactive Hook',
        'url' => 'https://hooks.example.com/inactive',
        'events' => ['contact.created'],
        'secret' => 'custom-secret',
        'is_active' => false,
        'team_id' => $team->id,
    ]);

    expect($webhook->secret)->not->toBeEmpty()
        ->and($service->verifySignature('{"a":1}', 'sha256='.hash_hmac('sha256', '{"a":1}', $webhook->secret), $webhook->secret))->toBeTrue()
        ->and($service->verifySignature('{"a":1}', 'sha256=wrong', $webhook->secret))->toBeFalse();

    $updated = $service->update($webhook, ['name' => 'Renamed Hook', 'is_active' => true]);
    expect($updated->name)->toBe('Renamed Hook');

    $originalSecret = $webhook->secret;
    $newSecret = $service->regenerateSecret($updated);
    expect($newSecret)->not->toBe($originalSecret);

    // Dispatch succeeds for active subscribed webhook
    Http::fake(['https://hooks.example.com/events' => Http::response(['ok' => true], 200)]);
    $service->dispatch('contact.created', ['id' => 1]);

    $this->assertDatabaseHas('crm_webhook_deliveries', [
        'webhook_id' => $updated->id,
        'event' => 'contact.created',
        'success' => 1,
        'status_code' => 200,
    ]);

    // HTTP 500 failure increments failure_count and logs warning
    Http::fake(['https://hooks.example.com/events' => Http::response('fail', 500)]);
    expect($service->send($updated->fresh(), 'contact.created', ['id' => 2]))->toBeFalse()
        ->and($updated->fresh()->failure_count)->toBe(1)
        ->and($updated->fresh()->is_active)->toBeTrue();

    // Exception during delivery + auto-disable at 10 consecutive failures + null secret sign branch
    $updated->update(['failure_count' => 9, 'secret' => null]);
    Http::fake(fn () => throw new RuntimeException('network timeout'));

    expect($service->send($updated->fresh(), 'contact.created', ['id' => 3]))->toBeFalse()
        ->and($updated->fresh()->is_active)->toBeFalse()
        ->and($updated->fresh()->failure_count)->toBe(10);

    $this->assertDatabaseHas('crm_webhook_deliveries', [
        'webhook_id' => $updated->id,
        'success' => 0,
        'status_code' => null,
        'error' => 'network timeout',
    ]);
});

it('enforces public URL validation in production environment in WebhookService', function (): void {
    $team = Team::factory()->create();
    $service = new WebhookService();
    $previousEnv = $this->app['env'];

    try {
        $this->app['env'] = 'production';

        // Internal IP resolves to 127.0.0.1 -> throws RuntimeException
        $internal = $service->register([
            'name' => 'Internal',
            'url' => 'http://localhost/hook',
            'events' => ['contact.created'],
            'team_id' => $team->id,
        ]);

        expect(fn () => $service->send($internal, 'contact.created', []))
            ->toThrow(RuntimeException::class, 'internal IP');

        // Hostless URL returns early from ensurePublicUrl
        $hostless = $service->register([
            'name' => 'Hostless',
            'url' => '/relative/path',
            'events' => ['contact.created'],
            'team_id' => $team->id,
        ]);

        Http::fake(fn () => Http::response('ok', 200));
        expect($service->send($hostless, 'contact.created', []))->toBeTrue();

        // Public IP passes ensurePublicUrl
        $public = $service->register([
            'name' => 'Public',
            'url' => 'https://8.8.8.8/hook',
            'events' => ['contact.created'],
            'team_id' => $team->id,
        ]);
        expect($service->send($public, 'contact.created', []))->toBeTrue();
    } finally {
        $this->app['env'] = $previousEnv;
    }
});

it('handles CRUD, secret regeneration, events, teamless scoping, and production SSRF in WebhookController', function (): void {
    $controller = new WebhookController(new WebhookService());
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    // Store (team-scoped)
    $storeReq = Request::create('/api/webhooks', 'POST', [
        'name' => 'API Hook',
        'url' => 'https://8.8.8.8/hook',
        'events' => ['contact.created'],
    ]);
    $storeReq->setUserResolver(fn () => $user);

    $stored = $controller->store($storeReq);
    expect($stored->getStatusCode())->toBe(201);

    $webhookId = $stored->getData(true)['id'];
    $webhook = Webhook::withoutGlobalScopes()->findOrFail($webhookId);

    // Index
    $indexReq = Request::create('/api/webhooks', 'GET');
    $indexReq->setUserResolver(fn () => $user);
    expect($controller->index($indexReq)->getData(true))->toHaveCount(1);

    // Show
    $showReq = Request::create('/api/webhooks/'.$webhookId, 'GET');
    $showReq->setUserResolver(fn () => $user);
    $showData = $controller->show($showReq, $webhook)->getData(true);
    expect($showData['name'])->toBe('API Hook')
        ->and($showData)->toHaveKey('secret');

    // Update
    $updateReq = Request::create('/api/webhooks/'.$webhookId, 'PATCH', [
        'name' => 'API Hook Updated',
        'is_active' => false,
    ]);
    $updateReq->setUserResolver(fn () => $user);
    expect($controller->update($updateReq, $webhook)->getData(true)['name'])->toBe('API Hook Updated');

    // Regenerate secret
    $regenReq = Request::create('/api/webhooks/'.$webhookId.'/regenerate', 'POST');
    $regenReq->setUserResolver(fn () => $user);
    expect($controller->regenerateSecret($regenReq, $webhook)->getData(true))->toHaveKey('secret');

    // Events
    expect($controller->events()->getData(true)['events'])->toBe(Webhook::EVENTS);

    // Destroy
    $destroyReq = Request::create('/api/webhooks/'.$webhookId, 'DELETE');
    $destroyReq->setUserResolver(fn () => $user);
    expect($controller->destroy($destroyReq, $webhook)->getStatusCode())->toBe(204);

    // Teamless user owned() branch
    $teamless = User::factory()->create();
    $teamless->forceFill(['current_team_id' => null])->save();
    $teamlessReq = Request::create('/api/webhooks', 'GET');
    $teamlessReq->setUserResolver(fn () => $teamless);
    expect($controller->index($teamlessReq)->getData(true))->toBe([]);

    // Production SSRF validation (both valid public HTTPS and blocked private URL)
    $previousEnv = $this->app['env'];
    try {
        $this->app['env'] = 'production';

        $goodProdReq = Request::create('/api/webhooks', 'POST', [
            'name' => 'Prod Hook',
            'url' => 'https://8.8.8.8/prod-hook',
            'events' => ['contact.created'],
        ]);
        $goodProdReq->setUserResolver(fn () => $user);
        expect($controller->store($goodProdReq)->getStatusCode())->toBe(201);

        $badProdReq = Request::create('/api/webhooks', 'POST', [
            'name' => 'Bad Hook',
            'url' => 'http://127.0.0.1/hook',
            'events' => ['contact.created'],
        ]);
        $badProdReq->setUserResolver(fn () => $user);

        expect(fn () => $controller->store($badProdReq))->toThrow(ValidationException::class);
    } finally {
        $this->app['env'] = $previousEnv;
    }
});
