<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MonitorSpecRequest extends FormRequest
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
        return [
            'brand' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'screen_size' => ['required', 'numeric', 'min:10', 'max:100'],
            'resolution' => ['required', 'string', 'max:50'],
            'panel_type' => ['required', 'string', 'in:IPS,VA,TN,OLED'],
            'ports' => ['nullable', 'array'],
            'ports.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
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
            'screen_size' => 'screen size',
            'panel_type' => 'panel type',
            'ports.*' => 'port',
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
            'panel_type.in' => 'The panel type must be one of: IPS, VA, TN, or OLED.',
            'screen_size.min' => 'The screen size must be at least 10 inches.',
            'screen_size.max' => 'The screen size cannot exceed 100 inches.',
        ];
    }
}
