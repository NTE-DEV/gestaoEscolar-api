<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolGroup extends Model
{
    protected $fillable = [
        'school_uid',
        'school_name',
        'unit_type',
        'school_inep',
        'active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Membros vinculados ao grupo desta unidade escolar ou setorial.
     */
    public function members(): HasMany
    {
        return $this->hasMany(SchoolGroupMember::class, 'school_group_id');
    }
}
