<?php

namespace App\Http\Requests\Applicant;

use App\Enums\HelpApplicationStatus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SubmitHelpApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('helpApplication');

        if ($application !== null && $this->user() !== null && $application->applicant_id !== $this->user()->getKey()) {
            abort(404);
        }

        return $application !== null && $this->user()?->can('submit', $application) === true;
    }

    public function rules(): array
    {
        if ($this->route('helpApplication')?->status !== HelpApplicationStatus::Draft) {
            return [];
        }

        return [
            'consent' => [
                'required',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== '1') {
                        $fail('You must deliberately accept the consent before submission. / يجب أن توافق صراحةً على الإقرار قبل الإرسال.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'consent.required' => 'You must deliberately accept the consent before submission. / يجب أن توافق صراحةً على الإقرار قبل الإرسال.',
        ];
    }
}
