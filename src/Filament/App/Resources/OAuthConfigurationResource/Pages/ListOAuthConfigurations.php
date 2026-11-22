<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource;

class ListOAuthConfigurations extends ListRecords
{
    protected static string $resource = OAuthConfigurationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
