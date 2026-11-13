<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'portal_user_id',
        'name',
        'email',
        'cpf',
        'roles',
        'password',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'roles' => 'array',
            'password' => 'hashed',
        ];
    }

    public function hasAnyRole(array $allowedRoles): bool
    {
        $userRoles = collect($this->roles ?? [])
            ->map(fn($r) => mb_strtolower(trim((string) $r), 'UTF-8'))
            ->filter();

        $normalizedAllowed = collect($allowedRoles)
            ->map(fn($r) => mb_strtolower(trim((string) $r), 'UTF-8'))
            ->filter();

        return $userRoles->intersect($normalizedAllowed)->isNotEmpty();
    }

    public function isMaster(): bool
    {
        return $this->hasAnyRole(['Master']);
    }
}
