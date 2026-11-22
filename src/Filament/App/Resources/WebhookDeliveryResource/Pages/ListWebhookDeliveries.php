<?php

namespace Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource;

class ListWebhookDeliveries extends ListRecords
{
    protected static string $resource = WebhookDeliveryResource::class;
}
