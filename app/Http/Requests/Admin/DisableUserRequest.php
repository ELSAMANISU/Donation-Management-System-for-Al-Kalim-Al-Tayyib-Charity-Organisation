<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DisableUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeAccountState', $this->route('user')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('disabled_reason'))) {
            $this->merge(['disabled_reason' => trim($this->input('disabled_reason'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'disabled_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
