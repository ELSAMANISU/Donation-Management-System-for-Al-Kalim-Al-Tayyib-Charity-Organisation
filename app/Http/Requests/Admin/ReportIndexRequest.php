<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;

final class ReportIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewReports');
    }

    public function rules(): array
    {
        return [
            'start_date' => ['sometimes', 'required', 'string', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'required', 'string', 'date_format:Y-m-d'],
            'group_by' => ['sometimes', 'required', 'string', 'in:day,month'],
            'campaign_page' => ['sometimes', 'required', 'regex:/\A[1-9][0-9]{0,4}\z/', 'integer', 'max:10000'],
            'category_page' => ['sometimes', 'required', 'regex:/\A[1-9][0-9]{0,4}\z/', 'integer', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $input = $this->query();
            if (array_diff(array_keys($input), array_keys($this->rules())) !== [] || $this->request->all() !== [] || $this->getContent() !== '' || $this->allFiles() !== []
                || array_key_exists('start_date', $input) !== array_key_exists('end_date', $input)) {
                $validator->errors()->add('filters', 'Invalid filters.');
            }
            if ($validator->errors()->isNotEmpty() || ! isset($input['start_date'])) {
                return;
            }
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $input['start_date'], 'UTC');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $input['end_date'], 'UTC');
            if ($start->gt($end) || $end->gt(CarbonImmutable::today('UTC')) || $start->diffInDays($end) > 365) {
                $validator->errors()->add('filters', 'Invalid period.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        // Do not flash input, reflect unknown parameter names, or redirect to an untrusted Referer.
        throw new HttpResponseException(response('Invalid report filters. / مرشحات التقرير غير صالحة.', 422));
    }

    public function filters(): array
    {
        $valid = $this->validated();
        $today = CarbonImmutable::today('UTC');

        return [
            'start_date' => $valid['start_date'] ?? $today->subDays(29)->format('Y-m-d'),
            'end_date' => $valid['end_date'] ?? $today->format('Y-m-d'),
            'group_by' => $valid['group_by'] ?? 'day',
            'campaign_page' => (int) ($valid['campaign_page'] ?? 1),
            'category_page' => (int) ($valid['category_page'] ?? 1),
        ];
    }
}
