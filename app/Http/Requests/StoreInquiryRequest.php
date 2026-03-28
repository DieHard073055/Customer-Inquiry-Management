<?php

namespace App\Http\Requests;

use App\Enums\InquiryCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255'],
            'phone'    => ['nullable', 'string', 'max:20'],
            'category' => ['required', Rule::enum(InquiryCategory::class)],
            'subject'  => ['required', 'string', 'max:255'],
            'message'  => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.enum' => 'Category must be one of: trading, market_data, technical_issues, general_questions.',
            'message.min'   => 'The message must be at least 10 characters.',
        ];
    }
}
