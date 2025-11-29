import { Head, usePage } from '@inertiajs/react';
import React, { useState, useMemo, useRef } from 'react';
import { Download, Loader2, FileSpreadsheet, Check, ChevronsUpDown } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Can } from '@/components/authorization';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Progress } from '@/components/ui/progress';
import { index as attendanceIndex } from '@/routes/attendance';
import biometricExportRoutes, { index as biometricExportIndex } from '@/routes/biometric-export';

interface User {
    id: number;
    name: string;
    employee_number: string;
    campaign_ids: number[];
    site_ids: number[];
}

interface Site {
    id: number;
    name: string;
    campaign_ids: number[];
}

interface Campaign {
    id: number;
    name: string;
}

interface PageProps {
    users: User[];
    sites: Site[];
    campaigns: Campaign[];
    [key: string]: unknown;
}

export default function Export() {
    const { users, sites, campaigns } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Export Attendance Records',
        breadcrumbs: [
            { title: 'Attendance', href: attendanceIndex().url },
            { title: 'Export Records', href: biometricExportIndex().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [selectedUsers, setSelectedUsers] = useState<number[]>([]);
    const [selectedSites, setSelectedSites] = useState<number[]>([]);
    const [selectedCampaigns, setSelectedCampaigns] = useState<number[]>([]);
    const [isExporting, setIsExporting] = useState(false);
    const [exportProgress, setExportProgress] = useState(0);
    const [exportStatus, setExportStatus] = useState<string>('');
    const abortControllerRef = useRef<AbortController | null>(null);
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);
    const [isSitePopoverOpen, setIsSitePopoverOpen] = useState(false);
    const [isCampaignPopoverOpen, setIsCampaignPopoverOpen] = useState(false);
    const [employeeSearchQuery, setEmployeeSearchQuery] = useState('');
    const [siteSearchQuery, setSiteSearchQuery] = useState('');
    const [campaignSearchQuery, setCampaignSearchQuery] = useState('');

    // Filter employees based on search query and selected campaigns
    const filteredEmployees = useMemo(() => {
        if (!users) return [];
        let filtered = users;

        // Filter by selected campaigns first
        if (selectedCampaigns.length > 0) {
            filtered = filtered.filter(user =>
                user.campaign_ids.some(campaignId => selectedCampaigns.includes(campaignId))
            );
        }

        // Then filter by search query
        if (employeeSearchQuery) {
            filtered = filtered.filter(user =>
                user.name.toLowerCase().includes(employeeSearchQuery.toLowerCase()) ||
                user.employee_number.toLowerCase().includes(employeeSearchQuery.toLowerCase())
            );
        }

        return filtered;
    }, [users, employeeSearchQuery, selectedCampaigns]);

    // Filter sites based on search query and selected campaigns
    const filteredSites = useMemo(() => {
        if (!sites) return [];
        let filtered = sites;

        // Filter by selected campaigns first
        if (selectedCampaigns.length > 0) {
            filtered = filtered.filter(site =>
                site.campaign_ids.some(campaignId => selectedCampaigns.includes(campaignId))
            );
        }

        // Then filter by search query
        if (siteSearchQuery) {
            filtered = filtered.filter(site =>
                site.name.toLowerCase().includes(siteSearchQuery.toLowerCase())
            );
        }

        return filtered;
    }, [sites, siteSearchQuery, selectedCampaigns]);

    // Filter campaigns based on search query
    const filteredCampaigns = useMemo(() => {
        if (!campaigns) return [];
        if (!campaignSearchQuery) return campaigns;
        return campaigns.filter(campaign =>
            campaign.name.toLowerCase().includes(campaignSearchQuery.toLowerCase())
        );
    }, [campaigns, campaignSearchQuery]);

    const handleExport = async () => {
        if (!startDate || !endDate) {
            alert('Please select both start and end dates');
            return;
        }

        setIsExporting(true);
        setExportProgress(0);
        setExportStatus('Preparing export...');

        // Build query string
        const params = new URLSearchParams({
            start_date: startDate,
            end_date: endDate,
        });

        // Add array parameters
        selectedUsers.forEach(id => params.append('user_ids[]', id.toString()));
        selectedSites.forEach(id => params.append('site_ids[]', id.toString()));
        selectedCampaigns.forEach(id => params.append('campaign_ids[]', id.toString()));

        const url = `${biometricExportRoutes.export.url()}?${params.toString()}`;

        try {
            // Create abort controller for potential cancellation
            abortControllerRef.current = new AbortController();

            setExportProgress(10);
            setExportStatus('Fetching attendance records...');

            const response = await fetch(url, {
                signal: abortControllerRef.current.signal,
            });

            if (!response.ok) {
                throw new Error(`Export failed: ${response.statusText}`);
            }

            setExportProgress(50);
            setExportStatus('Generating Excel file...');

            // Get total size if available
            const contentLength = response.headers.get('content-length');
            const total = contentLength ? parseInt(contentLength, 10) : 0;

            // Read the response as a stream to track progress
            const reader = response.body?.getReader();
            const chunks: Uint8Array[] = [];
            let receivedLength = 0;

            if (reader) {
                while (true) {
                    const { done, value } = await reader.read();

                    if (done) break;

                    chunks.push(value);
                    receivedLength += value.length;

                    // Update progress based on download
                    if (total > 0) {
                        const downloadProgress = 50 + Math.round((receivedLength / total) * 40);
                        setExportProgress(downloadProgress);
                        setExportStatus(`Downloading... ${formatBytes(receivedLength)} / ${formatBytes(total)}`);
                    } else {
                        setExportStatus(`Downloading... ${formatBytes(receivedLength)}`);
                        // Simulate progress when content-length is unknown
                        setExportProgress(prev => Math.min(prev + 1, 90));
                    }
                }
            }

            setExportProgress(95);
            setExportStatus('Preparing download...');

            // Combine chunks into a single Uint8Array
            const allChunks = new Uint8Array(receivedLength);
            let position = 0;
            for (const chunk of chunks) {
                allChunks.set(chunk, position);
                position += chunk.length;
            }

            // Create blob and download
            const blob = new Blob([allChunks], {
                type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            });

            // Extract filename from Content-Disposition header or generate default
            const contentDisposition = response.headers.get('content-disposition');
            let filename = `attendance_export_${startDate}_to_${endDate}.xlsx`;
            if (contentDisposition) {
                const filenameMatch = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                if (filenameMatch && filenameMatch[1]) {
                    filename = filenameMatch[1].replace(/['"]/g, '');
                }
            }

            // Trigger download
            const downloadUrl = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(downloadUrl);

            setExportProgress(100);
            setExportStatus('Export complete!');

            // Reset after a short delay
            setTimeout(() => {
                setIsExporting(false);
                setExportProgress(0);
                setExportStatus('');
            }, 1500);

        } catch (error) {
            if (error instanceof Error && error.name === 'AbortError') {
                setExportStatus('Export cancelled');
            } else {
                setExportStatus('Export failed. Please try again.');
                console.error('Export error:', error);
            }

            setTimeout(() => {
                setIsExporting(false);
                setExportProgress(0);
                setExportStatus('');
            }, 2000);
        } finally {
            abortControllerRef.current = null;
        }
    };

    const cancelExport = () => {
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }
    };

    const formatBytes = (bytes: number): string => {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
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

    const toggleCampaign = (campaignId: number) => {
        setSelectedCampaigns(prev => {
            const newSelection = prev.includes(campaignId)
                ? prev.filter(id => id !== campaignId)
                : [...prev, campaignId];

            // Clear employee and site selections when campaign filter changes
            // to avoid having invalid selections
            setSelectedUsers([]);
            setSelectedSites([]);

            return newSelection;
        });
    };

    const selectAllUsers = () => {
        // Use filtered employees based on campaign selection
        const availableEmployees = selectedCampaigns.length > 0
            ? users.filter(user => user.campaign_ids.some(cid => selectedCampaigns.includes(cid)))
            : users;
        setSelectedUsers(selectedUsers.length === availableEmployees.length ? [] : availableEmployees.map(u => u.id));
    };

    const selectAllSites = () => {
        // Use filtered sites based on campaign selection
        const availableSites = selectedCampaigns.length > 0
            ? sites.filter(site => site.campaign_ids.some(cid => selectedCampaigns.includes(cid)))
            : sites;
        setSelectedSites(selectedSites.length === availableSites.length ? [] : availableSites.map(s => s.id));
    };

    const selectAllCampaigns = () => {
        const newSelection = selectedCampaigns.length === campaigns.length ? [] : campaigns.map(c => c.id);
        setSelectedCampaigns(newSelection);
        // Clear employee and site selections when campaign filter changes
        setSelectedUsers([]);
        setSelectedSites([]);
    };

    const clearFilters = () => {
        setSelectedUsers([]);
        setSelectedSites([]);
        setSelectedCampaigns([]);
    };

    const showClearFilters = selectedUsers.length > 0 || selectedSites.length > 0 || selectedCampaigns.length > 0;

    // Get display text for selected employees
    const selectedEmployeesText = useMemo(() => {
        // Get the available employees (filtered by campaign if any selected)
        const availableEmployees = selectedCampaigns.length > 0
            ? users.filter(user => user.campaign_ids.some(cid => selectedCampaigns.includes(cid)))
            : users;

        if (selectedUsers.length === 0) {
            return selectedCampaigns.length > 0
                ? `All Employees (${availableEmployees.length})`
                : 'All Employees';
        }
        if (selectedUsers.length === 1) {
            const user = users.find(u => u.id === selectedUsers[0]);
            return user?.name || '1 selected';
        }
        return `${selectedUsers.length} employees selected`;
    }, [selectedUsers, users, selectedCampaigns]);

    // Get display text for selected sites
    const selectedSitesText = useMemo(() => {
        // Get the available sites (filtered by campaign if any selected)
        const availableSites = selectedCampaigns.length > 0
            ? sites.filter(site => site.campaign_ids.some(cid => selectedCampaigns.includes(cid)))
            : sites;

        if (selectedSites.length === 0) {
            return selectedCampaigns.length > 0
                ? `All Sites (${availableSites.length})`
                : 'All Sites';
        }
        if (selectedSites.length === 1) {
            const site = sites.find(s => s.id === selectedSites[0]);
            return site?.name || '1 selected';
        }
        return `${selectedSites.length} sites selected`;
    }, [selectedSites, sites, selectedCampaigns]);

    // Get display text for selected campaigns
    const selectedCampaignsText = useMemo(() => {
        if (selectedCampaigns.length === 0) return 'All Campaigns';
        if (selectedCampaigns.length === 1) {
            const campaign = campaigns.find(c => c.id === selectedCampaigns[0]);
            return campaign?.name || '1 selected';
        }
        return `${selectedCampaigns.length} campaigns selected`;
    }, [selectedCampaigns, campaigns]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isPageLoading} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Export Attendance Records"
                    description="Export attendance records to Excel format with full details and statistics"
                />

                <div className="max-w-2xl mx-auto w-full">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileSpreadsheet className="h-5 w-5" />
                                Export Configuration
                            </CardTitle>
                            <CardDescription>
                                Select date range and optional filters to export attendance records
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <Alert>
                                <FileSpreadsheet className="h-4 w-4" />
                                <AlertDescription>
                                    <strong>Export includes:</strong> Employee details, Campaign, Shift Date, Scheduled &amp; Actual Time In/Out,
                                    Sites, Status, Tardy/Undertime/Overtime minutes, Verification status, and Notes.
                                    <br />
                                    <strong>Statistics section:</strong> Status breakdown, Time statistics, and Attendance percentages are included on the right side of the Excel file.
                                </AlertDescription>
                            </Alert>

                            {/* Date Range Selection */}
                            <div className="flex flex-col gap-3">
                                <Label className="text-sm font-medium">Date Range</Label>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div className="flex items-center gap-2 text-sm">
                                        <span className="text-muted-foreground text-xs whitespace-nowrap">From:</span>
                                        <Input
                                            type="date"
                                            value={startDate}
                                            onChange={(e) => setStartDate(e.target.value)}
                                            className="w-full"
                                        />
                                    </div>
                                    <div className="flex items-center gap-2 text-sm">
                                        <span className="text-muted-foreground text-xs whitespace-nowrap">To:</span>
                                        <Input
                                            type="date"
                                            value={endDate}
                                            onChange={(e) => setEndDate(e.target.value)}
                                            min={startDate}
                                            className="w-full"
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Filters */}
                            <div className="flex flex-col gap-3">
                                <Label className="text-sm font-medium">Filters (Optional)</Label>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    {/* Campaign Filter */}
                                    <Popover open={isCampaignPopoverOpen} onOpenChange={setIsCampaignPopoverOpen}>
                                        <PopoverTrigger asChild>
                                            <Button
                                                variant="outline"
                                                role="combobox"
                                                aria-expanded={isCampaignPopoverOpen}
                                                className="w-full justify-between font-normal"
                                            >
                                                <span className="truncate">{selectedCampaignsText}</span>
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-full p-0" align="start">
                                            <Command shouldFilter={false}>
                                                <CommandInput
                                                    placeholder="Search campaign..."
                                                    value={campaignSearchQuery}
                                                    onValueChange={setCampaignSearchQuery}
                                                />
                                                <CommandList>
                                                    <CommandEmpty>No campaign found.</CommandEmpty>
                                                    <CommandGroup>
                                                        <CommandItem
                                                            onSelect={selectAllCampaigns}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${selectedCampaigns.length === campaigns.length && campaigns.length > 0 ? 'opacity-100' : 'opacity-0'}`}
                                                            />
                                                            {selectedCampaigns.length === campaigns.length && campaigns.length > 0 ? 'Deselect All' : 'Select All'}
                                                        </CommandItem>
                                                        {filteredCampaigns.map((campaign) => (
                                                            <CommandItem
                                                                key={campaign.id}
                                                                value={campaign.name}
                                                                onSelect={() => toggleCampaign(campaign.id)}
                                                                className="cursor-pointer"
                                                            >
                                                                <Check
                                                                    className={`mr-2 h-4 w-4 ${selectedCampaigns.includes(campaign.id) ? 'opacity-100' : 'opacity-0'}`}
                                                                />
                                                                {campaign.name}
                                                            </CommandItem>
                                                        ))}
                                                    </CommandGroup>
                                                </CommandList>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>

                                    {/* Employee Filter */}
                                    <Popover open={isEmployeePopoverOpen} onOpenChange={setIsEmployeePopoverOpen}>
                                        <PopoverTrigger asChild>
                                            <Button
                                                variant="outline"
                                                role="combobox"
                                                aria-expanded={isEmployeePopoverOpen}
                                                className="w-full justify-between font-normal"
                                            >
                                                <span className="truncate">{selectedEmployeesText}</span>
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-full p-0" align="start">
                                            <Command shouldFilter={false}>
                                                <CommandInput
                                                    placeholder="Search employee..."
                                                    value={employeeSearchQuery}
                                                    onValueChange={setEmployeeSearchQuery}
                                                />
                                                <CommandList>
                                                    <CommandEmpty>No employee found.</CommandEmpty>
                                                    <CommandGroup>
                                                        <CommandItem
                                                            onSelect={selectAllUsers}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${selectedUsers.length === filteredEmployees.length && filteredEmployees.length > 0 ? 'opacity-100' : 'opacity-0'}`}
                                                            />
                                                            {selectedUsers.length === filteredEmployees.length && filteredEmployees.length > 0 ? 'Deselect All' : `Select All (${filteredEmployees.length})`}
                                                        </CommandItem>
                                                        {filteredEmployees.map((user) => (
                                                            <CommandItem
                                                                key={user.id}
                                                                value={user.name}
                                                                onSelect={() => toggleUser(user.id)}
                                                                className="cursor-pointer"
                                                            >
                                                                <Check
                                                                    className={`mr-2 h-4 w-4 ${selectedUsers.includes(user.id) ? 'opacity-100' : 'opacity-0'}`}
                                                                />
                                                                {user.name} ({user.employee_number})
                                                            </CommandItem>
                                                        ))}
                                                    </CommandGroup>
                                                </CommandList>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>

                                    {/* Site Filter */}
                                    <Popover open={isSitePopoverOpen} onOpenChange={setIsSitePopoverOpen}>
                                        <PopoverTrigger asChild>
                                            <Button
                                                variant="outline"
                                                role="combobox"
                                                aria-expanded={isSitePopoverOpen}
                                                className="w-full justify-between font-normal"
                                            >
                                                <span className="truncate">{selectedSitesText}</span>
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-full p-0" align="start">
                                            <Command shouldFilter={false}>
                                                <CommandInput
                                                    placeholder="Search site..."
                                                    value={siteSearchQuery}
                                                    onValueChange={setSiteSearchQuery}
                                                />
                                                <CommandList>
                                                    <CommandEmpty>No site found.</CommandEmpty>
                                                    <CommandGroup>
                                                        <CommandItem
                                                            onSelect={selectAllSites}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${selectedSites.length === filteredSites.length && filteredSites.length > 0 ? 'opacity-100' : 'opacity-0'}`}
                                                            />
                                                            {selectedSites.length === filteredSites.length && filteredSites.length > 0 ? 'Deselect All' : `Select All (${filteredSites.length})`}
                                                        </CommandItem>
                                                        {filteredSites.map((site) => (
                                                            <CommandItem
                                                                key={site.id}
                                                                value={site.name}
                                                                onSelect={() => toggleSite(site.id)}
                                                                className="cursor-pointer"
                                                            >
                                                                <Check
                                                                    className={`mr-2 h-4 w-4 ${selectedSites.includes(site.id) ? 'opacity-100' : 'opacity-0'}`}
                                                                />
                                                                {site.name}
                                                            </CommandItem>
                                                        ))}
                                                    </CommandGroup>
                                                </CommandList>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>
                                </div>
                            </div>

                            {/* Selected Filters Summary */}
                            {showClearFilters && (
                                <div className="flex flex-wrap gap-2 items-center">
                                    <span className="text-sm text-muted-foreground">Selected filters:</span>
                                    {selectedCampaigns.length > 0 && (
                                        <Badge variant="secondary" className="font-normal">
                                            {selectedCampaigns.length} campaign{selectedCampaigns.length === 1 ? '' : 's'}
                                        </Badge>
                                    )}
                                    {selectedUsers.length > 0 && (
                                        <Badge variant="secondary" className="font-normal">
                                            {selectedUsers.length} employee{selectedUsers.length === 1 ? '' : 's'}
                                        </Badge>
                                    )}
                                    {selectedSites.length > 0 && (
                                        <Badge variant="secondary" className="font-normal">
                                            {selectedSites.length} site{selectedSites.length === 1 ? '' : 's'}
                                        </Badge>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={clearFilters}
                                        className="h-6 px-2 text-xs"
                                    >
                                        Clear filters
                                    </Button>
                                </div>
                            )}

                            {/* Export Button */}
                            <div className="flex flex-col gap-3 border-t pt-4">
                                {isExporting && (
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="text-muted-foreground">{exportStatus}</span>
                                            <span className="font-medium">{exportProgress}%</span>
                                        </div>
                                        <Progress value={exportProgress} className="h-2" />
                                    </div>
                                )}
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                                    {isExporting && (
                                        <Button
                                            variant="outline"
                                            onClick={cancelExport}
                                            className="w-full sm:w-auto"
                                        >
                                            Cancel
                                        </Button>
                                    )}
                                    <Can permission="biometric.export">
                                        <Button
                                            onClick={handleExport}
                                            disabled={isExporting || !startDate || !endDate}
                                            className="w-full sm:w-auto"
                                        >
                                            {isExporting ? (
                                                <>
                                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                    Exporting...
                                                </>
                                            ) : (
                                                <>
                                                    <Download className="mr-2 h-4 w-4" />
                                                    Export to Excel
                                                </>
                                            )}
                                        </Button>
                                    </Can>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
