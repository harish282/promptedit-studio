<?php

namespace App\Http\Requests;

use App\Models\Generation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerationRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'string', Rule::in(Generation::SIZES)],
            'quality' => ['required', 'string', Rule::in(Generation::QUALITIES)],
            'reference_images' => ['sometimes', 'array', 'max:4'],
            'reference_images.*' => ['url', 'max:2048'],
        ];
    }
}
