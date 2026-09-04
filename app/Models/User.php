<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Services\PageAccessService;
use App\Services\RoleActingService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property UserRole $role
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'email_verified_at', 'role', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
            'role' => UserRole::class,
        ];
    }

    /**
     * Get the role requests submitted by the user.
     *
     * @return HasMany<RoleRequest, $this>
     */
    public function roleRequests(): HasMany
    {
        return $this->hasMany(RoleRequest::class);
    }

    /**
     * Get the role stored on the user record.
     */
    public function actualRole(): UserRole
    {
        return $this->role;
    }

    /**
     * Get the effective role, including an admin "act as" overlay.
     */
    public function effectiveRole(): UserRole
    {
        if ($this->isActuallyAdmin()) {
            $actingAs = app(RoleActingService::class)->current();

            if ($actingAs !== null) {
                return $actingAs;
            }
        }

        return $this->actualRole();
    }

    /**
     * Determine whether the user is a real admin (ignores act-as overlay).
     */
    public function isActuallyAdmin(): bool
    {
        return $this->actualRole() === UserRole::Admin;
    }

    /**
     * Determine whether the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->effectiveRole() === UserRole::Admin;
    }

    /**
     * Determine whether the user is a receptionist.
     */
    public function isReceptionist(): bool
    {
        return $this->effectiveRole() === UserRole::Receptionist;
    }

    /**
     * Determine whether the user is management.
     */
    public function isManagement(): bool
    {
        return $this->effectiveRole() === UserRole::Management;
    }

    /**
     * Determine whether the user is a doctor.
     */
    public function isDoctor(): bool
    {
        return $this->effectiveRole() === UserRole::Doctor;
    }

    /**
     * Determine whether the user is indoor staff.
     */
    public function isIndoor(): bool
    {
        return $this->effectiveRole() === UserRole::Indoor;
    }

    /**
     * Determine whether the user is an incharge nurse.
     */
    public function isInchargeNurse(): bool
    {
        return $this->effectiveRole() === UserRole::InchargeNurse;
    }

    /**
     * Determine whether the user is a lab technician.
     */
    public function isLabTechnician(): bool
    {
        return $this->effectiveRole() === UserRole::LabTechnician;
    }

    /**
     * Determine whether the user has only the default user role.
     */
    public function isUser(): bool
    {
        return $this->effectiveRole() === UserRole::User;
    }

    /**
     * Get the doctor profile linked to this user.
     *
     * @return HasOne<Doctor, $this>
     */
    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    /**
     * Get the patient calls made by this user.
     *
     * @return HasMany<PatientCall, $this>
     */
    public function patientCalls(): HasMany
    {
        return $this->hasMany(PatientCall::class, 'called_by');
    }

    /**
     * Determine whether the user has the given role.
     */
    public function hasRole(UserRole $role): bool
    {
        return $this->effectiveRole() === $role;
    }

    /**
     * Determine whether the user can access the given route.
     */
    public function canAccessRoute(string $routeName): bool
    {
        return app(PageAccessService::class)->canAccess($this, $routeName);
    }

    /**
     * Get the translated label for the user's role.
     */
    public function roleLabel(): string
    {
        return $this->effectiveRole()->label();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
