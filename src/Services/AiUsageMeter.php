<?php

namespace Lyre\Billing\Services;

use Illuminate\Database\Eloquent\Model;
use Lyre\Billing\Models\BillableItem;

class AiUsageMeter
{
    public function recordQuestionScopedUsage(
        BillableItem $billableItem,
        Model $user,
        int $totalTokens,
        ?Model $subscription = null,
        array $context = []
    ) {
        return app(BillableUsageRecorder::class)->record(
            $billableItem,
            $user,
            $totalTokens,
            0,
            $subscription,
            [
                'feature' => 'question_explainer',
                'scope' => 'question',
                ...$context,
            ]
        );
    }
}
