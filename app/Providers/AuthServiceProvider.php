<?php

namespace App\Providers;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\BiometricRecord;
use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\ItConcern;
use App\Models\LeaveRequest;
use App\Models\MedicationRequest;
use App\Models\PcMaintenance;
use App\Models\PcSpec;
use App\Models\PcTransfer;
use App\Models\Site;
use App\Models\Station;
use App\Models\Stock;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Policies\AttendancePointPolicy;
use App\Policies\AttendancePolicy;
use App\Policies\BiometricRecordPolicy;
use App\Policies\CampaignPolicy;
use App\Policies\EmployeeSchedulePolicy;
use App\Policies\ItConcernPolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\MedicationRequestPolicy;
use App\Policies\PcMaintenancePolicy;
use App\Policies\PcSpecPolicy;
use App\Policies\PcTransferPolicy;
use App\Policies\SitePolicy;
use App\Policies\StationPolicy;
use App\Policies\StockPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => AccountPolicy::class,
        Attendance::class => AttendancePolicy::class,
        AttendancePoint::class => AttendancePointPolicy::class,
        BiometricRecord::class => BiometricRecordPolicy::class,
        Campaign::class => CampaignPolicy::class,
        EmployeeSchedule::class => EmployeeSchedulePolicy::class,
        ItConcern::class => ItConcernPolicy::class,
        LeaveRequest::class => LeaveRequestPolicy::class,
        MedicationRequest::class => MedicationRequestPolicy::class,
        PcMaintenance::class => PcMaintenancePolicy::class,
        PcSpec::class => PcSpecPolicy::class,
        PcTransfer::class => PcTransferPolicy::class,
        Site::class => SitePolicy::class,
        Station::class => StationPolicy::class,
        Stock::class => StockPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
