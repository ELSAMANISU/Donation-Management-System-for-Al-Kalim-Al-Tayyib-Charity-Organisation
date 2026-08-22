<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReactivateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeAccountState', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
