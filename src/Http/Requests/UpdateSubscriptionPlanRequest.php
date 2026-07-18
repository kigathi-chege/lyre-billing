<?php

namespace Lyre\Billing\Http\Requests;

use Lyre\Request;

class UpdateSubscriptionPlanRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'billing_cycle' => ['sometimes', 'required', 'string', 'in:per_minute,per_hour,per_day,per_week,monthly,quarterly,semi_annually,annually'],
            'trial_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive,archived'],
            'kind' => ['sometimes', 'required', 'string', 'in:main,per_exam'],
            'entitlement_mode' => ['sometimes', 'nullable', 'string', 'in:fixed,quota'],
            'visibility' => ['sometimes', 'required', 'string', 'in:public,hidden'],
            'entitlements_config' => ['sometimes', 'nullable', 'array'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'features' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
