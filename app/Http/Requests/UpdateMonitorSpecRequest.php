<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMonitorSpecRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
}
