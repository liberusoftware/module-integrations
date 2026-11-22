<?php

declare(strict_types=1);

namespace App\Exceptions {
    if (! class_exists(AccountingIntegrationException::class)) {
        class AccountingIntegrationException extends \RuntimeException {}
    }
}

namespace Liberu\Foundation\Integrations\Services {
    if (! class_exists(QuickBooksService::class)) {
        class QuickBooksService
        {
            public function syncInvoice($integration, $invoice)
            {
                return ['provider' => 'quickbooks', 'invoice' => $invoice];
            }

            public function syncPayment($integration, $payment)
            {
                return ['provider' => 'quickbooks', 'payment' => $payment];
            }

            public function connect(array $credentials)
            {
                return ['provider' => 'quickbooks', 'credentials' => $credentials];
            }
        }
    }

    if (! class_exists(XeroService::class)) {
        class XeroService
        {
            public function syncInvoice($integration, $invoice)
            {
                return ['provider' => 'xero', 'invoice' => $invoice];
            }

            public function syncPayment($integration, $payment)
            {
                return ['provider' => 'xero', 'payment' => $payment];
            }

            public function connect(array $credentials)
            {
                return ['provider' => 'xero', 'credentials' => $credentials];
            }
        }
    }

    if (! class_exists(WhatsAppBusinessService::class)) {
        class WhatsAppBusinessService
        {
            public function getUnreadMessages($account): array
            {
                return [];
            }

            public function getMessage($account, $messageId)
            {
                return ['channel' => 'whatsapp', 'id' => $messageId];
            }

            public function sendReply($account, $messageId, $body)
            {
                return ['channel' => 'whatsapp', 'id' => $messageId, 'body' => $body];
            }
        }
    }

    if (! class_exists(FacebookMessengerService::class)) {
        class FacebookMessengerService
        {
            public function getUnreadMessages($account): array
            {
                return [];
            }

            public function getMessage($account, $messageId)
            {
                return ['channel' => 'facebook', 'id' => $messageId];
            }

            public function sendReply($account, $messageId, $body)
            {
                return ['channel' => 'facebook', 'id' => $messageId, 'body' => $body];
            }
        }
    }
}

namespace App\Services\Marketing {
    if (! class_exists(PlatformConfiguration::class)) {
        class PlatformConfiguration
        {
            public string $currentProvider = 'direct';

            /** @var array<string, string|null> */
            public array $credentials = [];

            public function provider(): string
            {
                return $this->currentProvider;
            }

            public function credential(string $key): ?string
            {
                return $this->credentials[$key] ?? null;
            }
        }
    }

    if (! class_exists(WorkspaceAccess::class)) {
        class WorkspaceAccess
        {
            public bool $allowed = true;

            public function canManage($user, $team): bool
            {
                return $this->allowed;
            }
        }
    }
}

namespace App\Models {
    if (! class_exists(Email::class)) {
        class Email
        {
            /** @var list<array<string, mixed>> */
            public static array $created = [];

            public static function create(array $attributes): array
            {
                self::$created[] = $attributes;

                return $attributes;
            }
        }
    }
}

namespace App\Filament\Concerns {
    if (! trait_exists(EnforcesResourcePermissions::class)) {
        trait EnforcesResourcePermissions {}
    }
}

namespace {
    if (! class_exists('Google_Client')) {
        class Google_Client
        {
            public array $calls = [];

            public function setApplicationName($name): void
            {
                $this->calls['appName'] = $name;
            }

            public function setScopes($scopes): void
            {
                $this->calls['scopes'] = $scopes;
            }

            public function setAuthConfig($path): void
            {
                $this->calls['authConfig'] = $path;
            }

            public function setAccessType($type): void
            {
                $this->calls['accessType'] = $type;
            }

            public function setPrompt($prompt): void
            {
                $this->calls['prompt'] = $prompt;
            }

            public function setAccessToken($token): void
            {
                $this->calls['accessToken'] = $token;
            }
        }
    }

    if (! class_exists('Google_Service_Gmail')) {
        class Google_Service_Gmail
        {
            public const GMAIL_MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

            public object $users_messages;

            public function __construct(public Google_Client $client)
            {
                $this->users_messages = new class()
                {
                    public array $listResult = [];

                    public mixed $getResult = null;

                    public mixed $sendResult = null;

                    public function listUsersMessages(string $user, array $optParams = []): object
                    {
                        $items = $this->listResult;

                        return new class($items)
                        {
                            public function __construct(private array $items) {}

                            public function getMessages(): array
                            {
                                return $this->items;
                            }
                        };
                    }

                    public function get(string $user, $id): mixed
                    {
                        return $this->getResult;
                    }

                    public function send(string $user, $reply): mixed
                    {
                        return $this->sendResult ?? $reply;
                    }
                };
            }
        }
    }

    if (! class_exists('Google_Service_Gmail_Message')) {
        class Google_Service_Gmail_Message
        {
            public ?string $raw = null;

            public ?string $threadId = null;

            public function setRaw(string $raw): void
            {
                $this->raw = $raw;
            }

            public function setThreadId(?string $threadId): void
            {
                $this->threadId = $threadId;
            }
        }
    }
}
