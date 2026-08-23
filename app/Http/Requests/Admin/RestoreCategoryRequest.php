<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class RestoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = Category::onlyTrashed()->findOrFail($this->route('category'));

        return $this->user()?->can('restore', $category) ?? false;
    }

    /** @return array<string, never> */
    public function rules(): array
    {
        return [];
    }
}
