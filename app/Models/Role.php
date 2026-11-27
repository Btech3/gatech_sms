<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $attributes = [
        'is_system' => false,
        'is_bootstrap' => false,
    ];

    protected $fillable = [
        'name',
        'name_key',
        'description',
    ];

    protected $hidden = [
        'name_key',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_bootstrap' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }
}
