@php
    $page = $report->filters[$parameter];
    $last = max(1, min(10000, (int) ceil($total / 25)));
@endphp
<nav aria-label="{{ $label }}" class="flex gap-4 mt-4">
    <span>{{ $label }}: {{ $page }} / {{ $last }} ({{ $total }})</span>
    @if ($page > 1)<a class="underline" href="{{ route('admin.reports.index', array_replace($report->filters, [$parameter => $page - 1])) }}">Previous / السابق</a>@endif
    @if ($page < $last)<a class="underline" href="{{ route('admin.reports.index', array_replace($report->filters, [$parameter => $page + 1])) }}">Next / التالي</a>@endif
</nav>