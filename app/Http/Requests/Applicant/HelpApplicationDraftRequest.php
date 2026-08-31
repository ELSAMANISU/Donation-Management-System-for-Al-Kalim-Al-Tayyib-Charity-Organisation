<?php

namespace App\Http\Requests\Applicant;

use App\Enums\IdentityDocumentType;
use App\Enums\PublicIdentityPreference;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class HelpApplicationDraftRequest extends FormRequest
{
    /** @var list<string> */
    public const EDITABLE_FIELDS = [
        'full_name', 'email', 'phone', 'address', 'date_of_birth',
        'identity_document_type', 'identity_issuing_country', 'identity_document_number',
        'requested_amount', 'private_story', 'preferred_receiving_method',
        'public_identity_preference',
    ];

    protected function prepareForValidation(): void
    {
        foreach (self::EDITABLE_FIELDS as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }

        if (is_string($this->input('identity_issuing_country'))) {
            $this->merge(['identity_issuing_country' => mb_strtoupper($this->input('identity_issuing_country'), 'UTF-8')]);
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    protected function draftRules(): array
    {
        return [
            'full_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'date_of_birth' => ['nullable', 'string', 'date_format:Y-m-d', 'before_or_equal:today'],
            'identity_document_type' => ['nullable', Rule::enum(IdentityDocumentType::class)],
            'identity_issuing_country' => ['nullable', 'string', 'size:2', 'regex:/\A[A-Z]{2}\z/'],
            'identity_document_number' => ['nullable', 'string', 'max:255'],
            'requested_amount' => ['nullable', $this->exactPositiveAmountRule()],
            'private_story' => ['nullable', 'string', 'max:20000'],
            'preferred_receiving_method' => ['nullable', 'string', 'max:2000'],
            'public_identity_preference' => ['nullable', Rule::enum(PublicIdentityPreference::class)],
        ];
    }

    protected function passedValidation(): void
    {
        $amount = $this->input('requested_amount');

        if (is_string($amount)) {
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
            $normalized = $whole.'.'.str_pad($fraction, 2, '0');
            $this->merge(['requested_amount' => $normalized]);
            $this->validator->setData(array_merge(
                $this->validator->getData(),
                ['requested_amount' => $normalized],
            ));
        }
    }

    public function messages(): array
    {
        return [
            'string' => 'Enter valid plain text. / أدخل نصاً عادياً صالحاً.',
            'max' => 'This field is too long. / هذا الحقل أطول من الحد المسموح.',
            'size' => 'Enter exactly two letters. / أدخل حرفين بالضبط.',
            'email' => 'Enter a valid email address. / أدخل عنوان بريد إلكتروني صالحاً.',
            'date_format' => 'Enter the date in YYYY-MM-DD format. / أدخل التاريخ بصيغة YYYY-MM-DD.',
            'before_or_equal' => 'The date cannot be in the future. / لا يمكن أن يكون التاريخ في المستقبل.',
            'identity_issuing_country.regex' => 'Use exactly two English letters. / استخدم حرفين إنجليزيين بالضبط.',
            'enum' => 'Choose a valid option. / اختر خياراً صالحاً.',
            'boolean' => 'Choose a valid yes or no option. / اختر خيار نعم أو لا صالحاً.',
            'prohibited_if' => 'Choose either replacement or removal, not both. / اختر الاستبدال أو الإزالة، وليس كليهما.',
        ];
    }

    private function exactPositiveAmountRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)
                || preg_match('/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?\z/', $value) !== 1
                || preg_match('/\A0(?:\.0{1,2})?\z/', $value) === 1) {
                $fail('Enter a positive amount in ordinary decimal notation with no more than two decimal places. / أدخل مبلغاً موجباً بصيغة عشرية عادية وبحد أقصى منزلتين عشريتين.');
            }
        };
    }
}
