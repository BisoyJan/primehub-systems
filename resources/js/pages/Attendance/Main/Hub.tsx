import { Can } from '@/components/authorization';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import AppLayout from '@/layouts/app-layout';
import {
    calendar as attendanceCalendar,
    dailyRoster as attendanceDailyRoster,
    importMethod as attendanceImport,
    index as attendanceIndex,
    review as attendanceReview,
    spreadsheet as attendanceSpreadsheet,
} from '@/routes/attendance';
import { Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    Calendar,
    CalendarDays,
    ClipboardList,
    Clock,
    Grid3x3,
    Table2,
    Upload,
    type LucideIcon,
} from 'lucide-react';

interface ActionCardProps {
    title: string;
    description: string;
    icon: LucideIcon;
    href: string;
    variant?: 'default' | 'primary' | 'warning';
}

function ActionCard({
    title,
    description,
    icon: Icon,
    href,
    variant = 'default',
}: ActionCardProps) {
    const variantStyles = {
        default: 'hover:border-primary/50',
        primary: 'border-primary/30 hover:border-primary/50',
        warning: 'border-orange-500/30 hover:border-orange-500/50',
    };

    return (
        <Link href={href} className="block">
            <Card
                className={`h-full cursor-pointer transition-all hover:shadow-lg ${variantStyles[variant]}`}
            >
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium">
                        {title}
                    </CardTitle>
                    <Icon className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <CardDescription className="text-sm">
                        {description}
                    </CardDescription>
                </CardContent>
            </Card>
        </Link>
    );
}

export default function AttendanceHub() {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Attendance',
        breadcrumbs: [{ title: 'Attendance' }],
    });

    useFlashMessage();
    const isLoading = usePageLoading();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-3">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader
                    title="Attendance Management"
                    description="Choose an action to manage attendance records"
                />

                {/* Primary Actions */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <ActionCard
                        title="Calendar View"
                        description="View attendance in a calendar format with daily status overview"
                        icon={Calendar}
                        href={attendanceCalendar().url}
                        variant="primary"
                    />

                    <ActionCard
                        title="View All Records"
                        description="Browse and filter all attendance records in a table view"
                        icon={Table2}
                        href={attendanceIndex().url}
                    />

                    <ActionCard
                        title="Spreadsheet View"
                        description="Per-employee × per-day grid showing hours and leave codes (VL, SL, ABS, ML, ...)"
                        icon={Grid3x3}
                        href={attendanceSpreadsheet().url}
                        variant="primary"
                    />

                    <Can permission="attendance.import">
                        <ActionCard
                            title="Import Biometric"
                            description="Upload and process biometric attendance data from files"
                            icon={Upload}
                            href={attendanceImport().url}
                        />
                    </Can>

                    <Can permission="attendance.create">
                        <ActionCard
                            title="Daily Roster"
                            description="Generate attendance records for scheduled employees"
                            icon={CalendarDays}
                            href={attendanceDailyRoster().url}
                        />
                    </Can>

                    <Can permission="attendance.review">
                        <ActionCard
                            title="Review Flagged"
                            description="Review and verify attendance records that need attention"
                            icon={AlertCircle}
                            href={attendanceReview().url}
                            variant="warning"
                        />
                    </Can>
                </div>

                {/* Quick Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm font-medium">
                            Quick Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="pt-0">
                        <div className="flex flex-wrap gap-2">
                            <Link
                                href={
                                    attendanceIndex({
                                        query: { needs_verification: '1' },
                                    }).url
                                }
                                className="inline-flex items-center gap-2 rounded-md bg-muted px-3 py-2 text-sm transition-colors hover:bg-muted/80"
                            >
                                <ClipboardList className="h-4 w-4" />
                                Pending Verification
                            </Link>
                            <Link
                                href={
                                    attendanceIndex({
                                        query: { status: 'ncns' },
                                    }).url
                                }
                                className="inline-flex items-center gap-2 rounded-md bg-red-50 px-3 py-2 text-sm text-red-600 transition-colors hover:bg-red-100 dark:bg-red-950/20 dark:text-red-400 dark:hover:bg-red-950/30"
                            >
                                <AlertTriangle className="h-4 w-4" />
                                NCNS Records
                            </Link>
                            <Link
                                href={
                                    attendanceIndex({
                                        query: { status: 'tardy' },
                                    }).url
                                }
                                className="inline-flex items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-600 transition-colors hover:bg-amber-100 dark:bg-amber-950/20 dark:text-amber-400 dark:hover:bg-amber-950/30"
                            >
                                <Clock className="h-4 w-4" />
                                Tardy Records
                            </Link>
                            <Link
                                href={
                                    attendanceIndex({
                                        query: { verified_status: 'pending' },
                                    }).url
                                }
                                className="inline-flex items-center gap-2 rounded-md bg-muted px-3 py-2 text-sm transition-colors hover:bg-muted/80"
                            >
                                <AlertCircle className="h-4 w-4" />
                                Unverified Records
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
