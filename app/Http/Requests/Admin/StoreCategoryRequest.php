<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public const DESCRIPTION_MAX_LENGTH = 5000;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Category::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name_ar', 'name_en', 'slug', 'description_ar', 'description_en'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => Category::normalizeSlug($this->input('slug'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:160',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique(Category::class, 'slug'),
            ],
            'description_ar' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            'description_en' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
        ];
    }
}
