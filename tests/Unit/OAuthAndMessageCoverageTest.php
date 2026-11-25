<?php

declare(strict_types=1);

use App\Models\ConnectedAccount;
use App\Models\Email;
use App\Models\User;
use App\Services\Marketing\PlatformConfiguration;
use App\Services\Marketing\WorkspaceAccess;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Liberu\Foundation\Integrations\Http\Controllers\AccountingIntegrationController;
use Liberu\Foundation\Integrations\Http\Controllers\OAuthConfigurationController;
use Liberu\Foundation\Integrations\Http\Controllers\OAuthController;
use Liberu\Foundation\Integrations\Models\AccountingIntegration;
use Liberu\Foundation\Integrations\Models\OAuthConfiguration;
use Liberu\Foundation\Integrations\Services\AccountingService;
use Liberu\Foundation\Integrations\Services\FacebookMessengerService;
use Liberu\Foundation\Integrations\Services\MessageService;
use Liberu\Foundation\Integrations\Services\OAuth\OAuthManager;
use Liberu\Foundation\Integrations\Services\QuickBooksService;
use Liberu\Foundation\Integrations\Services\WhatsAppBusinessService;
use Liberu\Foundation\Integrations\Services\XeroService;
use Liberu\Foundation\Organizations\Models\Team;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once dirname(__DIR__).'/Stubs.php';

beforeEach(function (): void {
    Route::get('/_test/oauth/{provider}/callback', fn () => 'ok')->name('oauth.callback');
    Route::get('/_test/oauth/configurations', fn () => 'index')->name('oauth.configurations.index');
    Route::get('/_test/oauth/authenticate/{service}/{configId}', fn () => 'auth')->name('oauth.authenticate');
    Route::get('/_test/oauth/configurations/{service}/callback', fn () => 'cb')->name('oauth.configurations.callback');
    app('router')->getRoutes()->refreshNameLookups();

    if (! Schema::hasColumn('oauth_configurations', 'user_id')) {
        Schema::table('oauth_configurations', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable();
        });
    }
    if (! Schema::hasColumn('oauth_configurations', 'team_id')) {
        Schema::table('oauth_configurations', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id')->nullable();
        });
    }

    if (! Builder::hasGlobalMacro('ofType')) {
        Builder::macro('ofType', function (string $type) {
            /** @var Builder $this */
            return $this->where('provider', $type);
        });
    }
});

function oauthManagerWithResponses(array $responses): OAuthManager
{
    $manager = new OAuthManager();
    $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    $prop = new ReflectionProperty($manager, 'client');
    $prop->setValue($manager, $client);

    return $manager;
}

it('covers OAuthManager authorization URLs, token exchange, refresh, save, and helpers', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $manager = new OAuthManager();

    expect($manager->getSupportedProviders())->toContain('google', 'mailchimp', 'twitter', 'zoom')
        ->and($manager->isProviderSupported('mailchimp'))->toBeTrue()
        ->and($manager->isProviderSupported('nope'))->toBeFalse();

    // Unsupported provider & missing config exceptions
    expect(fn () => $manager->getAuthorizationUrl('nope'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $manager->exchangeCodeForToken('nope', 'code'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $manager->getAuthorizationUrl('google'))->toThrow(RuntimeException::class);
    expect(fn () => $manager->exchangeCodeForToken('google', 'code'))->toThrow(RuntimeException::class);

    foreach (['google', 'mailchimp', 'twitter', 'stripe'] as $provider) {
        OAuthConfiguration::create([
            'service_name' => $provider,
            'client_id' => 'cid-'.$provider,
            'client_secret' => 'csec-'.$provider,
            'is_active' => true,
        ]);
    }

    expect($manager->getAuthorizationUrl('google', ['extra']))->toContain('access_type=offline', 'prompt=consent')
        ->and($manager->getAuthorizationUrl('mailchimp'))->not->toContain('scope=')
        ->and($manager->getAuthorizationUrl('twitter'))->toContain('code_challenge=challenge')
        ->and($manager->getAuthorizationUrl('stripe'))->toContain('read_write');

    // Token exchange: google, mailchimp + metadata success, mailchimp + metadata failure, exchange error
    $mgr = oauthManagerWithResponses([
        new Response(200, [], json_encode(['access_token' => 'g-tok', 'expires_in' => 3600])),
        new Response(200, [], json_encode(['access_token' => 'mc-tok'])),
        new Response(200, [], json_encode(['user_id' => 'mc-1', 'accountname' => 'MC Co', 'login' => ['email' => 'mc@example.com']])),
        new Response(200, [], json_encode(['access_token' => 'mc-tok-2'])),
        new RuntimeException('metadata down'),
        new RuntimeException('token endpoint down'),
    ]);

    $gData = $mgr->exchangeCodeForToken('google', 'code-1');
    expect($gData['access_token'])->toBe('g-tok');

    $mcData = $mgr->exchangeCodeForToken('mailchimp', 'code-2');
    expect($mcData['metadata']['user_id'])->toBe('mc-1');

    $mcData2 = $mgr->exchangeCodeForToken('mailchimp', 'code-3');
    expect($mcData2['metadata'])->toBe([]);

    expect(fn () => $mgr->exchangeCodeForToken('google', 'code-4'))
        ->toThrow(RuntimeException::class, 'Failed to exchange code for token');

    // saveConnectedAccount: mailchimp with metadata + explicit userId, and google with default Auth::id()
    $savedMc = ConnectedAccount::unguarded(fn () => $mgr->saveConnectedAccount('mailchimp', $mcData, $user->id));
    expect($savedMc->provider)->toBe('mailchimp');

    $savedGoogle = ConnectedAccount::unguarded(fn () => $mgr->saveConnectedAccount('google', ['access_token' => 'g-tok', 'provider_id' => 'g-id']));
    expect($savedGoogle->provider)->toBe('google');

    // needsRefresh
    expect($mgr->needsRefresh(new ConnectedAccount(['expires_at' => null])))->toBeFalse()
        ->and($mgr->needsRefresh(new ConnectedAccount(['expires_at' => Carbon::now()->addHour()])))->toBeFalse()
        ->and($mgr->needsRefresh(new ConnectedAccount(['expires_at' => Carbon::now()->addMinutes(2)])))->toBeTrue();

    // refreshToken: no refresh_token -> false
    $noRef = ConnectedAccount::unguarded(fn () => ConnectedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'p-noref',
        'token' => 't1',
    ]));
    expect($mgr->refreshToken($noRef))->toBeFalse();

    // refreshToken: unsupported provider & missing config
    $badProv = new ConnectedAccount();
    $badProv->forceFill(['provider' => 'nope', 'refresh_token' => 'r1']);
    expect(fn () => $mgr->refreshToken($badProv))->toThrow(InvalidArgumentException::class);

    $noCfg = new ConnectedAccount();
    $noCfg->forceFill(['provider' => 'zoom', 'refresh_token' => 'r1']);
    expect(fn () => $mgr->refreshToken($noCfg))->toThrow(RuntimeException::class);

    // refreshToken: success with expires_in, success without expires_in, and failure
    $refMgr = oauthManagerWithResponses([
        new Response(200, [], json_encode(['access_token' => 'new-tok', 'refresh_token' => 'new-ref', 'expires_in' => 1800])),
        new Response(200, [], json_encode(['access_token' => 'new-tok-2'])),
        new RuntimeException('refresh fail'),
    ]);

    $acc = ConnectedAccount::unguarded(fn () => ConnectedAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'p-ref',
        'token' => 'old-tok',
        'refresh_token' => 'old-ref',
    ]));

    expect(ConnectedAccount::unguarded(fn () => $refMgr->refreshToken($acc)))->toBeTrue()
        ->and($acc->fresh()->token)->toBe('new-tok')
        ->and(ConnectedAccount::unguarded(fn () => $refMgr->refreshToken($acc->fresh())))->toBeTrue()
        ->and($acc->fresh()->token)->toBe('new-tok-2')
        ->and($refMgr->refreshToken($acc->fresh()))->toBeFalse();
});

it('covers MessageService unread messages, fetching, content parsing, and replies across all channels', function (): void {
    Email::$created = [];
    $user = User::factory()->create();

    $gmail = ConnectedAccount::unguarded(fn () => ConnectedAccount::create([
        'user_id' => $user->id, 'provider' => 'gmail', 'provider_id' => 'g1', 'token' => 'g-tok',
    ]));
    $wa = ConnectedAccount::unguarded(fn () => ConnectedAccount::create([
        'user_id' => $user->id, 'provider' => 'whatsapp', 'provider_id' => 'w1', 'token' => 'w-tok',
    ]));
    $fb = ConnectedAccount::unguarded(fn () => ConnectedAccount::create([
        'user_id' => $user->id, 'provider' => 'facebook', 'provider_id' => 'f1', 'token' => 'f-tok',
    ]));

    $waService = new class() extends WhatsAppBusinessService
    {
        public function getUnreadMessages($account): array
        {
            return [['id' => 'wa-1']];
        }
    };
    $fbService = new class() extends FacebookMessengerService
    {
        public function getUnreadMessages($account): array
        {
            return [['id' => 'fb-1']];
        }
    };

    $service = new MessageService($waService, $fbService);
    $gmailSvc = (new ReflectionProperty($service, 'gmailService'))->getValue($service);

    $makeHeader = fn (string $name, string $val) => new class($name, $val)
    {
        public function __construct(private string $n, private string $v) {}

        public function getName(): string
        {
            return $this->n;
        }

        public function getValue(): string
        {
            return $this->v;
        }
    };

    $makeMsg = fn (string $id, ?object $payload) => new class($id, $payload)
    {
        public ?int $accountId = null;

        public function __construct(private string $id, private ?object $payload) {}

        public function getId(): string
        {
            return $this->id;
        }

        public function getThreadId(): string
        {
            return 'thread-1';
        }

        public function getPayload(): ?object
        {
            return $this->payload;
        }
    };

    $bodyPayload = new class([$makeHeader('From', 'a@b.com'), $makeHeader('To', 'me@b.com'), $makeHeader('Subject', 'Hi')])
    {
        public function __construct(private array $headers) {}

        public function getHeaders(): array
        {
            return $this->headers;
        }

        public function getParts(): array
        {
            return [];
        }

        public function getBody(): object
        {
            return new class()
            {
                public function getData(): string
                {
                    return base64_encode('Hello body');
                }
            };
        }
    };

    $partsPayload = new class([$makeHeader('From', 'a@b.com')])
    {
        public function __construct(private array $headers) {}

        public function getHeaders(): array
        {
            return $this->headers;
        }

        public function getParts(): array
        {
            return [
                ['mimeType' => 'text/html', 'body' => ['data' => base64_encode('<p>HTML</p>')]],
                ['mimeType' => 'text/plain', 'body' => ['data' => base64_encode('Part body')]],
            ];
        }

        public function getBody(): ?object
        {
            return null;
        }
    };

    $emptyPayload = new class([])
    {
        public function __construct(private array $headers) {}

        public function getHeaders(): array
        {
            return $this->headers;
        }

        public function getParts(): array
        {
            return [];
        }

        public function getBody(): ?object
        {
            return null;
        }
    };

    // getUnreadMessages
    $gmailSvc->users_messages->listResult = [$makeMsg('m1', $bodyPayload)];
    $unread = $service->getUnreadMessages();

    expect($unread['email'])->toHaveCount(1)
        ->and($unread['email'][0]->accountId)->toBe($gmail->id)
        ->and($unread['whatsapp'])->toHaveCount(1)
        ->and($unread['facebook'])->toHaveCount(1);

    // getMessage across body, parts, empty, null payload, whatsapp, facebook, invalid
    $gmailSvc->users_messages->getResult = $makeMsg('m1', $bodyPayload);
    $service->getMessage('m1', 'email', $gmail->id);

    $gmailSvc->users_messages->getResult = $makeMsg('m2', $partsPayload);
    $service->getMessage('m2', 'email', $gmail->id);

    $gmailSvc->users_messages->getResult = $makeMsg('m3', $emptyPayload);
    $service->getMessage('m3', 'email', $gmail->id);

    $getContent = new ReflectionMethod($service, 'getEmailContent');
    expect($getContent->invoke($service, $makeMsg('m4', null)))->toBe('')
        ->and($service->getMessage('w1', 'whatsapp', $wa->id)['channel'])->toBe('whatsapp')
        ->and($service->getMessage('f1', 'facebook', $fb->id)['channel'])->toBe('facebook');

    expect(fn () => $service->getMessage('x', 'sms', $gmail->id))
        ->toThrow(InvalidArgumentException::class, 'Invalid message type: sms');

    // sendReply across email, whatsapp, facebook, invalid
    $gmailSvc->users_messages->getResult = $makeMsg('m1', $bodyPayload);
    $gmailSvc->users_messages->sendResult = $makeMsg('m-sent', $bodyPayload);
    $service->sendReply('m1', 'Thanks!', 'email', $gmail->id);

    expect($service->sendReply('w1', 'Yo', 'whatsapp', $wa->id)['channel'])->toBe('whatsapp')
        ->and($service->sendReply('f1', 'Yo', 'facebook', $fb->id)['channel'])->toBe('facebook')
        ->and(Email::$created)->toHaveCount(4);

    expect(fn () => $service->sendReply('x', 'Yo', 'sms', $gmail->id))
        ->toThrow(InvalidArgumentException::class, 'Invalid message type: sms');
});

it('covers AccountingIntegrationController connect, status, disconnect, and error handling', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $controller = new AccountingIntegrationController(
        new AccountingService(new QuickBooksService(), new XeroService()),
    );

    $req = Request::create('/accounting/connect', 'POST', [
        'platform' => 'quickbooks',
        'credentials' => ['token' => 'abc'],
    ]);

    $res = $controller->connect($req);
    expect($res->getStatusCode())->toBe(200)
        ->and($res->getData(true)['message'])->toContain('quickbooks');

    $integration = AccountingIntegration::firstOrFail();
    $status = $controller->status($integration);
    expect($status->getData(true)['connected'])->toBeTrue()
        ->and($status->getData(true)['platform'])->toBe('quickbooks');

    $disconnected = $controller->disconnect($integration);
    expect($disconnected->getStatusCode())->toBe(200);
    $this->assertDatabaseCount('accounting_integrations', 0);

    // Exception path
    $failingService = Mockery::mock(AccountingService::class);
    $failingService->shouldReceive('connectPlatform')->andThrow(new RuntimeException('Bad creds'));
    $failingController = new AccountingIntegrationController($failingService);

    $failRes = $failingController->connect($req);
    expect($failRes->getStatusCode())->toBe(400)
        ->and($failRes->getData(true)['error'])->toBe('Bad creds');
});

it('covers OAuthController redirect and callbacks for all providers and error paths', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    ConnectedAccount::creating(function (ConnectedAccount $account): void {
        $account->token ??= 'default-token';
        $account->provider_id ??= 'default-provider-id';
    });

    $controller = new OAuthController();

    // Missing config on redirect and callback
    expect($controller->redirect('google'))->toBeInstanceOf(RedirectResponse::class)
        ->and($controller->callback('google'))->toBeInstanceOf(RedirectResponse::class);

    foreach (['google', 'facebook', 'linkedin-openid', 'zoom'] as $provider) {
        OAuthConfiguration::create([
            'service_name' => $provider,
            'client_id' => 'cid-'.$provider,
            'client_secret' => 'csec-'.$provider,
            'is_active' => true,
        ]);
    }

    // Redirect for google (scopes + offline params)
    $googleProvider = Mockery::mock();
    $googleProvider->shouldReceive('scopes')->once()->andReturnSelf();
    $googleProvider->shouldReceive('with')->once()->with(['access_type' => 'offline', 'prompt' => 'consent'])->andReturnSelf();
    $googleProvider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://accounts.google.com/auth'));
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($googleProvider);

    expect($controller->redirect('google')->getTargetUrl())->toBe('https://accounts.google.com/auth');

    // Redirect for zoom (no scopes, not google)
    $zoomProvider = Mockery::mock();
    $zoomProvider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://zoom.us/oauth'));
    Socialite::shouldReceive('driver')->with('zoom')->once()->andReturn($zoomProvider);

    expect($controller->redirect('zoom')->getTargetUrl())->toBe('https://zoom.us/oauth');

    // Callbacks for facebook, linkedin-openid, google: 200 success, 500 failure, and HTTP exception
    $socialiteUser = new SocialiteUser();
    $socialiteUser->name = 'Jane';
    $socialiteUser->nickname = 'jane';
    $socialiteUser->email = 'jane@example.com';
    $socialiteUser->avatar = 'https://example.com/a.png';
    $socialiteUser->token = 'tok-1';
    $socialiteUser->refreshToken = 'ref-1';
    $socialiteUser->expiresIn = 3600;

    foreach (['facebook', 'linkedin-openid', 'google'] as $idx => $provider) {
        $socialiteUser->id = 'prov-'.$idx;
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->times(3)->andReturn($socialiteUser);
        Socialite::shouldReceive('driver')->with($provider)->times(3)->andReturn($driver);

        Http::fake(fn () => Http::response([
            'data' => [['id' => 'p1']],
            'elements' => [['id' => 'o1']],
            'items' => [['id' => 'c1']],
        ], 200));
        expect($controller->callback($provider))->toBeInstanceOf(RedirectResponse::class);

        Http::fake(fn () => Http::response(['error' => 'failed'], 500));
        expect($controller->callback($provider))->toBeInstanceOf(RedirectResponse::class);

        Http::fake(fn () => throw new RuntimeException('network error'));
        expect($controller->callback($provider))->toBeInstanceOf(RedirectResponse::class);
    }

    // Outer callback catch
    $failingDriver = Mockery::mock();
    $failingDriver->shouldReceive('user')->once()->andThrow(new RuntimeException('oauth error'));
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($failingDriver);

    expect($controller->callback('google'))->toBeInstanceOf(RedirectResponse::class);
});

it('covers OAuthConfigurationController full lifecycle and edge cases', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $user->refresh();
    $this->actingAs($user);

    $platform = new PlatformConfiguration();
    $platform->currentProvider = 'direct';
    $platform->credentials = [
        'google_client_id' => 'g-id',
        'google_client_secret' => 'g-sec',
        'facebook_client_id' => 'f-id',
        'facebook_client_secret' => 'f-sec',
        'microsoft_client_id' => 'm-id',
        'microsoft_client_secret' => 'm-sec',
    ];
    $this->app->instance(PlatformConfiguration::class, $platform);

    $workspaceAccess = new WorkspaceAccess();
    $workspaceAccess->allowed = true;
    $this->app->instance(WorkspaceAccess::class, $workspaceAccess);

    $controller = new OAuthConfigurationController();

    // Views for index & create
    $tmpViews = sys_get_temp_dir().'/integrations_views_'.uniqid();
    @mkdir($tmpViews.'/oauth/configurations', 0777, true);
    file_put_contents($tmpViews.'/oauth/configurations/index.blade.php', 'index');
    file_put_contents($tmpViews.'/oauth/configurations/create.blade.php', 'create');
    View::addLocation($tmpViews);

    expect($controller->index()->name())->toBe('oauth.configurations.index')
        ->and($controller->create()->name())->toBe('oauth.configurations.create');

    // Supply defaults for NOT NULL columns during store()
    OAuthConfiguration::creating(function (OAuthConfiguration $m): void {
        $m->client_id ??= 'default-cid';
        $m->client_secret ??= 'default-csec';
    });

    // Unauthorized store -> 403
    $workspaceAccess->allowed = false;
    $storeReq = Request::create('/oauth/configurations', 'POST', [
        'service_name' => 'google',
        'account_name' => 'Primary Google',
        'additional_settings' => ['region' => 'eu'],
    ]);
    try {
        $controller->store($storeReq);
        $this->fail('Expected 403 on unauthorized store');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    // Authorized store
    $workspaceAccess->allowed = true;
    $storeRes = $controller->store($storeReq);
    expect($storeRes)->toBeInstanceOf(RedirectResponse::class);

    $config = OAuthConfiguration::withoutGlobalScopes()->firstOrFail();

    // authenticate: google with AbstractProvider (scopes + offline params)
    $abstractProvider = Mockery::mock(AbstractProvider::class);
    $abstractProvider->shouldReceive('scopes')->once()->andReturnSelf();
    $abstractProvider->shouldReceive('with')->once()->andReturnSelf();
    $abstractProvider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2'));
    Socialite::shouldReceive('forgetDrivers')->atLeast()->once();
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($abstractProvider);

    expect($controller->authenticate('google', $config->id)->getTargetUrl())
        ->toBe('https://accounts.google.com/o/oauth2');

    // callback: google success with expiresIn
    $socialiteUser = new SocialiteUser();
    $socialiteUser->id = 'g-user-1';
    $socialiteUser->email = 'g@example.com';
    $socialiteUser->name = 'G User';
    $socialiteUser->avatar = 'https://example.com/g.png';
    $socialiteUser->token = 'access-tok';
    $socialiteUser->refreshToken = 'refresh-tok';
    $socialiteUser->expiresIn = 3600;

    $cbDriver = Mockery::mock();
    $cbDriver->shouldReceive('user')->once()->andReturn($socialiteUser);
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($cbDriver);

    expect($controller->callback('google'))->toBeInstanceOf(RedirectResponse::class)
        ->and($config->fresh()->is_active)->toBeTrue();

    // callback: mismatched session service triggers catch block
    session(['oauth_config_service' => 'other']);
    expect($controller->callback('google'))->toBeInstanceOf(RedirectResponse::class);

    // callback: non-SocialiteUser triggers catch block
    session(['oauth_config_id' => $config->id, 'oauth_config_service' => 'google']);
    $badDriver = Mockery::mock();
    $badDriver->shouldReceive('user')->once()->andReturn(new stdClass());
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($badDriver);
    expect($controller->callback('google'))->toBeInstanceOf(RedirectResponse::class);

    // authenticate: outlook (microsoft credentialProvider) & whatsapp (default credentialProvider)
    $outlookConfig = OAuthConfiguration::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'service_name' => 'outlook',
        'account_name' => 'Outlook',
        'client_id' => 'cid',
        'client_secret' => 'csec',
    ]);
    $msProvider = Mockery::mock();
    $msProvider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://login.microsoftonline.com/auth'));
    Socialite::shouldReceive('driver')->with('microsoft')->once()->andReturn($msProvider);
    expect($controller->authenticate('outlook', $outlookConfig->id)->getTargetUrl())->toBe('https://login.microsoftonline.com/auth');

    // callback: outlook with null expiresIn
    $socialiteUser->expiresIn = null;
    $msCbDriver = Mockery::mock();
    $msCbDriver->shouldReceive('user')->once()->andReturn($socialiteUser);
    Socialite::shouldReceive('driver')->with('microsoft')->once()->andReturn($msCbDriver);
    expect($controller->callback('outlook'))->toBeInstanceOf(RedirectResponse::class);

    // authenticate: instagram (facebook credentialProvider) throws -> catch redirect
    $igConfig = OAuthConfiguration::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'service_name' => 'instagram',
        'account_name' => 'IG',
        'client_id' => 'cid',
        'client_secret' => 'csec',
    ]);
    Socialite::shouldReceive('driver')->with('facebook')->once()->andThrow(new RuntimeException('driver fail'));
    expect($controller->authenticate('instagram', $igConfig->id))->toBeInstanceOf(RedirectResponse::class);

    // authenticate: social service when provider !== 'direct' -> 422
    $platform->currentProvider = 'zernio';
    try {
        $controller->authenticate('instagram', $igConfig->id);
        $this->fail('Expected 422 when provider is not direct');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }
    $platform->currentProvider = 'direct';

    // destroy: forbidden for another user -> 403
    $otherUser = User::factory()->create();
    $otherConfig = OAuthConfiguration::create([
        'team_id' => $team->id,
        'user_id' => $otherUser->id,
        'service_name' => 'whatsapp',
        'account_name' => 'Other WA',
        'client_id' => 'cid',
        'client_secret' => 'csec',
    ]);
    try {
        $controller->destroy($otherConfig);
        $this->fail('Expected 403 when destroying another user config');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    // destroy: own config succeeds
    expect($controller->destroy($igConfig))->toBeInstanceOf(RedirectResponse::class);
    $this->assertDatabaseMissing('oauth_configurations', ['id' => $igConfig->id]);
});
