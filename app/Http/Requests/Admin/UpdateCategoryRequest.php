<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public const DESCRIPTION_MAX_LENGTH = 5000;

    public const MAX_DISPLAY_ORDER = 4294967295;

    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof Category
            && ($this->user()?->can('update', $category) ?? false);
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

        $active = $this->input('is_active');

        if (in_array($active, [true, 1, '1'], true)) {
            $this->merge(['is_active' => true]);
        } elseif (in_array($active, [false, 0, '0'], true)) {
            $this->merge(['is_active' => false]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Category $category */
        $category = $this->route('category');

        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:160',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique(Category::class, 'slug')->ignore($category->getKey()),
            ],
            'description_ar' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            'description_en' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            'display_order' => ['required', 'integer', 'min:0', 'max:'.self::MAX_DISPLAY_ORDER],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
