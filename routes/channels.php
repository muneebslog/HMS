<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('hms.reception', function (User $user) {
    return $user->isAdmin() || $user->isReceptionist();
});

Broadcast::channel('hms.staff', function (User $user) {
    if ($user->actualRole() === UserRole::User) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'role' => $user->actualRole()->value,
    ];
});
