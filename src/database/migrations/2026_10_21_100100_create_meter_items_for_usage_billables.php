<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Usage billables (metadata.billable_type = usage, e.g. exam-attempts, ai-usage) were seeded
 * without billable items, but usage rows must reference an item. Give each one a meter item
 * keyed by its metadata.code so allowances can be recorded and enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('lyre.table_prefix');

        foreach (DB::table($prefix.'billables')->get() as $billable) {
            $metadata = json_decode((string) ($billable->metadata ?? '{}'), true) ?: [];
            $code = $metadata['code'] ?? null;

            if (($metadata['billable_type'] ?? null) !== 'usage' || ! $code) {
                continue;
            }

            $exists = DB::table($prefix.'billable_items')
                ->where('billable_id', $billable->id)
                ->where('item_type', 'meter')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table($prefix.'billable_items')->insert([
                'name' => $billable->name,
                'slug' => Str::slug($billable->slug.'-meter-'.Str::random(6)),
                'pricing_model' => 'quota_based',
                'status' => 'active',
                'item_type' => 'meter',
                'item_id' => $code,
                'reset_period' => 'billing_cycle',
                'billable_id' => $billable->id,
                'metadata' => json_encode(['unit' => $metadata['unit'] ?? null]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Meter items may already carry usage; leave them in place.
    }
};
