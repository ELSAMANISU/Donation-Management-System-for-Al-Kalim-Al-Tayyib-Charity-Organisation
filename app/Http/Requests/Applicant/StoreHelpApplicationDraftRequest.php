<?php

namespace App\Http\Requests\Applicant;

use App\Models\HelpApplication;

class StoreHelpApplicationDraftRequest extends HelpApplicationDraftRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', HelpApplication::class) ?? false;
    }

    public function rules(): array
    {
        return $this->draftRules();
    }
}
