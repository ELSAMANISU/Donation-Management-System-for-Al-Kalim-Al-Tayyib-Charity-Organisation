<x-app-layout>
    <x-slot name="header"><div class="flex items-center justify-between"><h1 class="text-xl font-semibold text-gray-800">Edit Campaign Draft / <span lang="ar" dir="rtl">تعديل مسودة الحملة</span></h1><a class="text-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500" href="{{ route('admin.campaigns.index') }}">Back to Campaigns / العودة للحملات</a></div></x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8"><div class="rounded-lg bg-white p-6 shadow-sm">
        <p class="mb-3 text-sm">Saving edits this draft only and does not publish it. / <span lang="ar" dir="rtl">الحفظ يعدّل المسودة فقط ولا ينشرها.</span></p>
        <p class="mb-6 text-sm">Immutable slug / <span lang="ar" dir="rtl">المعرّف غير قابل للتعديل</span>: <b dir="ltr">{{ $campaign->slug }}</b></p>
        @if ($currentCategory && ($currentCategory->trashed() || ! $currentCategory->is_active))<div role="alert" class="mb-4 rounded bg-amber-50 p-4 text-sm">The current Category is unavailable; choose an eligible Category before saving. / الفئة الحالية غير متاحة؛ اختر فئة متاحة قبل الحفظ.</div>@endif
        @if ($categories->isEmpty())
            <div role="status" class="rounded bg-amber-50 p-4 text-sm">No eligible Categories are available. This draft cannot be saved. / لا توجد فئات متاحة ولا يمكن حفظ المسودة.</div>
        @else
            @php $oldCategory=old('category_id',$campaign->category_id); $oldCategory=is_string($oldCategory)||is_int($oldCategory)?(string)$oldCategory:''; @endphp
            <form method="POST" action="{{ route('admin.campaigns.update', $campaign) }}" class="space-y-5">@csrf @method('PATCH')
                @if($errors->any())<div role="alert" class="rounded bg-red-50 p-4 text-sm"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div><label for="category_id">Category / الفئة</label><select id="category_id" name="category_id" required class="mt-1 block w-full focus:ring-2 focus:ring-indigo-500" @if($errors->has('category_id')) aria-invalid="true" aria-describedby="category_id-error" @endif><option value="">Select / اختر</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($oldCategory===(string)$category->id)>{{ $category->name_en }} / {{ $category->name_ar }}</option>@endforeach</select>@error('category_id')<p id="category_id-error">{{ $message }}</p>@enderror</div>
                @foreach(['title_en'=>['English title / العنوان الإنجليزي','en','ltr',255],'title_ar'=>['Arabic title / العنوان العربي','ar','rtl',255]] as $name=>$meta) @php $value=old($name,$campaign->{$name}); $value=is_string($value)?$value:''; @endphp <div><label for="{{ $name }}">{{ $meta[0] }}</label><input id="{{ $name }}" name="{{ $name }}" value="{{ $value }}" required maxlength="{{ $meta[3] }}" lang="{{ $meta[1] }}" dir="{{ $meta[2] }}" class="mt-1 block w-full focus:ring-2 focus:ring-indigo-500" @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>@error($name)<p id="{{ $name }}-error">{{ $message }}</p>@enderror</div> @endforeach
                @foreach(['summary_en'=>['English summary / الملخص الإنجليزي','en','ltr',1000],'summary_ar'=>['Arabic summary / الملخص العربي','ar','rtl',1000],'story_en'=>['English public story / القصة العامة الإنجليزية','en','ltr',20000],'story_ar'=>['Arabic public story / القصة العامة العربية','ar','rtl',20000]] as $name=>$meta) @php $value=old($name,$campaign->{$name}); $value=is_string($value)?$value:''; @endphp <div><label for="{{ $name }}">{{ $meta[0] }}</label><textarea id="{{ $name }}" name="{{ $name }}" required maxlength="{{ $meta[3] }}" lang="{{ $meta[1] }}" dir="{{ $meta[2] }}" class="mt-1 block w-full focus:ring-2 focus:ring-indigo-500" @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>{{ $value }}</textarea>@error($name)<p id="{{ $name }}-error">{{ $message }}</p>@enderror</div> @endforeach
                @php $amount=old('target_amount',$campaign->target_amount); $amount=is_string($amount)||is_int($amount)||is_float($amount)?(string)$amount:''; @endphp
                <div><label for="target_amount">Target amount (SDG) / المبلغ المستهدف</label><input id="target_amount" name="target_amount" dir="ltr" inputmode="decimal" value="{{ $amount }}" required class="mt-1 block w-full focus:ring-2 focus:ring-indigo-500" @if($errors->has('target_amount')) aria-invalid="true" aria-describedby="target_amount-error" @endif>@error('target_amount')<p id="target_amount-error">{{ $message }}</p>@enderror</div>
                <button class="rounded bg-indigo-600 px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">Save Changes / حفظ التغييرات</button>
            </form>
        @endif
        <section class="mt-10 border-t border-gray-200 pt-8" aria-labelledby="campaign-image-heading">
            <h2 id="campaign-image-heading" class="text-lg font-semibold">Draft Image / <span lang="ar" dir="rtl">صورة المسودة</span></h2>
            @if (session('status') === 'campaign-image-updated')<div role="status" class="mt-4 rounded bg-green-50 p-4 text-sm">Campaign image saved securely. The Campaign remains unpublished. / <span lang="ar" dir="rtl">تم حفظ صورة الحملة بأمان وتبقى الحملة غير منشورة.</span></div>@endif
            @if (session('status') === 'campaign-image-removed')<div role="status" class="mt-4 rounded bg-green-50 p-4 text-sm">Campaign image removed. / <span lang="ar" dir="rtl">تمت إزالة صورة الحملة.</span></div>@endif
            @if (session('status') === 'campaign-image-unchanged')<div role="status" class="mt-4 rounded bg-blue-50 p-4 text-sm">There was no Campaign image to remove. / <span lang="ar" dir="rtl">لم تكن هناك صورة للحملة لإزالتها.</span></div>@endif
            <p class="mt-3 text-sm">JPEG, PNG, or WebP; maximum 5 MiB and 8000 pixels per side. Uploading does not publish this Campaign. / <span lang="ar" dir="rtl">JPEG أو PNG أو WebP؛ بحد أقصى 5 ميبيبايت و8000 بكسل لكل بُعد. رفع الصورة لا ينشر الحملة.</span></p>
            <p class="mt-2 rounded bg-amber-50 p-3 text-sm" role="note">Do not upload identity documents or confidential evidence. / <span lang="ar" dir="rtl">لا ترفع مستندات الهوية أو الأدلة السرية.</span></p>
            @if ($campaign->image_path)
                <img class="mt-4 max-h-72 rounded border object-contain" src="{{ route('admin.campaigns.image.show', $campaign) }}" alt="{{ $campaign->image_alt_en }} / {{ $campaign->image_alt_ar }}">
            @else
                <p class="mt-4 text-sm" role="status">No image / <span lang="ar" dir="rtl">لا توجد صورة</span></p>
            @endif
            @php
                $imageAltAr=old('image_alt_ar',$campaign->image_alt_ar); $imageAltAr=is_string($imageAltAr)?$imageAltAr:'';
                $imageAltEn=old('image_alt_en',$campaign->image_alt_en); $imageAltEn=is_string($imageAltEn)?$imageAltEn:'';
            @endphp
            <form method="POST" action="{{ route('admin.campaigns.image.store', $campaign) }}" enctype="multipart/form-data" class="mt-5 space-y-4">@csrf
                <div><label for="campaign_image">Image / <span lang="ar" dir="rtl">الصورة</span></label><input id="campaign_image" name="image" type="file" required accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" class="mt-1 block w-full focus:ring-2 focus:ring-indigo-500" @if($errors->has('image')) aria-invalid="true" aria-describedby="image-error" @endif>@error('image')<p id="image-error">{{ $message }}</p>@enderror</div>
                <div><label for="image_alt_en">English image description / <span lang="ar" dir="rtl">وصف الصورة بالإنجليزية</span></label><input id="image_alt_en" name="image_alt_en" value="{{ $imageAltEn }}" required maxlength="255" lang="en" dir="ltr" class="mt-1 block w-full focus:ring-2 focus:ring-indigo-500" @if($errors->has('image_alt_en')) aria-invalid="true" aria-describedby="image_alt_en-error" @endif>@error('image_alt_en')<p id="image_alt_en-error">{{ $message }}</p>@enderror</div>
                <div><label for="image_alt_ar">Arabic image description / <span lang="ar" dir="rtl">وصف الصورة بالعربية</span></label><input id="image_alt_ar" name="image_alt_ar" value="{{ $imageAltAr }}" required maxlength="255" lang="ar" dir="rtl" class="mt-1 block w-full focus:ring-2 focus:ring-indigo-500" @if($errors->has('image_alt_ar')) aria-invalid="true" aria-describedby="image_alt_ar-error" @endif>@error('image_alt_ar')<p id="image_alt_ar-error">{{ $message }}</p>@enderror</div>
                <button class="rounded bg-indigo-600 px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ $campaign->image_path ? 'Replace Image / استبدال الصورة' : 'Upload Image / رفع الصورة' }}</button>
            </form>
            @if ($campaign->image_path)
                <form method="POST" action="{{ route('admin.campaigns.image.destroy', $campaign) }}" class="mt-4">@csrf @method('DELETE')
                    <button onclick="return confirm('Remove this draft image? / إزالة صورة المسودة؟')" class="rounded bg-red-700 px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-red-500">Remove Image / <span lang="ar" dir="rtl">إزالة الصورة</span></button>
                </form>
            @endif
        </section>
    </div></div></div>
</x-app-layout>
