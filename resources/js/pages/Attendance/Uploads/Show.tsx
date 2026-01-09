import React from "react";
import { Head, router } from "@inertiajs/react";
import { format } from 'date-fns';
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { ArrowLeft, FileText, AlertTriangle, Calendar, Users, CheckCircle, XCircle, Clock, MapPin } from "lucide-react";
import type { SharedData } from "@/types";
import { index as attendanceUploadsIndex, show as attendanceUploadsShow } from "@/routes/attendance-uploads";

interface Upload {
    id: number;
    original_filename: string;
    shift_date: string;
    biometric_site: {
        id: number;
        name: string;
    } | null;
    total_records: number;
    processed_records: number;
    matched_employees: number;
    unmatched_names: number;
    unmatched_names_list: string[] | null;
    date_warnings: string[] | null;
    dates_found: string[] | null;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    notes: string | null;
    error_message: string | null;
    uploaded_by: {
        id: number;
        name: string;
    } | null;
    created_at: string;
    updated_at: string;
}

interface PageProps extends SharedData {
    upload: Upload;
}

const UploadShow: React.FC<PageProps> = ({ upload }) => {
    useFlashMessage();

    const { title, breadcrumbs } = usePageMeta({
        title: `Upload Details - ${upload.original_filename}`,
        breadcrumbs: [
            { title: 'Recent Uploads', href: attendanceUploadsIndex().url },
            { title: upload.original_filename, href: attendanceUploadsShow({ upload: upload.id }).url },
        ],
    });

    const goBack = () => {
        router.get(attendanceUploadsIndex().url);
    };

    const getStatusBadge = (status: Upload['status']) => {
        const variants = {
            pending: { className: 'bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-800 dark:text-gray-100', icon: Clock },
            processing: { className: 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900 dark:text-blue-100', icon: Clock },
            completed: { className: 'bg-green-100 text-green-800 border-green-200 dark:bg-green-900 dark:text-green-100', icon: CheckCircle },
            failed: { className: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900 dark:text-red-100', icon: XCircle },
        };

        const variant = variants[status];
        const Icon = variant.icon;

        return (
            <Badge className={`${variant.className} border`}>
                <Icon className="mr-1 h-3 w-3" />
                {status.charAt(0).toUpperCase() + status.slice(1)}
            </Badge>
        );
    };

    const formattedShiftDate = format(new Date(upload.shift_date), 'EEEE, MMMM dd, yyyy');
    const hasIssues = (upload.unmatched_names_list && upload.unmatched_names_list.length > 0) ||
        (upload.date_warnings && upload.date_warnings.length > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="sm" onClick={goBack}>
                        <ArrowLeft className="h-4 w-4 mr-2" />
                        Back to Uploads
                    </Button>
                </div>

                <PageHeader
                    title={upload.original_filename}
                    description={formattedShiftDate}
                />

                {/* Status and Error Message */}
                {upload.error_message && (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertDescription>{upload.error_message}</AlertDescription>
                    </Alert>
                )}

                {/* Summary Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Records</CardTitle>
                            <FileText className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{upload.total_records}</div>
                            <p className="text-xs text-muted-foreground">
                                {upload.processed_records} processed
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Matched</CardTitle>
                            <CheckCircle className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-green-600 dark:text-green-400">
                                {upload.matched_employees}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {upload.total_records > 0
                                    ? Math.round((upload.matched_employees / upload.total_records) * 100)
                                    : 0}% match rate
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Unmatched</CardTitle>
                            <XCircle className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600 dark:text-red-400">
                                {upload.unmatched_names}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {upload.unmatched_names > 0 ? 'Needs attention' : 'All matched'}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Status</CardTitle>
                            {upload.status === 'completed' ? (
                                <CheckCircle className="h-4 w-4 text-muted-foreground" />
                            ) : upload.status === 'failed' ? (
                                <XCircle className="h-4 w-4 text-muted-foreground" />
                            ) : (
                                <Clock className="h-4 w-4 text-muted-foreground" />
                            )}
                        </CardHeader>
                        <CardContent>
                            <div className="mb-2">{getStatusBadge(upload.status)}</div>
                        </CardContent>
                    </Card>
                </div>

                {/* File Information */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FileText className="h-5 w-5" />
                            File Information
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-3">
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Biometric Site</p>
                                    <div className="flex items-center gap-2 mt-1">
                                        <MapPin className="h-4 w-4 text-muted-foreground" />
                                        <span className="text-base">{upload.biometric_site?.name || 'N/A'}</span>
                                    </div>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Shift Date</p>
                                    <div className="flex items-center gap-2 mt-1">
                                        <Calendar className="h-4 w-4 text-muted-foreground" />
                                        <span className="text-base">{format(new Date(upload.shift_date), 'MMM dd, yyyy')}</span>
                                    </div>
                                </div>
                            </div>
                            <div className="space-y-3">
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Uploaded By</p>
                                    <div className="flex items-center gap-2 mt-1">
                                        <Users className="h-4 w-4 text-muted-foreground" />
                                        <span className="text-base">{upload.uploaded_by?.name || 'Unknown'}</span>
                                    </div>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Upload Date</p>
                                    <div className="flex items-center gap-2 mt-1">
                                        <Clock className="h-4 w-4 text-muted-foreground" />
                                        <span className="text-base">{format(new Date(upload.created_at), 'MMM dd, yyyy HH:mm')}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {upload.notes && (
                            <div className="mt-4 pt-4 border-t">
                                <p className="text-sm font-medium text-muted-foreground mb-2">Notes</p>
                                <p className="text-foreground">{upload.notes}</p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Date Validation Warnings */}
                {upload.date_warnings && upload.date_warnings.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-orange-600 dark:text-orange-400">
                                <AlertTriangle className="h-5 w-5" />
                                Date Validation Warning
                            </CardTitle>
                            <CardDescription>
                                File contains records from unexpected dates
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Alert className="border-orange-200 bg-orange-50 dark:bg-orange-950 dark:border-orange-800">
                                <AlertTriangle className="h-4 w-4 text-orange-600 dark:text-orange-400" />
                                <AlertDescription className="text-orange-800 dark:text-orange-200">
                                    {upload.date_warnings.map((warning, index) => (
                                        <div key={index} className="mb-1 last:mb-0">{warning}</div>
                                    ))}
                                </AlertDescription>
                            </Alert>

                            {upload.dates_found && upload.dates_found.length > 0 && (
                                <div className="mt-4">
                                    <p className="text-sm font-medium text-foreground mb-2">Dates Found in File:</p>
                                    <div className="flex flex-wrap gap-2">
                                        {upload.dates_found.map((date, index) => (
                                            <Badge key={index} variant="outline" className="text-sm">
                                                <Calendar className="mr-1 h-3 w-3" />
                                                {format(new Date(date), 'MMM dd, yyyy')}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Unmatched Employee Names */}
                {upload.unmatched_names_list && upload.unmatched_names_list.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-red-600 dark:text-red-400">
                                <XCircle className="h-5 w-5" />
                                Unmatched Employee Names ({upload.unmatched_names_list.length})
                            </CardTitle>
                            <CardDescription>
                                These names from the biometric file were not found in the system
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Alert className="border-red-200 bg-red-50 mb-4 dark:bg-red-950 dark:border-red-800">
                                <AlertTriangle className="h-4 w-4 text-red-600 dark:text-red-400" />
                                <AlertDescription className="text-red-800 dark:text-red-200">
                                    These employees may need to be added to the system or their names may need to be corrected in the biometric device.
                                </AlertDescription>
                            </Alert>

                            <div className="grid gap-2 md:grid-cols-2 lg:grid-cols-3">
                                {upload.unmatched_names_list.map((name, index) => (
                                    <div
                                        key={index}
                                        className="flex items-center gap-2 p-3 border rounded-lg bg-muted"
                                    >
                                        <XCircle className="h-4 w-4 text-red-500 dark:text-red-400 flex-shrink-0" />
                                        <span className="text-sm font-medium text-foreground">{name}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Success Message */}
                {upload.status === 'completed' && !hasIssues && (
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3 text-green-600 dark:text-green-400">
                                <CheckCircle className="h-6 w-6" />
                                <div>
                                    <p className="font-semibold text-lg">Upload Completed Successfully</p>
                                    <p className="text-sm text-muted-foreground">All employees were matched and records processed without issues.</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
};

export default UploadShow;
