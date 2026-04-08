<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProcessorSpecRequest extends FormRequest
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
        $rules = [
            'manufacturer' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'core_count' => ['required', 'integer', 'min:1'],
            'thread_count' => ['required', 'integer', 'min:1'],
            'base_clock_ghz' => ['required', 'numeric', 'min:0'],
            'boost_clock_ghz' => ['nullable', 'numeric', 'min:0'],
            'release_date' => ['nullable', 'date'],
        ];

        return $rules;
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'core_count' => 'number of cores',
            'thread_count' => 'number of threads',
            'base_clock_ghz' => 'base clock speed',
            'boost_clock_ghz' => 'boost clock speed',
            'release_date' => 'release date',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'core_count.min' => 'The processor must have at least 1 core.',
            'thread_count.min' => 'The processor must have at least 1 thread.',
            'base_clock_ghz.min' => 'The base clock speed must be greater than 0.',
        ];
    }
}
