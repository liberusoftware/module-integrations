<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationConnection extends Model
{
    protected $fillable = [
        'scope_type',
        'scope_id',
        'provider',
        'credentials',
        'capabilities',
        'status',
        'last_tested_at',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'last_tested_at' => 'datetime',
    ];
}
