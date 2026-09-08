<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HelpApplicationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\HelpApplicationDuplicateWarning;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class InReviewHelpApplicationController extends Controller
{
    private const PRIVATE_HEADERS = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];

    public function index(Request $request): Response
    {
        Gate::authorize('reviewInProgressAny', HelpApplication::class);
        $actor = $request->user();
        $isSuperAdmin = $actor->hasRole(UserRole::SuperAdmin);
        $query = HelpApplication::query()
            ->select(['reference', 'status', 'full_name', 'submitted_at', 'review_started_at'])
            ->where('status', HelpApplicationStatus::UnderReview);

        if (! $isSuperAdmin) {
            $query->where('reviewed_by', $actor->getKey());
        } else {
            $query->addSelect(['reviewer_name' => User::query()->select('name')
                ->whereColumn('users.id', 'help_applications.reviewed_by')->limit(1)]);
        }

        $applications = $query->orderByDesc('review_started_at')->orderByDesc('id')->paginate(25);

        return response()->view('admin.help-applications.in-review.index', compact('applications', 'isSuperAdmin'), 200, self::PRIVATE_HEADERS);
    }

    public function show(Request $request, string $helpApplication): Response
    {
        Gate::authorize('reviewInProgressAny', HelpApplication::class);
        $actor = $request->user();
        $isSuperAdmin = $actor->hasRole(UserRole::SuperAdmin);
        $applicationQuery = HelpApplication::query()
            ->select([
                'reference', 'status', 'reviewed_by', 'submitted_at', 'review_started_at',
                'full_name', 'email', 'phone', 'address', 'date_of_birth',
                'identity_document_type', 'identity_issuing_country', 'requested_amount',
                'private_story', 'preferred_receiving_method', 'public_identity_preference',
            ])->where('reference', $helpApplication)->where('status', HelpApplicationStatus::UnderReview);
        $this->restrictToActor($applicationQuery, $actor, $isSuperAdmin);
        $application = $applicationQuery->firstOrFail();
        Gate::authorize('reviewInProgress', $application);
        $application->makeHidden('reviewed_by');

        $applicationKey = HelpApplication::query()->select('id')
            ->where('reference', $helpApplication)->where('status', HelpApplicationStatus::UnderReview);
        $this->restrictToActor($applicationKey, $actor, $isSuperAdmin);

        $documents = HelpApplicationDocument::query()
            ->select(['original_name', 'extension', 'size_bytes', 'purpose', 'security_status', 'created_at'])
            ->whereIn('help_application_id', clone $applicationKey)->whereNull('removed_at')
            ->orderBy('created_at')->get();
        $duplicateWarningCount = HelpApplicationDuplicateWarning::query()
            ->whereIn('submitted_application_id', clone $applicationKey)->count();
        $reviewerName = $isSuperAdmin
            ? User::query()->whereIn('id', HelpApplication::query()->select('reviewed_by')->whereIn('id', clone $applicationKey))->value('name')
            : null;

        return response()->view('admin.help-applications.in-review.show', compact(
            'application', 'documents', 'duplicateWarningCount', 'isSuperAdmin', 'reviewerName'
        ), 200, self::PRIVATE_HEADERS);
    }

    /** @param Builder<HelpApplication> $query */
    private function restrictToActor(Builder $query, User $actor, bool $isSuperAdmin): void
    {
        if (! $isSuperAdmin) {
            $query->where('reviewed_by', $actor->getKey());
        }
    }
}
