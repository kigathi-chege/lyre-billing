<?php

namespace Lyre\Billing\Policies;

use Illuminate\Auth\Access\Response;
use Lyre\Billing\Models\Transaction;
use Lyre\Policy;

class TransactionPolicy extends Policy
{
    public function __construct(Transaction $model)
    {
        parent::__construct($model);
    }

    public function viewAny(?\App\Models\User $user): Response
    {
        return parent::viewAny($user);
    }

    public function view(?\App\Models\User $user, $model): Response
    {
        if (! $this->usingSpatieRoles) {
            return Response::allow();
        }

        $viewAnyPermission = get_model_permission_by_prefix(get_class($this->model), 'view-any');
        $viewPermission = get_model_permission_by_prefix(get_class($this->model), 'view');

        if ($user && $user->can($viewAnyPermission)) {
            return Response::allow();
        }

        $ownerId = $model->user_id ?? $model->creator_id ?? null;

        if ($user && $user->can($viewPermission) && $ownerId && (int) $ownerId === (int) $user->getAuthIdentifier()) {
            return Response::allow();
        }

        $modelName = class_basename(get_class($this->model));
        return Response::deny("You do not have access to view this {$modelName}.");
    }
}
