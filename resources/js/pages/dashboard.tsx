import React, { useState, useMemo, useEffect, Suspense, lazy } from 'react';
import { motion } from 'framer-motion';
import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Calendar, Building2, Users, ClipboardList, UserCheck, Package, User } from 'lucide-react';
import CalendarWithHolidays from '@/components/CalendarWithHolidays';
import type { SharedData, UserRole } from '@/types';
import type { BreadcrumbItem } from '@/types';
import { DetailDialog } from './Dashboard/components/DetailDialog';
// Lazy-load tab components — only the active tab's JS is loaded (code splitting)
const InfrastructureTab = lazy(() => import('./Dashboard/tabs/InfrastructureTab').then(m => ({ default: m.InfrastructureTab })));
const ItConcernsTab = lazy(() => import('./Dashboard/tabs/ItConcernsTab').then(m => ({ default: m.ItConcernsTab })));
const PresenceInsightsTab = lazy(() => import('./Dashboard/tabs/PresenceInsightsTab').then(m => ({ default: m.PresenceInsightsTab })));
const AttendanceTab = lazy(() => import('./Dashboard/tabs/AttendanceTab').then(m => ({ default: m.AttendanceTab })));
const StockOverviewTab = lazy(() => import('./Dashboard/tabs/StockOverviewTab').then(m => ({ default: m.StockOverviewTab })));
const PersonalDashboardTab = lazy(() => import('./Dashboard/tabs/PersonalDashboardTab').then(m => ({ default: m.PersonalDashboardTab })));
// Widgets are small and always visible — no lazy loading needed
import { NotificationSummaryWidget } from './Dashboard/widgets/NotificationSummaryWidget';
import { UserAccountStatsWidget } from './Dashboard/widgets/UserAccountStatsWidget';
import { RecentActivityWidget } from './Dashboard/widgets/RecentActivityWidget';
import { BiometricAnomalyWidget } from './Dashboard/widgets/BiometricAnomalyWidget';
import { PendingLeaveApprovalsWidget } from './Dashboard/widgets/PendingLeaveApprovalsWidget';
import type { DashboardProps, TabType } from './Dashboard/types';
import { ROLE_TABS, TAB_CONFIG, ROLE_WIDGETS } from './Dashboard/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

// Icon mapping for tab config (can't store React components in const objects in types.ts)
const TAB_ICONS: Record<TabType, React.ComponentType<{ className?: string }>> = {
    'infrastructure': Building2,
    'attendance': Users,
    'it-concerns': ClipboardList,
    'presence-insights': UserCheck,
    'stock-overview': Package,
    'personal': User,
};

// Skeleton fallback for lazy-loaded tabs
function TabSkeleton() {
    return (
        <div className="space-y-4 animate-pulse">
            <div className="h-6 w-48 bg-muted rounded" />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {[...Array(6)].map((_, i) => (
                    <div key={i} className="h-32 bg-muted rounded-lg" />
                ))}
            </div>
        </div>
    );
}

export default function Dashboard({
    totalStations,
    noPcs,
    vacantStations,

    dualMonitor,
    maintenanceDue,
    unassignedPcSpecs,
    itConcernStats,
    itConcernTrends,
    attendanceStatistics,
    monthlyAttendanceData,
    dailyAttendanceData,
    startDate,
    endDate,
    campaignId,
    verificationFilter,
    campaigns,
    isRestrictedRole,
    presenceInsights,
    leaveCredits,
    leaveCalendarMonth,
    leaveConflicts,
    // Phase 1 new props
    stockSummary,
    personalSchedule,
    personalRequests,
    personalAttendanceSummary,
    notificationSummary,
    userAccountStats,
    recentActivityLogs,
    biometricAnomalies,
    // Phase 4 new props
    pointsEscalation,
    ncnsTrend,
    leaveUtilization,
    campaignPresence,
    pointsByCampaign,
    pendingLeaveApprovals,
}: DashboardProps) {
    // Get user role from shared data
    const { auth } = usePage<SharedData>().props;
    const userRole: UserRole = auth?.user?.role || 'Agent';

    // Get available tabs and widgets based on user role
    const availableTabs = useMemo(() => ROLE_TABS[userRole] || ['attendance'], [userRole]);
    const availableWidgets = useMemo(() => ROLE_WIDGETS[userRole] || ['notifications'], [userRole]);
    const hasWidgets = availableWidgets.length > 0;
    const defaultTab = availableTabs[0];

    const [activeTab, setActiveTab] = useState<TabType>(defaultTab);
    const [activeDialog, setActiveDialog] = useState<string | null>(null);

    // Date/time display
    const [currentDateTime, setCurrentDateTime] = useState<{ date: string; time: string }>({ date: '', time: '' });

    useEffect(() => {
        const updateDateTime = () => {
            const now = new Date();
            const date = now.toLocaleDateString("en-US", {
                weekday: "long",
                year: "numeric",
                month: "long",
                day: "numeric",
            });
            const time = now.toLocaleTimeString("en-US", {
                hour: "2-digit",
                minute: "2-digit",
                hour12: true,
            });
            setCurrentDateTime({ date, time });
        };
        updateDateTime();
        const interval = setInterval(updateDateTime, 10000);
        return () => clearInterval(interval);
    }, []);

    const closeDialog = () => setActiveDialog(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <motion.div
                className="p-4 md:p-6 space-y-6"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={{ duration: 0.5 }}
            >
                {/* Header with Date/Time */}
                <motion.div
                    className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                    initial={{ opacity: 0, y: -20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.5, delay: 0.1 }}
                >
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Dashboard</h1>
                        <p className="text-muted-foreground">
                            {isRestrictedRole
                                ? "Your personal overview"
                                : "System overview and analytics"
                            }
                        </p>
                    </div>
                    <button
                        onClick={() => setActiveDialog('dateTime')}
                        className="group flex items-center gap-3 px-4 py-3 rounded-lg border bg-card hover:bg-accent hover:border-primary/50 transition-all cursor-pointer"
                    >
                        <Calendar className="h-5 w-5 text-muted-foreground group-hover:text-primary transition-colors" />
                        <div className="text-left">
                            <div className="text-sm font-medium">{currentDateTime.date}</div>
                            <div className="text-lg font-bold text-primary">{currentDateTime.time}</div>
                        </div>
                    </button>
                </motion.div>

                {/* Main Content: Tabs + Sidebar Widgets */}
                <div className={`flex flex-col ${hasWidgets ? 'xl:flex-row' : ''} gap-6`}>
                    {/* Tabs — Main Area */}
                    <div className={hasWidgets ? 'xl:flex-1 xl:min-w-0' : 'w-full'}>
                        <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as TabType)} className="space-y-6">
                            <motion.div
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.3, delay: 0.2 }}
                            >
                                <TabsList className="grid w-full max-w-3xl" style={{ gridTemplateColumns: `repeat(${availableTabs.length}, 1fr)` }}>
                                    {availableTabs.map((tab) => {
                                        const config = TAB_CONFIG[tab];
                                        const Icon = TAB_ICONS[tab];
                                        return (
                                            <TabsTrigger key={tab} value={tab} className="flex items-center gap-2">
                                                <Icon className="h-4 w-4" />
                                                <span className="hidden sm:inline">{config.label}</span>
                                            </TabsTrigger>
                                        );
                                    })}
                                </TabsList>
                            </motion.div>

                            {/* Infrastructure Tab */}
                            {availableTabs.includes('infrastructure') && (
                                <TabsContent value="infrastructure" className="space-y-6">
                                    <Suspense fallback={<TabSkeleton />}>
                                        <InfrastructureTab
                                            totalStations={totalStations}
                                            noPcs={noPcs}
                                            vacantStations={vacantStations}

                                            dualMonitor={dualMonitor}
                                            maintenanceDue={maintenanceDue}
                                            unassignedPcSpecs={unassignedPcSpecs}
                                        />
                                    </Suspense>
                                </TabsContent>
                            )}

                            {/* IT Concerns Tab */}
                            {availableTabs.includes('it-concerns') && (
                                <TabsContent value="it-concerns" className="space-y-6">
                                    <Suspense fallback={<TabSkeleton />}>
                                        <ItConcernsTab
                                            itConcernStats={itConcernStats}
                                            itConcernTrends={itConcernTrends}
                                        />
                                    </Suspense>
                                </TabsContent>
                            )}

                            {/* Presence Insights Tab */}
                            {availableTabs.includes('presence-insights') && (
                                <TabsContent value="presence-insights" className="space-y-6">
                                    <Suspense fallback={<TabSkeleton />}>
                                        <PresenceInsightsTab
                                            presenceInsights={presenceInsights}
                                            isRestrictedRole={isRestrictedRole}
                                            leaveCalendarMonth={leaveCalendarMonth}
                                            campaignPresence={campaignPresence}
                                            pointsByCampaign={pointsByCampaign}
                                        />
                                    </Suspense>
                                </TabsContent>
                            )}

                            {/* Attendance Tab */}
                            {availableTabs.includes('attendance') && (
                                <TabsContent value="attendance" className="space-y-6">
                                    <Suspense fallback={<TabSkeleton />}>
                                        <AttendanceTab
                                            attendanceStatistics={attendanceStatistics}
                                            monthlyAttendanceData={monthlyAttendanceData}
                                            dailyAttendanceData={dailyAttendanceData}
                                            campaigns={campaigns}
                                            startDate={startDate}
                                            endDate={endDate}
                                            campaignId={campaignId}
                                            verificationFilter={verificationFilter}
                                            isRestrictedRole={isRestrictedRole}
                                            leaveCredits={leaveCredits}
                                            leaveConflicts={leaveConflicts}
                                            pointsEscalation={pointsEscalation}
                                            ncnsTrend={ncnsTrend}
                                            leaveUtilization={leaveUtilization}
                                        />
                                    </Suspense>
                                </TabsContent>
                            )}

                            {/* Stock Overview Tab */}
                            {availableTabs.includes('stock-overview') && (
                                <TabsContent value="stock-overview" className="space-y-6">
                                    <Suspense fallback={<TabSkeleton />}>
                                        <StockOverviewTab stockSummary={stockSummary} />
                                    </Suspense>
                                </TabsContent>
                            )}

                            {/* Personal Dashboard Tab */}
                            {availableTabs.includes('personal') && (
                                <TabsContent value="personal" className="space-y-6">
                                    <Suspense fallback={<TabSkeleton />}>
                                        <PersonalDashboardTab
                                            personalSchedule={personalSchedule}
                                            personalRequests={personalRequests}
                                            personalAttendanceSummary={personalAttendanceSummary}
                                            leaveCredits={leaveCredits}
                                        />
                                    </Suspense>
                                </TabsContent>
                            )}
                        </Tabs>
                    </div>

                    {/* Sidebar Widgets — Right side on xl, stacked below on mobile */}
                    {hasWidgets && (
                        <motion.aside
                            className="w-full xl:w-80 shrink-0 space-y-4"
                            initial={{ opacity: 0, x: 20 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ duration: 0.4, delay: 0.3 }}
                        >
                            {availableWidgets.includes('notifications') && (
                                <NotificationSummaryWidget notificationSummary={notificationSummary} />
                            )}
                            {availableWidgets.includes('pending-leave-approvals') && (
                                <PendingLeaveApprovalsWidget pendingLeaveApprovals={pendingLeaveApprovals} />
                            )}
                            {availableWidgets.includes('user-accounts') && (
                                <UserAccountStatsWidget userAccountStats={userAccountStats} />
                            )}
                            {availableWidgets.includes('recent-activity') && (
                                <RecentActivityWidget recentActivityLogs={recentActivityLogs} />
                            )}
                            {availableWidgets.includes('biometric-anomalies') && (
                                <BiometricAnomalyWidget biometricAnomalies={biometricAnomalies} />
                            )}
                        </motion.aside>
                    )}
                </div>

                {/* Shared Calendar Dialog */}
                <DetailDialog
                    open={activeDialog === 'dateTime'}
                    onClose={closeDialog}
                    title="Calendar"
                    description="View the current month and date. Holidays are highlighted."
                >
                    <div className="flex flex-col items-center py-4 w-full">
                        <CalendarWithHolidays countryCode={['PH', 'US']} width={420} />
                    </div>
                </DetailDialog>
            </motion.div>
        </AppLayout>
    );
}
