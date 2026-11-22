<?php

namespace Liberu\Foundation\Integrations\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Foundation\Organizations\Traits\IsTenantModel;

class WebhookDelivery extends Model
{
    use HasFactory;
    use IsTenantModel;

    protected $table = 'crm_webhook_deliveries';

    protected $fillable = ['webhook_id', 'team_id', 'event', 'success', 'status_code', 'error'];

    protected function casts(): array
    {
        return ['success' => 'boolean', 'status_code' => 'integer', 'team_id' => 'integer'];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
