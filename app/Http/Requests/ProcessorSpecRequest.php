<?php

namespace App\Http\Requests;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
        ];

        // Include stock_quantity only on create
        if ($this->isMethod('POST')) {
            $rules['stock_quantity'] = ['required', 'integer', 'min:0'];
        }

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
            'stock_quantity' => 'initial stock quantity',
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
