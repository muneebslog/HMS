<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;

class RoleActingService
{
    public const SESSION_KEY = 'acting_as_role';

    /**
     * Roles an admin may preview.
     *
     * @return list<UserRole>
     */
    public function selectableRoles(): array
    {
        return [
            UserRole::Receptionist,
            UserRole::Management,
            UserRole::Doctor,
            UserRole::Indoor,
            UserRole::InchargeNurse,
            UserRole::LabTechnician,
        ];
    }

    /**
     * Start acting as the given role for a real admin.
     */
    public function start(User $admin, UserRole $role): void
    {
        if ($admin->actualRole() !== UserRole::Admin) {
            throw new InvalidArgumentException('Only admins can act as another role.');
        }

        if (! in_array($role, $this->selectableRoles(), true)) {
            throw new InvalidArgumentException('Cannot act as the selected role.');
        }

        Session::put(self::SESSION_KEY, $role->value);
    }

    /**
     * Stop acting as another role.
     */
    public function stop(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * Get the role currently being acted as, if any.
     */
    public function current(): ?UserRole
    {
        $value = Session::get(self::SESSION_KEY);

        if (! is_string($value)) {
            return null;
        }

        $role = UserRole::tryFrom($value);

        if ($role === null || ! in_array($role, $this->selectableRoles(), true)) {
            $this->stop();

            return null;
        }

        return $role;
    }

    /**
     * Whether a role overlay is active in the session.
     */
    public function isActing(): bool
    {
        return $this->current() !== null;
    }
}
