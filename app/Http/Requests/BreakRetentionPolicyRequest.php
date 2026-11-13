<?php

namespace App\Http\Requests;

use App\Models\BreakRetentionPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BreakRetentionPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'retention_months' => ['required', 'integer', 'min:1', 'max:120'],
            'applies_to_type' => ['required', Rule::in(['global', 'site'])],
            'applies_to_id' => ['required_if:applies_to_type,site', 'nullable', 'exists:sites,id'],
            'break_type' => ['nullable', Rule::in(['all', ...array_keys(BreakRetentionPolicy::BREAK_TYPES)])],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'retention_months' => 'retention period',
            'applies_to_type' => 'scope',
            'applies_to_id' => 'site',
            'break_type' => 'break type',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'applies_to_id.required_if' => 'Select a site for a site-specific policy.',
        ];
    }

    /**
     * Validated attributes ready to persist: a global policy never keeps a site,
     * and omitted optional fields fall back to their column defaults.
     *
     * @return array<string, mixed>
     */
    public function policyAttributes(): array
    {
        $attributes = $this->validated();

        if ($attributes['applies_to_type'] === 'global') {
            $attributes['applies_to_id'] = null;
        }

        foreach (['break_type', 'priority', 'is_active'] as $optional) {
            if (($attributes[$optional] ?? null) === null) {
                unset($attributes[$optional]);
            }
        }

        return $attributes;
    }
}
