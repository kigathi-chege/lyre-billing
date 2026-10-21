<?php

namespace Lyre\Billing\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Lyre\Billing\Support\BillingRules;
use Lyre\Request;

class StoreBillableRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return BillingRules::billable();
    }
}
