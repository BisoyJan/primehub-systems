<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RamSpecRequest extends FormRequest
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
            'capacity_gb' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', 'max:255'],
            'speed' => ['required', 'integer', 'min:1'],
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
            'capacity_gb' => 'capacity',
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
            'capacity_gb.min' => 'The capacity must be at least 1 GB.',
            'speed.min' => 'The speed must be at least 1 MHz.',
        ];
    }
}
