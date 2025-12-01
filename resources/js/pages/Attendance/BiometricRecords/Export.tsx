import { Head, usePage } from '@inertiajs/react';
import React, { useState, useMemo, useEffect } from 'react';
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
import { toast } from 'sonner';
import { index as attendanceIndex } from '@/routes/attendance';
import { index as biometricExportIndex } from '@/routes/biometric-export';

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
    const [exportJobId, setExportJobId] = useState<string | null>(null);
    const exportIntervalRef = React.useRef<NodeJS.Timeout | null>(null);
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

    const handleExport = () => {
        if (!startDate || !endDate) {
            toast.error('Please select both start and end dates');
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            toast.error('CSRF token not found');
            return;
        }

        setIsExporting(true);
        setExportProgress(0);
        setExportStatus('Starting export...');

        // Build request body
        const body = {
            start_date: startDate,
            end_date: endDate,
            user_ids: selectedUsers,
            site_ids: selectedSites,
            campaign_ids: selectedCampaigns,
        };

        fetch('/biometric-export/start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(body),
        })
            .then(res => res.json())
            .then(data => {
                if (data.jobId) {
                    setExportJobId(data.jobId);
                } else {
                    toast.error('Failed to start export');
                    setIsExporting(false);
                }
            })
            .catch(err => {
                console.error('Export start error:', err);
                toast.error('Failed to start export');
                setIsExporting(false);
            });
    };

    // Poll for export progress
    useEffect(() => {
        if (isExporting && exportJobId) {
            if (exportIntervalRef.current) {
                clearInterval(exportIntervalRef.current);
                exportIntervalRef.current = null;
            }

            const pollProgress = () => {
                fetch(`/biometric-export/progress/${exportJobId}`)
                    .then(res => {
                        if (!res.ok) throw new Error('Failed to fetch progress');
                        return res.json();
                    })
                    .then(data => {
                        setExportProgress(data.percent || 0);
                        setExportStatus(data.status || 'Processing...');

                        if (data.finished) {
                            if (exportIntervalRef.current) {
                                clearInterval(exportIntervalRef.current);
                                exportIntervalRef.current = null;
                            }

                            if (data.error) {
                                toast.error('Export failed');
                                setIsExporting(false);
                                setExportJobId(null);
                            } else if (data.downloadUrl) {
                                window.location.href = data.downloadUrl;
                                toast.success('Export complete! Download started.');

                                // Reset after a short delay
                                setTimeout(() => {
                                    setIsExporting(false);
                                    setExportProgress(0);
                                    setExportStatus('');
                                    setExportJobId(null);
                                }, 1500);
                            }
                        }
                    })
                    .catch(err => {
                        console.error('Progress fetch error:', err);
                    });
            };

            // Poll immediately
            pollProgress();
            // Then poll every 500ms
            exportIntervalRef.current = setInterval(pollProgress, 500);
        }

        return () => {
            if (exportIntervalRef.current) {
                clearInterval(exportIntervalRef.current);
                exportIntervalRef.current = null;
            }
        };
    }, [isExporting, exportJobId]);

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
