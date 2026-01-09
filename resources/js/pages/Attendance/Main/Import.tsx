import React, { useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Upload, FileText, AlertCircle, CheckCircle, Clock, X } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from "@/components/ui/tooltip";
import {
    index as attendanceIndex,
    importMethod as attendanceImport,
    upload as attendanceUpload,
} from "@/routes/attendance";

interface FileUploadItem {
    id: string;
    file: File;
    biometric_site_id: number | "";
}

interface AttendanceUpload {
    id: number;
    original_filename: string;
    shift_date: string;
    total_records: number;
    processed_records: number;
    matched_employees: number;
    unmatched_names: number;
    unmatched_names_list: string[];
    date_warnings?: string[];
    dates_found?: string[];
    status: string;
    error_message?: string | null;
    created_at: string;
    uploader: {
        name: string;
    };
    biometric_site?: {
        id: number;
        name: string;
    };
}

interface Site {
    id: number;
    name: string;
}

interface PageProps {
    recentUploads?: AttendanceUpload[];
    sites: Site[];
    [key: string]: unknown;
}

export default function AttendanceImport() {
    const { recentUploads, sites } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: "Import Attendance",
        breadcrumbs: [
            { title: "Attendance", href: attendanceIndex().url },
            { title: "Import", href: attendanceImport().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [files, setFiles] = useState<FileUploadItem[]>([]);
    const [dateFrom, setDateFrom] = useState(new Date().toISOString().split("T")[0]);
    const [dateTo, setDateTo] = useState(new Date().toISOString().split("T")[0]);
    const [notes, setNotes] = useState("");
    const [isUploading, setIsUploading] = useState(false);

    const handleFilesChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const selectedFiles = Array.from(e.target.files || []);
        const newFiles: FileUploadItem[] = selectedFiles.map(file => ({
            id: `${Date.now()}-${Math.random()}`,
            file,
            biometric_site_id: sites?.[0]?.id || "",
        }));
        setFiles(prev => [...prev, ...newFiles]);
    };

    const updateFileSite = (fileId: string, siteId: number) => {
        setFiles(prev => prev.map(f =>
            f.id === fileId ? { ...f, biometric_site_id: siteId } : f
        ));
    };

    const removeFile = (fileId: string) => {
        setFiles(prev => prev.filter(f => f.id !== fileId));
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        console.log('handleSubmit triggered');
        console.log('Files:', files);
        console.log('Date range:', dateFrom, 'to', dateTo);

        if (files.length === 0) {
            console.log('No files selected');
            return;
        }

        // Validate all files have sites assigned
        const unassignedFiles = files.filter(f => !f.biometric_site_id);
        if (unassignedFiles.length > 0) {
            console.log('Unassigned files:', unassignedFiles);
            alert("Please assign a site to all files before uploading.");
            return;
        }

        setIsUploading(true);

        let allSuccessful = true;
        const successMessages: string[] = [];

        // Upload files sequentially
        for (const fileItem of files) {
            const formData = new FormData();
            formData.append('file', fileItem.file);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            formData.append('biometric_site_id', fileItem.biometric_site_id.toString());
            formData.append('notes', notes);

            console.log('Uploading file:', fileItem.file.name, 'to URL:', attendanceUpload().url);

            try {
                const response = await fetch(attendanceUpload().url, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                console.log('Response status:', response.status);

                if (!response.ok) {
                    allSuccessful = false;
                    const errorData = await response.json().catch(() => ({}));
                    console.error(`Error uploading ${fileItem.file.name}:`, errorData);
                    toast.error(`Failed to upload ${fileItem.file.name}`, {
                        description: errorData.message || response.statusText
                    });
                } else {
                    const successData = await response.json();
                    console.log('Upload successful for:', fileItem.file.name, successData);
                    successMessages.push(successData.message || `${fileItem.file.name} uploaded successfully`);
                }
            } catch (error) {
                allSuccessful = false;
                console.error(`Error uploading ${fileItem.file.name}:`, error);
                toast.error(`Network error uploading ${fileItem.file.name}`);
            }
        }

        setIsUploading(false);
        setFiles([]);
        setNotes("");

        // Show success messages
        if (allSuccessful && successMessages.length > 0) {
            successMessages.forEach((msg, index) => {
                // Delay each toast slightly to show them in sequence
                setTimeout(() => {
                    toast.success('Attendance file processed', {
                        description: msg,
                        duration: 5000,
                    });
                }, index * 100);
            });
        }

        // Reload page to show updated uploads
        router.reload();
    };

    const getStatusBadge = (upload: AttendanceUpload) => {
        const { status, error_message } = upload;

        switch (status) {
            case "completed":
                return (
                    <Badge className="bg-green-500">
                        <CheckCircle className="mr-1 h-3 w-3" />
                        Completed
                    </Badge>
                );
            case "processing":
                return (
                    <Badge className="bg-blue-500">
                        <Clock className="mr-1 h-3 w-3" />
                        Processing
                    </Badge>
                );
            case "failed":
                return (
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Badge variant="destructive" className="cursor-help">
                                    <AlertCircle className="mr-1 h-3 w-3" />
                                    Failed
                                </Badge>
                            </TooltipTrigger>
                            {error_message && (
                                <TooltipContent className="max-w-md">
                                    <p className="text-sm">{error_message}</p>
                                </TooltipContent>
                            )}
                        </Tooltip>
                    </TooltipProvider>
                );
            default:
                return <Badge variant="secondary">{status}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="Import Attendance Files"
                    description="Upload multiple biometric TXT files and assign sites to each"
                />

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Upload Form */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Upload className="h-5 w-5" />
                                Upload Biometric Files
                            </CardTitle>
                            <CardDescription>
                                Upload multiple .TXT files from different biometric devices/sites
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                {/* File Upload */}
                                <div className="space-y-2">
                                    <Label htmlFor="file">
                                        Biometric Files (.TXT) <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="file"
                                        type="file"
                                        accept=".txt"
                                        multiple
                                        onChange={handleFilesChange}
                                        className="cursor-pointer"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Select one or more TXT files. You can assign different sites to each file.
                                    </p>
                                </div>

                                {/* Selected Files List */}
                                {files.length > 0 && (
                                    <div className="space-y-2">
                                        <Label>Selected Files ({files.length})</Label>
                                        <div className="border rounded-md divide-y max-h-64 overflow-y-auto">
                                            {files.map((fileItem) => (
                                                <div key={fileItem.id} className="p-3 flex items-center gap-3">
                                                    <FileText className="h-4 w-4 text-muted-foreground flex-shrink-0" />
                                                    <div className="flex-1 min-w-0">
                                                        <p className="text-sm font-medium truncate">
                                                            {fileItem.file.name}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {(fileItem.file.size / 1024).toFixed(1)} KB
                                                        </p>
                                                    </div>
                                                    <Select
                                                        value={fileItem.biometric_site_id.toString()}
                                                        onValueChange={(value) => updateFileSite(fileItem.id, parseInt(value))}
                                                    >
                                                        <SelectTrigger className="w-[180px]">
                                                            <SelectValue placeholder="Select site" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {sites.map(site => (
                                                                <SelectItem key={site.id} value={site.id.toString()}>
                                                                    {site.name}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => removeFile(fileItem.id)}
                                                        className="flex-shrink-0"
                                                    >
                                                        <X className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Date Range */}
                                <div className="space-y-2">
                                    <Label htmlFor="date_from">
                                        Date Range <span className="text-red-500">*</span>
                                    </Label>
                                    <div className="grid grid-cols-2 gap-2">
                                        <div>
                                            <Label htmlFor="date_from" className="text-xs text-muted-foreground">
                                                From
                                            </Label>
                                            <Input
                                                id="date_from"
                                                type="date"
                                                value={dateFrom}
                                                onChange={e => setDateFrom(e.target.value)}
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="date_to" className="text-xs text-muted-foreground">
                                                To
                                            </Label>
                                            <Input
                                                id="date_to"
                                                type="date"
                                                value={dateTo}
                                                onChange={e => setDateTo(e.target.value)}
                                                min={dateFrom}
                                            />
                                        </div>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Select the date range covered by the biometric file(s). The system will process all records within this period.
                                    </p>
                                </div>

                                {/* Notes */}
                                <div className="space-y-2">
                                    <Label htmlFor="notes">Notes (Optional)</Label>
                                    <Textarea
                                        id="notes"
                                        value={notes}
                                        onChange={e => setNotes(e.target.value)}
                                        placeholder="Add any notes about these uploads..."
                                        rows={3}
                                    />
                                </div>

                                {/* Submit Button */}
                                <div className="flex gap-2 pt-4">
                                    <Button
                                        type="submit"
                                        disabled={files.length === 0 || isUploading}
                                        className="flex-1"
                                    >
                                        {isUploading ? (
                                            <>
                                                <Clock className="mr-2 h-4 w-4 animate-spin" />
                                                Uploading {files.length} file(s)...
                                            </>
                                        ) : (
                                            <>
                                                <Upload className="mr-2 h-4 w-4" />
                                                Upload {files.length > 0 ? `${files.length} file(s)` : 'Files'}
                                            </>
                                        )}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => router.get(attendanceIndex().url)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Instructions */}
                    <Card>
                        <CardHeader>
                            <CardTitle>How It Works</CardTitle>
                            <CardDescription>Step-by-step guide for attendance processing</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Alert>
                                <AlertCircle className="h-4 w-4" />
                                <AlertTitle>Smart Shift Detection</AlertTitle>
                                <AlertDescription>
                                    Upload biometric TXT files that may contain multiple days of records.
                                    The system automatically detects shift dates based on time in records from all shift types.
                                </AlertDescription>
                            </Alert>

                            <div className="space-y-4">
                                <div className="border-l-4 border-blue-500 pl-4">
                                    <h4 className="font-semibold mb-1">How It Works</h4>
                                    <p className="text-sm text-muted-foreground">
                                        Files can contain records from multiple days. The system automatically groups records by shift date based on shift type:
                                    </p>
                                    <p className="text-sm text-muted-foreground mt-2">
                                        <strong>Shift Detection Examples:</strong>
                                    </p>
                                    <ul className="text-sm text-muted-foreground mt-1 space-y-1 list-disc list-inside ml-4">
                                        <li>Morning shift (07:00-16:00): Jan 15 07:02 → Shift Date = Jan 15</li>
                                        <li>Afternoon shift (15:00-00:00): Jan 15 15:02 → Shift Date = Jan 15</li>
                                        <li>Night shift (22:00-07:00): Jan 15 22:00 → Shift Date = Jan 15</li>
                                        <li>Graveyard shift (00:00-09:00): Jan 15 00:05 → Shift Date = Jan 15</li>
                                    </ul>

                                    <p className="text-sm text-muted-foreground mt-3">
                                        <strong>Attendance Status Rules:</strong>
                                    </p>
                                    <ul className="text-sm text-muted-foreground mt-1 space-y-1 list-disc list-inside">
                                        <li><strong>On Time:</strong> Arrived within 14 minutes of scheduled time</li>
                                        <li><strong>Tardy:</strong> 15 minutes late (up to grace period limit)</li>
                                        <li><strong>Half-Day Absence:</strong> More than grace period late (default 15+ minutes)</li>
                                        <li><strong>Undertime:</strong> Left 1-60 minutes early (0.25 pts)</li>
                                        <li><strong>Undertime (&gt;1 Hour):</strong> Left 61+ minutes early (0.50 pts)</li>
                                        <li><strong>NCNS:</strong> No bio record found</li>
                                        <li><strong>Failed to Bio Out:</strong> No time out record found</li>
                                        <li><strong>Failed to Bio In:</strong> Has time out but no time in</li>
                                    </ul>

                                    <p className="text-xs text-muted-foreground mt-2 italic">
                                        Note: Grace period can be configured per employee schedule (default: 15 minutes)
                                    </p>
                                </div>

                                <div className="bg-muted p-3 rounded-md">
                                    <h4 className="font-semibold mb-1 text-sm">Benefits</h4>
                                    <ul className="text-xs text-muted-foreground space-y-1 list-disc list-inside">
                                        <li>Upload one file for entire week - no need to split by shift date</li>
                                        <li>System automatically detects shift dates based on employee's schedule and time in records</li>
                                        <li>Handles all shift types: morning (05:00-11:59), afternoon (12:00-17:59), evening (18:00-21:59), night (22:00-23:59), graveyard (00:00-04:59)</li>
                                        <li>Multiple files can be uploaded at once from different biometric sites</li>
                                        <li>Automatic tardiness detection with 15-minute threshold before penalties apply</li>
                                    </ul>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Recent Uploads */}
                {recentUploads && recentUploads.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent Uploads</CardTitle>
                            <CardDescription>Last 10 uploaded attendance files</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>File</TableHead>
                                            <TableHead>Shift Date</TableHead>
                                            <TableHead>Biometric Site</TableHead>
                                            <TableHead>Records</TableHead>
                                            <TableHead>Matched</TableHead>
                                            <TableHead>Unmatched</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Uploaded By</TableHead>
                                            <TableHead>Date</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentUploads.map(upload => (
                                            <React.Fragment key={upload.id}>
                                                <TableRow>
                                                    <TableCell className="font-medium">
                                                        <div className="flex items-center gap-2">
                                                            <FileText className="h-4 w-4 text-muted-foreground" />
                                                            {upload.original_filename}
                                                            {upload.date_warnings && upload.date_warnings.length > 0 && (
                                                                <AlertCircle className="h-4 w-4 text-orange-500" />
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>{upload.shift_date}</TableCell>
                                                    <TableCell>
                                                        <span className="text-sm font-medium">
                                                            {upload.biometric_site?.name || "-"}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>{upload.total_records}</TableCell>
                                                    <TableCell className="text-green-600 font-medium">
                                                        {upload.matched_employees}
                                                    </TableCell>
                                                    <TableCell className="text-orange-600 font-medium">
                                                        {upload.unmatched_names}
                                                    </TableCell>
                                                    <TableCell>{getStatusBadge(upload)}</TableCell>
                                                    <TableCell>{upload.uploader?.name || "-"}</TableCell>
                                                    <TableCell className="text-sm text-muted-foreground">
                                                        {new Date(upload.created_at).toLocaleDateString()}
                                                    </TableCell>
                                                </TableRow>
                                                {/* Error Message Row */}
                                                {upload.status === 'failed' && upload.error_message && (
                                                    <TableRow key={`${upload.id}-error`}>
                                                        <TableCell colSpan={9} className="bg-red-50 dark:bg-red-950/20">
                                                            <Alert variant="destructive" className="border-red-300 bg-red-50 dark:bg-red-950/20">
                                                                <AlertCircle className="h-4 w-4 text-red-600" />
                                                                <AlertTitle className="text-red-800 dark:text-red-200">
                                                                    Upload Failed
                                                                </AlertTitle>
                                                                <AlertDescription className="text-red-700 dark:text-red-300">
                                                                    {upload.error_message}
                                                                </AlertDescription>
                                                            </Alert>
                                                        </TableCell>
                                                    </TableRow>
                                                )}
                                                {/* Date Warnings Row */}
                                                {upload.date_warnings && upload.date_warnings.length > 0 && (
                                                    <TableRow key={`${upload.id}-warning`}>
                                                        <TableCell colSpan={10} className="bg-orange-50 dark:bg-orange-950/20">
                                                            <Alert className="border-orange-300 bg-orange-50 dark:bg-orange-950/20">
                                                                <AlertCircle className="h-4 w-4 text-orange-600" />
                                                                <AlertTitle className="text-orange-800 dark:text-orange-200">
                                                                    Date Validation Warning
                                                                </AlertTitle>
                                                                <AlertDescription className="text-orange-700 dark:text-orange-300">
                                                                    {upload.date_warnings.map((warning, idx) => (
                                                                        <div key={idx}>{warning}</div>
                                                                    ))}
                                                                </AlertDescription>
                                                            </Alert>
                                                        </TableCell>
                                                    </TableRow>
                                                )}
                                            </React.Fragment>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
