<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CampaignStatus;
use App\Enums\HelpApplicationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvertHelpApplicationToCampaignRequest;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Models\HelpApplicationDuplicateWarning;
use App\Models\User;
use App\Services\CampaignApplicationAmount;
use App\Services\HelpApplicationCampaignConversionService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class DecidedHelpApplicationController extends Controller
{
    private const PRIVATE_HEADERS = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];

    private function scoped(User $actor): Builder
    {
        return HelpApplication::query()->whereIn('status', ['approved', 'rejected', 'converted_to_campaign'])
            ->when(! $actor->hasRole(UserRole::SuperAdmin), fn ($query) => $query->where('reviewed_by', $actor->getKey()));
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewDecidedAny', HelpApplication::class);
        $isSuperAdmin = $request->user()->hasRole(UserRole::SuperAdmin);
        $status = $request->query('status');
        abort_if(array_key_exists('status', $request->query()) && ! in_array($status, ['approved', 'rejected'], true), 404);
        $query = $this->scoped($request->user())->select(['reference', 'status', 'full_name', 'submitted_at', 'review_started_at', 'decided_at']);
        if ($status !== null) {
            $query->whereIn('status', $status === 'approved' ? ['approved', 'converted_to_campaign'] : ['rejected']);
        }
        if ($isSuperAdmin) {
            $query->addSelect(['reviewer_name' => User::query()->select('name')->whereColumn('users.id', 'help_applications.reviewed_by')->limit(1)]);
        }
        $applications = $query->orderByDesc('decided_at')->orderByDesc('id')->paginate(25);
        if ($status !== null) {
            $applications->appends(['status' => $status]);
        }

        return response()->view('admin.help-applications.decided.index', compact('applications', 'isSuperAdmin', 'status'), 200, self::PRIVATE_HEADERS);
    }

    public function show(Request $request, string $helpApplication): Response
    {
        Gate::authorize('viewDecidedAny', HelpApplication::class);
        $actor = $request->user();
        $isSuperAdmin = $actor->hasRole(UserRole::SuperAdmin);
        $scope = $this->scoped($actor)->where('reference', $helpApplication);
        $application = (clone $scope)->select(array_values(array_unique(array_merge(HelpApplicationCampaignConversionService::FIELDS, [
            'full_name', 'email', 'phone', 'address', 'date_of_birth', 'identity_document_type', 'identity_issuing_country',
            'private_story', 'preferred_receiving_method', 'public_identity_preference',
        ]))))->firstOrFail();
        abort_unless(Gate::allows('viewDecided', $application), 404);
        try {
            $decisionNote = $application->decision_note;
        } catch (DecryptException) {
            abort(404);
        }
        $key = (clone $scope)->select('id')->where('status', $application->status);
        $documents = HelpApplicationDocument::query()->select(['original_name', 'extension', 'size_bytes', 'purpose', 'security_status', 'created_at'])
            ->whereIn('help_application_id', clone $key)->whereNull('removed_at')->orderBy('created_at')->get();
        $warningCounts = HelpApplicationDuplicateWarning::query()->selectRaw('status, COUNT(*) AS aggregate')
            ->whereIn('submitted_application_id', clone $key)->groupBy('status')->pluck('aggregate', 'status');
        $reviewerName = $isSuperAdmin ? User::query()->whereIn('id', (clone $scope)->select('reviewed_by')->whereIn('id', clone $key))->value('name') : null;
        $decisionMakerName = $isSuperAdmin ? User::query()->whereIn('id', (clone $scope)->select('decided_by')->whereIn('id', clone $key))->value('name') : null;
        $assignedCategory = Category::withTrashed()->select(['name_en', 'name_ar', 'is_active', 'deleted_at'])
            ->whereIn('id', (clone $scope)->select('category_id')->whereIn('id', clone $key))->first();
        $campaigns = Campaign::withTrashed()->select(['slug', 'status', 'category_id', 'deleted_at', 'published_at', 'raised_amount', 'target_amount'])
            ->whereIn('help_application_id', clone $key)->limit(2)->get();
        $linkedCampaign = null;
        if ($application->status === HelpApplicationStatus::ConvertedToCampaign) {
            abort_unless($campaigns->count() === 1, 404);
            $linkedCampaign = $campaigns->first();
            abort_unless(! $linkedCampaign->trashed() && $linkedCampaign->status === CampaignStatus::Draft
                && $linkedCampaign->published_at === null && $linkedCampaign->category_id === $application->category_id
                && $assignedCategory !== null && $linkedCampaign->raised_amount === '0.00'
                && CampaignApplicationAmount::canonical($linkedCampaign->target_amount) !== null
                && CampaignApplicationAmount::canonical($application->requested_amount) !== null
                && ! CampaignApplicationAmount::exceeds($linkedCampaign->target_amount, $application->requested_amount)
                && Gate::allows('update', $linkedCampaign), 404);
        } else {
            abort_if($campaigns->isNotEmpty(), 404);
        }
        $canConvert = $assignedCategory !== null && app(HelpApplicationCampaignConversionService::class)->eligible($application);
        // Reassert scope after secondary reads; deterministic interleaving tests do not simulate parallel connections.
        $fresh = (clone $scope)->select(HelpApplicationCampaignConversionService::FIELDS)->firstOrFail();
        foreach (HelpApplicationCampaignConversionService::FIELDS as $field) {
            abort_unless($fresh->getRawOriginal($field) === $application->getRawOriginal($field), 404);
        }

        return response()->view('admin.help-applications.decided.show', compact('application', 'documents', 'warningCounts', 'isSuperAdmin', 'reviewerName', 'decisionMakerName', 'assignedCategory', 'decisionNote', 'linkedCampaign', 'canConvert'), 200, self::PRIVATE_HEADERS);
    }

    public function convert(ConvertHelpApplicationToCampaignRequest $request, string $helpApplication, HelpApplicationCampaignConversionService $service): Response
    {
        try {
            $result = $service->convert($request->user(), $helpApplication, $request->validated());
        } catch (ValidationException $exception) {
            return $request->validationRedirect($exception->errors());
        }

        return redirect()->route('admin.campaigns.edit', $result->campaign)->with('status', 'campaign-created-from-help-application')->withHeaders(self::PRIVATE_HEADERS);
    }
}
