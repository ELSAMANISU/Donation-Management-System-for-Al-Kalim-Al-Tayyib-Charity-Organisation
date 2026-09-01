<?php

namespace App\Http\Requests\Admin;

use App\Models\HelpApplication;
use Illuminate\Foundation\Http\FormRequest;

class StartHelpApplicationReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reviewPendingAny', HelpApplication::class) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
        $this->replace([]);
    }
}
