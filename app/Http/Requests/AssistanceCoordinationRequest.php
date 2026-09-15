<?php

namespace App\Http\Requests;

use App\Services\AssistanceCoordinationInput;
use App\Services\AssistanceCoordinationService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AssistanceCoordinationRequest extends FormRequest
{
    public function action(): string
    {
        return substr($this->route()->getName(), strrpos($this->route()->getName(), '.') + 1);
    }

    public function administrator(): bool
    {
        return $this->routeIs('admin.coordination.*');
    }

    public function authorize(): bool
    {
        // Authorize before validation so unauthorized callers cannot inspect form errors.
        app(AssistanceCoordinationService::class)->authorize($this->user(), $this->route('helpApplication'), $this->route('coordination'), $this->administrator());

        return true;
    }

    public function rules(): array
    {
        // Generic, bilingual errors contain neither input nor parser diagnostics.
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $input = $this->getInputSource()->all();
            unset($input['_token']);
            if ($this->query->count() !== 0 || $this->files->count() !== 0
                || ! AssistanceCoordinationInput::valid($this->action(), $input)) {
                $validator->errors()->add('coordination', 'Enter valid text and select one listed method. Re-enter demonstration details after an error. / أدخل نصًا صالحًا واختر طريقة واحدة من القائمة. أعد إدخال التفاصيل التجريبية بعد الخطأ.');
            }
        });
    }

    public function coordinationInput(): array
    {
        $input = $this->getInputSource()->all();
        unset($input['_token']);

        return $input;
    }

    protected function failedValidation(Validator $validator): void
    {
        // Never withInput()/old(): neither private messages nor details enter the session.
        throw new HttpResponseException(redirect()->route($this->administrator() ? 'admin.coordination.entry' : 'help-applications.coordination.entry', ['helpApplication' => $this->route('helpApplication')])
            ->withErrors($validator, 'coordination'));
    }
}
