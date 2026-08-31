<?php

namespace App\Http\Requests\Applicant;

use App\Models\HelpApplication;
use Illuminate\Validation\Rule;

class UpdateHelpApplicationDraftRequest extends HelpApplicationDraftRequest
{
    public function authorize(): bool
    {
        $application = $this->route('helpApplication');

        return $application instanceof HelpApplication
            && ($this->user()?->can('update', $application) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $clear = $this->input('clear_identity_document_number');

        if (in_array($clear, [true, 1, '1'], true)) {
            $this->merge(['clear_identity_document_number' => true]);
        } elseif (in_array($clear, [false, 0, '0'], true)) {
            $this->merge(['clear_identity_document_number' => false]);
        }

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return array_merge($this->draftRules(), [
            'identity_document_number' => [
                'nullable', 'string', 'max:255',
                Rule::prohibitedIf(fn (): bool => $this->boolean('clear_identity_document_number')),
            ],
            'clear_identity_document_number' => ['nullable', 'boolean'],
        ]);
    }
}
