<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MedicationRequestRequest extends FormRequest
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
        return [
            'requested_for_user_id' => ['nullable', 'exists:users,id'],
            'medication_type' => ['required', 'in:Declogen,Biogesic,Mefenamic Acid,Kremil-S,Cetirizine,Saridon,Diatabs'],
            'reason' => ['required', 'string', 'max:1000'],
            'onset_of_symptoms' => ['required', 'in:Just today,More than 1 day,More than 1 week'],
            'agrees_to_policy' => ['required', 'boolean', 'accepted'],
        ];
    }

    /**
     * Get custom attribute names.
     */
    public function attributes(): array
    {
        return [
            'medication_type' => 'type of medication',
            'onset_of_symptoms' => 'onset of symptoms',
            'agrees_to_policy' => 'policy agreement',
        ];
    }
}
