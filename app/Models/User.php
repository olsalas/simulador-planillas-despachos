<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_PLANNER = 'planner';
    public const ROLE_VIEWER = 'viewer';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'can_upload_csv',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => 'string',
            'can_upload_csv' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function supportedRoles(): array
    {
        return [
            self::ROLE_ADMIN,
            self::ROLE_PLANNER,
            self::ROLE_VIEWER,
        ];
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_ADMIN => 'Administrador',
            self::ROLE_PLANNER => 'Planificador',
            default => 'Consulta',
        };
    }

    /**
     * @return array<string, bool>
     */
    public function appAbilities(): array
    {
        return [
            'view_batches' => true,
            'view_simulation' => true,
            'view_planning' => true,
            'manage_planning' => $this->canManagePlanning(),
            'upload_csv' => $this->canUploadCsv(),
        ];
    }

    public function canUploadCsv(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN) || $this->can_upload_csv;
    }

    public function canManagePlanning(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN, self::ROLE_PLANNER);
    }
}
