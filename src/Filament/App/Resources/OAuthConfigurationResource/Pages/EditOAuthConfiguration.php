<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource;

class EditOAuthConfiguration extends EditRecord
{
    protected static string $resource = OAuthConfigurationResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['client_secret'] = '';

        return $data;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
