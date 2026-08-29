<?php

namespace App\Http\Requests\Admin;

use App\Models\Campaign;
use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Campaign::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['slug', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'story_ar', 'story_en', 'target_amount'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => Campaign::normalizeSlug($this->input('slug'))]);
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists(Category::class, 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at')),
            ],
            'slug' => ['required', 'string', 'max:160', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', Rule::unique(Campaign::class, 'slug')],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'summary_ar' => ['required', 'string', 'max:1000'],
            'summary_en' => ['required', 'string', 'max:1000'],
            'story_ar' => ['required', 'string', 'max:20000'],
            'story_en' => ['required', 'string', 'max:20000'],
            'target_amount' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?\z/', $value) !== 1
                    || preg_match('/\A0(?:\.0{1,2})?\z/', $value) === 1) {
                    $fail('Enter a positive amount in ordinary decimal notation with no more than two decimal places. / أدخل مبلغاً موجباً بصيغة عشرية عادية وبحد أقصى منزلتين عشريتين.');
                }
            }],
        ];
    }

    protected function passedValidation(): void
    {
        $amount = $this->string('target_amount')->toString();
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $normalizedAmount = $whole.'.'.str_pad($fraction, 2, '0');

        $this->merge(['target_amount' => $normalizedAmount]);
        $this->validator->setData(array_merge(
            $this->validator->getData(),
            ['target_amount' => $normalizedAmount],
        ));
    }

    public function messages(): array
    {
        return [
            'required' => 'This field is required. / هذا الحقل مطلوب.',
            'string' => 'Enter valid plain text. / أدخل نصاً عادياً صالحاً.',
            'integer' => 'Enter a valid selection. / أدخل اختياراً صالحاً.',
            'max' => 'This field is too long. / هذا الحقل أطول من الحد المسموح.',
            'category_id.exists' => 'The selected category is unavailable. / الفئة المحددة غير متاحة.',
            'slug.regex' => 'Use lowercase letters, numbers, and single hyphens only. / استخدم أحرفاً إنجليزية صغيرة وأرقاماً وشرطات مفردة فقط.',
            'slug.unique' => 'This campaign slug is already in use. / مُعرّف الحملة مستخدم بالفعل.',
        ];
    }
}
