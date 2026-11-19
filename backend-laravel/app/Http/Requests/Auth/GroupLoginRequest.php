<?php

namespace App\Http\Requests\Auth;

class GroupLoginRequest extends GroupVerifyRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'password' => ['required', 'string', 'max:150'],
            'device_id' => ['nullable', 'string', 'max:120'],
        ]);
    }
}
