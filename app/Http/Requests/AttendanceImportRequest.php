<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceImportRequest extends FormRequest
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
            'file' => 'required|file|mimes:txt|max:10240', // 10MB max
            'site_id' => 'nullable|exists:sites,id',
            'file_date' => 'required|date',
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
            'file.required' => 'Please upload a .txt file.',
            'file.mimes' => 'Only .txt files are allowed.',
            'file.max' => 'File size cannot exceed 10MB.',
            'site_id.exists' => 'The selected site does not exist.',
            'file_date.required' => 'Please specify the file date.',
            'file_date.date' => 'Invalid date format.',
        ];
    }
}
