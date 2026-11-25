<?php

declare(strict_types=1);

use App\Exceptions\AccountingIntegrationException;
use App\Models\User;
use App\Services\Marketing\PlatformConfiguration;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\Integrations\Contracts\IntegrationAdapter;
use Liberu\Foundation\Integrations\Contracts\OAuthIntegrationAdapter;
use Liberu\Foundation\Integrations\Contracts\PaymentProviderAdapter;
use Liberu\Foundation\Integrations\Enums\SocialPlatform;
use Liberu\Foundation\Integrations\IntegrationsServiceProvider;
use Liberu\Foundation\Integrations\Models\AccountingIntegration;
use Liberu\Foundation\Integrations\Models\IntegrationConnection;
use Liberu\Foundation\Integrations\Models\OAuthConfiguration;
use Liberu\Foundation\Integrations\Models\Webhook;
use Liberu\Foundation\Integrations\Models\WebhookDelivery;
use Liberu\Foundation\Integrations\Services\AccountingService;
use Liberu\Foundation\Integrations\Services\CalendarService;
use Liberu\Foundation\Integrations\Services\QuickBooksService;
use Liberu\Foundation\Integrations\Services\Social\DirectSocialApiClient;
use Liberu\Foundation\Integrations\Services\XeroService;
use Liberu\Foundation\Integrations\Support\CredentialVault;
use Liberu\Foundation\Integrations\Support\IntegrationRegistry;
use Liberu\Foundation\Integrations\Support\SsrfGuard;
use Liberu\Foundation\Organizations\Models\Team;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once dirname(__DIR__).'/Stubs.php';

it('registers integration registry singleton and boots migrations in service provider', function (): void {
    $provider = new IntegrationsServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    $first = $this->app->make(IntegrationRegistry::class);
    $second = $this->app->make(IntegrationRegistry::class);

    expect($first)->toBeInstanceOf(IntegrationRegistry::class)
        ->and($first)->toBe($second);
});

it('exposes all social platform enum labels, options, and values', function (): void {
    $options = SocialPlatform::options();
    $values = SocialPlatform::values();

    expect($options)->toHaveCount(count(SocialPlatform::cases()))
        ->and($values)->toHaveCount(count(SocialPlatform::cases()))
        ->and(SocialPlatform::Facebook->label())->toBe('Facebook')
        ->and(SocialPlatform::Instagram->label())->toBe('Instagram')
        ->and(SocialPlatform::LinkedIn->label())->toBe('LinkedIn')
        ->and(SocialPlatform::Twitter->label())->toBe('Twitter/X')
        ->and(SocialPlatform::YouTube->label())->toBe('YouTube')
        ->and(SocialPlatform::TikTok->label())->toBe('TikTok')
        ->and(SocialPlatform::Pinterest->label())->toBe('Pinterest')
        ->and(SocialPlatform::Reddit->label())->toBe('Reddit')
        ->and(SocialPlatform::Bluesky->label())->toBe('Bluesky')
        ->and(SocialPlatform::Threads->label())->toBe('Threads')
        ->and(SocialPlatform::GoogleBusiness->label())->toBe('Google Business')
        ->and(SocialPlatform::Telegram->label())->toBe('Telegram')
        ->and(SocialPlatform::Snapchat->label())->toBe('Snapchat')
        ->and(SocialPlatform::WhatsApp->label())->toBe('WhatsApp')
        ->and(SocialPlatform::Discord->label())->toBe('Discord')
        ->and(SocialPlatform::Slack->label())->toBe('Slack')
        ->and($values)->toContain('facebook', 'slack')
        ->and($options['slack'])->toBe('Slack');
});

it('seals and opens credentials with CredentialVault', function (): void {
    $vault = new CredentialVault();
    $payload = ['client_id' => 'abc-123', 'secret' => 's3cr3t', 'scopes' => ['read', 'write']];

    $sealed = $vault->seal($payload);

    expect($sealed)->toBeString()
        ->and($sealed)->not->toContain('s3cr3t')
        ->and($vault->open($sealed))->toBe($payload);
});

it('registers, retrieves, and enforces adapter contracts in IntegrationRegistry', function (): void {
    $registry = new IntegrationRegistry();

    $basic = new class() implements IntegrationAdapter, PaymentProviderAdapter
    {
        public function name(): string
        {
            return 'basic-pay';
        }

        public function capabilities(): array
        {
            return ['payments'];
        }

        public function test(array $credentials): bool
        {
            return ! empty($credentials);
        }

        public function sendPayment(array $payment): array
        {
            return ['status' => 'sent', 'payment' => $payment];
        }

        public function sendBulkPayments(string $title, array $payments, ?string $scheduleFor = null): array
        {
            return ['title' => $title, 'count' => count($payments), 'schedule' => $scheduleFor];
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
            return 'https://example.com/auth?state='.$state;
        }

        public function exchangeAuthorizationCode(string $code): array
        {
            return ['access_token' => 'tok-'.$code];
        }

        public function refreshAccessToken(string $refreshToken): array
        {
            return ['access_token' => 'ref-'.$refreshToken];
        }
    };

    $registry->register($basic);
    $registry->register($oauth);

    expect($registry->get('basic-pay'))->toBe($basic)
        ->and($registry->oauth('oauth-provider'))->toBe($oauth)
        ->and($registry->all())->toHaveCount(2)
        ->and($basic->sendPayment(['amount' => 100])['status'])->toBe('sent')
        ->and($basic->sendBulkPayments('Payroll', [['amount' => 50]], '2026-12-01')['count'])->toBe(1)
        ->and(interface_exists(CalendarService::class))->toBeTrue();

    expect(fn () => $registry->register($basic))
        ->toThrow(InvalidArgumentException::class, 'Duplicate integration adapter.');

    expect(fn () => $registry->get('missing'))
        ->toThrow(InvalidArgumentException::class, 'Unknown integration [missing].');

    expect(fn () => $registry->oauth('basic-pay'))
        ->toThrow(InvalidArgumentException::class, 'Integration [basic-pay] does not support OAuth.');
});

it('validates public https URLs and blocks private, loopback, and invalid URLs in SsrfGuard', function (): void {
    // Public IP literal and unresolvable hostname pass
    SsrfGuard::assertPublicHttps('https://8.8.8.8/oidc');
    SsrfGuard::assertPublicHttps('https://1.1.1.1/oidc');
    SsrfGuard::assertPublicHttps('https://nonexistent.example.invalid/oidc');

    $blocked = [
        'http://example.com/oidc',
        'ftp://8.8.8.8/oidc',
        'not-a-url',
        'https:///no-host',
        'https://localhost/oidc',
        'https://LOCALHOST/oidc',
        'https://sub.localhost/oidc',
        'https://127.0.0.1/oidc',
        'https://169.254.169.254/latest/meta-data',
        'https://10.0.0.1/internal',
        'https://172.16.0.1/internal',
        'https://192.168.1.1/internal',
        'https://[::1]/oidc',
        'https://[fe80::1]/oidc',
    ];

    foreach ($blocked as $url) {
        expect(fn () => SsrfGuard::assertPublicHttps($url))
            ->toThrow(InvalidArgumentException::class);
    }

    // Exercise hostname DNS resolution returning a non-public IP when localhost.localdomain or gethostname() resolves locally
    foreach (['localhost.localdomain', (string) gethostname()] as $localHost) {
        $resolved = gethostbynamel($localHost) ?: [];
        $hasPrivate = false;
        foreach ($resolved as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $hasPrivate = true;
                break;
            }
        }
        if ($hasPrivate) {
            expect(fn () => SsrfGuard::assertPublicHttps('https://'.$localHost.'/oidc'))
                ->toThrow(InvalidArgumentException::class, 'non-public address');
            break;
        }
    }
});

it('covers integration models, casts, hidden fields, relationships, and helpers', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    $accounting = AccountingIntegration::create([
        'user_id' => $user->id,
        'platform' => 'quickbooks',
        'connection_details' => ['realm_id' => '12345'],
        'last_synced' => now(),
    ]);

    expect($accounting->user->id)->toBe($user->id)
        ->and($accounting->connection_details)->toBe(['realm_id' => '12345'])
        ->and($accounting->last_synced)->not->toBeNull();

    $connection = IntegrationConnection::create([
        'scope_type' => 'team',
        'scope_id' => (string) $team->id,
        'provider' => 'stripe',
        'credentials' => 'encrypted-blob',
        'capabilities' => ['payments', 'refunds'],
        'status' => 'connected',
        'last_tested_at' => now(),
    ]);

    expect($connection->capabilities)->toBe(['payments', 'refunds'])
        ->and($connection->last_tested_at)->not->toBeNull()
        ->and($connection->provider)->toBe('stripe');

    $oauth = OAuthConfiguration::create([
        'service_name' => 'google',
        'account_name' => 'Workspace Google',
        'client_id' => 'cid-1',
        'client_secret' => 'csec-1',
        'access_token' => 'acc-1',
        'refresh_token' => 'ref-1',
        'token_expires_at' => now()->addHour(),
        'additional_settings' => ['region' => 'eu'],
        'is_active' => true,
    ]);

    $oauthArray = $oauth->toArray();
    expect(OAuthConfiguration::getConfig('google')?->id)->toBe($oauth->id)
        ->and(OAuthConfiguration::getConfig('nonexistent'))->toBeNull()
        ->and($oauthArray)->not->toHaveKeys(['client_secret', 'access_token', 'refresh_token'])
        ->and($oauth->client_secret)->toBe('csec-1')
        ->and($oauth->additional_settings)->toBe(['region' => 'eu'])
        ->and($oauth->is_active)->toBeTrue();

    $webhook = Webhook::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'name' => 'Main Hook',
        'url' => 'https://hooks.example.com/main',
        'events' => ['contact.created'],
        'secret' => 'top-secret',
    ]);

    $delivery = WebhookDelivery::create([
        'team_id' => $team->id,
        'webhook_id' => $webhook->id,
        'event' => 'contact.created',
        'success' => true,
        'status_code' => 200,
    ]);

    expect($webhook->is_active)->toBeTrue()
        ->and($webhook->failure_count)->toBe(0)
        ->and($webhook->toArray())->not->toHaveKey('secret')
        ->and($webhook->deliveries)->toHaveCount(1)
        ->and($delivery->webhook->id)->toBe($webhook->id)
        ->and($delivery->success)->toBeTrue()
        ->and($delivery->status_code)->toBe(200)
        ->and(Webhook::EVENTS)->toContain('contact.created', 'ticket.closed');
});

it('delegates accounting operations and rejects unsupported platforms in AccountingService', function (): void {
    $service = new AccountingService(new QuickBooksService(), new XeroService());

    $qb = new AccountingIntegration(['platform' => 'quickbooks']);
    $xero = new AccountingIntegration(['platform' => 'xero']);
    $bad = new AccountingIntegration(['platform' => 'sage']);

    expect($service->syncInvoice($qb, 'inv-1'))->toBe(['provider' => 'quickbooks', 'invoice' => 'inv-1'])
        ->and($service->syncInvoice($xero, 'inv-2'))->toBe(['provider' => 'xero', 'invoice' => 'inv-2'])
        ->and($service->syncPayment($qb, 'pay-1'))->toBe(['provider' => 'quickbooks', 'payment' => 'pay-1'])
        ->and($service->syncPayment($xero, 'pay-2'))->toBe(['provider' => 'xero', 'payment' => 'pay-2'])
        ->and($service->connectPlatform('quickbooks', ['k' => 'v']))->toBe(['provider' => 'quickbooks', 'credentials' => ['k' => 'v']])
        ->and($service->connectPlatform('xero', ['k' => 'v']))->toBe(['provider' => 'xero', 'credentials' => ['k' => 'v']]);

    expect(fn () => $service->syncInvoice($bad, 'inv'))->toThrow(AccountingIntegrationException::class, 'Unsupported accounting platform');
    expect(fn () => $service->syncPayment($bad, 'pay'))->toThrow(AccountingIntegrationException::class, 'Unsupported accounting platform');
    expect(fn () => $service->connectPlatform('sage', []))->toThrow(AccountingIntegrationException::class, 'Unsupported accounting platform');
});

it('handles success, non-array responses, HTTP failures, and guards in DirectSocialApiClient', function (): void {
    $platform = new PlatformConfiguration();
    $platform->currentProvider = 'direct';
    $this->app->instance(PlatformConfiguration::class, $platform);

    config([
        'services.zernio.direct_api_enabled' => true,
        'services.social.direct_base_urls.twitter' => 'https://api.twitter.test/v2/',
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
            ->push(['error' => 'server error'], 500),
    ]);

    expect($client->request($config, 'post', 'tweets', ['text' => 'hello']))->toBe(['id' => 't1'])
        ->and($client->request($config, 'post', '/tweets', ['text' => 'hello']))->toBe([]);

    expect(fn () => $client->request($config, 'post', 'tweets', ['text' => 'hello']))
        ->toThrow(RuntimeException::class, 'Direct social API request failed with status 500.');

    // Missing token -> 422
    try {
        $client->request(new OAuthConfiguration(['service_name' => 'twitter', 'access_token' => '']), 'get', 'me');
        $this->fail('Expected 422 HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }

    // Invalid base URL -> 422
    config(['services.social.direct_base_urls.twitter' => 'not-a-url']);
    try {
        $client->request($config, 'get', 'me');
        $this->fail('Expected 422 HttpException for invalid URL');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }

    // Disabled config flag -> 503
    config([
        'services.social.direct_base_urls.twitter' => 'https://api.twitter.test/v2',
        'services.zernio.direct_api_enabled' => false,
    ]);
    try {
        $client->request($config, 'get', 'me');
        $this->fail('Expected 503 HttpException when direct_api_enabled is false');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(503);
    }

    // Non-direct provider -> 503
    config(['services.zernio.direct_api_enabled' => true]);
    $platform->currentProvider = 'zernio';
    try {
        $client->request($config, 'get', 'me');
        $this->fail('Expected 503 HttpException when provider is not direct');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(503);
    }
});
