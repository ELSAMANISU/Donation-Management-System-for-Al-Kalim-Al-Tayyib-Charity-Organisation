<?php

namespace App\Http\Requests\Applicant;

use App\Enums\HelpApplicationDocumentPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHelpApplicationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('helpApplication');

        return $application !== null && $this->user()?->can('update', $application) === true;
    }

    public function rules(): array
    {
        return [
            'document' => ['bail', 'required', 'file', 'max:10240'],
            'purpose' => ['bail', 'required', Rule::enum(HelpApplicationDocumentPurpose::class)],
        ];
    }
}
