<?php

namespace Lyre\Billing\Traits;

use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Lyre\Billing\Attributes\Billable;

trait HasBillable
{
    /**
     * Call a hook method
     * @param string $hook The hook method name
     * @return void
     * 
     * Usage:
     * try {
     *     $this->callHook('before');
     *     // Execute Billable method
     *     $this->callHook('after');
     * } catch (\Throwable $e) {
     *     $this->callHook('error', $e);
     *     throw $e;
     * }
     */
    protected function callHook(string $hook, ...$args): void
    {
        if (! method_exists($this, $hook)) {
            return;
        }

        // Determine which method called callHook()
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[1] ?? null;
        $method = $caller['function'] ?? null;

        // Try to reflect the calling method
        $billable = null;
        if ($method && method_exists($this, $method)) {
            $ref = new ReflectionMethod($this, $method);
            $attrs = $ref->getAttributes(Billable::class);
            if (!empty($attrs)) {
                $billable = $attrs[0]->newInstance();
            }
        }

        // Log or handle this as needed
        Log::debug('Billable hook called', [
            'hook' => $hook,
            'method' => $method,
            'billable' => $billable ? get_object_vars($billable) : null,
        ]);

        $this->{$hook}($billable, ...$args);
    }

    protected function before(Billable $billable, ...$args) {}

    protected function after(Billable $billable, ...$args) {}

    protected function error(Billable $billable, ...$args) {}

    /**
     * Record billable usage for the calling method
     * Call this AFTER the parent method succeeds
     *
     * Usage:
     * #[Billable('User Creation')]
     * public function create(bool $another = false): void
     * {
     *     parent::create($another);
     *     $this->recordBillableUsage(); // Call after success
     * }
     */
    protected function recordBillableUsage(?string $methodName = null): void
    {
        // Get the calling method name if not provided
        $methodName = $methodName ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];

        try {
            $reflection = new ReflectionMethod($this, $methodName);
            $attributes = $reflection->getAttributes(Billable::class);

            if (empty($attributes)) {
                // No Billable attribute, nothing to record
                return;
            }

            $className = get_class($this);

            // Record usage using helper function
            record_billable_usage($className, $methodName);
        } catch (\Throwable $e) {
            Log::error("❌ [Trait] Failed to record billable usage", [
                'class' => get_class($this),
                'method' => $methodName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Intercept method calls and record billable usage if method has Billable attribute
     * This works automatically for non-existent methods
     */
    public function __call($method, $args)
    {
        // Check if method exists on this class
        if (method_exists($this, $method)) {
            $reflection = new ReflectionMethod($this, $method);
            $attributes = $reflection->getAttributes(Billable::class);

            if (!empty($attributes)) {
                $billable = $attributes[0]->newInstance();
                $className = get_class($this);

                // Log before execution
                Log::info("💰 [Trait] Before executing billable method [{$method}]", [
                    'class' => $className,
                    'method' => $method,
                    'name' => $billable->name,
                ]);

                try {
                    // Execute the method
                    $result = $reflection->invokeArgs($this, $args);

                    // Record usage after successful execution
                    record_billable_usage($className, $method);

                    // Log after execution
                    Log::info("✅ [Trait] After executing billable method [{$method}]", [
                        'class' => $className,
                        'method' => $method,
                        'name' => $billable->name,
                    ]);

                    return $result;
                } catch (\Throwable $e) {
                    Log::error("❌ [Trait] Error in billable method [{$method}]", [
                        'class' => $className,
                        'method' => $method,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }

            // Method exists but no Billable attribute, call it normally
            return $reflection->invokeArgs($this, $args);
        }

        // Method doesn't exist, try parent __call if available
        if (method_exists(get_parent_class($this), '__call')) {
            return parent::__call($method, $args);
        }

        throw new \BadMethodCallException("Method {$method} does not exist on " . get_class($this));
    }
}
