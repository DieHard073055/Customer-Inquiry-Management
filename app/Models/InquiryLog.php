<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryLog extends Model
{
    protected $fillable = [
        'inquiry_id',
        'event',
        'context',
        'ip_address',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }
}
