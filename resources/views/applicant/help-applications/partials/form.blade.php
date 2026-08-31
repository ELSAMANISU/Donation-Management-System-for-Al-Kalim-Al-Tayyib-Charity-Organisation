@php
    $value = function (string $field) use ($application): string {
        $candidate = old($field, $application?->{$field});
        if ($candidate instanceof \BackedEnum) $candidate = $candidate->value;
        return is_string($candidate) ? $candidate : '';
    };
    $describedBy = fn (string $field, string $help): string => $help.($errors->has($field) ? ' '.$field.'-error' : '');
    $linkableErrorFields = [
        'full_name', 'email', 'phone', 'address', 'date_of_birth',
        'identity_document_type', 'identity_issuing_country', 'identity_document_number',
        'requested_amount', 'private_story', 'preferred_receiving_method',
        'public_identity_preference',
    ];
    if ($application !== null) $linkableErrorFields[] = 'clear_identity_document_number';
@endphp

<p class="text-sm text-gray-700" role="note">Saving keeps a private draft only and does not send it for review. / <span lang="ar" dir="rtl">الحفظ يحتفظ بمسودة خاصة فقط ولا يرسلها للمراجعة.</span></p>
<p class="mt-3 rounded-md bg-amber-50 p-4 text-sm text-amber-900" role="note">Your private story remains private and is not Campaign public copy. The preferred receiving method is only a general preference. Do not enter bank-account, card, wallet, trusted-person account, or transfer-destination details. / <span lang="ar" dir="rtl">تبقى قصتك الخاصة سرية وليست نصاً عاماً للحملة. طريقة الاستلام المفضلة مجرد تفضيل عام. لا تدخل بيانات حساب بنكي أو بطاقة أو محفظة أو حساب شخص موثوق أو أي تفاصيل للتحويل.</span></p>

@if ($errors->any())
    <div role="alert" class="mt-5 rounded-md bg-red-50 p-4 text-sm text-red-800" tabindex="-1">
        <p class="font-semibold">Please correct the highlighted fields. / <span lang="ar" dir="rtl">يرجى تصحيح الحقول المحددة.</span></p>
        <ul class="mt-2 list-disc ps-5">
            @foreach ($errors->getMessages() as $field => $messages)
                @foreach ($messages as $message)
                    <li>
                        @if (in_array($field, $linkableErrorFields, true))
                            <a class="underline focus:outline-none focus:ring-2 focus:ring-red-500" href="#{{ $field }}">{{ $message }}</a>
                        @else
                            <span>{{ $message }}</span>
                        @endif
                    </li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $action }}" class="mt-6 space-y-8">
    @csrf
    @if ($method === 'PATCH') @method('PATCH') @endif

    <fieldset class="space-y-5">
        <legend class="text-lg font-semibold text-gray-900">Personal and contact details / <span lang="ar" dir="rtl">البيانات الشخصية وبيانات الاتصال</span></legend>
        @foreach ([
            'full_name' => ['Full name / الاسم الكامل', 255, 'name', 'text'],
            'email' => ['Email / البريد الإلكتروني', 255, 'email', 'email'],
            'phone' => ['Phone / الهاتف', 50, 'tel', 'tel'],
        ] as $field => $meta)
            <div>
                <label id="{{ $field }}-label" for="{{ $field }}" class="block font-medium text-gray-800">{{ $meta[0] }}</label>
                <p id="{{ $field }}-help" class="text-sm text-gray-600">Optional while this is a draft. / <span lang="ar" dir="rtl">اختياري أثناء مرحلة المسودة.</span></p>
                <input id="{{ $field }}" name="{{ $field }}" type="{{ $meta[3] }}" value="{{ $value($field) }}" maxlength="{{ $meta[1] }}" autocomplete="{{ $meta[2] }}" aria-describedby="{{ $describedBy($field, $field.'-help') }}" @if($errors->has($field)) aria-invalid="true" @endif class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
                @error($field)<p id="{{ $field }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
        @endforeach

        <div>
            <label id="address-label" for="address" class="block font-medium text-gray-800">Address / <span lang="ar" dir="rtl">العنوان</span></label>
            <p id="address-help" class="text-sm text-gray-600">Optional private contact information. / <span lang="ar" dir="rtl">بيانات اتصال خاصة اختيارية.</span></p>
            <textarea id="address" name="address" maxlength="2000" rows="3" autocomplete="street-address" aria-describedby="{{ $describedBy('address', 'address-help') }}" @if($errors->has('address')) aria-invalid="true" @endif class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">{{ $value('address') }}</textarea>
            @error('address')<p id="address-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label id="date_of_birth-label" for="date_of_birth" class="block font-medium text-gray-800">Date of birth / <span lang="ar" dir="rtl">تاريخ الميلاد</span></label>
            <p id="date_of_birth-help" class="text-sm text-gray-600">Use YYYY-MM-DD. / <span lang="ar" dir="rtl">استخدم الصيغة YYYY-MM-DD.</span></p>
            <input id="date_of_birth" name="date_of_birth" type="date" value="{{ $value('date_of_birth') }}" autocomplete="bday" aria-describedby="{{ $describedBy('date_of_birth', 'date_of_birth-help') }}" @if($errors->has('date_of_birth')) aria-invalid="true" @endif class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            @error('date_of_birth')<p id="date_of_birth-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>
    </fieldset>

    <fieldset class="space-y-5">
        <legend class="text-lg font-semibold text-gray-900">Identity details / <span lang="ar" dir="rtl">بيانات الهوية</span></legend>
        <div>
            <label id="identity_document_type-label" for="identity_document_type" class="block font-medium text-gray-800">Document type / <span lang="ar" dir="rtl">نوع الوثيقة</span></label>
            <p id="identity_document_type-help" class="text-sm text-gray-600">Optional while this is a draft. / <span lang="ar" dir="rtl">اختياري أثناء مرحلة المسودة.</span></p>
            <select id="identity_document_type" name="identity_document_type" aria-describedby="{{ $describedBy('identity_document_type', 'identity_document_type-help') }}" @if($errors->has('identity_document_type')) aria-invalid="true" @endif class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
                <option value="">Not selected / غير محدد</option>
                @foreach ($identityDocumentTypes as $type)
                    <option value="{{ $type->value }}" @selected($value('identity_document_type') === $type->value)>{{ $type === \App\Enums\IdentityDocumentType::NationalId ? 'National ID / الهوية الوطنية' : 'Passport / جواز السفر' }}</option>
                @endforeach
            </select>
            @error('identity_document_type')<p id="identity_document_type-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label id="identity_issuing_country-label" for="identity_issuing_country" class="block font-medium text-gray-800">Issuing country code / <span lang="ar" dir="rtl">رمز بلد الإصدار</span></label>
            <p id="identity_issuing_country-help" class="text-sm text-gray-600">Two English letters, for example SD. / <span lang="ar" dir="rtl">حرفان إنجليزيان، مثل SD.</span></p>
            <input id="identity_issuing_country" name="identity_issuing_country" value="{{ $value('identity_issuing_country') }}" maxlength="2" dir="ltr" aria-describedby="{{ $describedBy('identity_issuing_country', 'identity_issuing_country-help') }}" @if($errors->has('identity_issuing_country')) aria-invalid="true" @endif class="mt-1 block w-full rounded-md border-gray-300 uppercase focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            @error('identity_issuing_country')<p id="identity_issuing_country-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label id="identity_document_number-label" for="identity_document_number" class="block font-medium text-gray-800">Identity document number / <span lang="ar" dir="rtl">رقم وثيقة الهوية</span></label>
            @if ($application?->identity_document_number !== null)
                <p id="identity-stored-indicator" class="mt-1 rounded-md bg-blue-50 p-3 text-sm text-blue-800" role="status">An identity number is stored securely. The field below is blank; enter a value only to replace it. / <span lang="ar" dir="rtl">يوجد رقم هوية محفوظ بأمان. الحقل أدناه فارغ؛ أدخل قيمة فقط لاستبداله.</span></p>
            @endif
            <p id="identity_document_number-help" class="text-sm text-gray-600">This sensitive value is never restored after a validation error. / <span lang="ar" dir="rtl">لا تتم إعادة عرض هذه القيمة الحساسة بعد خطأ في التحقق.</span></p>
            <input id="identity_document_number" name="identity_document_number" value="" maxlength="255" autocomplete="off" dir="ltr" aria-describedby="{{ $describedBy('identity_document_number', 'identity_document_number-help') }}" @if($errors->has('identity_document_number')) aria-invalid="true" @endif class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            @error('identity_document_number')<p id="identity_document_number-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>

        @if ($application !== null)
            <div>
                <input id="clear_identity_document_number" name="clear_identity_document_number" type="checkbox" value="1" @checked(old('clear_identity_document_number') === '1') aria-describedby="clear_identity_document_number-help{{ $errors->has('clear_identity_document_number') ? ' clear_identity_document_number-error' : '' }}" @if($errors->has('clear_identity_document_number')) aria-invalid="true" @endif class="rounded border-gray-300 text-indigo-600 focus:ring-2 focus:ring-indigo-500">
                <label for="clear_identity_document_number" class="ms-2 font-medium text-gray-800">Remove the stored identity number / <span lang="ar" dir="rtl">إزالة رقم الهوية المحفوظ</span></label>
                <p id="clear_identity_document_number-help" class="text-sm text-gray-600">Select this only to remove the stored number. Do not also enter a replacement. / <span lang="ar" dir="rtl">حدد هذا الخيار فقط لإزالة الرقم المحفوظ، ولا تدخل بديلاً في الوقت نفسه.</span></p>
                @error('clear_identity_document_number')<p id="clear_identity_document_number-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
        @endif
    </fieldset>

    <fieldset class="space-y-5">
        <legend class="text-lg font-semibold text-gray-900">Assistance details / <span lang="ar" dir="rtl">تفاصيل المساعدة</span></legend>
        <div>
            <label id="requested_amount-label" for="requested_amount" class="block font-medium text-gray-800">Requested amount (SDG) / <span lang="ar" dir="rtl">المبلغ المطلوب (ج.س)</span></label>
            <p id="requested_amount-help" class="text-sm text-gray-600">Use ordinary decimal notation with up to two decimal places. / <span lang="ar" dir="rtl">استخدم صيغة عشرية عادية وبحد أقصى منزلتين عشريتين.</span></p>
            <input id="requested_amount" name="requested_amount" value="{{ $value('requested_amount') }}" inputmode="decimal" dir="ltr" aria-describedby="{{ $describedBy('requested_amount', 'requested_amount-help') }}" @if($errors->has('requested_amount')) aria-invalid="true" @endif class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            @error('requested_amount')<p id="requested_amount-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>

        @foreach ([
            'private_story' => ['Private story / القصة الخاصة', 20000, 7, 'This is private and is not Campaign public copy. / هذه القصة خاصة وليست نصاً عاماً للحملة.'],
            'preferred_receiving_method' => ['Preferred way to receive assistance / الطريقة المفضلة لاستلام المساعدة', 2000, 4, 'General preference only; do not enter transfer-destination details. / تفضيل عام فقط؛ لا تدخل تفاصيل وجهة التحويل.'],
        ] as $field => $meta)
            <div>
                <label id="{{ $field }}-label" for="{{ $field }}" class="block font-medium text-gray-800">{{ $meta[0] }}</label>
                <p id="{{ $field }}-help" class="text-sm text-gray-600">{{ $meta[3] }}</p>
                <textarea id="{{ $field }}" name="{{ $field }}" maxlength="{{ $meta[1] }}" rows="{{ $meta[2] }}" aria-describedby="{{ $describedBy($field, $field.'-help') }}" @if($errors->has($field)) aria-invalid="true" @endif class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">{{ $value($field) }}</textarea>
                @error($field)<p id="{{ $field }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </fieldset>

    <fieldset class="space-y-4">
        <legend class="text-lg font-semibold text-gray-900">Future public identity preference / <span lang="ar" dir="rtl">تفضيل الهوية العامة مستقبلاً</span></legend>
        <p id="public_identity_preference-help" class="text-sm text-gray-600">This preference does not publish your draft. / <span lang="ar" dir="rtl">هذا التفضيل لا ينشر مسودتك.</span></p>
        <select id="public_identity_preference" name="public_identity_preference" aria-describedby="{{ $describedBy('public_identity_preference', 'public_identity_preference-help') }}" @if($errors->has('public_identity_preference')) aria-invalid="true" @endif class="block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500">
            <option value="">Not selected / غير محدد</option>
            @foreach ($publicIdentityPreferences as $preference)
                <option value="{{ $preference->value }}" @selected($value('public_identity_preference') === $preference->value)>{{ match($preference) { \App\Enums\PublicIdentityPreference::FullName => 'Full name / الاسم الكامل', \App\Enums\PublicIdentityPreference::FirstName => 'First name only / الاسم الأول فقط', \App\Enums\PublicIdentityPreference::Anonymous => 'Anonymous / مجهول' } }}</option>
            @endforeach
        </select>
        @error('public_identity_preference')<p id="public_identity_preference-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
    </fieldset>

    <div class="flex items-center justify-between gap-4">
        <a href="{{ route('help-applications.index') }}" class="rounded-md text-indigo-700 underline focus:outline-none focus:ring-2 focus:ring-indigo-500">Back / <span lang="ar" dir="rtl">رجوع</span></a>
        <button class="rounded-md bg-indigo-600 px-5 py-2 font-semibold text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Save Draft / <span lang="ar" dir="rtl">حفظ المسودة</span></button>
    </div>
</form>
