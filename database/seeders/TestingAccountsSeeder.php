<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\AttendanceUpload;
use App\Models\BiometricRecord;
use App\Models\BreakEvent;
use App\Models\BreakPolicy;
use App\Models\BreakSession;
use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\CoachingStatusSetting;
use App\Models\EmployeeSchedule;
use App\Models\ItConcern;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditCarryover;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveRequestDeniedDate;
use App\Models\MedicationRequest;
use App\Models\Site;
use App\Models\SplCredit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TestingAccountsSeeder extends Seeder
{
    private array $approverCache = [];

    public function run(): void
    {
        $users = $this->findTestingUsers();

        if ($users->isEmpty()) {
            $this->command->warn('No Testing accounts found. Create users with "Testing" in first_name or last_name first.');
            $this->command->info('Hint: php artisan db:seed --class=AccountSeeder');

            return;
        }

        $this->command->info(sprintf(
            '[INFO] Found %d Testing account(s): %s',
            $users->count(),
            $users->pluck('email')->implode(', ')
        ));

        $this->ensureCoachingStatusSettings();
        $this->ensureBreakPolicy();

        $headers = ['User', 'Attendance', 'Points', 'Leave', 'Credits', 'IT', 'Med', 'Coaching', 'Break', 'BioConflict'];
        $rows = [];

        foreach ($users as $user) {
            $this->command->info(sprintf('Seeding for %s (%s)...', $user->first_name.' '.$user->last_name, $user->email));

            $rows[] = [
                $user->email,
                $this->seedAttendanceFor($user),
                $this->seedAttendancePointsFor($user),
                $this->seedLeaveRequestsFor($user),
                $this->seedLeaveCreditsFor($user),
                $this->seedItConcernsFor($user),
                $this->seedMedicationRequestsFor($user),
                $this->seedCoachingSessionsFor($user),
                $this->seedBreakSessionsFor($user),
                $this->seedBiometricDuringLeaveFor($user),
            ];
        }

        $this->command->table($headers, $rows);
        $this->command->info('TestingAccountsSeeder completed.');
    }

    private function findTestingUsers()
    {
        return User::where(function ($q) {
            $q->where('first_name', 'LIKE', '%Testing%')
                ->orWhere('last_name', 'LIKE', '%Testing%');
        })->get();
    }

    private function resolveApprover(string $role): ?User
    {
        if (! isset($this->approverCache[$role])) {
            $this->approverCache[$role] = User::where('role', $role)
                ->where('is_approved', true)
                ->first();
        }

        return $this->approverCache[$role];
    }

    private function ensureCoachingStatusSettings(): void
    {
        if (CoachingStatusSetting::count() === 0) {
            $this->command->info('  Seeding CoachingStatusSetting defaults...');
            $this->callSilent(CoachingStatusSettingSeeder::class);
        }
    }

    private function ensureBreakPolicy(): void
    {
        if (! BreakPolicy::where('is_active', true)->exists()) {
            $this->command->info('  Seeding default BreakPolicy...');
            $this->callSilent(BreakPolicySeeder::class);
        }
    }

    private function ensureSchedule(User $user): EmployeeSchedule
    {
        $campaignId = Campaign::inRandomOrder()->value('id');
        $siteId = Site::inRandomOrder()->value('id');

        return EmployeeSchedule::firstOrCreate(
            ['user_id' => $user->id],
            [
                'campaign_id' => $campaignId,
                'site_id' => $siteId,
                'shift_type' => 'night_shift',
                'scheduled_time_in' => '22:00:00',
                'scheduled_time_out' => '07:00:00',
                'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'grace_period_minutes' => 0,
                'is_active' => true,
                'effective_date' => now()->subYear(),
            ]
        );
    }

    private function pickWorkdays(int $count, int $pastDays = 90): array
    {
        $dates = [];
        $attempts = 0;

        while (count($dates) < $count && $attempts < $count * 10) {
            $attempts++;
            $date = Carbon::now()->subDays(rand(1, $pastDays));
            if ($date->isWeekday()) {
                $key = $date->format('Y-m-d');
                if (! isset($dates[$key])) {
                    $dates[$key] = $date;
                }
            }
        }

        return array_values($dates);
    }

    private function seedBiometricDuringLeaveFor(User $user): int
    {
        if (BiometricRecord::where('user_id', $user->id)->exists()) {
            $this->command->info("  ↷ Biometric records already seeded for {$user->email}, skipping");

            return BiometricRecord::where('user_id', $user->id)->count();
        }

        $approvedLeaves = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('end_date', '<', now())
            ->get();

        if ($approvedLeaves->isEmpty()) {
            $this->command->info('  - No past approved leaves found, creating one for biometric conflict seeding');

            $admin = $this->resolveApprover('Admin');
            $hr = $this->resolveApprover('HR');
            $start = now()->subDays(rand(10, 20));
            $end = (clone $start)->addDays(rand(1, 2));

            $leave = LeaveRequest::factory()
                ->fullyApproved($admin, $hr)
                ->state([
                    'user_id' => $user->id,
                    'leave_type' => 'VL',
                    'start_date' => $start,
                    'end_date' => $end,
                    'days_requested' => $start->diffInDays($end) + 1,
                ])
                ->create();

            $approvedLeaves = collect([$leave]);
        }

        $siteId = Site::inRandomOrder()->value('id');
        if (! $siteId) {
            $this->command->info('  - No site found, skipping biometric conflict seeding');

            return 0;
        }

        $upload = AttendanceUpload::firstOrCreate(
            ['original_filename' => 'testing_biometric_conflict.txt'],
            [
                'uploaded_by' => $this->resolveApprover('Admin')?->id ?? 1,
                'stored_filename' => Str::uuid()->toString().'.txt',
                'date_from' => now()->subDays(90),
                'date_to' => now(),
                'biometric_site_id' => $siteId,
                'status' => 'completed',
                'total_records' => 20,
                'processed_records' => 20,
                'matched_employees' => 10,
                'unmatched_names' => 0,
                'unmatched_names_list' => [],
                'date_warnings' => [],
                'dates_found' => [],
                'error_message' => null,
                'notes' => 'Auto-generated by TestingAccountsSeeder',
            ]
        );

        $totalCreated = 0;

        foreach ($approvedLeaves as $leave) {
            $leaveStart = $leave->start_date;
            $leaveEnd = $leave->end_date;
            $numDays = $leaveStart->diffInDays($leaveEnd) + 1;

            $datesToSeed = min($numDays, 2);

            for ($d = 0; $d < $datesToSeed; $d++) {
                $bioDate = (clone $leaveStart)->addDays($d);
                $schedule = EmployeeSchedule::where('user_id', $user->id)->where('is_active', true)->first();

                $scheduledIn = $schedule ? Carbon::parse($bioDate->format('Y-m-d').' '.$schedule->scheduled_time_in) : (clone $bioDate)->setHour(22)->setMinute(0);
                $scheduledOut = $schedule ? Carbon::parse($bioDate->format('Y-m-d').' '.$schedule->scheduled_time_out) : (clone $bioDate)->setHour(7)->setMinute(0);

                if ($scheduledOut->lessThan($scheduledIn)) {
                    $scheduledOut->addDay();
                }

                $actualIn = (clone $scheduledIn)->addMinutes(rand(-5, 10));
                $actualOut = $schedule
                    ? (clone $scheduledOut)->addMinutes(rand(-10, 5))
                    : (clone $scheduledOut);

                // Create bio-in record
                BiometricRecord::factory()->create([
                    'user_id' => $user->id,
                    'attendance_upload_id' => $upload->id,
                    'site_id' => $siteId,
                    'employee_name' => $user->last_name.' '.strtoupper(substr($user->first_name, 0, 1)),
                    'datetime' => $actualIn,
                    'record_date' => $bioDate->format('Y-m-d'),
                    'record_time' => $actualIn->format('H:i:s'),
                ]);
                $totalCreated++;

                // Create bio-out record
                BiometricRecord::factory()->create([
                    'user_id' => $user->id,
                    'attendance_upload_id' => $upload->id,
                    'site_id' => $siteId,
                    'employee_name' => $user->last_name.' '.strtoupper(substr($user->first_name, 0, 1)),
                    'datetime' => $actualOut,
                    'record_date' => $bioDate->format('Y-m-d'),
                    'record_time' => $actualOut->format('H:i:s'),
                ]);
                $totalCreated++;

                // Update matching attendance record to create the conflict
                Attendance::where('user_id', $user->id)
                    ->where('shift_date', $bioDate->format('Y-m-d'))
                    ->update([
                        'actual_time_in' => $actualIn,
                        'actual_time_out' => $actualOut,
                        'bio_in_site_id' => $siteId,
                        'bio_out_site_id' => $siteId,
                        'leave_request_id' => $leave->id,
                    ]);
            }
        }

        if ($totalCreated > 0) {
            $this->command->info("  ✓ Created {$totalCreated} biometric conflict records");
        } else {
            $this->command->info('  - No biometric conflicts created');
        }

        return $totalCreated;
    }

    private function seedAttendanceFor(User $user): int
    {
        if (Attendance::where('user_id', $user->id)->exists()) {
            $this->command->info("  ↷ Attendance already seeded for {$user->email}, skipping");

            return Attendance::where('user_id', $user->id)->count();
        }

        $schedule = $this->ensureSchedule($user);
        $dates = $this->pickWorkdays(12);

        $states = [
            'onTime', 'onTime', 'tardy', 'halfDayAbsence', 'ncns',
            'advisedAbsence', 'undertime', 'failedBioIn',
            'verified', 'partiallyVerified', 'onTime', 'tardy',
        ];

        foreach ($dates as $i => $date) {
            $stateMethod = $states[$i % count($states)];
            Attendance::factory()
                ->state([
                    'shift_date' => $date,
                    'user_id' => $user->id,
                    'employee_schedule_id' => $schedule->id,
                ])
                ->{$stateMethod}()
                ->create();
        }

        $count = 12;
        $this->command->info("  ✓ Created {$count} attendance records");

        return $count;
    }

    private function seedAttendancePointsFor(User $user): int
    {
        if (AttendancePoint::where('user_id', $user->id)->exists()) {
            $this->command->info("  ↷ Attendance points already seeded for {$user->email}, skipping");

            return AttendancePoint::where('user_id', $user->id)->count();
        }

        $attendances = Attendance::where('user_id', $user->id)->get();

        $configs = [
            ['state' => 'tardy', 'count' => 2],
            ['state' => 'undertime', 'count' => 1],
            ['state' => 'undertimeMoreThanHour', 'count' => 1],
            ['state' => 'halfDayAbsence', 'count' => 1],
            ['state' => 'ncns', 'count' => 1],
            ['state' => 'ftn', 'count' => 1],
            ['state' => 'excused', 'count' => 1],
            ['state' => 'expiredSro', 'count' => 1],
            ['state' => 'expiredGbro', 'count' => 1],
            ['state' => 'expiringSoon', 'count' => 1],
            ['state' => 'pastExpiration', 'count' => 1],
        ];

        $totalCreated = 0;

        foreach ($configs as $cfg) {
            for ($i = 0; $i < $cfg['count']; $i++) {
                $attendance = $attendances->shift();
                $factory = AttendancePoint::factory()
                    ->forUser($user)
                    ->{$cfg['state']}();

                if ($attendance && ! in_array($cfg['state'], ['excused', 'expiredSro', 'expiredGbro', 'expiringSoon', 'pastExpiration'])) {
                    $factory = $factory->forAttendance($attendance);
                } else {
                    $admin = $this->resolveApprover('Admin');
                    $factory = $factory->manual($admin);

                    if ($attendance) {
                        $factory = $factory->forAttendance($attendance);
                    }
                }

                $factory->create();
                $totalCreated++;
            }
        }

        $this->command->info("  ✓ Created {$totalCreated} attendance point records");

        return $totalCreated;
    }

    private function seedLeaveRequestsFor(User $user): int
    {
        if (LeaveRequest::where('user_id', $user->id)->exists()) {
            $this->command->info("  ↷ Leave requests already seeded for {$user->email}, skipping");

            return LeaveRequest::where('user_id', $user->id)->count();
        }

        $fullYear = now()->year;
        $admin = $this->resolveApprover('Admin');
        $hr = $this->resolveApprover('HR');
        $tl = $this->resolveApprover('Team Lead');

        $leaveSeeds = [];

        // 1-2: VL pending (2)
        $leaveSeeds[] = ['type' => 'VL', 'state' => 'pending', 'days' => 1, 'start' => now()->addDays(5)];
        $leaveSeeds[] = ['type' => 'VL', 'state' => 'pending', 'days' => 2, 'start' => now()->addDays(14)];

        // 3: VL requires TL approval (agents only)
        $leaveSeeds[] = [
            'type' => 'VL', 'state' => 'requiresTlApproval', 'days' => 1,
            'start' => now()->addDays(20),
            'extra' => $user->role === 'Agent' ? [] : ['requires_tl_approval' => false],
        ];

        // 4: SL adminApproved
        $leaveSeeds[] = ['type' => 'SL', 'state' => 'adminApproved', 'days' => 1, 'start' => now()->subDays(10), 'admin' => $admin];

        // 5: VL fullyApproved (past)
        $leaveSeeds[] = ['type' => 'VL', 'state' => 'fullyApproved', 'days' => 2, 'start' => now()->subDays(30), 'admin' => $admin, 'hr' => $hr];

        // 6: VL fullyApproved (future)
        $leaveSeeds[] = ['type' => 'VL', 'state' => 'fullyApproved', 'days' => 3, 'start' => now()->addDays(21), 'admin' => $admin, 'hr' => $hr];

        // 7: BL denied
        $leaveSeeds[] = ['type' => 'BL', 'state' => 'denied', 'days' => 1, 'start' => now()->subDays(5)];

        // 8: SPL cancelled
        $leaveSeeds[] = ['type' => 'SPL', 'state' => 'cancelled', 'days' => 1, 'start' => now()->addDays(10)];

        // 9: LOA with partial denial
        $leaveSeeds[] = ['type' => 'LOA', 'state' => 'fullyApproved', 'days' => 3, 'start' => now()->subDays(20), 'partial_denial' => true, 'admin' => $admin, 'hr' => $hr];

        // 10: SL with per-day LeaveRequestDay rows
        $leaveSeeds[] = ['type' => 'SL', 'state' => 'fullyApproved', 'days' => 3, 'start' => now()->subDays(15), 'with_days' => true, 'admin' => $admin, 'hr' => $hr];

        // 11: UPTO pending
        $leaveSeeds[] = ['type' => 'UPTO', 'state' => 'pending', 'days' => 1, 'start' => now()->addDays(7)];

        // 12: ML fullyApproved (past)
        $leaveSeeds[] = ['type' => 'ML', 'state' => 'fullyApproved', 'days' => 5, 'start' => now()->subDays(45), 'admin' => $admin, 'hr' => $hr];

        $totalCreated = 0;

        foreach ($leaveSeeds as $seed) {
            $endDate = (clone $seed['start'])->addDays($seed['days'] - 1);
            $factory = LeaveRequest::factory()
                ->{$seed['state']}(
                    $seed['admin'] ?? null,
                    $seed['hr'] ?? null,
                )
                ->state([
                    'user_id' => $user->id,
                    'leave_type' => $seed['type'],
                    'start_date' => $seed['start'],
                    'end_date' => $endDate,
                    'days_requested' => $seed['days'],
                ]);

            if (isset($seed['extra'])) {
                $factory = $factory->state($seed['extra']);
            }

            $leave = $factory->create();

            if (($seed['partial_denial'] ?? false) && $seed['days'] > 1) {
                $leave->update([
                    'has_partial_denial' => true,
                    'approved_days' => $seed['days'] - 1,
                ]);

                $deniedDate = (clone $seed['start'])->addDays($seed['days'] - 1);
                LeaveRequestDeniedDate::create([
                    'leave_request_id' => $leave->id,
                    'denied_date' => $deniedDate,
                    'denial_reason' => 'Insufficient credits for this date',
                    'denied_by' => $admin?->id,
                ]);
            }

            if ($seed['with_days'] ?? false) {
                $dayStatuses = ['sl_credited', 'ncns', 'advised_absence'];
                for ($d = 0; $d < $seed['days']; $d++) {
                    $dayDate = (clone $seed['start'])->addDays($d);
                    LeaveRequestDay::create([
                        'leave_request_id' => $leave->id,
                        'date' => $dayDate,
                        'day_status' => $dayStatuses[$d % count($dayStatuses)],
                        'assigned_by' => $admin?->id,
                        'assigned_at' => now(),
                    ]);
                }
            }

            $totalCreated++;
        }

        $this->command->info("  ✓ Created {$totalCreated} leave request records");

        return $totalCreated;
    }

    private function seedLeaveCreditsFor(User $user): int
    {
        if (LeaveCredit::where('user_id', $user->id)->exists()) {
            $this->command->info("  ↷ Leave credits already seeded for {$user->email}, skipping");

            return LeaveCredit::where('user_id', $user->id)->count();
        }

        $year = now()->year;
        $currentMonth = now()->month;
        $totalCreated = 0;

        for ($month = 1; $month <= $currentMonth && $month <= 12; $month++) {
            $earned = in_array($month, [1, 7]) ? 1.5 : 1.25;
            $isFullyUsed = in_array($month, [3, 6, 9]);

            $used = $isFullyUsed ? $earned : 0;
            $balance = $earned - $used;

            LeaveCredit::create([
                'user_id' => $user->id,
                'credits_earned' => $earned,
                'credits_used' => $used,
                'credits_balance' => $balance,
                'year' => $year,
                'month' => $month,
                'accrued_at' => Carbon::create($year, $month, 1),
            ]);

            $totalCreated++;
        }

        LeaveCreditCarryover::create([
            'user_id' => $user->id,
            'credits_from_previous_year' => 5,
            'carryover_credits' => 4,
            'forfeited_credits' => 1,
            'from_year' => $year - 1,
            'to_year' => $year,
            'is_first_regularization' => false,
            'cash_converted' => false,
        ]);

        $totalCreated++;

        if ($user->is_solo_parent) {
            SplCredit::create([
                'user_id' => $user->id,
                'year' => $year,
                'total_credits' => SplCredit::YEARLY_CREDITS,
                'credits_used' => 0,
                'credits_balance' => SplCredit::YEARLY_CREDITS,
            ]);
            $totalCreated++;
        }

        $this->command->info("  ✓ Created {$totalCreated} leave credit records");

        return $totalCreated;
    }

    private function seedItConcernsFor(User $user): int
    {
        if (ItConcern::where('user_id', $user->id)->exists()) {
            $this->command->info("  ↷ IT concerns already seeded for {$user->email}, skipping");

            return ItConcern::where('user_id', $user->id)->count();
        }

        $categories = ['Hardware', 'Software', 'Network/Connectivity', 'Other'];
        $priorities = ['low', 'medium', 'high', 'urgent'];
        $siteId = Site::inRandomOrder()->value('id') ?? 1;

        $statuses = [
            ['state' => null, 'status' => 'pending', 'count' => 3],
            ['state' => 'assigned', 'status' => 'in_progress', 'count' => 2],
            ['state' => 'resolved', 'status' => 'resolved', 'count' => 4],
        ];

        $totalCreated = 0;

        foreach ($statuses as $group) {
            for ($i = 0; $i < $group['count']; $i++) {
                $category = $categories[$totalCreated % count($categories)];
                $priority = $priorities[$totalCreated % count($priorities)];

                $factory = ItConcern::factory()
                    ->state([
                        'user_id' => $user->id,
                        'site_id' => $siteId,
                        'category' => $category,
                        'priority' => $priority,
                        'station_number' => rand(1, 50),
                    ]);

                if ($group['state']) {
                    $factory = $factory->{$group['state']}();
                }

                if ($group['status'] === 'resolved') {
                    $resolver = $this->resolveApprover('IT') ?? $this->resolveApprover('Admin');
                    $factory = $factory->state([
                        'resolved_by' => $resolver?->id,
                    ]);
                }

                $factory->create();
                $totalCreated++;
            }
        }

        // 1 cancelled (manually set status via state)
        ItConcern::factory()
            ->state([
                'user_id' => $user->id,
                'site_id' => $siteId,
                'category' => $categories[$totalCreated % count($categories)],
                'priority' => $priorities[$totalCreated % count($priorities)],
                'status' => 'cancelled',
                'station_number' => rand(1, 50),
            ])
            ->create();

        $totalCreated++;

        $this->command->info("  ✓ Created {$totalCreated} IT concern records");

        return $totalCreated;
    }

    private function seedMedicationRequestsFor(User $user): int
    {
        if (MedicationRequest::where('user_id', $user->id)->exists()) {
            $this->command->info("  ↷ Medication requests already seeded for {$user->email}, skipping");

            return MedicationRequest::where('user_id', $user->id)->count();
        }

        $types = ['Decolgen', 'Biogesic', 'Mefenamic Acid', 'Kremil-S', 'Cetirizine', 'Saridon', 'Diatabs'];
        $symptoms = ['Just today', 'More than 1 day', 'More than 1 week'];
        $totalCreated = 0;

        // 3 pending
        for ($i = 0; $i < 3; $i++) {
            MedicationRequest::factory()
                ->state([
                    'user_id' => $user->id,
                    'medication_type' => $types[$totalCreated % count($types)],
                    'onset_of_symptoms' => $symptoms[$totalCreated % count($symptoms)],
                    'reason' => fake()->sentence(),
                    'status' => 'pending',
                ])
                ->create();
            $totalCreated++;
        }

        // 3 approved
        $approver = $this->resolveApprover('Admin');
        for ($i = 0; $i < 3; $i++) {
            MedicationRequest::factory()
                ->state([
                    'user_id' => $user->id,
                    'medication_type' => $types[$totalCreated % count($types)],
                    'onset_of_symptoms' => $symptoms[$totalCreated % count($symptoms)],
                    'reason' => fake()->sentence(),
                    'status' => 'approved',
                    'approved_by' => $approver?->id,
                    'approved_at' => now()->subHours(rand(1, 48)),
                    'admin_notes' => 'Approved after assessment',
                ])
                ->create();
            $totalCreated++;
        }

        // 3 dispensed
        for ($i = 0; $i < 3; $i++) {
            MedicationRequest::factory()
                ->state([
                    'user_id' => $user->id,
                    'medication_type' => $types[$totalCreated % count($types)],
                    'onset_of_symptoms' => $symptoms[$totalCreated % count($symptoms)],
                    'reason' => fake()->sentence(),
                    'status' => 'dispensed',
                    'approved_by' => $approver?->id,
                    'approved_at' => now()->subHours(rand(4, 72)),
                    'admin_notes' => 'Medication dispensed',
                ])
                ->create();
            $totalCreated++;
        }

        // 1 rejected
        MedicationRequest::factory()
            ->state([
                'user_id' => $user->id,
                'medication_type' => $types[$totalCreated % count($types)],
                'onset_of_symptoms' => $symptoms[$totalCreated % count($symptoms)],
                'reason' => fake()->sentence(),
                'status' => 'rejected',
                'approved_by' => $approver?->id,
                'approved_at' => now()->subHours(rand(1, 24)),
                'admin_notes' => 'Rejected: no valid reason provided',
            ])
            ->create();
        $totalCreated++;

        $this->command->info("  ✓ Created {$totalCreated} medication request records");

        return $totalCreated;
    }

    private function seedCoachingSessionsFor(User $user): int
    {
        if (! in_array($user->role, ['Agent', 'Team Lead'])) {
            $this->command->info("  - Coaching skipped (role {$user->role} not eligible)");

            return 0;
        }

        $count = CoachingSession::where('coachee_id', $user->id)->count();
        if ($count > 0) {
            $this->command->info("  ↷ Coaching sessions already seeded for {$user->email}, skipping");

            return $count;
        }

        if ($user->role === 'Agent') {
            $coach = $this->resolveApprover('Team Lead') ?? $this->resolveApprover('Admin');
        } else {
            $coach = $this->resolveApprover('Admin') ?? $this->resolveApprover('Super Admin');
        }

        if (! $coach) {
            $this->command->info("  - Coaching skipped (no suitable coach found for {$user->email})");

            return 0;
        }

        $coachId = $coach->id;

        $configs = [
            ['state' => null, 'critical' => false, 'count' => 2],
            ['state' => 'acknowledged', 'critical' => false, 'count' => 2],
            ['state' => 'verified', 'critical' => false, 'count' => 1],
            ['state' => 'rejected', 'critical' => false, 'count' => 1],
            ['state' => 'disputed', 'critical' => false, 'count' => 1],
            ['state' => 'draft', 'critical' => false, 'count' => 1],
        ];

        $totalCreated = 0;

        foreach ($configs as $cfg) {
            for ($i = 0; $i < $cfg['count']; $i++) {
                $isCritical = $cfg['critical'] || ($totalCreated < 2);
                $sessionDate = Carbon::now()->subDays(rand(1, 60));

                $factory = CoachingSession::factory()
                    ->state([
                        'coachee_id' => $user->id,
                        'coach_id' => $coachId,
                        'session_date' => $sessionDate->format('Y-m-d'),
                        'severity_flag' => $isCritical ? 'Critical' : 'Normal',
                    ]);

                if ($cfg['state']) {
                    $factory = $factory->{$cfg['state']}();
                }

                $factory->create();
                $totalCreated++;
            }
        }

        $this->command->info("  ✓ Created {$totalCreated} coaching session records");

        return $totalCreated;
    }

    private function seedBreakSessionsFor(User $user): int
    {
        $count = BreakSession::where('user_id', $user->id)->count();
        if ($count > 0) {
            $this->command->info("  ↷ Break sessions already seeded for {$user->email}, skipping");

            return $count;
        }

        $policy = BreakPolicy::where('is_active', true)->first();
        if (! $policy) {
            $this->command->info('  - Break sessions skipped (no active BreakPolicy found)');

            return 0;
        }

        $totalCreated = 0;

        $sessionDates = $this->pickWorkdays(15, 30);

        foreach ($sessionDates as $idx => $date) {
            $startHour = rand(8, 22);
            $startMin = rand(0, 59);
            $startedAt = (clone $date)->setTime($startHour, $startMin);

            $breakTypes = ['1st_break', '2nd_break', 'lunch'];
            $type = $breakTypes[$idx % count($breakTypes)];
            $durationSec = $type === 'lunch' ? 3600 : 900;

            $overageSec = 0;
            $remainingSec = $durationSec;

            if ($idx < 10) {
                $status = 'completed';
                $endedAt = (clone $startedAt)->addSeconds($durationSec);
                $remainingSec = 0;
                $overageSec = 0;
            } elseif ($idx < 13) {
                $status = 'overage';
                $overageSec = rand(60, 600);
                $endedAt = (clone $startedAt)->addSeconds($durationSec + $overageSec);
                $remainingSec = 0;
            } elseif ($idx === 13) {
                $status = 'auto_ended';
                $endedAt = (clone $startedAt)->addSeconds($durationSec + 300);
                $remainingSec = 0;
            } else {
                // Check if we can add a paused session (no other active/paused for this user+date)
                $hasActive = BreakSession::where('user_id', $user->id)
                    ->where('shift_date', $date->format('Y-m-d'))
                    ->whereIn('status', ['active', 'paused'])
                    ->exists();

                if (! $hasActive) {
                    $status = 'paused';
                    $endedAt = null;
                    $remainingSec = 300;
                } else {
                    $status = 'completed';
                    $endedAt = (clone $startedAt)->addSeconds($durationSec);
                    $remainingSec = 0;
                }
            }

            $session = BreakSession::create([
                'session_id' => strtoupper(str_replace(' ', '_', $type)).'-'.Str::uuid(),
                'user_id' => $user->id,
                'break_policy_id' => $policy->id,
                'type' => $type,
                'status' => $status,
                'duration_seconds' => $durationSec,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'remaining_seconds' => $remainingSec,
                'overage_seconds' => $overageSec,
                'total_paused_seconds' => 0,
                'last_pause_reason' => null,
                'shift_date' => $date->format('Y-m-d'),
                'ended_by' => in_array($status, ['auto_ended']) ? 'system' : null,
            ]);

            // Create BreakEvents for timeline
            $events = [];

            if ($status === 'active') {
                $events[] = ['action' => 'start', 'occurred_at' => $startedAt];
            } elseif ($status === 'paused') {
                $pauseAt = (clone $startedAt)->addMinutes(5);
                $events[] = ['action' => 'start', 'occurred_at' => $startedAt];
                $events[] = ['action' => 'pause', 'occurred_at' => $pauseAt, 'reason' => 'Coaching'];
            } else {
                $events[] = ['action' => 'start', 'occurred_at' => $startedAt];
                $events[] = ['action' => 'end', 'occurred_at' => $endedAt];
            }

            foreach ($events as $event) {
                BreakEvent::create([
                    'break_session_id' => $session->id,
                    'action' => $event['action'],
                    'occurred_at' => $event['occurred_at'],
                    'remaining_seconds' => $durationSec,
                    'overage_seconds' => 0,
                    'reason' => $event['reason'] ?? null,
                ]);
            }

            $totalCreated++;
        }

        $this->command->info("  ✓ Created {$totalCreated} break session records");

        return $totalCreated;
    }
}
