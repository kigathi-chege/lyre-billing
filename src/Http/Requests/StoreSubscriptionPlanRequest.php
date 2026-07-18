<?php

namespace Lyre\Billing\Http\Requests;

use Lyre\Request;

class StoreSubscriptionPlanRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', 'string', 'in:per_minute,per_hour,per_day,per_week,monthly,quarterly,semi_annually,annually'],
            'trial_days' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'string', 'in:active,inactive,archived'],
            'kind' => ['nullable', 'string', 'in:main,per_exam'],
            'entitlement_mode' => ['nullable', 'string', 'in:fixed,quota'],
            'visibility' => ['nullable', 'string', 'in:public,hidden'],
            'entitlements_config' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'features' => ['nullable', 'array'],
        ];
    }
}
