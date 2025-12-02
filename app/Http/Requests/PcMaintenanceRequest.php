<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PcMaintenanceRequest extends FormRequest
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
        $isCreating = $this->isMethod('POST');

        return [
            // For bulk create, pc_spec_ids is required. For update, pc_spec_id is required.
            'pc_spec_ids' => $isCreating ? ['required', 'array', 'min:1'] : ['sometimes', 'array'],
            'pc_spec_ids.*' => ['exists:pc_specs,id'],
            'pc_spec_id' => !$isCreating ? ['required', 'exists:pc_specs,id'] : ['sometimes', 'exists:pc_specs,id'],

            'last_maintenance_date' => ['required', 'date'],
            'next_due_date' => ['required', 'date', 'after:last_maintenance_date'],
            'maintenance_type' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'performed_by' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:completed,pending,overdue'],
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
            'pc_spec_ids' => 'PC specs',
            'pc_spec_ids.*' => 'PC spec',
            'pc_spec_id' => 'PC spec',
            'last_maintenance_date' => 'last maintenance date',
            'next_due_date' => 'next due date',
            'maintenance_type' => 'maintenance type',
            'performed_by' => 'performed by',
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
            'next_due_date.after' => 'The next due date must be after the last maintenance date.',
            'pc_spec_ids.min' => 'Please select at least one PC.',
            'status.in' => 'The status must be either completed, pending, or overdue.',
        ];
    }
}
