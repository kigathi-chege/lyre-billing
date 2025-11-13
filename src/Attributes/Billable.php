<?php

namespace Lyre\Billing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Billable
{
    public function __construct(
        public string $name,
    ) {}
}
