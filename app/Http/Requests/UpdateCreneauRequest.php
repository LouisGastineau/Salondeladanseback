<?php

namespace App\Http\Requests;

class UpdateCreneauRequest extends StoreCreneauRequest
{
    public function rules(): array
    {
        return collect(parent::rules())->map(fn (array $rules) => array_merge(['sometimes'], array_values(array_diff($rules, ['after:heure_debut']))))->all();
    }
}
