<?php

namespace App\Http\Requests;

use App\Services\AidDeliveryFormTokens;
use App\Services\AidDeliveryInput;
use App\Services\AidDeliveryService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AidDeliveryRequest extends FormRequest
{
    private array $deliveryInput = [];

    public function action(): string
    {
        return substr($this->route()->getName(), strrpos($this->route()->getName(), '.') + 1);
    }

    public function authorize(): bool
    {
        app(AidDeliveryService::class)->detail($this->user(), $this->route('helpApplication'), $this->route('coordination'), true);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $input = $this->getInputSource()->all();
            $key = app(AidDeliveryFormTokens::class)->key($this, $this->action(), $input);
            unset($input['_token'], $input['idempotency_token']);
            if ($this->action() === 'start') {
                // An explicit persistence-key field is never accepted from the client.
                if (array_key_exists('entry_key', $input)) {
                    abort(404);
                }
                $input['entry_key'] = $key;
            }
            if ($this->query->count() || $this->files->count() || ! AidDeliveryInput::valid($this->action(), $input)) {
                $validator->errors()->add('delivery', 'The sandbox delivery form is invalid. Re-enter the demonstration values. / نموذج التسليم التجريبي غير صالح. أعد إدخال القيم التجريبية.');
            }
            $this->deliveryInput = $input;
        });
    }

    public function deliveryInput(): array
    {
        return $this->deliveryInput;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(redirect()->route('admin.aid-delivery.index', [
            'helpApplication' => $this->route('helpApplication'), 'coordination' => $this->route('coordination'),
        ])->withErrors($validator, 'delivery'));
    }
}
