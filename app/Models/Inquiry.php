<?php

namespace App\Models;

use App\Enums\InquiryCategory;
use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inquiry extends Model
{
    protected $fillable = [
        'reference_number',
        'name',
        'email',
        'phone',
        'category',
        'subject',
        'message',
        'status',
        'ip_address',
    ];

    protected $casts = [
        'category' => InquiryCategory::class,
        'status'   => InquiryStatus::class,
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(InquiryLog::class);
    }
}
