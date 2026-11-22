<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Tests\Feature;

use App\Models\User;
use App\Services\Marketing\PlatformConfiguration;
use App\Services\Marketing\WorkspaceAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Liberu\Foundation\Integrations\Services\QuickBooksService;
use Liberu\Foundation\Integrations\Services\XeroService;
use Liberu\Foundation\Organizations\Models\Team;
use Mockery;
use RuntimeException;
use Tests\TestCase;

require_once dirname(__DIR__).'/Stubs.php';

class OAuthAndAccountingControllersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
        if (! Schema::hasColumn('oauth_configurations', 'client_id')) {
            Schema::table('oauth_configurations', function (Blueprint $table): void {
                $table->string('client_id')->nullable();
            });
        }
    }

    public function test_accounting_integration_controller_connect_status_and_disconnect(): void
    {
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
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('quickbooks', $res->getData(true)['message']);

        $integration = AccountingIntegration::firstOrFail();
        $status = $controller->status($integration);
        $this->assertTrue($status->getData(true)['connected']);

        $disconnected = $controller->disconnect($integration);
        $this->assertSame(200, $disconnected->getStatusCode());
        $this->assertDatabaseCount('accounting_integrations', 0);

        // Exception path
        $failingService = Mockery::mock(AccountingService::class);
        $failingService->shouldReceive('connectPlatform')->andThrow(new RuntimeException('Bad creds'));
        $failingController = new AccountingIntegrationController($failingService);
        $failRes = $failingController->connect($req);
        $this->assertSame(400, $failRes->getStatusCode());
        $this->assertSame('Bad creds', $failRes->getData(true)['error']);
    }

    public function test_oauth_controller_redirect_and_callbacks(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $controller = new OAuthController();

        // Missing config on redirect and callback
        $this->assertInstanceOf(RedirectResponse::class, $controller->redirect('google'));
        $this->assertInstanceOf(RedirectResponse::class, $controller->callback('google'));

        foreach (['google', 'facebook', 'linkedin-openid'] as $provider) {
            OAuthConfiguration::create([
                'service_name' => $provider,
                'client_id' => 'cid-'.$provider,
                'client_secret' => 'csec-'.$provider,
                'is_active' => true,
            ]);
        }

        // Redirect for google (covers scopes + google offline params)
        $providerMock = Mockery::mock();
        $providerMock->shouldReceive('scopes')->once()->andReturnSelf();
        $providerMock->shouldReceive('with')->once()->with(['access_type' => 'offline', 'prompt' => 'consent'])->andReturnSelf();
        $providerMock->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://accounts.google.com/auth'));
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($providerMock);

        $redirect = $controller->redirect('google');
        $this->assertSame('https://accounts.google.com/auth', $redirect->getTargetUrl());

        // Callback for facebook, linkedin-openid, google (success + exception in HTTP helpers)
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = 'prov-1';
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
            $driver->shouldReceive('user')->twice()->andReturn($socialiteUser);
            Socialite::shouldReceive('driver')->with($provider)->twice()->andReturn($driver);

            Http::fake(fn () => Http::response(['data' => [['id' => 'p1']], 'elements' => [['id' => 'o1']], 'items' => [['id' => 'c1']]], 200));
            $res = $controller->callback($provider);
            $this->assertInstanceOf(RedirectResponse::class, $res);

            Http::fake(fn () => throw new RuntimeException('network error'));
            $res2 = $controller->callback($provider);
            $this->assertInstanceOf(RedirectResponse::class, $res2);
        }

        // Outer callback catch
        $failingDriver = Mockery::mock();
        $failingDriver->shouldReceive('user')->once()->andThrow(new RuntimeException('oauth error'));
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($failingDriver);
        $errRes = $controller->callback('google');
        $this->assertInstanceOf(RedirectResponse::class, $errRes);
    }

    public function test_oauth_configuration_controller_full_lifecycle(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
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

        // index & create views (fake view finder via View::addNamespace or temporary blade)
        $tmpViews = sys_get_temp_dir().'/integrations_views_'.uniqid();
        @mkdir($tmpViews.'/oauth/configurations', 0777, true);
        file_put_contents($tmpViews.'/oauth/configurations/index.blade.php', 'index');
        file_put_contents($tmpViews.'/oauth/configurations/create.blade.php', 'create');
        View::addLocation($tmpViews);

        $this->assertSame('oauth.configurations.index', $controller->index()->name());
        $this->assertSame('oauth.configurations.create', $controller->create()->name());

        // store (note: sqlite table has client_id/client_secret NOT NULL from base migration, so set defaults via creating event)
        OAuthConfiguration::creating(function (OAuthConfiguration $m): void {
            $m->client_id ??= 'default-cid';
            $m->client_secret ??= 'default-csec';
        });

        $storeReq = Request::create('/oauth/configurations', 'POST', [
            'service_name' => 'google',
            'account_name' => 'Primary Google',
            'additional_settings' => ['region' => 'eu'],
        ]);
        $storeRes = $controller->store($storeReq);
        $this->assertInstanceOf(RedirectResponse::class, $storeRes);

        $config = OAuthConfiguration::withoutGlobalScopes()->firstOrFail();

        // authenticate (google -> AbstractProvider with scopes & offline params)
        $abstractProvider = Mockery::mock(AbstractProvider::class);
        $abstractProvider->shouldReceive('scopes')->once()->andReturnSelf();
        $abstractProvider->shouldReceive('with')->once()->andReturnSelf();
        $abstractProvider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2'));
        Socialite::shouldReceive('forgetDrivers')->atLeast()->once();
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($abstractProvider);

        $authRes = $controller->authenticate('google', $config->id);
        $this->assertSame('https://accounts.google.com/o/oauth2', $authRes->getTargetUrl());

        // callback success
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

        $cbRes = $controller->callback('google');
        $this->assertInstanceOf(RedirectResponse::class, $cbRes);
        $this->assertTrue($config->fresh()->is_active);

        // callback non-SocialiteUser branch (triggers catch)
        session(['oauth_config_id' => $config->id, 'oauth_config_service' => 'google']);
        $badDriver = Mockery::mock();
        $badDriver->shouldReceive('user')->once()->andReturn(new \stdClass());
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($badDriver);
        $badCbRes = $controller->callback('google');
        $this->assertInstanceOf(RedirectResponse::class, $badCbRes);

        // authenticate exception branch (instagram -> facebook credentialProvider)
        $igConfig = OAuthConfiguration::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'service_name' => 'instagram',
            'account_name' => 'IG',
            'client_id' => 'cid',
            'client_secret' => 'csec',
        ]);
        Socialite::shouldReceive('driver')->with('facebook')->once()->andThrow(new RuntimeException('driver fail'));
        $igRes = $controller->authenticate('instagram', $igConfig->id);
        $this->assertInstanceOf(RedirectResponse::class, $igRes);

        // destroy
        $delRes = $controller->destroy($igConfig);
        $this->assertInstanceOf(RedirectResponse::class, $delRes);
        $this->assertDatabaseMissing('oauth_configurations', ['id' => $igConfig->id]);
    }
}
