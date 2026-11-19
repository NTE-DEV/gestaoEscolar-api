<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GroupVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $schoolUid = $this->filled('school_uid')
            ? $this->input('school_uid')
            : $this->input('uid_escola');

        $login = $this->filled('login')
            ? $this->input('login')
            : ($this->filled('email') ? $this->input('email') : $this->input('cpf'));

        $this->merge([
            'school_uid' => is_string($schoolUid) ? trim($schoolUid) : $schoolUid,
            'login' => is_string($login) ? trim($login) : $login,
        ]);
    }

    public function rules(): array
    {
        return [
            'school_uid' => ['required', 'uuid'],
            'login' => ['required', 'string', 'max:150'],
        ];
    }
}
