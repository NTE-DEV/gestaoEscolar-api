<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolGroupMember extends Model
{
    protected $fillable = [
        'school_group_id',
        'school_uid',
        'portal_user_id',
        'portal_user_uid',
        'local_user_id',
        'name',
        'email',
        'cpf',
        'role',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'portal_user_id' => 'integer',
            'local_user_id' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * Normaliza o e-mail para minúsculas ou null quando vazio.
     */
    public static function normalizeEmail(?string $value): ?string
    {
        $trimmed = mb_strtolower(trim((string) $value), 'UTF-8');

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Mantém somente dígitos numéricos do CPF ou null quando vazio.
     */
    public static function normalizeCpf(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return $digits !== '' ? $digits : null;
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => self::normalizeEmail($value),
        );
    }

    protected function cpf(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => self::normalizeCpf($value),
        );
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(SchoolGroup::class, 'school_group_id');
    }

    public function localUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'local_user_id');
    }
}
