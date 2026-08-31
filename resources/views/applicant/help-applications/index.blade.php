<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">My Help Application / <span lang="ar" dir="rtl">طلب المساعدة الخاص بي</span></h1>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="application-state-heading">
                <h2 id="application-state-heading" class="text-lg font-semibold text-gray-900">Application status / <span lang="ar" dir="rtl">حالة الطلب</span></h2>

                @if (session('status') === 'help-application-not-editable')
                    <div role="status" class="mt-4 rounded-md bg-amber-50 p-4 text-sm text-amber-900">This application is no longer an editable draft. / <span lang="ar" dir="rtl">لم يعد هذا الطلب مسودة قابلة للتعديل.</span></div>
                @endif

                @if ($application === null)
                    <p class="mt-4 text-gray-700">You have no open Help Application. You may start a private draft and return to it later. Saving a draft does not send it for review. / <span lang="ar" dir="rtl">ليس لديك طلب مساعدة مفتوح. يمكنك بدء مسودة خاصة والعودة إليها لاحقاً. حفظ المسودة لا يرسلها للمراجعة.</span></p>
                    <a href="{{ route('help-applications.create') }}" class="mt-6 inline-flex rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Start Help Application / <span lang="ar" dir="rtl">بدء طلب مساعدة</span></a>
                @elseif ($application->status === \App\Enums\HelpApplicationStatus::Draft)
                    <p class="mt-4 text-gray-700">Your private draft is available to continue. / <span lang="ar" dir="rtl">مسودتك الخاصة متاحة للمتابعة.</span></p>
                    <a href="{{ route('help-applications.edit', $application) }}" class="mt-6 inline-flex rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Continue Draft / <span lang="ar" dir="rtl">متابعة المسودة</span></a>
                @else
                    <p class="mt-4 text-gray-700">Current status: <strong>{{ $statusLabel }}</strong></p>
                    <p class="mt-2 text-gray-700">Draft editing is unavailable at this stage. / <span lang="ar" dir="rtl">تعديل المسودة غير متاح في هذه المرحلة.</span></p>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
