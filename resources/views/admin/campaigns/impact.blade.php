<x-app-layout>
    <x-slot name="header"><h1>Public impact / الأثر العام</h1></x-slot>
    <main class="max-w-4xl mx-auto py-8 px-4 space-y-6">
        <p role="note">Public text must use synthetic, non-sensitive information only. Review both languages before publication. / يجب أن يستخدم النص العام معلومات تجريبية غير حساسة فقط. راجع اللغتين قبل النشر.</p>
        @if($campaign->impact_published_at)
            <p>Published and immutable / منشور وغير قابل للتعديل</p>
            <p lang="ar" dir="rtl" class="whitespace-pre-line">{{ $campaign->impact_update_ar }}</p>
            <p lang="en" class="whitespace-pre-line">{{ $campaign->impact_update_en }}</p>
        @else
            <form method="POST" action="{{ route('admin.campaigns.impact.draft', $campaign) }}" class="space-y-5 rounded-lg bg-white p-6 shadow-sm">
                @csrf
                <input type="hidden" name="impact_token" value="{{ $draftToken }}">
                <div class="space-y-2">
                    <label for="impact-ar" class="block font-medium text-gray-700">Arabic impact / الأثر بالعربية</label>
                    <textarea id="impact-ar" name="impact_update_ar" maxlength="2000" required dir="rtl" class="w-full rounded-md border border-gray-300 px-3 py-2 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ $campaign->impact_update_ar }}</textarea>
                </div>
                <div class="space-y-2">
                    <label for="impact-en" class="block font-medium text-gray-700">English impact / الأثر بالإنجليزية</label>
                    <textarea id="impact-en" name="impact_update_en" maxlength="2000" required dir="ltr" class="w-full rounded-md border border-gray-300 px-3 py-2 text-left shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ $campaign->impact_update_en }}</textarea>
                </div>
                <button class="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Save private draft / حفظ المسودة الخاصة</button>
            </form>
            @if($publishToken)
                <form method="POST" action="{{ route('admin.campaigns.impact.publish', $campaign) }}" class="mt-8 rounded-lg border border-emerald-200 bg-emerald-50 p-6">
                    @csrf
                    <input type="hidden" name="impact_token" value="{{ $publishToken }}">
                    <button class="rounded-md bg-emerald-700 px-4 py-2 font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2">Publish reviewed impact / نشر الأثر بعد المراجعة</button>
                </form>
            @endif
        @endif
    </main>
</x-app-layout>
