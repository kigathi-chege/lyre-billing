<?php

namespace Lyre\Billing\Http\Requests;

use Lyre\Request;

class StoreBillableUsageRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'billable_item_id' => ['required', 'exists:' . config('lyre.table_prefix') . 'billable_items,id'],
            'user_id' => ['required', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'recorded_at' => ['required', 'date'],
        ];
    }
}

