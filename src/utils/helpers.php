<?php

use Lyre\Billing\Attributes\Billable;
use Lyre\Billing\Models\BillableItem;
use Lyre\Billing\Models\BillableUsage;
use Illuminate\Support\Facades\Auth;


if (! function_exists('mpesa')) {
    function mpesa()
    {
        return new \Lyre\Billing\Services\Mpesa\Client();
    }
}


if (! function_exists('get_billable_methods')) {
    function get_billable_methods(string $basePath = 'app')
    {
        $directory = base_path($basePath); // or your src folder
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        $results = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $classPath = str_replace(
                [base_path() . '/', '/', '.php'],
                ['', '\\', ''],
                $file->getPathname()
            );

            try {
                if (!class_exists($classPath)) continue;
                $reflection = new ReflectionClass($classPath);

                foreach ($reflection->getMethods() as $method) {
                    foreach ($method->getAttributes(Billable::class) as $attribute) {
                        $results[] = [
                            'class' => $reflection->getName(),
                            'method' => $method->getName(),
                            'args' => $attribute->getArguments(),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // ignore invalid classes
            }
        }

        return $results;
    }
}

if (! function_exists('record_billable_usage')) {
    /**
     * Record billable usage for a method call
     *
     * @param string $className The fully qualified class name
     * @param string $methodName The method name that was called
     * @param int|null $userId Optional user ID (defaults to authenticated user)
     * @param float|null $customAmount Optional custom amount to override pricing calculation
     * @return \Lyre\Billing\Models\BillableUsage|null
     */
    function record_billable_usage(string $className, string $methodName, ?int $userId = null, ?float $customAmount = null)
    {
        try {
            // Find the billable item
            $billableItem = BillableItem::where('item_type', 'function')
                ->where('item_id', $className)
                ->where('status', 'active')
                ->first();

            if (!$billableItem) {
                logger()->debug("🔷 No active billable item found for class: {$className}");
                return null;
            }

            // Get user ID
            $userId = $userId ?? Auth::id();

            if (!$userId) {
                logger()->warning("🔷 No authenticated user to record usage");
                return null;
            }

            // Calculate amount based on pricing model (unless custom amount provided)
            $amount = $customAmount ?? match ($billableItem->pricing_model) {
                'free' => 0.0,
                'usage_based' => (float) ($billableItem->unit_price ?? 0.0),
                'quota_based' => 0.0,
                default => 0.0,
            };

            // Record the usage
            $billableUsage = BillableUsage::create([
                'billable_item_id' => $billableItem->id,
                'user_id' => $userId,
                'amount' => $amount,
                'recorded_at' => now(),
            ]);

            logger()->info("✅ Recorded billable usage", [
                'class' => $className,
                'method' => $methodName,
                'billable_item_id' => $billableItem->id,
                'user_id' => $userId,
                'amount' => $amount,
            ]);

            return $billableUsage;
        } catch (\Throwable $e) {
            logger()->error("❌ Failed to record billable usage", [
                'class' => $className,
                'method' => $methodName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
}
