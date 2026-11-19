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
        $unitUid = $this->filled('unit_uid')
            ? $this->input('unit_uid')
            : ($this->filled('school_uid')
                ? $this->input('school_uid')
                : ($this->filled('sector_uid')
                    ? $this->input('sector_uid')
                    : ($this->filled('uid_escola')
                        ? $this->input('uid_escola')
                        : $this->input('uid_setor'))));

        $login = $this->filled('login')
            ? $this->input('login')
            : ($this->filled('email') ? $this->input('email') : $this->input('cpf'));

        $cleanUid = is_string($unitUid) ? trim($unitUid) : $unitUid;
        $cleanLogin = is_string($login) ? trim($login) : $login;

        $this->merge([
            'unit_uid' => $cleanUid,
            'school_uid' => $cleanUid,
            'login' => $cleanLogin,
        ]);
    }

    public function rules(): array
    {
        return [
            'unit_uid' => ['nullable', 'uuid'],
            'school_uid' => ['required', 'uuid'],
            'login' => ['required', 'string', 'max:150'],
        ];
    }
}
