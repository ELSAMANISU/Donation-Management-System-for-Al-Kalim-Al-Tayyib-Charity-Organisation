<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HelpApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartHelpApplicationReviewRequest;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\HelpApplicationDuplicateWarning;
use App\Services\HelpApplicationReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class HelpApplicationController extends Controller
{
    private const PRIVATE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
    ];

    public function __construct(private readonly HelpApplicationReviewService $reviewService) {}

    public function index(): Response
    {
        Gate::authorize('reviewPendingAny', HelpApplication::class);

        $applications = HelpApplication::query()
            ->select(['reference', 'status', 'full_name', 'submitted_at'])
            ->where('status', HelpApplicationStatus::Pending)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->view('admin.help-applications.index', compact('applications'), 200, self::PRIVATE_HEADERS);
    }

    public function show(string $helpApplication): Response
    {
        Gate::authorize('reviewPendingAny', HelpApplication::class);

        $application = HelpApplication::query()
            ->select([
                'reference', 'status', 'submitted_at', 'full_name', 'email', 'phone', 'address',
                'date_of_birth', 'identity_document_type', 'identity_issuing_country',
                'requested_amount', 'private_story', 'preferred_receiving_method',
                'public_identity_preference',
            ])
            ->where('reference', $helpApplication)
            ->where('status', HelpApplicationStatus::Pending)
            ->firstOrFail();

        Gate::authorize('reviewPending', $application);

        $applicationKey = HelpApplication::query()
            ->select('id')
            ->where('reference', $helpApplication)
            ->where('status', HelpApplicationStatus::Pending);

        $documents = HelpApplicationDocument::query()
            ->select(['original_name', 'extension', 'size_bytes', 'purpose', 'security_status', 'created_at'])
            ->whereIn('help_application_id', clone $applicationKey)
            ->whereNull('removed_at')
            ->orderBy('created_at')
            ->get();

        $duplicateWarningCount = HelpApplicationDuplicateWarning::query()
            ->whereIn('submitted_application_id', $applicationKey)
            ->count();

        return response()->view(
            'admin.help-applications.show',
            compact('application', 'documents', 'duplicateWarningCount'),
            200,
            self::PRIVATE_HEADERS,
        );
    }

    public function startReview(StartHelpApplicationReviewRequest $request, string $helpApplication): RedirectResponse
    {
        $result = $this->reviewService->start($request->user(), $helpApplication);

        return redirect()->route('admin.help-applications.index')
            ->with('status', $result->changed ? 'help-application-review-started' : 'help-application-review-already-started')
            ->withHeaders(self::PRIVATE_HEADERS);
    }
}
