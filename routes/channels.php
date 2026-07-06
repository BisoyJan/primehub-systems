<?php

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Anyone who can view attendance records can subscribe to live spreadsheet
// updates. Authorization mirrors the AttendancePolicy::viewAny gate used by
// the spreadsheet endpoint.
Broadcast::channel('attendance.spreadsheet', function (User $user) {
    return $user->can('viewAny', Attendance::class);
});

// IT staff and Super Admins receive live desktop notifications about new IT
// concerns. Authorization mirrors the permission that gates concern resolution
// so new IT staff are covered automatically without any code changes.
Broadcast::channel('it-concerns', function (User $user) {
    return $user->hasPermission('it_concerns.resolve');
});

// Presence channel: tracks who is currently viewing the spreadsheet and is
// used to broadcast cell-focus whispers so each tab can highlight cells
// other users are editing. Returning an array makes the user a "member".
Broadcast::channel('attendance.spreadsheet.presence', function (User $user) {
    if (! $user->can('viewAny', Attendance::class)) {
        return null;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'avatar_url' => $user->avatar_url,
    ];
});
