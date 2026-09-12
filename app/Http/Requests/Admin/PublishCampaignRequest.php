<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PublishCampaignRequest extends FormRequest
{
    public const REQUIRED = 'Enter an expiration date and time. / أدخل تاريخ ووقت انتهاء الحملة.';

    public const INVALID = 'Enter a valid future date and time. / أدخل تاريخاً ووقتاً صالحين في المستقبل.';

    public function authorize(): bool
    {
        abort_unless($this->user()?->can('publish', $this->route('campaign')), 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('expires_at');
        $this->query->replace([]);
        $this->files->replace([]);
        $this->getInputSource()->replace(['expires_at' => $value]);
    }

    public function rules(): array
    {
        return ['expires_at' => ['bail', 'required', 'string', 'max:19', 'regex:/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?\z/', 'date', function (string $attribute, mixed $value, \Closure $fail): void {
            if (! CarbonImmutable::parse($value, config('app.timezone'))->gt(CarbonImmutable::now(config('app.timezone')))) {
                $fail(self::INVALID);
            }
        }]];
    }

    public function messages(): array
    {
        return ['expires_at.required' => self::REQUIRED, 'expires_at.*' => self::INVALID];
    }

    public function expiration(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('expires_at'), config('app.timezone'));
    }

    protected function failedValidation(Validator $validator): void
    {
        $value = $this->input('expires_at');
        $safe = is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?\z/', $value) === 1;
        throw new HttpResponseException(redirect()->route('admin.campaigns.edit', $this->route('campaign'))
            ->withErrors($validator, 'publication')->withInput($safe ? ['expires_at' => $value] : [])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']));
    }
}
