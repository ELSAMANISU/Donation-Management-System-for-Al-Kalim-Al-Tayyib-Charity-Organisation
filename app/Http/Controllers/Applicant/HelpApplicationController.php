<?php

namespace App\Http\Controllers\Applicant;

use App\Enums\HelpApplicationStatus;
use App\Enums\IdentityDocumentType;
use App\Enums\PublicIdentityPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\HelpApplicationDraftRequest;
use App\Http\Requests\Applicant\StoreHelpApplicationDraftRequest;
use App\Http\Requests\Applicant\UpdateHelpApplicationDraftRequest;
use App\Models\HelpApplication;
use App\Services\HelpApplicationDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class HelpApplicationController extends Controller
{
    public function __construct(private readonly HelpApplicationDraftService $draftService) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', HelpApplication::class);
        $application = HelpApplication::query()
            ->forApplicant($request->user())
            ->where('open_slot', true)
            ->select(['id', 'reference', 'applicant_id', 'status', 'open_slot'])
            ->first();

        return view('applicant.help-applications.index', [
            'application' => $application,
            'statusLabel' => $application ? $this->statusLabel($application->status) : null,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        Gate::authorize('create', HelpApplication::class);
        $open = HelpApplication::query()->forApplicant($request->user())->where('open_slot', true)->first();

        if ($open?->status === HelpApplicationStatus::Draft) {
            return redirect()->route('help-applications.edit', $open);
        }

        if ($open !== null) {
            return redirect()->route('help-applications.index')->with('status', 'help-application-not-editable');
        }

        return view('applicant.help-applications.create', $this->formOptions());
    }

    public function store(StoreHelpApplicationDraftRequest $request): RedirectResponse
    {
        Gate::authorize('create', HelpApplication::class);
        $application = $this->draftService->create(
            $request->user(),
            $request->safe()->only(HelpApplicationDraftRequest::EDITABLE_FIELDS),
            $request,
        );

        return redirect()->route('help-applications.edit', $application)
            ->with('status', 'help-application-draft-created');
    }

    public function edit(HelpApplication $helpApplication): View
    {
        Gate::authorize('update', $helpApplication);

        return view('applicant.help-applications.edit', [
            ...$this->formOptions(),
            'application' => $helpApplication,
        ]);
    }

    public function update(UpdateHelpApplicationDraftRequest $request, HelpApplication $helpApplication): RedirectResponse
    {
        Gate::authorize('update', $helpApplication);
        $result = $this->draftService->update(
            $request->user(),
            $helpApplication,
            $request->safe()->only(HelpApplicationDraftRequest::EDITABLE_FIELDS),
            $request->boolean('clear_identity_document_number'),
            $request,
        );

        return redirect()->route('help-applications.edit', $result->application)
            ->with('status', $result->changed ? 'help-application-draft-updated' : 'help-application-draft-unchanged');
    }

    private function formOptions(): array
    {
        return [
            'identityDocumentTypes' => IdentityDocumentType::cases(),
            'publicIdentityPreferences' => PublicIdentityPreference::cases(),
        ];
    }

    private function statusLabel(HelpApplicationStatus $status): string
    {
        return match ($status) {
            HelpApplicationStatus::Draft => 'Draft / مسودة',
            HelpApplicationStatus::Pending => 'Pending / قيد الانتظار',
            HelpApplicationStatus::UnderReview => 'Under review / قيد المراجعة',
            HelpApplicationStatus::AdditionalInformationRequired => 'Additional information required / معلومات إضافية مطلوبة',
            HelpApplicationStatus::Approved => 'Approved / مقبول',
            HelpApplicationStatus::Rejected => 'Rejected / مرفوض',
            HelpApplicationStatus::Appealed => 'Appealed / قيد الاستئناف',
            HelpApplicationStatus::ConvertedToCampaign => 'Converted to campaign / حُوّل إلى حملة',
            HelpApplicationStatus::CampaignActive => 'Campaign active / الحملة نشطة',
            HelpApplicationStatus::AidDelivery => 'Aid delivery / تسليم المساعدة',
            HelpApplicationStatus::Completed => 'Completed / مكتمل',
            HelpApplicationStatus::Closed => 'Closed / مغلق',
        };
    }
}
