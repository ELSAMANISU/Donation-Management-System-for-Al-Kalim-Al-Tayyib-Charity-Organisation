<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DecideHelpApplicationRequest;
use App\Services\HelpApplicationDecisionService;
use Symfony\Component\HttpFoundation\Response;

class HelpApplicationDecisionController extends Controller
{
    public function __invoke(DecideHelpApplicationRequest $request, string $helpApplication, HelpApplicationDecisionService $service): Response
    {
        $result = $service->decide($request->user(), $helpApplication, $request->validated('outcome'), $request->validated('decision_note'));

        return redirect()->route('admin.help-applications.in-review.index')
            ->with('status', 'help-application-'.$result->outcome)
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
