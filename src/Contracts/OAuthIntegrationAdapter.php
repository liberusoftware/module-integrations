<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Contracts;

interface OAuthIntegrationAdapter extends IntegrationAdapter
{
    public function authorizationUrl(string $state): string;

    public function exchangeAuthorizationCode(string $code): array;

    public function refreshAccessToken(string $refreshToken): array;
}
