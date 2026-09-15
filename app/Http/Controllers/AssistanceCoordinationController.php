<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistanceCoordinationRequest;
use App\Services\AssistanceCoordinationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssistanceCoordinationController extends Controller
{
    public function __construct(private readonly AssistanceCoordinationService $service) {}

    public function show(Request $request): View
    {
        $page = $request->query('page', '1');
        abort_unless(is_string($page) && preg_match('/\A[1-9][0-9]{0,5}\z/', $page) === 1
            && array_diff(array_keys($request->query()), ['page']) === [], 404);

        return view('coordination.show', $this->service->detail($request->user(), $request->route('helpApplication'),
            $request->route('coordination'), $request->routeIs('admin.coordination.*'), (int) $page));
    }

    public function mutate(AssistanceCoordinationRequest $request): RedirectResponse
    {
        $application = $request->route('helpApplication');
        $administrator = $request->administrator();
        $input = $request->coordinationInput();
        $request->request->replace([]);
        if ($request->isJson()) {
            $request->json()->replace([]);
        }
        if ($request->action() === 'start') {
            $coordination = $this->service->start($request->user(), $application)->reference;
        } else {
            $coordination = $request->route('coordination');
            $this->service->mutate($request->user(), $application, $coordination, $administrator, $request->action(), $input);
        }

        return redirect()->route($administrator ? 'admin.coordination.show' : 'help-applications.coordination.show',
            ['helpApplication' => $application, 'coordination' => $coordination])->with('status', 'coordination-saved');
    }
}
