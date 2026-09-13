@extends('donations.layout')
@section('content')
<h1>{{ $locale === 'ar' ? 'الدفع التجريبي' : 'Sandbox checkout' }}</h1>
<p>{{ $result['amount'] }} SDG</p>
<p>{{ $locale === 'ar' ? 'المرجع' : 'Reference' }}: {{ $result['reference'] }}</p>
@foreach(['success'=>['Simulate success','محاكاة النجاح'], 'failure'=>['Simulate failure','محاكاة الفشل'], 'cancellation'=>['Simulate cancellation','محاكاة الإلغاء']] as $action=>$labels)
<form method="post" action="{{ route('donations.outcome', ['locale'=>$locale, 'donation'=>$result['reference'], 'action'=>$action, 'capability'=>$capability]) }}">@csrf<button type="submit">{{ $labels[$locale === 'ar' ? 1 : 0] }}</button></form>
@endforeach
@endsection
