<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class SalesReportRequest extends FormRequest
{
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
            // Days by the shop's calendar; "to" is included.
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => [
                'required', 'date_format:Y-m-d', 'after_or_equal:from',
                function (string $attribute, mixed $value, Closure $fail) {
                    $from = $this->input('from');

                    if (is_string($from) && strtotime($from) && Carbon::parse($from)->diffInDays(Carbon::parse($value)) > 366) {
                        $fail('Choose a period of a year or less.');
                    }
                },
            ],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['from' => 'from date', 'to' => 'to date'];
    }
}
