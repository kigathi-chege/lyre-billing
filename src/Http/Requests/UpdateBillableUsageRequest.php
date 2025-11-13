<?php

namespace Lyre\Billing\Http\Requests;

use Lyre\Request;

class UpdateBillableUsageRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'billable_item_id' => ['sometimes', 'required', 'exists:' . config('lyre.table_prefix') . 'billable_items,id'],
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'recorded_at' => ['sometimes', 'required', 'date'],
        ];
    }
}

