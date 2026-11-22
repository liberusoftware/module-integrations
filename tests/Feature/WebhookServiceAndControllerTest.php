<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Integrations\Http\Controllers\Api\WebhookController;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Integrations\Services\WebhookService;
use Liberu\Foundation\Organizations\Models\Team;
use RuntimeException;
use Tests\TestCase;

class WebhookServiceAndControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_service_lifecycle_dispatch_signing_and_failure_auto_disable(): void
    {
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

        $this->assertNotEmpty($webhook->secret);
        $this->assertTrue($service->verifySignature('{"a":1}', 'sha256='.hash_hmac('sha256', '{"a":1}', $webhook->secret), $webhook->secret));
        $this->assertFalse($service->verifySignature('{"a":1}', 'sha256=wrong', $webhook->secret));

        $updated = $service->update($webhook, ['name' => 'Renamed Hook', 'is_active' => true]);
        $this->assertSame('Renamed Hook', $updated->name);

        $originalSecret = $webhook->secret;
        $newSecret = $service->regenerateSecret($updated);
        $this->assertNotSame($originalSecret, $newSecret);

        // Success delivery via dispatch
        Http::fake(['https://hooks.example.com/events' => Http::response(['ok' => true], 200)]);
        $service->dispatch('contact.created', ['id' => 1]);
        $this->assertDatabaseHas('crm_webhook_deliveries', [
            'webhook_id' => $updated->id,
            'success' => 1,
            'status_code' => 200,
        ]);

        // HTTP failure delivery
        Http::fake(['https://hooks.example.com/events' => Http::response('fail', 500)]);
        $this->assertFalse($service->send($updated->fresh(), 'contact.created', ['id' => 2]));
        $this->assertSame(1, $updated->fresh()->failure_count);

        // Exception delivery + auto-disable at 10 failures + null secret sign branch
        $updated->update(['failure_count' => 9, 'secret' => null]);
        Http::fake(fn () => throw new RuntimeException('network timeout'));
        $this->assertFalse($service->send($updated->fresh(), 'contact.created', ['id' => 3]));
        $this->assertFalse($updated->fresh()->is_active);
        $this->assertSame(10, $updated->fresh()->failure_count);
    }

    public function test_webhook_service_production_public_url_check(): void
    {
        $team = Team::factory()->create();
        $service = new WebhookService();
        $previousEnv = $this->app['env'];

        try {
            $this->app['env'] = 'production';

            // Internal IP -> throws RuntimeException
            $internal = $service->register([
                'name' => 'Internal',
                'url' => 'http://localhost/hook',
                'events' => ['contact.created'],
                'team_id' => $team->id,
            ]);
            try {
                $service->send($internal, 'contact.created', []);
                $this->fail('Expected RuntimeException for internal IP');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('internal IP', $e->getMessage());
            }

            // Hostless URL -> returns early from ensurePublicUrl
            $hostless = $service->register([
                'name' => 'Hostless',
                'url' => '/relative/path',
                'events' => ['contact.created'],
                'team_id' => $team->id,
            ]);
            Http::fake(fn () => Http::response('ok', 200));
            $this->assertTrue($service->send($hostless, 'contact.created', []));
        } finally {
            $this->app['env'] = $previousEnv;
        }
    }

    public function test_webhook_api_controller_crud_for_team_and_teamless_users_and_production_ssrf(): void
    {
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
        $this->assertSame(201, $stored->getStatusCode());
        $webhookId = $stored->getData(true)['id'];
        $webhook = Webhook::withoutGlobalScopes()->findOrFail($webhookId);

        // Index
        $indexReq = Request::create('/api/webhooks', 'GET');
        $indexReq->setUserResolver(fn () => $user);
        $this->assertCount(1, $controller->index($indexReq)->getData(true));

        // Show
        $showReq = Request::create('/api/webhooks/'.$webhookId, 'GET');
        $showReq->setUserResolver(fn () => $user);
        $this->assertSame('API Hook', $controller->show($showReq, $webhook)->getData(true)['name']);

        // Update
        $updateReq = Request::create('/api/webhooks/'.$webhookId, 'PATCH', [
            'name' => 'API Hook Updated',
        ]);
        $updateReq->setUserResolver(fn () => $user);
        $this->assertSame('API Hook Updated', $controller->update($updateReq, $webhook)->getData(true)['name']);

        // Regenerate secret
        $regenReq = Request::create('/api/webhooks/'.$webhookId.'/regenerate', 'POST');
        $regenReq->setUserResolver(fn () => $user);
        $this->assertArrayHasKey('secret', $controller->regenerateSecret($regenReq, $webhook)->getData(true));

        // Events
        $this->assertSame(Webhook::EVENTS, $controller->events()->getData(true)['events']);

        // Destroy
        $destroyReq = Request::create('/api/webhooks/'.$webhookId, 'DELETE');
        $destroyReq->setUserResolver(fn () => $user);
        $this->assertSame(204, $controller->destroy($destroyReq, $webhook)->getStatusCode());

        // Teamless user owned() branch
        $teamless = User::factory()->create();
        $teamless->forceFill(['current_team_id' => null])->save();
        $teamlessReq = Request::create('/api/webhooks', 'GET');
        $teamlessReq->setUserResolver(fn () => $teamless);
        $this->assertSame([], $controller->index($teamlessReq)->getData(true));

        // Production SSRF validation
        $previousEnv = $this->app['env'];
        try {
            $this->app['env'] = 'production';
            $badReq = Request::create('/api/webhooks', 'POST', [
                'name' => 'Bad Hook',
                'url' => 'http://127.0.0.1/hook',
                'events' => ['contact.created'],
            ]);
            $badReq->setUserResolver(fn () => $user);

            $this->expectException(ValidationException::class);
            $controller->store($badReq);
        } finally {
            $this->app['env'] = $previousEnv;
        }
    }
}
