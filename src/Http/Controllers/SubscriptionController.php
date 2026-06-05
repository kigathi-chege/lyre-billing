<?php

namespace Lyre\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lyre\Billing\Models\Subscription;
use Lyre\Billing\Contracts\SubscriptionRepositoryInterface;
use Lyre\Billing\Services\SubscriptionProviderReturnService;
use Lyre\Controller;

class SubscriptionController extends Controller
{
    protected SubscriptionProviderReturnService $providerReturnService;

    public function __construct(
        SubscriptionRepositoryInterface $modelRepository,
        SubscriptionProviderReturnService $providerReturnService,
    ) {
        $model = new Subscription();
        $modelConfig = $model->generateConfig();
        parent::__construct($modelConfig, $modelRepository);
        $this->providerReturnService = $providerReturnService;
    }

    public function providerReturned(Request $request)
    {
        return __response(
            true,
            'Subscription provider return recorded',
            $this->providerReturnService->handle($request->query()),
            get_response_code("get-{$this->modelNamePlural}")
        );
    }

    public function providerReturnRedirect(Request $request): RedirectResponse
    {
        $this->providerReturnService->handle($request->query());

        return redirect()->away($this->providerReturnService->frontendRedirectUrl('returned'));
    }

    public function providerCancelRedirect(Request $request): RedirectResponse
    {
        $payload = array_merge($request->query(), [
            'cancelled' => true,
        ]);
        $this->providerReturnService->handle($payload);

        return redirect()->away($this->providerReturnService->frontendRedirectUrl('cancelled'));
    }
}
