import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Download, Loader2 } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@/components/ui/alert';

interface User {
    id: number;
    name: string;
    employee_number: string;
}

interface Site {
    id: number;
    name: string;
}

export default function Export({ users, sites }: { users: User[]; sites: Site[] }) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Export Records',
        breadcrumbs: [
            { title: 'Attendance', href: '/attendance' },
            { title: 'Export Records', href: '/biometric-export' },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [selectedUsers, setSelectedUsers] = useState<number[]>([]);
    const [selectedSites, setSelectedSites] = useState<number[]>([]);
    const [exportFormat, setExportFormat] = useState<'csv' | 'xlsx'>('csv');
    const [isExporting, setIsExporting] = useState(false);

    const handleExport = () => {
        if (!startDate || !endDate) {
            alert('Please select both start and end dates');
            return;
        }

        setIsExporting(true);

        // Build query string
        const params = new URLSearchParams({
            start_date: startDate,
            end_date: endDate,
            format: exportFormat,
        });

        // Add array parameters
        selectedUsers.forEach(id => params.append('user_ids[]', id.toString()));
        selectedSites.forEach(id => params.append('site_ids[]', id.toString()));

        // Trigger download via direct link
        const url = `/biometric-export/export?${params.toString()}`;
        window.location.href = url;

        // Reset loading state after a short delay
        setTimeout(() => {
            setIsExporting(false);
        }, 1000);
    };

    const toggleUser = (userId: number) => {
        setSelectedUsers(prev =>
            prev.includes(userId)
                ? prev.filter(id => id !== userId)
                : [...prev, userId]
        );
    };

    const toggleSite = (siteId: number) => {
        setSelectedSites(prev =>
            prev.includes(siteId)
                ? prev.filter(id => id !== siteId)
                : [...prev, siteId]
        );
    };

    const selectAllUsers = () => {
        setSelectedUsers(selectedUsers.length === users.length ? [] : users.map(u => u.id));
    };

    const selectAllSites = () => {
        setSelectedSites(selectedSites.length === sites.length ? [] : sites.map(s => s.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <PageHeader title={title} />
            <LoadingOverlay isLoading={isPageLoading} />

            <div className="py-6">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Download className="h-5 w-5" />
                                Export Records
                            </CardTitle>
                            <CardDescription>
                                Export biometric scan records to CSV or Excel format
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <Alert>
                                <AlertDescription>
                                    Export includes: Employee Number, Name, Scan Date/Time, Site Name,
                                    and all raw scan data.
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

                            <div className="space-y-2">
                                <Label htmlFor="export-format">Export Format</Label>
                                <select
                                    id="export-format"
                                    value={exportFormat}
                                    onChange={(e) => setExportFormat(e.target.value as 'csv' | 'xlsx')}
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option value="csv">CSV (Comma-Separated Values)</option>
                                    <option value="xlsx">Excel (XLSX)</option>
                                </select>
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <Label>Filter by Employees (Optional)</Label>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={selectAllUsers}
                                    >
                                        {selectedUsers.length === users.length ? 'Deselect All' : 'Select All'}
                                    </Button>
                                </div>
                                <div className="max-h-48 overflow-y-auto border rounded-md p-3 space-y-2">
                                    {users.map((user) => (
                                        <label
                                            key={user.id}
                                            className="flex items-center space-x-2 cursor-pointer hover:bg-gray-50 p-2 rounded"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={selectedUsers.includes(user.id)}
                                                onChange={() => toggleUser(user.id)}
                                                className="h-4 w-4 rounded border-gray-300"
                                            />
                                            <span className="text-sm">
                                                {user.name} ({user.employee_number})
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                {selectedUsers.length > 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {selectedUsers.length} employee(s) selected
                                    </p>
                                )}
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <Label>Filter by Sites (Optional)</Label>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={selectAllSites}
                                    >
                                        {selectedSites.length === sites.length ? 'Deselect All' : 'Select All'}
                                    </Button>
                                </div>
                                <div className="max-h-48 overflow-y-auto border rounded-md p-3 space-y-2">
                                    {sites.map((site) => (
                                        <label
                                            key={site.id}
                                            className="flex items-center space-x-2 cursor-pointer hover:bg-gray-50 p-2 rounded"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={selectedSites.includes(site.id)}
                                                onChange={() => toggleSite(site.id)}
                                                className="h-4 w-4 rounded border-gray-300"
                                            />
                                            <span className="text-sm">{site.name}</span>
                                        </label>
                                    ))}
                                </div>
                                {selectedSites.length > 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {selectedSites.length} site(s) selected
                                    </p>
                                )}
                            </div>

                            <Button
                                onClick={handleExport}
                                disabled={isExporting || !startDate || !endDate}
                                className="w-full"
                            >
                                {isExporting ? (
                                    <>
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        Exporting...
                                    </>
                                ) : (
                                    <>
                                        <Download className="mr-2 h-4 w-4" />
                                        Export {exportFormat === 'xlsx' ? 'Excel' : 'CSV'}
                                    </>
                                )}
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
