<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">
            Administrator created / <span lang="ar" dir="rtl">تم إنشاء المسؤول</span>
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            <section class="rounded-lg bg-white p-6 shadow-sm sm:p-8" aria-labelledby="administrator-created-heading">
                <h2 id="administrator-created-heading" class="text-lg font-semibold text-green-800">
                    Administrator created successfully / <span lang="ar" dir="rtl">تم إنشاء المسؤول بنجاح</span>
                </h2>

                <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Name / <span lang="ar" dir="rtl">الاسم</span></dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $administrator->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Email / <span lang="ar" dir="rtl">البريد الإلكتروني</span></dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $administrator->email }}</dd>
                    </div>
                </dl>

                <div class="mt-6 rounded-md border border-amber-300 bg-amber-50 p-4" role="alert">
                    <p class="font-semibold text-amber-900">
                        This temporary password is shown once and cannot be recovered from this page later.
                        <span class="mt-1 block" lang="ar" dir="rtl">تظهر كلمة المرور المؤقتة هذه مرة واحدة ولا يمكن استعادتها من هذه الصفحة لاحقًا.</span>
                    </p>
                    <p class="mt-2 text-sm text-amber-800">
                        Deliver it securely. The administrator must replace it at first login.
                        <span class="mt-1 block" lang="ar" dir="rtl">سلّمها بطريقة آمنة. يجب على المسؤول تغييرها عند تسجيل الدخول لأول مرة.</span>
                    </p>
                </div>

                <div class="mt-6">
                    <p class="text-sm font-medium text-gray-700">Temporary password / <span lang="ar" dir="rtl">كلمة المرور المؤقتة</span></p>
                    <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <code id="temporary-password" class="break-all rounded-md bg-gray-100 px-4 py-3 font-mono text-base text-gray-900">{{ $temporaryPassword }}</code>
                        <button id="copy-temporary-password" type="button" class="inline-flex items-center justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            Copy / <span class="ms-1" lang="ar" dir="rtl">نسخ</span>
                        </button>
                    </div>
                    <p id="copy-status" class="mt-2 text-sm text-green-700" aria-live="polite"></p>
                </div>

                <a href="{{ route('admin.administrators.index') }}" class="mt-8 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Back to Administrators / <span class="ms-1" lang="ar" dir="rtl">العودة إلى المسؤولين</span>
                </a>
            </section>
        </div>
    </div>

    <script>
        document.getElementById('copy-temporary-password')?.addEventListener('click', async () => {
            const password = document.getElementById('temporary-password')?.textContent ?? '';
            const status = document.getElementById('copy-status');

            try {
                await navigator.clipboard.writeText(password);
                status.textContent = 'Copied / تم النسخ';
            } catch (error) {
                status.textContent = 'Copy failed. Select and copy the password manually. / تعذر النسخ. حدد كلمة المرور وانسخها يدويًا.';
            }
        });
    </script>
</x-app-layout>
