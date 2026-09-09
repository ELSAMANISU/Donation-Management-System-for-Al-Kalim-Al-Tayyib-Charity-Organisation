<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HelpApplicationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResolveHelpApplicationDuplicateWarningRequest;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDuplicateWarning;
use App\Models\User;
use App\Services\HelpApplicationDuplicateWarningResolutionService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class HelpApplicationDuplicateWarningController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];

    private const COMPARISON = ['reference', 'status', 'full_name', 'date_of_birth', 'identity_document_type', 'identity_issuing_country', 'submitted_at'];

    private function boundary(User $actor, string $reference): Builder
    {
        return HelpApplication::query()->where('reference', $reference)->where('status', HelpApplicationStatus::UnderReview)
            ->when(! $actor->hasRole(UserRole::SuperAdmin), fn ($query) => $query->where('reviewed_by', $actor->getKey()));
    }

    public function index(Request $request, string $helpApplication): Response
    {
        $actor = $request->user();
        $application = $this->boundary($actor, $helpApplication)->select(['reference', 'status', 'reviewed_by'])->firstOrFail();
        abort_unless(Gate::allows('reviewDuplicateWarnings', $application), 404);
        $key = $this->boundary($actor, $helpApplication)->select('id');
        $query = HelpApplicationDuplicateWarning::query()->whereIn('submitted_application_id', clone $key);
        $counts = (clone $query)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $warnings = (clone $query)->select(['reference', 'status', 'created_at', 'resolved_at', 'resolution_note'])
            ->selectRaw('CASE WHEN resolved_by IS NULL THEN 0 ELSE 1 END AS has_resolver')->orderBy('created_at')->orderBy('id')->paginate(25);
        foreach ($warnings as $warning) {
            $rawStatus = $warning->getRawOriginal('status');
            abort_unless(in_array($rawStatus, ['unreviewed', 'confirmed_match', 'dismissed'], true), 404);
            // Resolve both comparison sides using SQL-only internal keys and a fresh access boundary.
            $ownedWarning = (clone $query)->where('reference', $warning->reference)
                ->whereColumn('submitted_application_id', '<>', 'matched_application_id');
            $current = HelpApplication::query()->select(self::COMPARISON)
                ->whereIn('id', (clone $ownedWarning)->select('submitted_application_id'))->first();
            $prior = HelpApplication::query()->select(self::COMPARISON)
                ->whereIn('id', (clone $ownedWarning)->select('matched_application_id'))->first();
            if ($current === null || $prior === null) {
                abort(404);
            }
            abort_unless($rawStatus === 'unreviewed'
                ? ! $warning->has_resolver && $warning->resolved_at === null && $warning->getRawOriginal('resolution_note') === null
                : $warning->resolved_at !== null && $warning->getRawOriginal('resolution_note') !== null, 404);
            try {
                if ($rawStatus !== 'unreviewed') {
                    $warning->resolution_note;
                }
            } catch (DecryptException) {
                abort(404);
            }
            $warning->offsetUnset('has_resolver');
            $warning->setRelation('current', $current);
            $warning->setRelation('prior', $prior);
        }

        // Do not pass authorization fields to the view.
        return response()->view('admin.help-applications.in-review.duplicate-warnings', [
            'reference' => $helpApplication, 'warnings' => $warnings, 'counts' => $counts,
        ], 200, self::HEADERS);
    }

    public function resolve(ResolveHelpApplicationDuplicateWarningRequest $request, string $helpApplication, string $duplicateWarning, HelpApplicationDuplicateWarningResolutionService $service): Response
    {
        $result = $service->resolve($request->user(), $helpApplication, $duplicateWarning, $request->validated('outcome'), $request->validated('resolution_note'));

        return redirect()->route('admin.help-applications.in-review.duplicate-warnings.index', $helpApplication)
            ->with('status', $result->changed
                ? 'Duplicate warning resolved successfully. / تم حسم تحذير التطابق بنجاح.'
                : 'Warning already resolved. / تم حسم التحذير بالفعل.')
            ->withHeaders(self::HEADERS);
    }
}
