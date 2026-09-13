@extends('donations.layout')
@section('content')
<h1>{{ $locale === 'ar' ? 'نتيجة التبرع التجريبي' : 'Sandbox donation result' }}</h1>
<p>{{ $locale === 'ar' ? 'المرجع' : 'Reference' }}: {{ $result['reference'] }}</p>
<p>{{ $result['amount'] }} SDG</p>
@php $labels = ['pending'=>['Pending','قيد الانتظار'], 'succeeded'=>['Succeeded','نجح'], 'failed'=>['Failed','فشل'], 'cancelled'=>['Cancelled','أُلغي'], 'expired'=>['Expired','انتهت الصلاحية']]; @endphp
<p role="status" data-status="{{ $result['status'] }}">{{ $labels[$result['status']][$locale === 'ar' ? 1 : 0] }}</p>
@endsection
