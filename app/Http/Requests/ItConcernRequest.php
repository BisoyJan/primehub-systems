<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ItConcernRequest extends FormRequest
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
     */
    public function rules(): array
    {
        $rules = [
            'site_id' => ['required', 'exists:sites,id'],
            'station_number' => ['required', 'string', 'max:50'],
            'category' => ['required', 'in:Hardware,Software,Network/Connectivity,Other'],
            'priority' => ['required', 'in:low,medium,high,urgent'],
            'description' => ['required', 'string', 'max:1000'],
        ];

        // Additional rules for edit (admin can update status, priority, assignment)
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['status'] = ['sometimes', 'in:pending,in_progress,resolved,cancelled'];
            $rules['priority'] = ['sometimes', 'in:low,medium,high,urgent'];
            $rules['resolution_notes'] = ['nullable', 'string', 'max:1000'];
        }

        return $rules;
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'site_id' => 'site',
            'station_number' => 'station number',
            'category' => 'category',
            'priority' => 'priority',
            'description' => 'description',
            'resolution_notes' => 'resolution notes',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'site_id.required' => 'Please select a site.',
            'station_number.required' => 'The station number is required.',
            'category.required' => 'Please select a category.',
            'category.in' => 'Invalid category selected.',
            'priority.required' => 'Please select a priority level.',
            'priority.in' => 'Invalid priority level selected.',
            'description.required' => 'Please provide a description of the IT concern.',
            'description.max' => 'The description may not be greater than 1000 characters.',
        ];
    }
}
