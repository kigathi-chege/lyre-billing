<?php

namespace Lyre\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Lyre\Billing\Models\Billable;

class BillableSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            [
                'name' => 'Exam Attempts',
                'slug' => 'exam-attempts',
                'status' => 'active',
                'description' => 'Usage allowance and overage billing for exam assessment attempts.',
                'metadata' => [
                    'code' => 'exam_attempts',
                    'unit' => 'attempt',
                    'billable_type' => 'usage',
                ],
            ],
            [
                'name' => 'AI Usage',
                'slug' => 'ai-usage',
                'status' => 'active',
                'description' => 'Token-based usage billing for question-scoped AI assistance.',
                'metadata' => [
                    'code' => 'ai_usage',
                    'unit' => 'token',
                    'billable_type' => 'usage',
                ],
            ],
        ] as $billable) {
            Billable::updateOrCreate(
                ['slug' => $billable['slug']],
                $billable
            );
        }
    }
};
