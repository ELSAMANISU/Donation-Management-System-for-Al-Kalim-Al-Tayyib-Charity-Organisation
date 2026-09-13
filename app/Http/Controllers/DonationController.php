<?php

namespace App\Http\Controllers;

use App\Enums\DonationStatus;
use App\Http\Requests\BeginDonationRequest;
use App\Models\Donation;
use App\Services\DonationFormTokens;
use App\Services\DonationMoney;
use App\Services\DonationService;
use App\Services\PublicCampaignQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class DonationController extends Controller
{
    public function __construct(private readonly PublicCampaignQuery $campaigns, private readonly DonationService $donations) {}

    public function create(Request $request, string $locale, string $campaign)
    {
        $case = $this->campaigns->content($locale)->where('slug', $campaign)->firstOrFail();
        abort_unless(DonationMoney::eligible($case) && config('donations.driver') === 'sandbox', 404);
        $idempotencyToken = app(DonationFormTokens::class)->issue($request, $case);

        return $this->privateResponse(view('donations.create', compact('case', 'locale', 'idempotencyToken')));
    }

    public function store(BeginDonationRequest $request, string $locale, string $campaign)
    {
        $visible = $this->campaigns->visible()->where('slug', $campaign)->firstOrFail();
        try {
            [$donation, $token] = $this->donations->begin($request, $visible, $request->validated('amount'), $request->validated('anonymous') === '1');
        } catch (ValidationException $exception) {
            // A locked remaining-amount validation failure must not flash any request input either.
            $response = $request->expectsJson()
                ? response()->json(['message' => 'Invalid donation input.', 'errors' => $exception->errors()], 422)
                : redirect()->route('donations.create', ['locale' => $locale, 'campaign' => $campaign])->withErrors($exception->errors());

            return $this->privateResponse($response);
        }

        return $this->privateResponse(redirect()->route('donations.checkout', ['locale' => $locale, 'donation' => $donation->reference, 'capability' => $token]));
    }

    public function checkout(Request $request, string $locale, Donation $donation)
    {
        abort_unless(config('donations.driver') === 'sandbox', 404);
        $this->donations->authorize($request, $donation);
        if ($donation->status !== DonationStatus::Pending) {
            return $this->resultRedirect($request, $locale, $donation);
        }
        $result = $this->safeResult($donation);
        $capability = $request->route('capability');

        return $this->privateResponse(view('donations.checkout', compact('locale', 'result', 'capability')));
    }

    public function outcome(Request $request, string $locale, Donation $donation, string $action)
    {
        abort_unless(array_diff(array_keys($request->all()), ['_token']) === [] && $request->query->count() === 0, 422);
        $settled = $this->donations->settle($request, $donation, $action);

        return $this->resultRedirect($request, $locale, $settled);
    }

    public function show(Request $request, string $locale, Donation $donation)
    {
        $this->donations->authorize($request, $donation);
        $result = $this->safeResult($donation);

        return $this->privateResponse(view('donations.result', compact('locale', 'result')));
    }

    public function index(Request $request, string $locale)
    {
        $donations = Donation::query()->where('donor_id', $request->user()->id)
            ->select(['reference', 'amount', 'currency', 'status', 'created_at'])->orderByDesc('id')->paginate(15);

        return $this->privateResponse(view('donations.index', compact('locale', 'donations')));
    }

    private function safeResult(Donation $donation): array
    {
        return ['reference' => $donation->reference, 'amount' => $donation->amount, 'currency' => 'SDG', 'status' => $donation->status->value];
    }

    private function resultRedirect(Request $request, string $locale, Donation $donation)
    {
        return $this->privateResponse(redirect()->route('donations.show', ['locale' => $locale, 'donation' => $donation->reference, 'capability' => $request->route('capability')]));
    }

    private function privateResponse($content)
    {
        $response = $content instanceof Response ? $content : response($content);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
