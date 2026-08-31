<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">Start Help Application Draft / <span lang="ar" dir="rtl">بدء مسودة طلب مساعدة</span></h1>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-lg bg-white p-6 shadow-sm">
                @include('applicant.help-applications.partials.form', [
                    'application' => null,
                    'action' => route('help-applications.store'),
                    'method' => 'POST',
                ])
            </div>
        </div>
    </div>
</x-app-layout>
