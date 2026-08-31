<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">Edit Help Application Draft / <span lang="ar" dir="rtl">تعديل مسودة طلب المساعدة</span></h1>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-lg bg-white p-6 shadow-sm">
                @if (session('status') === 'help-application-draft-created')
                    <div role="status" class="mb-5 rounded-md bg-green-50 p-4 text-sm text-green-800">Your private draft was created. / <span lang="ar" dir="rtl">تم إنشاء مسودتك الخاصة.</span></div>
                @elseif (session('status') === 'help-application-draft-updated')
                    <div role="status" class="mb-5 rounded-md bg-green-50 p-4 text-sm text-green-800">Your private draft was saved. / <span lang="ar" dir="rtl">تم حفظ مسودتك الخاصة.</span></div>
                @elseif (session('status') === 'help-application-draft-unchanged')
                    <div role="status" class="mb-5 rounded-md bg-blue-50 p-4 text-sm text-blue-800">No changes were made. / <span lang="ar" dir="rtl">لم يتم إجراء أي تغييرات.</span></div>
                @endif

                @if (session('status') === 'help-application-document-uploaded')
                    <div role="status" class="mb-5 rounded-md bg-green-50 p-4 text-sm text-green-800">Private supporting document uploaded. / <span lang="ar" dir="rtl">تم رفع المستند الداعم الخاص.</span></div>
                @elseif (session('status') === 'help-application-document-removed')
                    <div role="status" class="mb-5 rounded-md bg-green-50 p-4 text-sm text-green-800">Supporting document permanently removed. / <span lang="ar" dir="rtl">تمت إزالة المستند الداعم نهائياً.</span></div>
                @endif

                @include('applicant.help-applications.partials.form', [
                    'action' => route('help-applications.update', $application),
                    'method' => 'PATCH',
                ])

                <section class="mt-8 border-t pt-6" aria-labelledby="supporting-documents-heading">
                    <h2 id="supporting-documents-heading" class="text-lg font-semibold">Private supporting documents / <span lang="ar" dir="rtl">المستندات الداعمة الخاصة</span></h2>
                    <p id="document-guidance" class="mt-2 text-sm text-gray-700">PDF, JPG/JPEG, or PNG; maximum 10 MiB each, 10 documents, 50 MiB combined. Files remain private. Structural validation is not malware scanning. / <span lang="ar" dir="rtl">PDF أو JPG/JPEG أو PNG؛ بحد أقصى 10 ميبيبايت للملف و10 مستندات و50 ميبيبايت إجمالاً. تبقى الملفات خاصة. التحقق البنيوي ليس فحصاً للبرمجيات الخبيثة.</span></p>
                    @if ($errors->has('document') || $errors->has('purpose'))
                        <div role="alert" aria-live="assertive" class="mt-4 rounded-md bg-red-50 p-4 text-sm text-red-800"><p>Document upload needs attention. / <span lang="ar" dir="rtl">يرجى مراجعة رفع المستند.</span></p><ul class="mt-2 list-disc pl-5">@foreach (['document', 'purpose'] as $field) @foreach ($errors->get($field) as $error)<li>{{ $error }}</li>@endforeach @endforeach</ul></div>
                    @endif
                    <form class="mt-5 space-y-4" method="POST" enctype="multipart/form-data" action="{{ route('help-applications.documents.store', $application) }}">
                        @csrf
                        <div><label for="document" class="block text-sm font-medium">Document / <span lang="ar" dir="rtl">المستند</span></label><input id="document" name="document" type="file" required accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full" aria-describedby="document-guidance"></div>
                        <div><label for="purpose" class="block text-sm font-medium">Purpose / <span lang="ar" dir="rtl">الغرض</span></label><select id="purpose" name="purpose" required class="mt-1 block w-full rounded-md border-gray-300"><option value="">Select / اختر</option>@foreach ($documentPurposes as $purpose)<option value="{{ $purpose->value }}">{{ match($purpose->value) {'medical_report' => 'Medical report / تقرير طبي', 'cost_estimate' => 'Cost estimate / تقدير تكلفة', 'tuition_invoice' => 'Tuition invoice / فاتورة دراسية', 'admission_letter' => 'Admission letter / خطاب قبول', default => 'Other / أخرى'} }}</option>@endforeach</select></div>
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" type="submit">Upload document / رفع المستند</button>
                    </form>
                    <div class="mt-6 space-y-3">
                        @forelse ($documents as $document)
                            <article class="rounded-md border p-4"><p class="font-medium break-words">{{ $document->original_name }}</p><p class="text-sm text-gray-600">{{ match($document->purpose->value) {'medical_report' => 'Medical report / تقرير طبي', 'cost_estimate' => 'Cost estimate / تقدير تكلفة', 'tuition_invoice' => 'Tuition invoice / فاتورة دراسية', 'admission_letter' => 'Admission letter / خطاب قبول', default => 'Other / أخرى'} }} · {{ strtoupper($document->extension === 'jpg' ? 'JPEG' : $document->extension) }} · {{ number_format($document->size_bytes, 0, '.', ',') }} bytes / <span lang="ar" dir="rtl">بايت</span> · <time datetime="{{ $document->created_at->toIso8601String() }}">{{ $document->created_at->format('Y-m-d H:i') }}</time></p><form class="mt-3" method="POST" action="{{ route('help-applications.documents.destroy', [$application, $document]) }}" onsubmit="return confirm('Permanently remove this private document? / هل تريد إزالة هذا المستند الخاص نهائياً؟')">@csrf @method('DELETE')<button type="submit" class="text-sm font-semibold text-red-700">Permanently remove / إزالة نهائية</button></form></article>
                        @empty
                            <p class="text-sm text-gray-600">No supporting documents uploaded. / <span lang="ar" dir="rtl">لم يتم رفع مستندات داعمة.</span></p>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
