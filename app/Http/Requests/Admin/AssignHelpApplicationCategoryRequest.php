<?php

namespace App\Http\Requests\Admin;

use App\Models\HelpApplication;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;

class AssignHelpApplicationCategoryRequest extends FormRequest
{
    private const UNAVAILABLE = 'The selected category is unavailable. / الفئة المحددة غير متاحة.';

    public function authorize(): bool
    {
        return Gate::allows('reviewInProgressAny', HelpApplication::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['category' => ['bail', 'required', 'string', 'max:160', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category.required' => self::UNAVAILABLE,
            'category.string' => self::UNAVAILABLE,
            'category.max' => self::UNAVAILABLE,
            'category.regex' => self::UNAVAILABLE,
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->getInputSource()->replace(['category' => $this->input('category')]);
    }

    protected function failedValidation(Validator $validator): void
    {
        $category = $this->input('category');
        $safeInput = is_string($category)
            && strlen($category) <= 160
            && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $category) === 1
                ? ['category' => $category]
                : [];

        $response = redirect($this->getRedirectUrl())
            ->withErrors($validator, $this->errorBag)
            ->withInput($safeInput);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        throw new HttpResponseException($response);
    }
}
