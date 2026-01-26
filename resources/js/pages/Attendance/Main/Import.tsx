import React, { useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { DatePicker } from "@/components/ui/date-picker";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Badge } from "@/components/ui/badge";
import { Upload, FileText, AlertCircle, CheckCircle, Clock, X, Eye, AlertTriangle, Users } from "lucide-react";
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
    previewUpload as attendancePreviewUpload,
} from "@/routes/attendance";

interface FileUploadItem {
    id: string;
    file: File;
    biometric_site_id: number | "";
}

interface PreviewData {
    file_name: string;
    file_size: number;
    total_records: number;
    within_range: {
        count: number;
        unique_employees: number;
        dates: string[];
        employees: string[];
    };
    outside_range: {
        count: number;
        unique_employees: number;
        dates: string[];
        employees: string[];
    };
    duplicates: {
        count: number;
        records: Array<{
            employee: string;
            date: string;
            status: string;
            verified: boolean;
        }>;
        message: string;
    };
    date_range: {
        from: string;
        to: string;
        extended_to: string;
    };
}

interface FilePreview {
    fileItem: FileUploadItem;
    preview: PreviewData | null;
    loading: boolean;
    error: string | null;
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

    // Preview state
    const [showPreview, setShowPreview] = useState(false);
    const [isPreviewing, setIsPreviewing] = useState(false);
    const [filePreviews, setFilePreviews] = useState<FilePreview[]>([]);
    const [importAllRecords, setImportAllRecords] = useState(false);

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

    // Preview files before upload
    const handlePreview = async (e: React.FormEvent) => {
        e.preventDefault();

        if (files.length === 0) {
            toast.error("Please select at least one file");
            return;
        }

        // Validate all files have sites assigned
        const unassignedFiles = files.filter(f => !f.biometric_site_id);
        if (unassignedFiles.length > 0) {
            toast.error("Please assign a site to all files before previewing");
            return;
        }

        setIsPreviewing(true);
        const previews: FilePreview[] = [];

        // Get preview for each file
        for (const fileItem of files) {
            const formData = new FormData();
            formData.append('file', fileItem.file);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            formData.append('biometric_site_id', fileItem.biometric_site_id.toString());

            try {
                const response = await fetch(attendancePreviewUpload().url, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    previews.push({
                        fileItem,
                        preview: data.preview,
                        loading: false,
                        error: null,
                    });
                } else {
                    previews.push({
                        fileItem,
                        preview: null,
                        loading: false,
                        error: data.message || 'Failed to preview file',
                    });
                }
            } catch {
                previews.push({
                    fileItem,
                    preview: null,
                    loading: false,
                    error: 'Network error while previewing file',
                });
            }
        }

        setFilePreviews(previews);
        setIsPreviewing(false);
        setShowPreview(true);
    };

    // Confirm and upload files
    const handleConfirmUpload = async () => {
        setShowPreview(false);
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
            formData.append('filter_by_date', importAllRecords ? '0' : '1');

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

                if (!response.ok) {
                    allSuccessful = false;
                    const errorData = await response.json().catch(() => ({}));
                    toast.error(`Failed to upload ${fileItem.file.name}`, {
                        description: errorData.message || response.statusText
                    });
                } else {
                    const successData = await response.json();
                    successMessages.push(successData.message || `${fileItem.file.name} uploaded successfully`);
                }
            } catch {
                allSuccessful = false;
                toast.error(`Network error uploading ${fileItem.file.name}`);
            }
        }

        setIsUploading(false);
        setFiles([]);
        setNotes("");
        setImportAllRecords(false);
        setFilePreviews([]);

        // Show success messages
        if (allSuccessful && successMessages.length > 0) {
            successMessages.forEach((msg, index) => {
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

    // Legacy direct upload (kept for backwards compatibility, but not used in normal flow)
    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        // Now redirects to preview
        handlePreview(e);
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
                                            <DatePicker
                                                value={dateFrom}
                                                onChange={(value) => {
                                                    // Clear end date if it's before the new start date
                                                    if (dateTo && value && new Date(dateTo) < new Date(value)) {
                                                        setDateTo('');
                                                    }
                                                    setDateFrom(value);
                                                }}
                                                placeholder="Start date"
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="date_to" className="text-xs text-muted-foreground">
                                                To
                                            </Label>
                                            <DatePicker
                                                value={dateTo}
                                                onChange={(value) => setDateTo(value)}
                                                placeholder="End date"
                                                minDate={dateFrom || undefined}
                                                defaultMonth={dateFrom || undefined}
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
                                        disabled={files.length === 0 || isUploading || isPreviewing}
                                        className="flex-1"
                                    >
                                        {isPreviewing ? (
                                            <>
                                                <Clock className="mr-2 h-4 w-4 animate-spin" />
                                                Analyzing {files.length} file(s)...
                                            </>
                                        ) : isUploading ? (
                                            <>
                                                <Clock className="mr-2 h-4 w-4 animate-spin" />
                                                Uploading {files.length} file(s)...
                                            </>
                                        ) : (
                                            <>
                                                <Eye className="mr-2 h-4 w-4" />
                                                Preview & Upload {files.length > 0 ? `${files.length} file(s)` : ''}
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
                                        <TableRow className="bg-muted/50">
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

            {/* Preview Dialog */}
            <Dialog open={showPreview} onOpenChange={setShowPreview}>
                <DialogContent className="max-w-3xl max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Eye className="h-5 w-5" />
                            Import Preview
                        </DialogTitle>
                        <DialogDescription>
                            Review the records that will be imported for {dateFrom} to {dateTo}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        {filePreviews.map((fp, index) => (
                            <Card key={index} className={fp.error ? "border-red-300" : ""}>
                                <CardHeader className="py-3">
                                    <CardTitle className="text-sm flex items-center gap-2">
                                        <FileText className="h-4 w-4" />
                                        {fp.fileItem.file.name}
                                        {fp.error && <Badge variant="destructive">Error</Badge>}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="py-3">
                                    {fp.error ? (
                                        <Alert variant="destructive">
                                            <AlertCircle className="h-4 w-4" />
                                            <AlertDescription>{fp.error}</AlertDescription>
                                        </Alert>
                                    ) : fp.preview ? (
                                        <div className="space-y-4">
                                            {/* Summary Stats */}
                                            <div className="grid grid-cols-3 gap-4">
                                                <div className="bg-muted rounded-lg p-3 text-center">
                                                    <div className="text-2xl font-bold">{fp.preview.total_records}</div>
                                                    <div className="text-xs text-muted-foreground">Total Records</div>
                                                </div>
                                                <div className="bg-green-50 dark:bg-green-950/20 rounded-lg p-3 text-center">
                                                    <div className="text-2xl font-bold text-green-600">{fp.preview.within_range.count}</div>
                                                    <div className="text-xs text-muted-foreground">Within Range</div>
                                                </div>
                                                <div className={`rounded-lg p-3 text-center ${fp.preview.outside_range.count > 0 ? 'bg-orange-50 dark:bg-orange-950/20' : 'bg-muted'}`}>
                                                    <div className={`text-2xl font-bold ${fp.preview.outside_range.count > 0 ? 'text-orange-600' : ''}`}>
                                                        {fp.preview.outside_range.count}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">Outside Range</div>
                                                </div>
                                            </div>

                                            {/* Within Range Details */}
                                            <div className="border rounded-lg p-3">
                                                <div className="flex items-center gap-2 mb-2">
                                                    <CheckCircle className="h-4 w-4 text-green-600" />
                                                    <span className="font-medium text-sm">Records to Import</span>
                                                </div>
                                                <div className="grid grid-cols-2 gap-4 text-sm">
                                                    <div>
                                                        <span className="text-muted-foreground">Unique Employees:</span>{" "}
                                                        <span className="font-medium">{fp.preview.within_range.unique_employees}</span>
                                                    </div>
                                                    <div>
                                                        <span className="text-muted-foreground">Dates:</span>{" "}
                                                        <span className="font-medium">{fp.preview.within_range.dates.join(", ") || "None"}</span>
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Outside Range Warning */}
                                            {fp.preview.outside_range.count > 0 && (
                                                <Alert className="border-orange-300 bg-orange-50 dark:bg-orange-950/20">
                                                    <AlertTriangle className="h-4 w-4 text-orange-600" />
                                                    <AlertTitle className="text-orange-800 dark:text-orange-200">
                                                        Records Outside Date Range
                                                    </AlertTitle>
                                                    <AlertDescription className="text-orange-700 dark:text-orange-300">
                                                        <p>{fp.preview.outside_range.count} records from dates outside your selected range will be {importAllRecords ? 'included' : 'skipped'}:</p>
                                                        <p className="mt-1 font-medium">{fp.preview.outside_range.dates.join(", ")}</p>
                                                    </AlertDescription>
                                                </Alert>
                                            )}

                                            {/* Duplicates Warning */}
                                            {fp.preview.duplicates.count > 0 && (
                                                <Alert>
                                                    <Users className="h-4 w-4" />
                                                    <AlertTitle>Existing Records Found</AlertTitle>
                                                    <AlertDescription>
                                                        <p>{fp.preview.duplicates.message}</p>
                                                        {fp.preview.duplicates.records.length > 0 && (
                                                            <ul className="mt-2 text-sm space-y-1">
                                                                {fp.preview.duplicates.records.slice(0, 5).map((dup, i) => (
                                                                    <li key={i} className="flex items-center gap-2">
                                                                        <span>{dup.employee}</span>
                                                                        <span className="text-muted-foreground">({dup.date})</span>
                                                                        {dup.verified && <Badge variant="secondary" className="text-xs">Verified</Badge>}
                                                                    </li>
                                                                ))}
                                                                {fp.preview.duplicates.records.length > 5 && (
                                                                    <li className="text-muted-foreground">...and {fp.preview.duplicates.records.length - 5} more</li>
                                                                )}
                                                            </ul>
                                                        )}
                                                    </AlertDescription>
                                                </Alert>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="flex items-center justify-center py-4">
                                            <Clock className="h-4 w-4 animate-spin mr-2" />
                                            Loading preview...
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))}

                        {/* Import All Option */}
                        {filePreviews.some(fp => fp.preview && fp.preview.outside_range.count > 0) && (
                            <div className="flex items-center space-x-2 p-3 bg-muted rounded-lg">
                                <Checkbox
                                    id="import-all"
                                    checked={importAllRecords}
                                    onCheckedChange={(checked) => setImportAllRecords(checked === true)}
                                />
                                <label
                                    htmlFor="import-all"
                                    className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                >
                                    Import ALL records (ignore date range filter)
                                </label>
                            </div>
                        )}
                    </div>

                    <DialogFooter className="flex-col sm:flex-row gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setShowPreview(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={handleConfirmUpload}
                            disabled={filePreviews.every(fp => fp.error) || isUploading}
                        >
                            {isUploading ? (
                                <>
                                    <Clock className="mr-2 h-4 w-4 animate-spin" />
                                    Uploading...
                                </>
                            ) : (
                                <>
                                    <Upload className="mr-2 h-4 w-4" />
                                    Confirm & Upload
                                </>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
