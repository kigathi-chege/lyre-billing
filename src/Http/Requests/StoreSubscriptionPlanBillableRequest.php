<?php

namespace Lyre\Billing\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Lyre\Billing\Support\BillingRules;
use Lyre\Request;

class StoreSubscriptionPlanBillableRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subscription_plan_id' => ['required', 'exists:'.config('lyre.table_prefix').'subscription_plans,id'],
            'billable_id' => ['required', 'exists:'.config('lyre.table_prefix').'billables,id'],
            'usage_limit' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'order' => ['nullable', 'integer', 'min:0'],
            ...BillingRules::allowanceMetadata(),
        ];
    }
}
