import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { format } from 'date-fns';
import { Calendar, AlertCircle, CheckCircle, Loader2, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

interface Stats {
    total_records: number;
    oldest_record: string | null;
    newest_record: string | null;
}

interface PreviewData {
    total_records: number;
    affected_users: number;
    affected_dates: number;
    date_range: {
        start: string;
        end: string;
    };
    users: Array<{
        id: number;
        name: string;
        record_count: number;
    }>;
}

interface ReprocessResult {
    processed: number;
    failed: number;
    errors: Array<{
        user: string;
        error: string;
    }>;
    details: Array<{
        user: string;
        shifts_processed: number;
        records_count: number;
    }>;
}

interface FixStatusResult {
    updated: number;
    total_checked: number;
    details: Array<{
        user: string;
        date: string;
        old_status: string;
        new_status: string;
        secondary_status?: string;
    }>;
}

export default function Reprocessing({ stats, fixResults }: { stats: Stats; fixResults?: FixStatusResult }) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Reprocess Attendance',
        breadcrumbs: [
            { title: 'Attendance', href: '/attendance' },
            { title: 'Reprocess Attendance', href: '/biometric-reprocessing' },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [showPreview, setShowPreview] = useState(false);
    const [previewData, setPreviewData] = useState<PreviewData | null>(null);
    const [reprocessResult, setReprocessResult] = useState<ReprocessResult | null>(null);
    const [fixStatusResult, setFixStatusResult] = useState<FixStatusResult | null>(fixResults || null);
    const [deleteExisting, setDeleteExisting] = useState(true);
    const [rescanPoints, setRescanPoints] = useState(true);
    const [showReprocessConfirm, setShowReprocessConfirm] = useState(false);
    const [showFixStatusConfirm, setShowFixStatusConfirm] = useState(false);

    const handlePreview = () => {
        if (!startDate || !endDate) {
            toast.error('Please select both start and end dates');
            return;
        }

        setIsLoading(true);
        router.post(
            '/biometric-reprocessing/preview',
            {
                start_date: startDate,
                end_date: endDate,
            },
            {
                preserveState: false,
                preserveScroll: false,
                onSuccess: (page) => {
                    setPreviewData(page.props.preview as PreviewData);
                    setShowPreview(true);
                    setIsLoading(false);
                },
                onError: () => {
                    toast.error('Failed to load preview');
                    setIsLoading(false);
                },
            }
        );
    };

    const handleReprocess = () => {
        if (!startDate || !endDate) {
            toast.error('Please select both start and end dates');
            return;
        }

        setShowReprocessConfirm(true);
    };

    const confirmReprocess = () => {
        setShowReprocessConfirm(false);
        setIsLoading(true);
        setShowPreview(false);
        router.post(
            '/biometric-reprocessing/reprocess',
            {
                start_date: startDate,
                end_date: endDate,
                delete_existing: deleteExisting,
                rescan_points: rescanPoints,
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: (page) => {
                    setReprocessResult(page.props.results as ReprocessResult);
                    setIsLoading(false);
                    toast.success('Attendance reprocessing completed successfully');
                },
                onError: () => {
                    toast.error('Failed to reprocess attendance');
                    setIsLoading(false);
                },
            }
        );
    };

    const handleFixStatuses = () => {
        if (!startDate || !endDate) {
            toast.error('Please select both start and end dates');
            return;
        }

        setShowFixStatusConfirm(true);
    };

    const confirmFixStatuses = () => {
        setShowFixStatusConfirm(false);
        setIsLoading(true);
        router.post(
            '/biometric-reprocessing/fix-statuses',
            {
                start_date: startDate,
                end_date: endDate,
            },
            {
                preserveState: false,
                preserveScroll: false,
                onSuccess: (page) => {
                    setFixStatusResult(page.props.fixResults as FixStatusResult);
                    setIsLoading(false);
                    toast.success('Attendance statuses fixed successfully');
                },
                onError: () => {
                    toast.error('Failed to fix statuses');
                    setIsLoading(false);
                },
            }
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <PageHeader title={title} />
            <LoadingOverlay isLoading={isPageLoading} />

            <div className="py-6">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="space-y-6">
                        {/* Statistics Card */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Database Statistics</CardTitle>
                                <CardDescription>
                                    Overview of stored biometric records
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="grid gap-4 md:grid-cols-3">
                                    <div className="space-y-1">
                                        <p className="text-sm font-medium text-gray-500">Total Records</p>
                                        <p className="text-2xl font-bold">{stats.total_records.toLocaleString()}</p>
                                    </div>
                                    <div className="space-y-1">
                                        <p className="text-sm font-medium text-gray-500">Oldest Record</p>
                                        <p className="text-2xl font-bold">
                                            {stats.oldest_record ? format(new Date(stats.oldest_record), 'MMM d, yyyy') : 'N/A'}
                                        </p>
                                    </div>
                                    <div className="space-y-1">
                                        <p className="text-sm font-medium text-gray-500">Newest Record</p>
                                        <p className="text-2xl font-bold">
                                            {stats.newest_record ? format(new Date(stats.newest_record), 'MMM d, yyyy') : 'N/A'}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Reprocessing Form */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <RefreshCw className="h-5 w-5" />
                                    Reprocess Attendance
                                </CardTitle>
                                <CardDescription>
                                    Reprocess attendance records for a specific date range
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <Alert>
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertTitle>Important</AlertTitle>
                                    <AlertDescription>
                                        Reprocessing will recalculate attendance using the latest algorithm.
                                        Existing attendance records for the selected date range can be deleted and recreated.
                                        <strong className="block mt-2">Note: Admin-verified/approved records will be preserved and not affected by reprocessing.</strong>
                                    </AlertDescription>
                                </Alert>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="start-date">Start Date</Label>
                                        <Input
                                            id="start-date"
                                            type="date"
                                            value={startDate}
                                            onChange={(e) => setStartDate(e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="end-date">End Date</Label>
                                        <Input
                                            id="end-date"
                                            type="date"
                                            value={endDate}
                                            onChange={(e) => setEndDate(e.target.value)}
                                            min={startDate}
                                        />
                                    </div>
                                </div>

                                <div className="flex items-center space-x-2">
                                    <input
                                        type="checkbox"
                                        id="delete-existing"
                                        checked={deleteExisting}
                                        onChange={(e) => setDeleteExisting(e.target.checked)}
                                        className="h-4 w-4 rounded border-gray-300"
                                    />
                                    <Label htmlFor="delete-existing" className="cursor-pointer">
                                        Delete existing attendance records before reprocessing
                                    </Label>
                                </div>

                                <div className="flex items-center space-x-2">
                                    <input
                                        type="checkbox"
                                        id="rescan-points"
                                        checked={rescanPoints}
                                        onChange={(e) => setRescanPoints(e.target.checked)}
                                        className="h-4 w-4 rounded border-gray-300"
                                    />
                                    <Label htmlFor="rescan-points" className="cursor-pointer">
                                        Automatically rescan attendance points after reprocessing
                                    </Label>
                                </div>

                                <div className="flex gap-3">
                                    <Button
                                        onClick={handlePreview}
                                        disabled={isLoading || !startDate || !endDate}
                                        variant="outline"
                                    >
                                        {isLoading ? (
                                            <>
                                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                Loading...
                                            </>
                                        ) : (
                                            <>
                                                <Calendar className="mr-2 h-4 w-4" />
                                                Preview
                                            </>
                                        )}
                                    </Button>
                                    <Button
                                        onClick={handleReprocess}
                                        disabled={isLoading || !startDate || !endDate}
                                    >
                                        {isLoading ? (
                                            <>
                                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                Processing...
                                            </>
                                        ) : (
                                            <>
                                                <RefreshCw className="mr-2 h-4 w-4" />
                                                Reprocess
                                            </>
                                        )}
                                    </Button>
                                    <Button
                                        onClick={handleFixStatuses}
                                        disabled={isLoading || !startDate || !endDate}
                                        variant="secondary"
                                    >
                                        {isLoading ? (
                                            <>
                                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                Fixing...
                                            </>
                                        ) : (
                                            <>
                                                <CheckCircle className="mr-2 h-4 w-4" />
                                                Fix Statuses
                                            </>
                                        )}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Results Card */}
                        {reprocessResult && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <CheckCircle className="h-5 w-5 text-green-600" />
                                        Reprocessing Results
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="rounded-lg border p-4">
                                            <p className="text-sm font-medium text-gray-500">Successfully Processed</p>
                                            <p className="text-3xl font-bold text-green-600">{reprocessResult.processed}</p>
                                        </div>
                                        <div className="rounded-lg border p-4">
                                            <p className="text-sm font-medium text-gray-500">Failed</p>
                                            <p className="text-3xl font-bold text-red-600">{reprocessResult.failed}</p>
                                        </div>
                                    </div>

                                    {reprocessResult.details.length > 0 && (
                                        <div>
                                            <h4 className="mb-2 font-semibold">Processing Details</h4>
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>Employee</TableHead>
                                                        <TableHead>Shifts Processed</TableHead>
                                                        <TableHead>Records Count</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {reprocessResult.details.map((detail, index) => (
                                                        <TableRow key={index}>
                                                            <TableCell>{detail.user}</TableCell>
                                                            <TableCell>{detail.shifts_processed}</TableCell>
                                                            <TableCell>{detail.records_count}</TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    )}

                                    {reprocessResult.errors.length > 0 && (
                                        <Alert variant="destructive">
                                            <AlertCircle className="h-4 w-4" />
                                            <AlertTitle>Errors Encountered</AlertTitle>
                                            <AlertDescription>
                                                <ul className="mt-2 space-y-1">
                                                    {reprocessResult.errors.map((error, index) => (
                                                        <li key={index}>
                                                            <strong>{error.user}:</strong> {error.error}
                                                        </li>
                                                    ))}
                                                </ul>
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Fix Statuses Results Card */}
                        {fixStatusResult && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <CheckCircle className="h-5 w-5 text-blue-600" />
                                        Status Fix Results
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="rounded-lg border p-4">
                                            <p className="text-sm font-medium text-gray-500">Statuses Updated</p>
                                            <p className="text-3xl font-bold text-blue-600">{fixStatusResult.updated}</p>
                                        </div>
                                        <div className="rounded-lg border p-4">
                                            <p className="text-sm font-medium text-gray-500">Total Checked</p>
                                            <p className="text-3xl font-bold text-gray-600">{fixStatusResult.total_checked}</p>
                                        </div>
                                    </div>

                                    {fixStatusResult.details.length > 0 && (
                                        <div>
                                            <h4 className="mb-2 font-semibold">Status Changes</h4>
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>Employee</TableHead>
                                                        <TableHead>Date</TableHead>
                                                        <TableHead>Old Status</TableHead>
                                                        <TableHead>New Status</TableHead>
                                                        <TableHead>Secondary</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {fixStatusResult.details.map((detail, index) => (
                                                        <TableRow key={index}>
                                                            <TableCell>{detail.user}</TableCell>
                                                            <TableCell>{detail.date}</TableCell>
                                                            <TableCell>
                                                                <span className="rounded-full bg-gray-100 px-2 py-1 text-xs">
                                                                    {detail.old_status}
                                                                </span>
                                                            </TableCell>
                                                            <TableCell>
                                                                <span className="rounded-full bg-blue-100 px-2 py-1 text-xs text-blue-800">
                                                                    {detail.new_status}
                                                                </span>
                                                            </TableCell>
                                                            <TableCell>
                                                                {detail.secondary_status ? (
                                                                    <span className="rounded-full bg-purple-100 px-2 py-1 text-xs text-purple-800">
                                                                        {detail.secondary_status}
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-xs text-muted-foreground">-</span>
                                                                )}
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    )}

                                    {fixStatusResult.updated === 0 && (
                                        <Alert>
                                            <CheckCircle className="h-4 w-4" />
                                            <AlertTitle>No Changes Needed</AlertTitle>
                                            <AlertDescription>
                                                All attendance statuses in the selected date range are already correct.
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>

            {/* Preview Dialog */}
            <Dialog open={showPreview} onOpenChange={setShowPreview}>
                <DialogContent className="max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Reprocessing Preview</DialogTitle>
                        <DialogDescription>
                            Review what will be affected by reprocessing
                        </DialogDescription>
                    </DialogHeader>
                    {previewData && (
                        <div className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-3">
                                <div className="rounded-lg border p-3">
                                    <p className="text-sm text-gray-500">Total Records</p>
                                    <p className="text-2xl font-bold">{previewData.total_records}</p>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <p className="text-sm text-gray-500">Affected Employees</p>
                                    <p className="text-2xl font-bold">{previewData.affected_users}</p>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <p className="text-sm text-gray-500">Affected Dates</p>
                                    <p className="text-2xl font-bold">{previewData.affected_dates}</p>
                                </div>
                            </div>

                            <div>
                                <h4 className="mb-2 font-semibold">Date Range</h4>
                                <p className="text-sm">
                                    {format(new Date(previewData.date_range.start), 'MMMM d, yyyy')} to{' '}
                                    {format(new Date(previewData.date_range.end), 'MMMM d, yyyy')}
                                </p>
                            </div>

                            {previewData.users.length > 0 && (
                                <div>
                                    <h4 className="mb-2 font-semibold">Affected Employees</h4>
                                    <div className="max-h-60 overflow-y-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Name</TableHead>
                                                    <TableHead>Records</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {previewData.users.map((user) => (
                                                    <TableRow key={user.id}>
                                                        <TableCell>{user.name}</TableCell>
                                                        <TableCell>{user.record_count}</TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end gap-2">
                                <Button variant="outline" onClick={() => setShowPreview(false)}>
                                    Cancel
                                </Button>
                                <Button onClick={() => { setShowPreview(false); handleReprocess(); }}>
                                    Proceed with Reprocessing
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/* Reprocess Confirmation Dialog */}
            <AlertDialog open={showReprocessConfirm} onOpenChange={setShowReprocessConfirm}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Reprocess Attendance</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to reprocess attendance for this date range? This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmReprocess}>
                            Proceed
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Fix Statuses Confirmation Dialog */}
            <AlertDialog open={showFixStatusConfirm} onOpenChange={setShowFixStatusConfirm}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Fix Attendance Statuses</AlertDialogTitle>
                        <AlertDialogDescription>
                            Fix attendance statuses based on existing time in/out records for this date range?
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmFixStatuses}>
                            Fix Statuses
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
