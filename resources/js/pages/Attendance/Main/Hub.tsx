import { Head, Link } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Can } from "@/components/authorization";
import {
    index as attendanceIndex,
    calendar as attendanceCalendar,
    create as attendanceCreate,
    importMethod as attendanceImport,
    dailyRoster as attendanceDailyRoster,
    review as attendanceReview,
} from "@/routes/attendance";
import {
    CalendarDays,
    ClipboardList,
    Edit,
    Upload,
    AlertCircle,
    Calendar,
    Table2,
    Clock,
    AlertTriangle,
    type LucideIcon,
} from "lucide-react";

interface ActionCardProps {
    title: string;
    description: string;
    icon: LucideIcon;
    href: string;
    variant?: "default" | "primary" | "warning";
}

function ActionCard({ title, description, icon: Icon, href, variant = "default" }: ActionCardProps) {
    const variantStyles = {
        default: "hover:border-primary/50",
        primary: "border-primary/30 hover:border-primary/50",
        warning: "border-orange-500/30 hover:border-orange-500/50",
    };

    return (
        <Link href={href} className="block">
            <Card className={`h-full cursor-pointer transition-all hover:shadow-lg ${variantStyles[variant]}`}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium">{title}</CardTitle>
                    <Icon className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <CardDescription className="text-sm">{description}</CardDescription>
                </CardContent>
            </Card>
        </Link>
    );
}

export default function AttendanceHub() {
    const { title, breadcrumbs } = usePageMeta({
        title: "Attendance",
        breadcrumbs: [{ title: "Attendance" }],
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
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
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

                    <Can permission="attendance.create">
                        <ActionCard
                            title="Manual Attendance"
                            description="Create attendance records manually for employees"
                            icon={Edit}
                            href={attendanceCreate().url}
                        />
                    </Can>

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
                        <CardTitle className="text-sm font-medium">Quick Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="pt-0">
                        <div className="flex flex-wrap gap-2">
                            <Link
                                href={attendanceIndex({ query: { needs_verification: "1" } }).url}
                                className="inline-flex items-center gap-2 px-3 py-2 text-sm bg-muted hover:bg-muted/80 rounded-md transition-colors"
                            >
                                <ClipboardList className="h-4 w-4" />
                                Pending Verification
                            </Link>
                            <Link
                                href={attendanceIndex({ query: { status: "ncns" } }).url}
                                className="inline-flex items-center gap-2 px-3 py-2 text-sm bg-red-50 dark:bg-red-950/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-950/30 rounded-md transition-colors"
                            >
                                <AlertTriangle className="h-4 w-4" />
                                NCNS Records
                            </Link>
                            <Link
                                href={attendanceIndex({ query: { status: "tardy" } }).url}
                                className="inline-flex items-center gap-2 px-3 py-2 text-sm bg-amber-50 dark:bg-amber-950/20 text-amber-600 dark:text-amber-400 hover:bg-amber-100 dark:hover:bg-amber-950/30 rounded-md transition-colors"
                            >
                                <Clock className="h-4 w-4" />
                                Tardy Records
                            </Link>
                            <Link
                                href={attendanceIndex({ query: { verified_status: "pending" } }).url}
                                className="inline-flex items-center gap-2 px-3 py-2 text-sm bg-muted hover:bg-muted/80 rounded-md transition-colors"
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
