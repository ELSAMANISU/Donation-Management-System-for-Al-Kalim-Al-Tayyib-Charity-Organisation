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

                @include('applicant.help-applications.partials.form', [
                    'action' => route('help-applications.update', $application),
                    'method' => 'PATCH',
                ])
            </div>
        </div>
    </div>
</x-app-layout>
