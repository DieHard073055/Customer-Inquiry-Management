<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInquiryRequest;
use App\Http\Resources\InquiryCollection;
use App\Http\Resources\InquiryResource;
use App\Models\Inquiry;
use App\Services\InquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class InquiryController extends Controller
{
    public function __construct(private readonly InquiryService $inquiryService) {}

    public function index(Request $request): InquiryCollection
    {
        $inquiries = Inquiry::query()
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return new InquiryCollection($inquiries);
    }

    public function store(StoreInquiryRequest $request): JsonResponse
    {
        try {
            $inquiry = $this->inquiryService->store(
                $request->validated(),
                $request->ip()
            );

            return (new InquiryResource($inquiry))
                ->response()
                ->setStatusCode(201);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to submit inquiry. Please try again.',
            ], 500);
        }
    }

    public function show(Inquiry $inquiry): InquiryResource
    {
        return new InquiryResource($inquiry);
    }
}
