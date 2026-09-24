<?php

namespace App\Http\Requests;

class UpdateMissionRequest extends StoreMissionRequest
{
    public function rules(): array
    {
        return collect(parent::rules())->map(fn (array $rules) => array_merge(['sometimes'], $rules))->all();
    }
}
