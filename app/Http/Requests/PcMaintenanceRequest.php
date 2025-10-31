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
            // For bulk create, station_ids is required. For update, station_id is required.
            'station_ids' => $isCreating ? ['required', 'array', 'min:1'] : ['sometimes', 'array'],
            'station_ids.*' => ['exists:stations,id'],
            'station_id' => !$isCreating ? ['required', 'exists:stations,id'] : ['sometimes', 'exists:stations,id'],
            
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
            'station_ids' => 'stations',
            'station_ids.*' => 'station',
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
            'station_ids.min' => 'Please select at least one station.',
            'status.in' => 'The status must be either completed, pending, or overdue.',
        ];
    }
}
