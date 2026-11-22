<?php

namespace Liberu\Foundation\Integrations\Support;

use InvalidArgumentException;
use Liberu\Foundation\Integrations\Contracts\IntegrationAdapter;
use Liberu\Foundation\Integrations\Contracts\OAuthIntegrationAdapter;

final class IntegrationRegistry
{
    /** @var array<string, IntegrationAdapter> */
    private array $adapters = [];

    public function register(IntegrationAdapter $adapter): void
    {
        $name = $adapter->name();
        if (isset($this->adapters[$name])) {
            throw new InvalidArgumentException('Duplicate integration adapter.');
        }

        $this->adapters[$name] = $adapter;
    }

    public function get(string $name): IntegrationAdapter
    {
        return $this->adapters[$name] ?? throw new InvalidArgumentException("Unknown integration [{$name}].");
    }

    public function oauth(string $name): OAuthIntegrationAdapter
    {
        $adapter = $this->get($name);

        return $adapter instanceof OAuthIntegrationAdapter
            ? $adapter
            : throw new InvalidArgumentException("Integration [{$name}] does not support OAuth.");
    }

    public function all(): array
    {
        return $this->adapters;
    }
}
