import React from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { formatTime } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { ArrowLeft, Clock, MapPin, Calendar } from "lucide-react";
import type { SharedData } from "@/types";
import { index as biometricRecordsIndex } from "@/routes/biometric-records";

interface User {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
}

interface Site {
    id: number;
    name: string;
}

interface AttendanceUpload {
    id: number;
    shift_date: string;
}

interface BiometricRecord {
    id: number;
    site: Site;
    attendance_upload: AttendanceUpload;
    employee_name: string;
    datetime: string;
    record_date: string;
    record_time: string;
}

interface PageProps extends SharedData {
    user: User;
    date: string;
    records: BiometricRecord[];
}

const formatDateTime = (dateTime: string) => {
    const date = new Date(dateTime);
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    }).format(date);
};

// formatTime is now imported from @/lib/utils

export default function BiometricRecordsShow() {
    const { user, date, records } = usePage<PageProps>().props;
    useFlashMessage();

    const formattedDate = new Date(date).toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric'
    });

    const shortDate = new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

    const { title, breadcrumbs } = usePageMeta({
        title: `${user.name} – ${shortDate}`,
        breadcrumbs: [
            { title: 'Biometric Records', href: biometricRecordsIndex().url },
            { title: `${user.name} (${shortDate})`, href: '' },
        ],
    });

    const isPageLoading = usePageLoading();

    const goBack = () => {
        router.get(biometricRecordsIndex().url);
    };

    // Group records by upload
    const groupedRecords = records.reduce((acc, record) => {
        const uploadDate = record.attendance_upload.shift_date;
        if (!acc[uploadDate]) {
            acc[uploadDate] = [];
        }
        acc[uploadDate].push(record);
        return acc;
    }, {} as Record<string, BiometricRecord[]>);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isPageLoading} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <div className="flex items-center justify-between">
                    <Button variant="ghost" size="sm" onClick={goBack}>
                        <ArrowLeft className="h-4 w-4 mr-2" />
                        Back to Records
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.get(biometricRecordsIndex().url, { user_id: user.id })}
                    >
                        <Clock className="h-4 w-4 mr-2" />
                        View All Records
                    </Button>
                </div>

                <PageHeader
                    title={`${user.name}'s Biometric Records`}
                    description={formattedDate}
                />

                {/* Summary Cards */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Scans</CardTitle>
                            <Clock className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{records.length}</div>
                            <p className="text-xs text-muted-foreground">
                                On this date
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">First Scan</CardTitle>
                            <Calendar className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-sm text-muted-foreground">
                                {records.length > 0 ? formatTime(records[0].record_time) : 'N/A'}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Likely time in
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Last Scan</CardTitle>
                            <Calendar className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-lg font-bold">
                                {records.length > 0 ? formatTime(records[records.length - 1].record_time) : 'N/A'}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Likely time out
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Records by Upload */}
                {Object.entries(groupedRecords).map(([uploadDate, uploadRecords]) => (
                    <Card key={uploadDate}>
                        <CardHeader>
                            <CardTitle>
                                Upload: {new Date(uploadDate).toLocaleDateString('en-US', {
                                    month: 'long',
                                    day: 'numeric',
                                    year: 'numeric'
                                })}
                            </CardTitle>
                            <CardDescription>
                                {uploadRecords.length} scan{uploadRecords.length !== 1 ? 's' : ''} in this upload
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/50">
                                            <TableHead className="w-[50px]">#</TableHead>
                                            <TableHead>Timestamp</TableHead>
                                            <TableHead>Time</TableHead>
                                            <TableHead>Device Name</TableHead>
                                            <TableHead>Site</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {uploadRecords.map((record, index) => (
                                            <TableRow key={record.id}>
                                                <TableCell className="font-medium">
                                                    {index + 1}
                                                </TableCell>
                                                <TableCell>
                                                    {formatDateTime(record.datetime)}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="secondary">
                                                        {formatTime(record.record_time)}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">
                                                    {record.employee_name}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        <MapPin className="h-3 w-3 text-muted-foreground" />
                                                        {record.site.name}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                ))}

                {records.length === 0 && (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No biometric records found for this date
                        </CardContent>
                    </Card>
                )}

                {/* Timeline View */}
                {records.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Timeline</CardTitle>
                            <CardDescription>
                                Chronological view of all scans on this date
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                {records.map((record, index) => (
                                    <div key={record.id} className="flex gap-4">
                                        <div className="flex flex-col items-center">
                                            <div className={`w-3 h-3 rounded-full ${index === 0 ? 'bg-green-500' :
                                                index === records.length - 1 ? 'bg-red-500' :
                                                    'bg-blue-500'
                                                }`} />
                                            {index < records.length - 1 && (
                                                <div className="w-0.5 h-12 bg-border" />
                                            )}
                                        </div>
                                        <div className="flex-1 pb-4">
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <div className="font-semibold">
                                                        {formatTime(record.record_time)}
                                                    </div>
                                                    <div className="text-sm text-muted-foreground">
                                                        {record.site.name}
                                                    </div>
                                                </div>
                                                <div className="text-right">
                                                    <Badge variant="outline">
                                                        {index === 0 ? 'First' :
                                                            index === records.length - 1 ? 'Last' :
                                                                `Scan ${index + 1}`}
                                                    </Badge>
                                                </div>
                                            </div>
                                            <div className="text-xs text-muted-foreground mt-1 font-mono">
                                                {record.employee_name}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
