import React from "react";
import { Head, router } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { type SharedData } from "@/types";
import { useFlashMessage } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { FileText, AlertTriangle, CheckCircle, Clock, XCircle, Eye } from "lucide-react";
import type { BreadcrumbItem } from "@/types";

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Recent Uploads', href: '/attendance-uploads' }
];

interface User {
    id: number;
    name: string;
}

interface Site {
    id: number;
    name: string;
}

interface Upload {
    id: number;
    original_filename: string;
    shift_date: string;
    biometric_site: Site | null;
    total_records: number;
    processed_records: number;
    matched_employees: number;
    unmatched_names: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    uploader: User | null;
    created_at: string;
}

interface UploadPayload {
    data: Upload[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

interface PageProps extends SharedData {
    uploads?: UploadPayload;
    [key: string]: unknown;
}

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
};

const formatDateTime = (datetime: string, timeFormat: '12' | '24' = '24') => {
    return new Date(datetime).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: timeFormat === '12'
    });
};

export default function UploadsIndex({ uploads, auth }: PageProps) {
    useFlashMessage();
    const timeFormat = auth.user.time_format || '24';

    const uploadsData = {
        data: uploads?.data || [],
        links: uploads?.links || [],
        meta: {
            current_page: uploads?.current_page ?? 1,
            last_page: uploads?.last_page ?? 1,
            per_page: uploads?.per_page ?? 20,
            total: uploads?.total ?? 0,
            from: uploads?.from ?? 0,
            to: uploads?.to ?? 0
        }
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

    const viewUploadDetails = (uploadId: number) => {
        router.get(`/attendance-uploads/${uploadId}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recent Uploads" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Recent Uploads"
                    description="View and manage attendance file uploads"
                />

                <div className="flex justify-between items-center text-sm">
                    <div className="text-muted-foreground">
                        Showing {uploadsData.meta.from} to {uploadsData.meta.to} of {uploadsData.meta.total} upload
                        {uploadsData.meta.total === 1 ? "" : "s"}
                    </div>
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>File</TableHead>
                                    <TableHead>Shift Date</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead className="text-right">Records</TableHead>
                                    <TableHead className="text-right">Matched</TableHead>
                                    <TableHead className="text-right">Unmatched</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Uploaded By</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {uploadsData.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={10} className="text-center py-8 text-muted-foreground">
                                            No uploads found
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    uploadsData.data.map((upload) => (
                                        <TableRow key={upload.id} className="hover:bg-muted/50">
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <FileText className="h-4 w-4 text-muted-foreground" />
                                                    <span className="font-medium">{upload.original_filename}</span>
                                                    {upload.unmatched_names > 0 && (
                                                        <AlertTriangle className="h-4 w-4 text-orange-500" />
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>{formatDate(upload.shift_date)}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{upload.biometric_site?.name || 'N/A'}</Badge>
                                            </TableCell>
                                            <TableCell className="text-right font-medium">{upload.total_records}</TableCell>
                                            <TableCell className="text-right">
                                                <span className="text-green-600 dark:text-green-400 font-medium">
                                                    {upload.matched_employees}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {upload.unmatched_names > 0 ? (
                                                    <span className="text-red-600 dark:text-red-400 font-medium">
                                                        {upload.unmatched_names}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">0</span>
                                                )}
                                            </TableCell>
                                            <TableCell>{getStatusBadge(upload.status)}</TableCell>
                                            <TableCell>{upload.uploader?.name || 'Unknown'}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {formatDateTime(upload.created_at, timeFormat)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <button
                                                    onClick={() => viewUploadDetails(upload.id)}
                                                    className="inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                    View
                                                </button>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Mobile Cards */}
                <div className="md:hidden space-y-4">
                    {uploadsData.data.length === 0 ? (
                        <div className="py-12 text-center text-muted-foreground border rounded-lg bg-card">
                            No uploads found
                        </div>
                    ) : (
                        uploadsData.data.map((upload) => (
                            <div
                                key={upload.id}
                                className="bg-card border rounded-lg p-4 shadow-sm space-y-3 cursor-pointer hover:bg-muted/50"
                                onClick={() => viewUploadDetails(upload.id)}
                            >
                                <div className="flex items-start justify-between">
                                    <div className="flex items-center gap-2 flex-1">
                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                        <span className="font-medium text-sm">{upload.original_filename}</span>
                                        {upload.unmatched_names > 0 && (
                                            <AlertTriangle className="h-4 w-4 text-orange-500" />
                                        )}
                                    </div>
                                    {getStatusBadge(upload.status)}
                                </div>

                                <div className="grid grid-cols-2 gap-2 text-sm">
                                    <div>
                                        <span className="text-muted-foreground">Shift Date:</span>
                                        <p className="font-medium">{formatDate(upload.shift_date)}</p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Site:</span>
                                        <p className="font-medium">{upload.biometric_site?.name || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Records:</span>
                                        <p className="font-medium">{upload.total_records}</p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Matched:</span>
                                        <p className="font-medium text-green-600 dark:text-green-400">
                                            {upload.matched_employees}
                                        </p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Unmatched:</span>
                                        <p className={`font-medium ${upload.unmatched_names > 0 ? 'text-red-600 dark:text-red-400' : ''}`}>
                                            {upload.unmatched_names}
                                        </p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Uploaded By:</span>
                                        <p className="font-medium">{upload.uploader?.name || 'Unknown'}</p>
                                    </div>
                                </div>

                                <div className="text-sm text-muted-foreground mt-1">
                                    {formatDateTime(upload.created_at, timeFormat)}
                                </div>
                            </div>
                        ))
                    )}
                </div>

                <div className="flex justify-center mt-4">
                    {uploadsData.links && uploadsData.links.length > 0 && (
                        <PaginationNav links={uploadsData.links} />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
