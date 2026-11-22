<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource;

class CreateOAuthConfiguration extends CreateRecord
{
    protected static string $resource = OAuthConfigurationResource::class;
}
