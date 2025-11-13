<?php

namespace Lyre\Billing\Support;

use ReflectionMethod;
use ReflectionClass;
use Lyre\Billing\Attributes\Billable;
use Lyre\Billing\Traits\HasBillable;

class BillableProxy
{
    use HasBillable;


    public function __construct(
        protected object $target
    ) {
        // Log when proxy is created
        logger()->info("🔷 BillableProxy created for: " . get_class($target), [
            'target_class' => get_class($target),
        ]);
    }

    public function __call($method, $args)
    {
        logger()->info("🔷 BillableProxy __call intercepted: {$method}", [
            'class' => get_class($this->target),
            'method' => $method,
            'args_count' => count($args),
        ]);

        // Check if method exists on target
        if (!method_exists($this->target, $method)) {
            logger()->warning("🔷 Method does not exist: {$method} on " . get_class($this->target));
            throw new \BadMethodCallException("Method {$method} does not exist on " . get_class($this->target));
        }

        $reflection = new ReflectionMethod($this->target, $method);
        $attributes = $reflection->getAttributes(Billable::class);

        logger()->debug("🔷 Method {$method} has " . count($attributes) . " Billable attributes");

        if ($attributes) {
            $billable = $attributes[0]->newInstance();

            // BEFORE EXECUTION
            $this->before($method, $billable, $args);

            try {
                $result = $reflection->invokeArgs($this->target, $args);

                // AFTER EXECUTION - Record usage
                record_billable_usage(get_class($this->target), $method);
                $this->after($method, $billable, $args, $result);

                return $result;
            } catch (\Throwable $e) {
                // Log error but don't record usage if method failed
                logger()->error("❌ Error executing billable method [{$method}]", [
                    'name' => $billable->name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        }

        // Normal method call - no billable attribute
        logger()->debug("🔷 Normal method call (no Billable attribute): {$method}");
        return $reflection->invokeArgs($this->target, $args);
    }

    /**
     * Forward property access to target
     */
    public function __get($property)
    {
        return $this->target->$property;
    }

    /**
     * Forward property setting to target
     */
    public function __set($property, $value)
    {
        $this->target->$property = $value;
    }

    /**
     * Forward isset checks to target
     */
    public function __isset($property)
    {
        return isset($this->target->$property);
    }

    /**
     * Forward unset to target
     */
    public function __unset($property)
    {
        unset($this->target->$property);
    }

    protected function before(string $method, Billable $billable, array $args): void
    {
        logger()->info("💰 Before executing billable method [{$method}]", [
            'class' => get_class($this->target),
            'method' => $method,
            'name' => $billable->name,
            'args' => $args,
        ]);
    }

    protected function after(string $method, Billable $billable, array $args, $result): void
    {
        logger()->info("✅ After executing billable method [{$method}]", [
            'class' => get_class($this->target),
            'method' => $method,
            'name' => $billable->name,
            'result_type' => gettype($result),
        ]);
    }
}
