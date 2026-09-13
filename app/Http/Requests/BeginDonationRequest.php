<?php

namespace App\Http\Requests;

use App\Services\DonationFormTokens;
use App\Services\DonationMoney;
use App\Services\PublicCampaignQuery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class BeginDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = app(PublicCampaignQuery::class)->visible()->where('slug', $this->route('campaign'))->firstOrFail();
        abort_unless(DonationMoney::eligible($campaign) && config('donations.driver') === 'sandbox', 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', function ($attribute, $value, $fail) {
                if (! DonationMoney::canonical($value)) {
                    $fail('Enter a positive decimal amount in SDG. / أدخل مبلغاً عشرياً موجباً بالجنيه السوداني.');
                }
            }],
            'idempotency_token' => ['bail', 'required', function ($attribute, $value, $fail) {
                $campaign = app(PublicCampaignQuery::class)->visible()->where('slug', $this->route('campaign'))->firstOrFail();
                try {
                    app(DonationFormTokens::class)->entryKey($this, $campaign);
                } catch (ValidationException $exception) {
                    $fail($exception->errors()['idempotency_token'][0]);
                }
            }],
            'anonymous' => [$this->user() ? 'required' : 'prohibited', function ($attribute, $value, $fail) {
                if (! in_array($value, ['0', '1'], true)) {
                    $fail('Invalid anonymous choice. / خيار إخفاء الاسم غير صالح.');
                }
            }],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (array_diff(array_keys($this->all()), ['_token', 'idempotency_token', 'amount', 'anonymous']) || $this->query->count() !== 0 || (! $this->user() && $this->exists('anonymous'))) {
                $validator->errors()->add('amount', 'Only the amount and anonymous choice are accepted. / يُقبل المبلغ وخيار إخفاء الاسم فقط.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        // Never flash untrusted financial or identity input, including unknown fields.
        throw new HttpResponseException($this->expectsJson()
            ? response()->json(['message' => 'Invalid donation input.', 'errors' => $validator->errors()], 422)
            : redirect()->route('donations.create', ['locale' => $this->route('locale'), 'campaign' => $this->route('campaign')])->withErrors($validator));
    }
}
