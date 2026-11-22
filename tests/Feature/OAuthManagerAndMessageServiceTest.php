<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\Email;
use App\Models\User;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Liberu\Foundation\Integrations\Models\OAuthConfiguration;
use Liberu\Foundation\Integrations\Services\FacebookMessengerService;
use Liberu\Foundation\Integrations\Services\MessageService;
use Liberu\Foundation\Integrations\Services\OAuth\OAuthManager;
use Liberu\Foundation\Integrations\Services\WhatsAppBusinessService;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

require_once dirname(__DIR__).'/Stubs.php';

class OAuthManagerAndMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/oauth/{provider}/callback', fn () => 'ok')->name('oauth.callback');

        Builder::macro('ofType', function (string $type) {
            /** @var Builder $this */
            return $this->where('provider', $type);
        });
    }

    private function managerWithResponses(array $responses): OAuthManager
    {
        $manager = new OAuthManager();
        $mock = new MockHandler($responses);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $prop = new ReflectionProperty($manager, 'client');
        $prop->setValue($manager, $client);

        return $manager;
    }

    public function test_oauth_manager_authorization_url_token_exchange_refresh_and_save(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $manager = new OAuthManager();
        $this->assertContains('google', $manager->getSupportedProviders());
        $this->assertTrue($manager->isProviderSupported('mailchimp'));
        $this->assertFalse($manager->isProviderSupported('nope'));

        // Unsupported provider exceptions
        try {
            $manager->getAuthorizationUrl('nope');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            $manager->exchangeCodeForToken('nope', 'code');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // Missing config exceptions
        try {
            $manager->getAuthorizationUrl('google');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        try {
            $manager->exchangeCodeForToken('google', 'code');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        foreach (['google', 'mailchimp', 'twitter'] as $provider) {
            OAuthConfiguration::create([
                'service_name' => $provider,
                'client_id' => 'cid-'.$provider,
                'client_secret' => 'csec-'.$provider,
                'is_active' => true,
            ]);
        }

        $this->assertStringContainsString('access_type=offline', $manager->getAuthorizationUrl('google', ['extra']));
        $this->assertStringNotContainsString('scope=', $manager->getAuthorizationUrl('mailchimp'));
        $this->assertStringContainsString('code_challenge=challenge', $manager->getAuthorizationUrl('twitter'));

        // Token exchange: google success, mailchimp with metadata success, mailchimp with metadata error, and exchange failure
        $mgr = $this->managerWithResponses([
            new Response(200, [], json_encode(['access_token' => 'g-tok', 'expires_in' => 3600])),
            new Response(200, [], json_encode(['access_token' => 'mc-tok'])),
            new Response(200, [], json_encode(['user_id' => 'mc-1', 'accountname' => 'MC Co', 'login' => ['email' => 'mc@example.com']])),
            new Response(200, [], json_encode(['access_token' => 'mc-tok-2'])),
            new RuntimeException('metadata down'),
            new RuntimeException('token endpoint down'),
        ]);

        $gData = $mgr->exchangeCodeForToken('google', 'code-1');
        $this->assertSame('g-tok', $gData['access_token']);

        $mcData = $mgr->exchangeCodeForToken('mailchimp', 'code-2');
        $this->assertSame('mc-1', $mcData['metadata']['user_id']);

        $mcData2 = $mgr->exchangeCodeForToken('mailchimp', 'code-3');
        $this->assertSame([], $mcData2['metadata']);

        try {
            $mgr->exchangeCodeForToken('google', 'code-4');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to exchange code', $e->getMessage());
        }

        // saveConnectedAccount (mailchimp with metadata)
        $saved = $mgr->saveConnectedAccount('mailchimp', $mcData, $user->id);
        $this->assertSame('mailchimp', $saved->provider);

        // needsRefresh
        $this->assertFalse($mgr->needsRefresh(new ConnectedAccount(['expires_at' => null])));
        $this->assertFalse($mgr->needsRefresh(new ConnectedAccount(['expires_at' => Carbon::now()->addHour()])));
        $this->assertTrue($mgr->needsRefresh(new ConnectedAccount(['expires_at' => Carbon::now()->addMinutes(2)])));

        // refreshToken: no refresh_token -> false
        $noRef = ConnectedAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'p-noref',
            'token' => 't1',
        ]);
        $this->assertFalse($mgr->refreshToken($noRef));

        // refreshToken: unsupported provider
        $badProv = new ConnectedAccount(['provider' => 'nope', 'refresh_token' => 'r1']);
        try {
            $mgr->refreshToken($badProv);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // refreshToken: missing config
        $noCfg = new ConnectedAccount(['provider' => 'zoom', 'refresh_token' => 'r1']);
        try {
            $mgr->refreshToken($noCfg);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        // refreshToken: success and failure
        $refMgr = $this->managerWithResponses([
            new Response(200, [], json_encode(['access_token' => 'new-tok', 'refresh_token' => 'new-ref', 'expires_in' => 1800])),
            new RuntimeException('refresh fail'),
        ]);
        $acc = ConnectedAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'p-ref',
            'token' => 'old-tok',
            'refresh_token' => 'old-ref',
        ]);
        $this->assertTrue($refMgr->refreshToken($acc));
        $this->assertSame('new-tok', $acc->fresh()->token);
        $this->assertFalse($refMgr->refreshToken($acc));
    }

    public function test_message_service_unread_get_and_reply_across_channels(): void
    {
        Email::$created = [];
        $user = User::factory()->create();

        $gmail = ConnectedAccount::create([
            'user_id' => $user->id, 'provider' => 'gmail', 'provider_id' => 'g1', 'token' => 'g-tok',
        ]);
        $wa = ConnectedAccount::create([
            'user_id' => $user->id, 'provider' => 'whatsapp', 'provider_id' => 'w1', 'token' => 'w-tok',
        ]);
        $fb = ConnectedAccount::create([
            'user_id' => $user->id, 'provider' => 'facebook', 'provider_id' => 'f1', 'token' => 'f-tok',
        ]);

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

        $gmailProp = new ReflectionProperty($service, 'gmailService');
        $gmailSvc = $gmailProp->getValue($service);

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

        $makeMsg = function (string $id, ?object $payload) {
            return new class($id, $payload)
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
                return [['mimeType' => 'text/plain', 'body' => ['data' => base64_encode('Part body')]]];
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
        $msgItem = $makeMsg('m1', $bodyPayload);
        $gmailSvc->users_messages->listResult = [$msgItem];
        $unread = $service->getUnreadMessages();
        $this->assertCount(1, $unread['email']);
        $this->assertSame($gmail->id, $unread['email'][0]->accountId);
        $this->assertCount(1, $unread['whatsapp']);
        $this->assertCount(1, $unread['facebook']);

        // getMessage: email (body, parts, empty), whatsapp, facebook, invalid
        $gmailSvc->users_messages->getResult = $makeMsg('m1', $bodyPayload);
        $service->getMessage('m1', 'email', $gmail->id);

        $gmailSvc->users_messages->getResult = $makeMsg('m2', $partsPayload);
        $service->getMessage('m2', 'email', $gmail->id);

        $gmailSvc->users_messages->getResult = $makeMsg('m3', $emptyPayload);
        $service->getMessage('m3', 'email', $gmail->id);

        // null payload edge case via reflection
        $getContent = new \ReflectionMethod($service, 'getEmailContent');
        $this->assertSame('', $getContent->invoke($service, $makeMsg('m4', null)));

        $this->assertSame('whatsapp', $service->getMessage('w1', 'whatsapp', $wa->id)['channel']);
        $this->assertSame('facebook', $service->getMessage('f1', 'facebook', $fb->id)['channel']);

        try {
            $service->getMessage('x', 'sms', $gmail->id);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // sendReply: email, whatsapp, facebook, invalid
        $gmailSvc->users_messages->getResult = $makeMsg('m1', $bodyPayload);
        $gmailSvc->users_messages->sendResult = $makeMsg('m-sent', $bodyPayload);
        $service->sendReply('m1', 'Thanks!', 'email', $gmail->id);
        $this->assertSame('whatsapp', $service->sendReply('w1', 'Yo', 'whatsapp', $wa->id)['channel']);
        $this->assertSame('facebook', $service->sendReply('f1', 'Yo', 'facebook', $fb->id)['channel']);

        $this->expectException(InvalidArgumentException::class);
        $service->sendReply('x', 'Yo', 'sms', $gmail->id);
    }
}
