<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportIndexRequest;
use App\Services\AdministrativeReportQuery;
use Illuminate\View\View;

final class ReportController extends Controller
{
    public function __invoke(ReportIndexRequest $request, AdministrativeReportQuery $query): View
    {
        return view('admin.reports.index', ['report' => $query->generate($request->user(), $request->filters())]);
    }
}
