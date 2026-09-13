@extends('donations.layout')
@section('content')
@php $labels = ['pending'=>['Pending','قيد الانتظار'], 'succeeded'=>['Succeeded','نجح'], 'failed'=>['Failed','فشل'], 'cancelled'=>['Cancelled','أُلغي'], 'expired'=>['Expired','انتهت الصلاحية']]; @endphp

<h1>{{ $locale === 'ar' ? 'تبرعاتي' : 'My donations' }}</h1>
<table><thead><tr><th>{{ $locale === 'ar' ? 'المرجع' : 'Reference' }}</th><th>{{ $locale === 'ar' ? 'المبلغ' : 'Amount' }}</th><th>{{ $locale === 'ar' ? 'الحالة' : 'Status' }}</th></tr></thead><tbody>
@forelse($donations as $donation)
<tr><td><a href="{{ route('donations.show', ['locale'=>$locale,'donation'=>$donation->reference]) }}">{{ $donation->reference }}</a></td><td>{{ $donation->amount }} SDG</td><td>{{ $labels[$donation->status->value][$locale === 'ar' ? 1 : 0] }}</td></tr>
@empty<tr><td colspan="3">{{ $locale === 'ar' ? 'لا توجد تبرعات بعد.' : 'No donations yet.' }}</td></tr>@endforelse
</tbody></table>
<nav aria-label="{{ $locale === 'ar' ? 'صفحات التبرعات' : 'Donation pages' }}">@if($donations->previousPageUrl())<a href="{{ $donations->previousPageUrl() }}">{{ $locale === 'ar' ? 'السابق' : 'Previous' }}</a>@endif {{ $donations->currentPage() }} / {{ $donations->lastPage() }} @if($donations->nextPageUrl())<a href="{{ $donations->nextPageUrl() }}">{{ $locale === 'ar' ? 'التالي' : 'Next' }}</a>@endif</nav>
@endsection
