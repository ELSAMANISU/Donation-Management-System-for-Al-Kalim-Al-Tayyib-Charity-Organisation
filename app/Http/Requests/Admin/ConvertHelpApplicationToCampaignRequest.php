<?php

namespace App\Http\Requests\Admin;

use App\Models\Campaign;
use App\Services\CampaignApplicationAmount;
use App\Services\HelpApplicationCampaignConversionService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ConvertHelpApplicationToCampaignRequest extends StoreCampaignRequest
{
    public const INPUT_FIELDS = ['slug', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en', 'target_amount'];

    public const PRIVATE_HEADERS = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];

    private string $requestedAmount;

    protected function prepareForValidation(): void
    {
        $input = $this->only(self::INPUT_FIELDS);
        $this->query->replace([]);
        $this->getInputSource()->replace($input);
        $this->files->replace([]);
        $this->convertedFiles = null;
        parent::prepareForValidation();
    }

    public function authorize(): bool
    {
        $service = app(HelpApplicationCampaignConversionService::class);
        $application = $service->query($this->user(), $this->route('helpApplication'))->firstOrFail();
        abort_unless(Gate::allows('convertToCampaign', $application) && $service->eligible($application), 404);
        abort_if(Campaign::withTrashed()->whereIn('help_application_id', $service->query($this->user(), $this->route('helpApplication'))->select('id'))->exists(), 404);
        $this->requestedAmount = $application->requested_amount;

        return true;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['category_id']);
        $rules['target_amount'][] = function (string $attribute, mixed $value, \Closure $fail): void {
            $amount = CampaignApplicationAmount::canonical($value);
            $limit = CampaignApplicationAmount::canonical($this->requestedAmount);
            abort_if($limit === null, 404);
            if ($amount !== null && CampaignApplicationAmount::exceeds($amount, $limit)) {
                $fail(CampaignApplicationAmount::EXCEEDED);
            }
        };

        return $rules;
    }

    public function safeOldInput(): array
    {
        $safe = [];
        foreach (['title_ar', 'title_en'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && mb_strlen($value) <= 255) {
                $safe[$field] = $value;
            }
        }
        $slug = $this->input('slug');
        if (is_string($slug) && strlen($slug) <= 160 && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1) {
            $safe['slug'] = $slug;
        }
        if (CampaignApplicationAmount::canonical($this->input('target_amount')) !== null) {
            $safe['target_amount'] = $this->input('target_amount');
        }

        return $safe;
    }

    public function validationRedirect(array|Validator $errors): RedirectResponse
    {
        return redirect()->route('admin.help-applications.decided.show', $this->route('helpApplication'))
            ->withErrors($errors)->withInput($this->safeOldInput())->withHeaders(self::PRIVATE_HEADERS);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->validationRedirect($validator));
    }
}
