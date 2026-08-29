<?php

namespace App\Http\Requests\Admin;

use App\Models\Campaign;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreCampaignImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route('campaign');

        return $campaign instanceof Campaign
            && ($this->user()?->can('update', $campaign) ?? false);
    }

    protected function prepareForValidation(): void
    {
        foreach (['image_alt_ar', 'image_alt_en'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max(5 * 1024)
                    ->dimensions(Rule::dimensions()->maxWidth(8000)->maxHeight(8000)),
                'extensions:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
            'image_alt_ar' => ['required', 'string', 'max:255'],
            'image_alt_en' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('image')) {
                return;
            }

            $image = $this->file('image');
            if ($image === null) {
                return;
            }

            $validPairs = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
            ];
            if (($validPairs[strtolower($image->getClientOriginalExtension())] ?? null) !== $image->getMimeType()) {
                $validator->errors()->add('image', 'The image extension must match its detected content type. / يجب أن يتطابق امتداد الصورة مع نوع محتواها المكتشف.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'required' => 'This field is required. / هذا الحقل مطلوب.',
            'string' => 'Enter valid plain text. / أدخل نصاً صالحاً.',
            'max' => 'This field is too long. / هذا الحقل أطول من الحد المسموح.',
            'image.*' => 'Upload a valid JPEG, PNG, or WebP image no larger than 5 MiB and 8000 pixels per side. / ارفع صورة JPEG أو PNG أو WebP صالحة لا تتجاوز 5 ميبيبايت و8000 بكسل لكل بُعد.',
        ];
    }
}
