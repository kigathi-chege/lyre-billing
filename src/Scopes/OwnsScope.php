<?php

namespace Lyre\Billing\Scopes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;

class OwnsScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->check()) {
            if (auth()->user()->hasRole('super-admin')) {
                return;
            }

            $builder->where('user_id', auth()->id());
        }
    }
}
