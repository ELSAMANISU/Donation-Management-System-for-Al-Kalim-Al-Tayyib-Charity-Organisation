<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold text-gray-800">Campaigns / <span lang="ar" dir="rtl">الحملات</span></h1>
            @can('create', \App\Models\Campaign::class)
                <a href="{{ route('admin.campaigns.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Create Draft / <span lang="ar" dir="rtl">إنشاء مسودة</span></a>
            @endcan
        </div>
    </x-slot>
    <div class="py-12"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        @if (session('status') === 'campaign-created')
            <div role="status" class="rounded-md bg-green-50 p-4 text-sm text-green-800">Campaign draft created successfully. It is not published. / <span lang="ar" dir="rtl">تم إنشاء مسودة الحملة بنجاح ولم يتم نشرها.</span></div>
        @endif
        <section class="overflow-hidden rounded-lg bg-white shadow-sm" aria-labelledby="campaign-list-heading">
            <h2 id="campaign-list-heading" class="sr-only">Campaign list / قائمة الحملات</h2>
            @if ($campaigns->isEmpty())
                <div role="status" class="p-12 text-center text-sm text-gray-600">No campaigns have been created. / <span lang="ar" dir="rtl">لم يتم إنشاء أي حملات.</span></div>
            @else
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-6 py-3 text-start">Title / العنوان</th><th class="px-6 py-3 text-start">Category / الفئة</th><th class="px-6 py-3 text-start">Slug / <span lang="ar" dir="rtl">المعرّف</span></th><th class="px-6 py-3 text-start">Status / الحالة</th><th class="px-6 py-3 text-start">Target / الهدف</th><th class="px-6 py-3 text-start">Created / الإنشاء</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach ($campaigns as $campaign)
                        <tr><td class="px-6 py-4"><div>{{ $campaign->title_en }}</div><div lang="ar" dir="rtl">{{ $campaign->title_ar }}</div></td>
                            <td class="px-6 py-4">@if($campaign->category)<div>{{ $campaign->category->name_en }}</div><div lang="ar" dir="rtl">{{ $campaign->category->name_ar }}</div>@else — @endif</td>
                            <td class="px-6 py-4">{{ $campaign->slug }}</td><td class="px-6 py-4">{{ match ($campaign->status) {
                                \App\Enums\CampaignStatus::Draft => 'Draft / مسودة',
                                \App\Enums\CampaignStatus::Active => 'Active / نشطة',
                                \App\Enums\CampaignStatus::Paused => 'Paused / متوقفة مؤقتًا',
                                \App\Enums\CampaignStatus::Funded => 'Fully Funded / مكتملة التمويل',
                                \App\Enums\CampaignStatus::AidDelivery => 'Aid Delivery / تسليم المساعدة',
                                \App\Enums\CampaignStatus::Completed => 'Completed / مكتملة',
                                \App\Enums\CampaignStatus::Cancelled => 'Cancelled / ملغاة',
                            } }}</td>
                            <td class="px-6 py-4">{{ $campaign->target_amount }} SDG / ج.س</td><td class="px-6 py-4">{{ $campaign->created_at?->format('Y-m-d') }}</td></tr>
                    @endforeach
                    </tbody></table></div>
                {{ $campaigns->links() }}
            @endif
        </section>
    </div></div>
</x-app-layout>
