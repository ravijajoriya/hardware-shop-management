<?php

namespace App\Http\Requests\Admin;

use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillIndexRequest extends FormRequest
{
    public const OVERDUE_DAYS = [30, 60, 90];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(BillStatus::class)],
            // Days by the shop's calendar; "to" includes the whole day.
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Bills with at least one payment taken this way.
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            // Bills with a line matching this product name, size or item code.
            'product' => ['nullable', 'string', 'max:100'],
            // Bills still owing money more than this many days after they were made.
            'overdue' => ['nullable', 'integer', Rule::in(self::OVERDUE_DAYS)],
            'changes' => ['nullable', Rule::in(['edited', 'returned'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'total', 'due'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['from' => 'from date', 'to' => 'to date', 'method' => 'payment method', 'overdue' => 'due age'];
    }
}
