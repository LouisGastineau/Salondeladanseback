<?php

namespace App\Http\Requests;

class UpdateEditionRequest extends StoreEditionRequest
{
    public function rules(): array
    {
        return collect(parent::rules())->map(fn (array $rules) => array_merge(['sometimes'], $rules))->all();
    }
}
