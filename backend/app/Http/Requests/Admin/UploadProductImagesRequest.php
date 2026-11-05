<?php

namespace App\Http\Requests\Admin;

use App\Services\ProductImageService;
use App\Support\ImageCompressor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadProductImagesRequest extends FormRequest
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
        $side = ImageCompressor::MAX_INPUT_SIDE;

        return [
            'images' => ['required', 'array', 'min:1', 'max:'.ProductImageService::MAX_IMAGES],
            'images.*' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                "dimensions:max_width={$side},max_height={$side}",
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
        return [
            'images.*' => 'image',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.required' => 'Choose at least one image.',
            'images.max' => 'A product can have up to :max images.',
            'images.*.max' => 'Each image may not be larger than 2 MB.',
            'images.*.dimensions' => sprintf(
                'Each image can be up to %s pixels wide and high.',
                number_format(ImageCompressor::MAX_INPUT_SIDE),
            ),
        ];
    }
}
