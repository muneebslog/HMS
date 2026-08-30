<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property UserRole $role
 * @property string $route_name
 */
class RolePagePermission extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'role',
        'route_name',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
        ];
    }
}
