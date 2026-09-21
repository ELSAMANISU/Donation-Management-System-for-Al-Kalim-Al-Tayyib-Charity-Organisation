<?php

namespace App\Http\Controllers;

use App\Enums\AidDeliveryState;
use App\Http\Requests\AidDeliveryRequest;
use App\Services\AidDeliveryFormTokens;
use App\Services\AidDeliveryService;
use App\Services\CompletionFormTokens;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

class AidDeliveryController extends Controller
{
    public function __construct(private readonly AidDeliveryService $service, private readonly AidDeliveryFormTokens $tokens) {}

    public function show(Request $request)
    {
        abort_unless($request->query->count() === 0, 404);
        $data = $this->service->detail($request->user(), $request->route('helpApplication'), $request->route('coordination'), $request->routeIs('admin.aid-delivery.*'));
        $selected = $request->route('delivery');
        if ($selected !== null) {
            abort_unless($data['deliveries']->contains('reference', $selected), 404);
            $data['deliveries'] = $data['deliveries']->where('reference', $selected);
        }
        $data['tokens'] = [];
        $data['startToken'] = null;
        $data['completionToken'] = null;
        if ($data['canMutate']) {
            if ($selected === null && $data['deliveries']->every(fn ($delivery) => $delivery->state === AidDeliveryState::SimulatedDelivered)
                && BigDecimal::of($data['remaining'])->isGreaterThan(0)) {
                $data['startToken'] = $this->tokens->issue($request, $data['application']->reference, $data['coordination']->reference, 'start');
            }
            foreach ($data['deliveries'] as $delivery) {
                $actions = match ($delivery->state) {
                    AidDeliveryState::InProgress => ['problem', 'success'], AidDeliveryState::Problem => ['resume'], default => []
                };
                foreach ($actions as $action) {
                    $data['tokens'][$delivery->reference][$action] = $this->tokens->issue($request, $data['application']->reference, $data['coordination']->reference, $action, $delivery->reference, $delivery->revision);
                }
            }
        }
        if ($request->routeIs('admin.aid-delivery.*') && $selected === null) {
            $revision = $this->service->completionRevision($request->user(), $request->route('helpApplication'), $request->route('coordination'));
            if ($revision !== null) {
                $data['completionToken'] = app(CompletionFormTokens::class)->issue($request, $data['application']->reference, $data['coordination']->reference, $revision);
            }
        }

        return view('aid-delivery.show', $data);
    }

    public function mutate(AidDeliveryRequest $request)
    {
        $input = $request->deliveryInput();
        $request->request->replace([]);
        if ($request->isJson()) {
            $request->json()->replace([]);
        }
        $this->service->mutate($request->user(), $request->route('helpApplication'), $request->route('coordination'), $request->action(), $input, $request->route('delivery'));

        return redirect()->route('admin.aid-delivery.index', ['helpApplication' => $request->route('helpApplication'), 'coordination' => $request->route('coordination')]);
    }

    public function complete(Request $request, CompletionFormTokens $tokens)
    {
        $revision = $tokens->consume($request);
        $this->service->complete($request->user(), $request->route('helpApplication'), $request->route('coordination'), $revision);

        return redirect()->route('admin.aid-delivery.index', [
            'helpApplication' => $request->route('helpApplication'), 'coordination' => $request->route('coordination'),
        ]);
    }
}
