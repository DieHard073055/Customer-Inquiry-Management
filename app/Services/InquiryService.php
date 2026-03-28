<?php

namespace App\Services;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class InquiryService
{
    public function store(array $data, string $ipAddress): Inquiry
    {
        return DB::transaction(function () use ($data, $ipAddress) {
            $inquiry = Inquiry::create([
                'reference_number' => $this->generateReferenceNumber(),
                'name'             => $data['name'],
                'email'            => $data['email'],
                'phone'            => $data['phone'] ?? null,
                'category'         => $data['category'],
                'subject'          => $data['subject'],
                'message'          => $data['message'],
                'status'           => InquiryStatus::Open,
                'ip_address'       => $ipAddress,
            ]);

            $inquiry->logs()->create([
                'event'      => 'inquiry_created',
                'context'    => [
                    'category' => $inquiry->category->value,
                    'subject'  => $inquiry->subject,
                ],
                'ip_address' => $ipAddress,
            ]);

            Log::channel('daily')->info('Inquiry created', [
                'reference_number' => $inquiry->reference_number,
                'category'         => $inquiry->category->value,
                'email'            => $inquiry->email,
                'ip_address'       => $ipAddress,
            ]);

            return $inquiry;
        });
    }

    private function generateReferenceNumber(): string
    {
        $maxAttempts = 10;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ref = 'MSE-' . strtoupper(Str::random(8));

            if (! Inquiry::where('reference_number', $ref)->exists()) {
                return $ref;
            }
        }

        throw new RuntimeException("Unable to generate a unique reference number after {$maxAttempts} attempts.");
    }
}
