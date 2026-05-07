<?php

namespace App\Observers;

use App\Models\AttendancePoint;
use App\Services\AttendancePoint\StreakService;

class AttendancePointObserver
{
    public function __construct(protected StreakService $streakService) {}

    public function created(AttendancePoint $point): void
    {
        $this->streakService->clearUserCache($point->user_id);
    }

    public function updated(AttendancePoint $point): void
    {
        // Excused / unexcused / type changes all affect the streak calculation.
        $this->streakService->clearUserCache($point->user_id);
    }

    public function deleted(AttendancePoint $point): void
    {
        $this->streakService->clearUserCache($point->user_id);
    }
}
