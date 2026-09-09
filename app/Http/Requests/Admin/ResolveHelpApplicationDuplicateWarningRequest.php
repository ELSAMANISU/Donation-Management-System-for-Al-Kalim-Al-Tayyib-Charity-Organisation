<?php

namespace App\Http\Requests\Admin;

use App\Enums\HelpApplicationStatus;
use App\Enums\UserRole;
use App\Models\HelpApplication;
use App\Models\HelpApplicationDuplicateWarning;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ResolveHelpApplicationDuplicateWarningRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = HelpApplication::query()->select(['id', 'status', 'reviewed_by'])
            ->where('reference', $this->route('helpApplication'))->where('status', HelpApplicationStatus::UnderReview)
            ->when(! $this->user()->hasRole(UserRole::SuperAdmin), fn ($query) => $query->where('reviewed_by', $this->user()->getKey()))
            ->firstOrFail();
        abort_unless(Gate::allows('resolveDuplicateWarning', $application), 404);
        abort_unless(HelpApplicationDuplicateWarning::query()->where('reference', $this->route('duplicateWarning'))
            ->whereIn('submitted_application_id', HelpApplication::query()->select('id')
                ->where('reference', $this->route('helpApplication'))->where('status', HelpApplicationStatus::UnderReview)
                ->when(! $this->user()->hasRole(UserRole::SuperAdmin), fn ($query) => $query->where('reviewed_by', $this->user()->getKey())))
            ->exists(), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['bail', 'required', 'string', Rule::in(['confirmed_match', 'dismissed'])],
            'resolution_note' => ['bail', 'required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.required' => 'Select a supported outcome. / اختر نتيجة مدعومة.',
            'outcome.string' => 'Select a supported outcome. / اختر نتيجة مدعومة.',
            'outcome.in' => 'Select a supported outcome. / اختر نتيجة مدعومة.',
            'resolution_note.required' => 'Enter a resolution note. / أدخل ملاحظة الحسم.',
            'resolution_note.string' => 'The resolution note must be text. / يجب أن تكون ملاحظة الحسم نصًا.',
            'resolution_note.min' => 'The resolution note must contain at least 10 characters. / يجب أن تتكون ملاحظة الحسم من 10 أحرف على الأقل.',
            'resolution_note.max' => 'The resolution note must not exceed 1000 characters. / يجب ألا تتجاوز ملاحظة الحسم 1000 حرف.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('resolution_note');
        $this->getInputSource()->replace(['outcome' => $this->input('outcome'), 'resolution_note' => is_string($note) ? trim($note) : $note]);
    }

    protected function failedValidation(Validator $validator): void
    {
        $outcome = $this->input('outcome');
        throw new HttpResponseException(redirect()->route('admin.help-applications.in-review.duplicate-warnings.index', $this->route('helpApplication'))
            ->withErrors($validator, 'warning_'.$this->route('duplicateWarning'))
            ->withInput(in_array($outcome, ['confirmed_match', 'dismissed'], true) ? ['outcome' => $outcome] : [])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']));
    }
}
