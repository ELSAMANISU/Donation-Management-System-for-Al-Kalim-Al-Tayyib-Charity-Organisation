<?php

namespace App\Http\Controllers\Applicant;

use App\Enums\HelpApplicationDocumentPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Applicant\StoreHelpApplicationDocumentRequest;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDocument;
use App\Services\HelpApplicationDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class HelpApplicationDocumentController extends Controller
{
    public function __construct(private readonly HelpApplicationDocumentService $service) {}

    public function store(StoreHelpApplicationDocumentRequest $request, HelpApplication $helpApplication): RedirectResponse
    {
        Gate::authorize('update', $helpApplication);
        $this->service->upload($request->user(), $helpApplication, $request->file('document'), $request->enum('purpose', HelpApplicationDocumentPurpose::class), $request);

        return redirect()->route('help-applications.edit', $helpApplication)->with('status', 'help-application-document-uploaded');
    }

    public function destroy(Request $request, HelpApplication $helpApplication, string $helpApplicationDocument): RedirectResponse
    {
        Gate::authorize('update', $helpApplication);
        $document = HelpApplicationDocument::query()->forApplication($helpApplication)->active()->where('reference', $helpApplicationDocument)->firstOrFail();
        Gate::authorize('delete', $document);
        $this->service->remove($request->user(), $helpApplication, $document, $request);

        return redirect()->route('help-applications.edit', $helpApplication)->with('status', 'help-application-document-removed');
    }
}
