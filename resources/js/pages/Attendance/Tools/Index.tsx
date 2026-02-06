import { Head, Link } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Can } from "@/components/authorization";
import { index as attendanceToolsIndex } from "@/routes/attendance-tools";
import { index as biometricReprocessingIndex } from "@/routes/biometric-reprocessing";
import { index as biometricAnomaliesIndex } from "@/routes/biometric-anomalies";
import { index as biometricExportIndex } from "@/routes/biometric-export";
import { index as attendanceUploadsIndex } from "@/routes/attendance-uploads";
import { index as biometricRetentionPoliciesIndex } from "@/routes/biometric-retention-policies";
import {
    RefreshCw,
    AlertTriangle,
    Download,
    FileText,
    Shield,
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

export default function AttendanceToolsIndex() {
    const { title, breadcrumbs } = usePageMeta({
        title: "Attendance Tools",
        breadcrumbs: [{ title: "Attendance Tools", href: attendanceToolsIndex().url }],
    });

    useFlashMessage();
    const isLoading = usePageLoading();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-3">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader
                    title="Attendance Tools"
                    description="Biometric data management, exports, and maintenance tools"
                />

                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <Can permission="biometric.view">
                        <ActionCard
                            title="Recent Uploads"
                            description="View and track biometric file uploads and their processing status"
                            icon={FileText}
                            href={attendanceUploadsIndex().url}
                            variant="primary"
                        />
                    </Can>

                    <Can permission="biometric.export">
                        <ActionCard
                            title="Export Records"
                            description="Export biometric and attendance data to Excel for reporting"
                            icon={Download}
                            href={biometricExportIndex().url}
                        />
                    </Can>

                    <Can permission="biometric.reprocess">
                        <ActionCard
                            title="Reprocess Attendance"
                            description="Reprocess biometric data to recalculate attendance records"
                            icon={RefreshCw}
                            href={biometricReprocessingIndex().url}
                        />
                    </Can>

                    <Can permission="biometric.anomalies">
                        <ActionCard
                            title="Anomaly Detection"
                            description="Detect and review unusual patterns in biometric scan data"
                            icon={AlertTriangle}
                            href={biometricAnomaliesIndex().url}
                            variant="warning"
                        />
                    </Can>

                    <Can permission="biometric.retention">
                        <ActionCard
                            title="Retention Policies"
                            description="Configure data retention rules for biometric and attendance records"
                            icon={Shield}
                            href={biometricRetentionPoliciesIndex().url}
                        />
                    </Can>
                </div>
            </div>
        </AppLayout>
    );
}
