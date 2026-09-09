<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800">Help Application Under Review / <span lang="ar" dir="rtl">طلب المساعدة قيد المراجعة</span></h1></x-slot>
    @php
        $identityTypes = ['national_id' => ['National ID', 'بطاقة قومية'], 'passport' => ['Passport', 'جواز سفر']];
        $preferences = ['full_name' => ['Full name', 'الاسم الكامل'], 'first_name' => ['First name only', 'الاسم الأول فقط'], 'anonymous' => ['Anonymous', 'مجهول']];
        $purposes = ['medical_report' => ['Medical report', 'تقرير طبي'], 'cost_estimate' => ['Cost estimate', 'تقدير تكلفة'], 'tuition_invoice' => ['Tuition invoice', 'فاتورة رسوم دراسية'], 'admission_letter' => ['Admission letter', 'خطاب قبول'], 'other' => ['Other evidence', 'مستند آخر']];
        $securityStatuses = ['pending' => ['Processing', 'قيد المعالجة'], 'accepted_unscanned' => ['Structurally accepted; not malware-scanned', 'مقبول بنيويًا؛ لم يُفحص من البرمجيات الخبيثة'], 'clean' => ['Malware scan completed', 'اكتمل فحص البرمجيات الخبيثة'], 'rejected' => ['Not accepted', 'غير مقبول']];
    @endphp
    <div class="py-12"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        @if (session('status') === 'help-application-category-assigned')
            <div role="status" class="bg-green-50 p-4 text-sm text-green-800">Category assigned successfully. / <span lang="ar" dir="rtl">تم تعيين الفئة بنجاح.</span></div>
        @elseif (session('status') === 'help-application-category-already-assigned')
            <div role="status" class="bg-blue-50 p-4 text-sm text-blue-800">Category already assigned. / <span lang="ar" dir="rtl">تم تعيين الفئة بالفعل.</span></div>
        @endif
        <a href="{{ route('admin.help-applications.in-review.index') }}" class="text-indigo-600 hover:text-indigo-800">← In-review applications / <span lang="ar" dir="rtl">الطلبات قيد المراجعة</span></a>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="category-assignment">
            <h2 id="category-assignment" class="text-lg font-semibold">Application category / <span lang="ar" dir="rtl">فئة الطلب</span></h2>
            @if ($assignedCategory)
                <p class="mt-4">{{ $assignedCategory->name_en }} / <span lang="ar" dir="rtl">{{ $assignedCategory->name_ar }}</span></p>
                @if (! $assignedCategory->is_active || $assignedCategory->deleted_at)
                    <p class="mt-1 text-sm text-gray-700">Unavailable for new assignments / <span lang="ar" dir="rtl">غير متاحة للتعيينات الجديدة</span></p>
                @endif
            @elseif ($unassigned && $availableCategories->isNotEmpty())
                <form class="mt-4" method="POST" action="{{ route('admin.help-applications.in-review.assign-category', $application->reference) }}">
                    @csrf
                    <label for="category" class="text-sm font-medium text-gray-700">Category / <span lang="ar" dir="rtl">الفئة</span></label>
                    <select id="category" name="category" required @if ($errors->has('category')) aria-invalid="true" aria-describedby="category-error" @endif class="mt-1 block w-full rounded-md shadow-sm {{ $errors->has('category') ? 'border-red-200 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500' }}">
                        <option value="">Select / اختر</option>
                        @foreach ($availableCategories as $category)
                            <option value="{{ $category->slug }}" @selected(old('category') === $category->slug)>{{ $category->name_en }} / {{ $category->name_ar }}</option>
                        @endforeach
                    </select>
                    @error('category')<p id="category-error" class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
                    <button type="submit" class="mt-4 rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Assign category / <span lang="ar" dir="rtl">تعيين الفئة</span></button>
                </form>
            @elseif ($unassigned)
                <p class="mt-4 text-gray-700">No active categories are available. / <span lang="ar" dir="rtl">لا توجد فئات نشطة متاحة.</span></p>
            @endif
        </section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="application-status"><h2 id="application-status" class="text-lg font-semibold">Application lifecycle / <span lang="ar" dir="rtl">دورة حياة الطلب</span></h2><dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm font-medium text-gray-500">Reference / <span lang="ar" dir="rtl">المرجع</span></dt><dd class="mt-1 break-all font-mono">{{ $application->reference }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Status / <span lang="ar" dir="rtl">الحالة</span></dt><dd class="mt-1">Under review / <span lang="ar" dir="rtl">قيد المراجعة</span></dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Submitted / <span lang="ar" dir="rtl">تاريخ التقديم</span></dt><dd class="mt-1">{{ $application->submitted_at->format('Y-m-d H:i') }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Review started / <span lang="ar" dir="rtl">تاريخ بدء المراجعة</span></dt><dd class="mt-1">{{ $application->review_started_at->format('Y-m-d H:i') }}</dd></div>
            @if ($isSuperAdmin)<div><dt class="text-sm font-medium text-gray-500">Reviewer / <span lang="ar" dir="rtl">المسؤول</span></dt><dd class="mt-1">{{ $reviewerName ?: 'Reviewer unavailable / المسؤول غير متاح' }}</dd></div>@endif
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="contact"><h2 id="contact" class="text-lg font-semibold">Contact information / <span lang="ar" dir="rtl">معلومات الاتصال</span></h2><dl class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach ([['Full name', 'الاسم الكامل', $application->full_name], ['Email', 'البريد الإلكتروني', $application->email], ['Phone', 'الهاتف', $application->phone], ['Address', 'العنوان', $application->address], ['Date of birth', 'تاريخ الميلاد', $application->date_of_birth]] as [$en, $ar, $value])<div><dt class="text-sm font-medium text-gray-500">{{ $en }} / <span lang="ar" dir="rtl">{{ $ar }}</span></dt><dd class="mt-1 break-all">{{ $value }}</dd></div>@endforeach
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="assistance"><h2 id="assistance" class="text-lg font-semibold">Assistance information / <span lang="ar" dir="rtl">معلومات المساعدة</span></h2><dl class="mt-4 space-y-4">
            <div><dt class="text-sm font-medium text-gray-500">Requested amount / <span lang="ar" dir="rtl">المبلغ المطلوب</span></dt><dd class="mt-1">{{ number_format((float) $application->requested_amount, 2, '.', ',') }} SDG / <span lang="ar" dir="rtl">ج.س</span></dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Private story / <span lang="ar" dir="rtl">القصة الخاصة</span></dt><dd class="mt-1 whitespace-pre-wrap break-all">{{ $application->private_story }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Preferred way to receive assistance / <span lang="ar" dir="rtl">الطريقة المفضلة لاستلام المساعدة</span></dt><dd class="mt-1 whitespace-pre-wrap break-all">{{ $application->preferred_receiving_method }}</dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Public identity preference / <span lang="ar" dir="rtl">تفضيل الهوية العلنية</span></dt><dd class="mt-1">{{ $preferences[$application->public_identity_preference->value][0] }} / <span lang="ar" dir="rtl">{{ $preferences[$application->public_identity_preference->value][1] }}</span></dd></div>
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="identity"><h2 id="identity" class="text-lg font-semibold">Identity metadata / <span lang="ar" dir="rtl">بيانات الهوية</span></h2><dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm font-medium text-gray-500">Document type / <span lang="ar" dir="rtl">نوع الوثيقة</span></dt><dd class="mt-1">{{ $identityTypes[$application->identity_document_type->value][0] }} / <span lang="ar" dir="rtl">{{ $identityTypes[$application->identity_document_type->value][1] }}</span></dd></div>
            <div><dt class="text-sm font-medium text-gray-500">Issuing country / <span lang="ar" dir="rtl">بلد الإصدار</span></dt><dd class="mt-1">{{ $application->identity_issuing_country }}</dd></div>
        </dl></section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="documents"><h2 id="documents" class="text-lg font-semibold">Active supporting-document metadata / <span lang="ar" dir="rtl">بيانات المستندات الداعمة النشطة</span></h2>
            @forelse ($documents as $document) @php($purpose = $purposes[$document->purpose->value]) @php($security = $securityStatuses[$document->security_status->value])
                <dl class="mt-4 grid gap-3 border-t border-gray-200 pt-4 sm:grid-cols-2">
                    @foreach ([['Filename', 'اسم الملف', $document->original_name], ['Purpose', 'الغرض', $purpose[0].' / '.$purpose[1]], ['Format', 'التنسيق', ['pdf' => 'PDF', 'jpg' => 'JPEG', 'png' => 'PNG'][$document->extension]], ['Size', 'الحجم', number_format($document->size_bytes).' bytes'], ['Security status', 'حالة الأمان', $security[0].' / '.$security[1]], ['Uploaded', 'تاريخ الرفع', $document->created_at->format('Y-m-d H:i')]] as [$en, $ar, $value])<div><dt class="text-sm font-medium text-gray-500">{{ $en }} / <span lang="ar" dir="rtl">{{ $ar }}</span></dt><dd class="mt-1 break-all">{{ $value }}</dd></div>@endforeach
                </dl>
            @empty <p class="mt-4 text-gray-700">No active supporting-document metadata. / <span lang="ar" dir="rtl">لا توجد بيانات لمستندات داعمة نشطة.</span></p> @endforelse
        </section>
        <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="matches"><h2 id="matches" class="text-lg font-semibold">Possible prior matches / <span lang="ar" dir="rtl">المطابقات السابقة المحتملة</span></h2><p class="mt-4">Duplicate-warning count / <span lang="ar" dir="rtl">عدد تحذيرات التكرار</span>: {{ $duplicateWarningCount }}</p>
            <a class="text-indigo-600" href="{{ route('admin.help-applications.in-review.duplicate-warnings.index', $application->reference) }}">Review possible matches ({{ $duplicateWarningCount }}) / مراجعة المطابقات المحتملة ({{ $duplicateWarningCount }})</a>
            @if ($duplicateWarningCount === 0)
                <p>No possible matches. / لا توجد مطابقات محتملة.</p>
            @endif
        </section>
    </div></div>
</x-app-layout>
