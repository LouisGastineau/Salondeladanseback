<?php

namespace App\Http\Requests;

class UpdateEditionRequest extends StoreEditionRequest
{
    public function rules(): array
    {
        return collect(parent::rules())->map(fn (array $rules) => array_merge(['sometimes'], array_values(array_diff($rules, ['after_or_equal:date_debut']))))->all();
    }
}
