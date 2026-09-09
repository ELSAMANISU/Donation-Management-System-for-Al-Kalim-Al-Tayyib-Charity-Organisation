<?php

namespace App\Http\Requests\Admin;

use App\Enums\HelpApplicationStatus;
use App\Enums\UserRole;
use App\Models\HelpApplication;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DecideHelpApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = HelpApplication::query()->select(['id', 'status', 'reviewed_by'])
            ->where('reference', $this->route('helpApplication'))->where('status', HelpApplicationStatus::UnderReview)
            ->when(! $this->user()->hasRole(UserRole::SuperAdmin), fn ($query) => $query->where('reviewed_by', $this->user()->getKey()))
            ->firstOrFail();
        abort_unless(Gate::allows('decide', $application), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['bail', 'required', 'string', Rule::in(['approved', 'rejected'])],
            'decision_note' => ['bail', 'required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'outcome.required' => 'Select a supported outcome. / اختر نتيجة مدعومة.',
            'outcome.string' => 'Select a supported outcome. / اختر نتيجة مدعومة.',
            'outcome.in' => 'Select a supported outcome. / اختر نتيجة مدعومة.',
            'decision_note.required' => 'Enter a decision note. / أدخل ملاحظة القرار.',
            'decision_note.string' => 'The decision note must be text. / يجب أن تكون ملاحظة القرار نصًا.',
            'decision_note.min' => 'The decision note must contain at least 10 characters. / يجب أن تتكون ملاحظة القرار من 10 أحرف على الأقل.',
            'decision_note.max' => 'The decision note must not exceed 2000 characters. / يجب ألا تتجاوز ملاحظة القرار 2000 حرف.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('decision_note');
        $this->getInputSource()->replace(['outcome' => $this->input('outcome'), 'decision_note' => is_string($note) ? trim($note) : $note]);
    }

    protected function failedValidation(Validator $validator): void
    {
        $outcome = $this->input('outcome');
        throw new HttpResponseException(redirect()->route('admin.help-applications.in-review.show', $this->route('helpApplication'))
            ->withErrors($validator, 'decision')
            ->withInput(in_array($outcome, ['approved', 'rejected'], true) ? ['outcome' => $outcome] : [])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']));
    }
}
