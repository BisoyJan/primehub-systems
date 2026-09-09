<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    /**
     * A schedule's effective_date mirrors the employee's hired date, so any
     * change to hired_date must cascade to every schedule they own.
     */
    public function updated(User $user): void
    {
        if (! $user->wasChanged('hired_date') || ! $user->hired_date) {
            return;
        }

        $user->employeeSchedules()
            ->update(['effective_date' => $user->hired_date->format('Y-m-d')]);
    }
}
