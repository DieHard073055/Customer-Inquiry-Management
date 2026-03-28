<?php

use App\Http\Controllers\Api\InquiryController;
use Illuminate\Support\Facades\Route;

Route::apiResource('inquiries', InquiryController::class)
    ->only(['index', 'store', 'show']);
