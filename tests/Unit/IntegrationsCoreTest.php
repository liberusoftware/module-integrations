<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Tests\Unit;

use App\Exceptions\AccountingIntegrationException;
use App\Models\User;
use App\Services\Marketing\PlatformConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Liberu\Foundation\Integrations\Contracts\IntegrationAdapter;
use Liberu\Foundation\Integrations\Contracts\OAuthIntegrationAdapter;
use Liberu\Foundation\Integrations\Enums\SocialPlatform;
use Liberu\Foundation\Integrations\Models\AccountingIntegration;
use Liberu\Foundation\Integrations\Models\OAuthConfiguration;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Integrations\Models\WebhookDelivery;
use Liberu\Foundation\Integrations\Services\AccountingService;
use Liberu\Foundation\Integrations\Services\QuickBooksService;
use Liberu\Foundation\Integrations\Services\Social\DirectSocialApiClient;
use Liberu\Foundation\Integrations\Services\XeroService;
use Liberu\Foundation\Integrations\Support\CredentialVault;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;
use Liberu\Foundation\Integrations\Support\SsrfGuard;
use Liberu\Foundation\Organizations\Models\Team;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once dirname(__DIR__).'/Stubs.php';

class IntegrationsCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_platform_enum_labels_options_and_values(): void
    {
        $options = SocialPlatform::options();
        $values = SocialPlatform::values();

        $this->assertCount(count(SocialPlatform::cases()), $options);
        $this->assertCount(count(SocialPlatform::cases()), $values);
        $this->assertSame('Twitter/X', SocialPlatform::Twitter->label());
        $this->assertSame('Google Business', SocialPlatform::GoogleBusiness->label());
        $this->assertContains('facebook', $values);
        $this->assertSame('Slack', $options['slack']);
    }

    public function test_credential_vault_seals_and_opens_payload(): void
    {
        $vault = new CredentialVault();
        $sealed = $vault->seal(['client_id' => 'abc', 'secret' => 'xyz']);

        $this->assertNotSame('abc', $sealed);
        $this->assertSame(['client_id' => 'abc', 'secret' => 'xyz'], $vault->open($sealed));
    }

    public function test_integration_registry_registers_retrieves_and_enforces_contracts(): void
    {
        $registry = new IntegrationRegistry();

        $basic = new class() implements IntegrationAdapter
        {
            public function name(): string
            {
                return 'basic';
            }

            public function capabilities(): array
            {
                return ['sync'];
            }

            public function test(array $credentials): bool
            {
                return true;
            }
        };

        $oauth = new class() implements OAuthIntegrationAdapter
        {
            public function name(): string
            {
                return 'oauth-provider';
            }

            public function capabilities(): array
            {
                return ['oauth'];
            }

            public function test(array $credentials): bool
            {
                return true;
            }

            public function authorizationUrl(string $state): string
            {
                return 'https://example.com/auth';
            }

            public function exchangeAuthorizationCode(string $code): array
            {
                return ['access_token' => 'tok'];
            }

            public function refreshAccessToken(string $refreshToken): array
            {
                return ['access_token' => 'tok2'];
            }
        };

        $registry->register($basic);
        $registry->register($oauth);

        $this->assertSame($basic, $registry->get('basic'));
        $this->assertSame($oauth, $registry->oauth('oauth-provider'));
        $this->assertCount(2, $registry->all());

        try {
            $registry->register($basic);
            $this->fail('Expected duplicate exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Duplicate', $e->getMessage());
        }

        try {
            $registry->get('missing');
            $this->fail('Expected unknown exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown integration', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $registry->oauth('basic');
    }

    public function test_ssrf_guard_validates_https_ips_and_hostnames(): void
    {
        SsrfGuard::assertPublicHttps('https://8.8.8.8/oidc');
        SsrfGuard::assertPublicHttps('https://'.gethostname().'/oidc');

        foreach ([
            'http://example.com/oidc',
            'not-a-url',
            'https://localhost/oidc',
            'https://sub.localhost/oidc',
            'https://localhost.localdomain/oidc',
            'https://127.0.0.1/oidc',
            'https://169.254.169.254/latest/meta-data',
            'https://10.0.0.1/internal',
            'https://[::1]/oidc',
        ] as $blocked) {
            try {
                SsrfGuard::assertPublicHttps($blocked);
                $this->fail("Expected {$blocked} to be rejected");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_models_relationships_and_helpers(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);

        $accounting = AccountingIntegration::create([
            'user_id' => $user->id,
            'platform' => 'quickbooks',
            'connection_details' => ['realm_id' => '123'],
        ]);
        $this->assertSame($user->id, $accounting->user->id);

        $oauth = OAuthConfiguration::create([
            'service_name' => 'google',
            'client_id' => 'cid',
            'client_secret' => 'csec',
            'is_active' => true,
        ]);
        $this->assertSame($oauth->id, OAuthConfiguration::getConfig('google')?->id);

        $webhook = Webhook::create([
            'team_id' => $team->id,
            'name' => 'Main Hook',
            'url' => 'https://hooks.example.com/main',
            'events' => ['contact.created'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'team_id' => $team->id,
            'webhook_id' => $webhook->id,
            'event' => 'contact.created',
            'success' => true,
            'status_code' => 200,
        ]);

        $this->assertCount(1, $webhook->deliveries);
        $this->assertSame($webhook->id, $delivery->webhook->id);
    }

    public function test_accounting_service_delegates_and_rejects_unsupported_platforms(): void
    {
        $service = new AccountingService(new QuickBooksService(), new XeroService());

        $qb = new AccountingIntegration(['platform' => 'quickbooks']);
        $xero = new AccountingIntegration(['platform' => 'xero']);
        $bad = new AccountingIntegration(['platform' => 'unknown']);

        $this->assertSame(['provider' => 'quickbooks', 'invoice' => 'inv-1'], $service->syncInvoice($qb, 'inv-1'));
        $this->assertSame(['provider' => 'xero', 'invoice' => 'inv-2'], $service->syncInvoice($xero, 'inv-2'));
        $this->assertSame(['provider' => 'quickbooks', 'payment' => 'pay-1'], $service->syncPayment($qb, 'pay-1'));
        $this->assertSame(['provider' => 'xero', 'payment' => 'pay-2'], $service->syncPayment($xero, 'pay-2'));
        $this->assertSame(['provider' => 'quickbooks', 'credentials' => ['k' => 'v']], $service->connectPlatform('quickbooks', ['k' => 'v']));
        $this->assertSame(['provider' => 'xero', 'credentials' => ['k' => 'v']], $service->connectPlatform('xero', ['k' => 'v']));

        foreach ([
            fn () => $service->syncInvoice($bad, 'inv'),
            fn () => $service->syncPayment($bad, 'pay'),
            fn () => $service->connectPlatform('unknown', []),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected AccountingIntegrationException');
            } catch (AccountingIntegrationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_direct_social_api_client_handles_success_failure_and_guards(): void
    {
        $platform = new PlatformConfiguration();
        $platform->currentProvider = 'direct';
        $this->app->instance(PlatformConfiguration::class, $platform);

        config([
            'services.zernio.direct_api_enabled' => true,
            'services.social.direct_base_urls.twitter' => 'https://api.twitter.test/v2',
        ]);

        $client = new DirectSocialApiClient();
        $config = new OAuthConfiguration([
            'service_name' => 'twitter',
            'access_token' => 'secret-token',
        ]);

        Http::fake([
            'https://api.twitter.test/v2/tweets' => Http::sequence()
                ->push(['id' => 't1'], 200)
                ->push('plain-text', 200)
                ->push(['error' => 'bad'], 500),
        ]);

        $this->assertSame(['id' => 't1'], $client->request($config, 'post', 'tweets', ['text' => 'hi']));
        $this->assertSame([], $client->request($config, 'post', 'tweets', ['text' => 'hi']));

        try {
            $client->request($config, 'post', 'tweets', ['text' => 'hi']);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        // Missing token -> 422
        try {
            $client->request(new OAuthConfiguration(['service_name' => 'twitter', 'access_token' => '']), 'get', 'me');
            $this->fail('Expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Disabled provider -> 503
        $platform->currentProvider = 'zernio';
        try {
            $client->request($config, 'get', 'me');
            $this->fail('Expected 503');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }
}
