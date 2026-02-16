import { Head, usePage } from '@inertiajs/react';
import React, { useState, useMemo, useEffect } from 'react';
import { Download, Loader2, FileSpreadsheet, Check, ChevronsUpDown, AlertCircle } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { DatePicker } from '@/components/ui/date-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Can } from '@/components/authorization';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Progress } from '@/components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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

interface PointsUser {
    id: number;
    name: string;
}

interface PointType {
    value: string;
    label: string;
}

interface PointStatus {
    value: string;
    label: string;
}

interface PageProps {
    users: User[];
    sites: Site[];
    campaigns: Campaign[];
    pointsUsers: PointsUser[];
    pointTypes: PointType[];
    pointStatuses: PointStatus[];
    [key: string]: unknown;
}

export default function Export() {
    const { users, sites, campaigns, pointsUsers, pointTypes, pointStatuses } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Export Attendance Records',
        breadcrumbs: [
            { title: 'Attendance', href: attendanceIndex().url },
            { title: 'Export Records', href: biometricExportIndex().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    // Attendance Records Export State
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

    // Attendance Points Export State
    const [pointsStartDate, setPointsStartDate] = useState('');
    const [pointsEndDate, setPointsEndDate] = useState('');
    const [selectedPointsUsers, setSelectedPointsUsers] = useState<number[]>([]);
    const [selectedPointType, setSelectedPointType] = useState<string>('');
    const [selectedPointStatus, setSelectedPointStatus] = useState<string>('');
    const [isPointsExporting, setIsPointsExporting] = useState(false);
    const [pointsExportProgress, setPointsExportProgress] = useState(0);
    const [pointsExportStatus, setPointsExportStatus] = useState<string>('');
    const [pointsExportJobId, setPointsExportJobId] = useState<string | null>(null);
    const pointsExportIntervalRef = React.useRef<NodeJS.Timeout | null>(null);
    const pointsDownloadStartedRef = React.useRef<boolean>(false);
    const [isPointsEmployeePopoverOpen, setIsPointsEmployeePopoverOpen] = useState(false);
    const [pointsEmployeeSearchQuery, setPointsEmployeeSearchQuery] = useState('');

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
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            toast.error('CSRF token not found');
            return;
        }

        setIsExporting(true);
        setExportProgress(0);
        setExportStatus('Starting export...');

        // Build request body
        const body: Record<string, unknown> = {
            user_ids: selectedUsers,
            site_ids: selectedSites,
            campaign_ids: selectedCampaigns,
        };
        if (startDate) {
            body.start_date = startDate;
        }
        if (endDate) {
            body.end_date = endDate;
        }

        fetch('/biometric-export/start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        })
            .then(async res => {
                const data = await res.json();
                if (!res.ok) {
                    // Handle validation error (no records found)
                    if (data.error && data.message) {
                        throw new Error(data.message);
                    }
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                return data;
            })
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
                if (err.message.includes('419')) {
                    toast.error('Session expired. Please refresh the page and try again.');
                } else if (err.message.includes('401') || err.message.includes('403')) {
                    toast.error('You are not authorized to perform this action. Please login again.');
                } else if (err.message.includes('No attendance records')) {
                    toast.warning(err.message);
                } else {
                    toast.error('Failed to start export. Please try again.');
                }
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
                fetch(`/biometric-export/progress/${exportJobId}`, {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                    },
                })
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
                                // Clear interval immediately to prevent race condition
                                if (exportIntervalRef.current) {
                                    clearInterval(exportIntervalRef.current);
                                    exportIntervalRef.current = null;
                                }

                                // Store the download URL
                                const downloadUrl = data.downloadUrl;

                                // Use fetch to download with proper error handling
                                fetch(downloadUrl, {
                                    method: 'GET',
                                    credentials: 'same-origin',
                                })
                                    .then(response => {
                                        if (!response.ok) {
                                            throw new Error('Download failed');
                                        }
                                        return response.blob();
                                    })
                                    .then(blob => {
                                        const url = window.URL.createObjectURL(blob);
                                        const a = document.createElement('a');
                                        a.href = url;
                                        a.download = `attendance-export-${new Date().toISOString().split('T')[0]}.xlsx`;
                                        document.body.appendChild(a);
                                        a.click();
                                        window.URL.revokeObjectURL(url);
                                        document.body.removeChild(a);
                                        toast.success('Export complete! Download started.');
                                    })
                                    .catch(() => {
                                        toast.error('Download failed. Please try generating a new export.');
                                    })
                                    .finally(() => {
                                        setIsExporting(false);
                                        setExportProgress(0);
                                        setExportStatus('');
                                        setExportJobId(null);
                                    });
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

    // ========== ATTENDANCE POINTS EXPORT LOGIC ==========

    // Filter points users based on search query
    const filteredPointsUsers = useMemo(() => {
        if (!pointsUsers) return [];
        if (!pointsEmployeeSearchQuery) return pointsUsers;
        return pointsUsers.filter(user =>
            user.name.toLowerCase().includes(pointsEmployeeSearchQuery.toLowerCase())
        );
    }, [pointsUsers, pointsEmployeeSearchQuery]);

    // Toggle points user selection
    const togglePointsUser = (userId: number) => {
        setSelectedPointsUsers(prev =>
            prev.includes(userId)
                ? prev.filter(id => id !== userId)
                : [...prev, userId]
        );
    };

    // Select all points users
    const selectAllPointsUsers = () => {
        setSelectedPointsUsers(
            selectedPointsUsers.length === pointsUsers.length ? [] : pointsUsers.map(u => u.id)
        );
    };

    // Clear points filters
    const clearPointsFilters = () => {
        setSelectedPointsUsers([]);
        setSelectedPointType('');
        setSelectedPointStatus('');
    };

    const showClearPointsFilters = selectedPointsUsers.length > 0 || selectedPointType !== '' || selectedPointStatus !== '';

    // Get display text for selected points employees
    const selectedPointsEmployeesText = useMemo(() => {
        if (selectedPointsUsers.length === 0) return 'All Employees';
        if (selectedPointsUsers.length === 1) {
            const user = pointsUsers.find(u => u.id === selectedPointsUsers[0]);
            return user?.name || '1 selected';
        }
        return `${selectedPointsUsers.length} employees selected`;
    }, [selectedPointsUsers, pointsUsers]);

    // Handle attendance points export
    const handlePointsExport = () => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            toast.error('CSRF token not found');
            return;
        }

        setIsPointsExporting(true);
        setPointsExportProgress(0);
        setPointsExportStatus('Starting export...');

        // Build request body
        const body: Record<string, unknown> = {};
        if (selectedPointsUsers.length === 1) {
            body.user_id = selectedPointsUsers[0];
        }
        if (pointsStartDate) {
            body.date_from = pointsStartDate;
        }
        if (pointsEndDate) {
            body.date_to = pointsEndDate;
        }
        if (selectedPointType && selectedPointType !== 'all') {
            body.point_type = selectedPointType;
        }
        if (selectedPointStatus && selectedPointStatus !== 'all') {
            body.status = selectedPointStatus;
        }

        fetch('/attendance-points/start-export-all-excel', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        })
            .then(async res => {
                // Check response status before parsing JSON
                if (!res.ok) {
                    // Handle specific HTTP status codes
                    if (res.status === 419) {
                        throw new Error('Session expired. Please refresh the page and try again.');
                    }
                    if (res.status === 401 || res.status === 403) {
                        throw new Error('You are not authorized to perform this action.');
                    }
                    // Try to parse error message from JSON response
                    try {
                        const errorData = await res.json();
                        if (errorData.message) {
                            throw new Error(errorData.message);
                        }
                    } catch {
                        // If JSON parsing fails, throw generic error
                    }
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                return res.json();
            })
            .then(data => {
                if (data.jobId) {
                    setPointsExportJobId(data.jobId);
                } else {
                    toast.error('Failed to start export');
                    setIsPointsExporting(false);
                }
            })
            .catch(err => {
                console.error('Points export start error:', err);
                if (err.message.includes('No attendance points')) {
                    toast.warning(err.message);
                } else {
                    toast.error(err.message || 'Failed to start export. Please try again.');
                }
                setIsPointsExporting(false);
            });
    };

    // Poll for points export progress
    useEffect(() => {
        if (isPointsExporting && pointsExportJobId) {
            // Reset download started flag
            pointsDownloadStartedRef.current = false;

            if (pointsExportIntervalRef.current) {
                clearInterval(pointsExportIntervalRef.current);
                pointsExportIntervalRef.current = null;
            }

            const pollProgress = () => {
                // Skip if download already started
                if (pointsDownloadStartedRef.current) return;

                fetch(`/attendance-points/export-all-excel/status/${pointsExportJobId}`, {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                    },
                })
                    .then(res => {
                        // Skip if download already started
                        if (pointsDownloadStartedRef.current) return null;
                        if (!res.ok) throw new Error('Failed to fetch progress');
                        return res.json();
                    })
                    .then(data => {
                        // Skip if download already started or no data
                        if (pointsDownloadStartedRef.current || !data) return;

                        setPointsExportProgress(data.percent || 0);
                        setPointsExportStatus(data.status || 'Processing...');

                        if (data.finished) {
                            if (pointsExportIntervalRef.current) {
                                clearInterval(pointsExportIntervalRef.current);
                                pointsExportIntervalRef.current = null;
                            }

                            // Check for downloadUrl first - if it exists, export succeeded
                            if (data.downloadUrl) {
                                // Mark download as started immediately
                                pointsDownloadStartedRef.current = true;

                                // Clear interval
                                if (pointsExportIntervalRef.current) {
                                    clearInterval(pointsExportIntervalRef.current);
                                    pointsExportIntervalRef.current = null;
                                }

                                // Store the download URL and filename
                                const downloadUrl = data.downloadUrl;
                                const filename = data.filename || `attendance-points-export-${new Date().toISOString().split('T')[0]}.xlsx`;

                                // Use fetch to download with proper error handling
                                fetch(downloadUrl, {
                                    method: 'GET',
                                    credentials: 'same-origin',
                                })
                                    .then(response => {
                                        if (!response.ok) {
                                            throw new Error('Download failed');
                                        }
                                        return response.blob();
                                    })
                                    .then(blob => {
                                        const url = window.URL.createObjectURL(blob);
                                        const a = document.createElement('a');
                                        a.href = url;
                                        a.download = filename;
                                        document.body.appendChild(a);
                                        a.click();
                                        window.URL.revokeObjectURL(url);
                                        document.body.removeChild(a);
                                        toast.success('Export complete! Download started.');
                                    })
                                    .catch(() => {
                                        toast.error('Download failed. Please try generating a new export.');
                                    })
                                    .finally(() => {
                                        setIsPointsExporting(false);
                                        setPointsExportProgress(0);
                                        setPointsExportStatus('');
                                        setPointsExportJobId(null);
                                    });
                            } else if (data.error) {
                                // Only show error if there's no downloadUrl
                                toast.error(data.status || 'Export failed');
                                setIsPointsExporting(false);
                                setPointsExportJobId(null);
                            }
                        }
                    })
                    .catch(err => {
                        // Only log if download hasn't started
                        if (!pointsDownloadStartedRef.current) {
                            console.error('Points progress fetch error:', err);
                        }
                    });
            };

            // Poll immediately
            pollProgress();
            // Then poll every 500ms
            pointsExportIntervalRef.current = setInterval(pollProgress, 500);
        }

        return () => {
            if (pointsExportIntervalRef.current) {
                clearInterval(pointsExportIntervalRef.current);
                pointsExportIntervalRef.current = null;
            }
        };
    }, [isPointsExporting, pointsExportJobId]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isPageLoading} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Export Records"
                    description="Export attendance records and attendance points to Excel format"
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
                                <Label className="text-sm font-medium">Date Range (Optional)</Label>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div className="flex items-center gap-2 text-sm">
                                        <span className="text-muted-foreground text-xs whitespace-nowrap">From:</span>
                                        <DatePicker
                                            value={startDate}
                                            onChange={(value) => setStartDate(value)}
                                            placeholder="Start date"
                                            className="w-full"
                                        />
                                    </div>
                                    <div className="flex items-center gap-2 text-sm">
                                        <span className="text-muted-foreground text-xs whitespace-nowrap">To:</span>
                                        <DatePicker
                                            value={endDate}
                                            onChange={(value) => setEndDate(value)}
                                            placeholder="End date"
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
                                            disabled={isExporting}
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

                    {/* Attendance Points Export Card */}
                    <Card className="mt-6">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <AlertCircle className="h-5 w-5" />
                                Export Attendance Points
                            </CardTitle>
                            <CardDescription>
                                Export attendance points/violations to Excel format with filters
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <Alert>
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>
                                    <strong>Export includes:</strong> Employee details, Shift Date, Point Type, Points Value, Status (Active/Excused/Expired), Violation Details, Expiration Date, and GBRO Eligibility.
                                </AlertDescription>
                            </Alert>

                            {/* Date Range Selection */}
                            <div className="flex flex-col gap-3">
                                <Label className="text-sm font-medium">Date Range (Optional)</Label>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div className="flex items-center gap-2 text-sm">
                                        <span className="text-muted-foreground text-xs whitespace-nowrap">From:</span>
                                        <DatePicker
                                            value={pointsStartDate}
                                            onChange={(value) => setPointsStartDate(value)}
                                            placeholder="Start date"
                                            className="w-full"
                                        />
                                    </div>
                                    <div className="flex items-center gap-2 text-sm">
                                        <span className="text-muted-foreground text-xs whitespace-nowrap">To:</span>
                                        <DatePicker
                                            value={pointsEndDate}
                                            onChange={(value) => setPointsEndDate(value)}
                                            placeholder="End date"
                                            className="w-full"
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Filters */}
                            <div className="flex flex-col gap-3">
                                <Label className="text-sm font-medium">Filters (Optional)</Label>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    {/* Employee Filter */}
                                    <Popover open={isPointsEmployeePopoverOpen} onOpenChange={setIsPointsEmployeePopoverOpen}>
                                        <PopoverTrigger asChild>
                                            <Button
                                                variant="outline"
                                                role="combobox"
                                                aria-expanded={isPointsEmployeePopoverOpen}
                                                className="w-full justify-between font-normal"
                                            >
                                                <span className="truncate">{selectedPointsEmployeesText}</span>
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-full p-0" align="start">
                                            <Command shouldFilter={false}>
                                                <CommandInput
                                                    placeholder="Search employee..."
                                                    value={pointsEmployeeSearchQuery}
                                                    onValueChange={setPointsEmployeeSearchQuery}
                                                />
                                                <CommandList>
                                                    <CommandEmpty>No employee found.</CommandEmpty>
                                                    <CommandGroup>
                                                        <CommandItem
                                                            onSelect={selectAllPointsUsers}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${selectedPointsUsers.length === pointsUsers.length && pointsUsers.length > 0 ? 'opacity-100' : 'opacity-0'}`}
                                                            />
                                                            {selectedPointsUsers.length === pointsUsers.length && pointsUsers.length > 0 ? 'Deselect All' : `Select All (${pointsUsers.length})`}
                                                        </CommandItem>
                                                        {filteredPointsUsers.map((user) => (
                                                            <CommandItem
                                                                key={user.id}
                                                                value={user.name}
                                                                onSelect={() => togglePointsUser(user.id)}
                                                                className="cursor-pointer"
                                                            >
                                                                <Check
                                                                    className={`mr-2 h-4 w-4 ${selectedPointsUsers.includes(user.id) ? 'opacity-100' : 'opacity-0'}`}
                                                                />
                                                                {user.name}
                                                            </CommandItem>
                                                        ))}
                                                    </CommandGroup>
                                                </CommandList>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>

                                    {/* Point Type Filter */}
                                    <Select value={selectedPointType} onValueChange={setSelectedPointType}>
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="All Point Types" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Point Types</SelectItem>
                                            {pointTypes.map((type) => (
                                                <SelectItem key={type.value} value={type.value}>
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>

                                    {/* Status Filter */}
                                    <Select value={selectedPointStatus} onValueChange={setSelectedPointStatus}>
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="All Statuses" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Statuses</SelectItem>
                                            {pointStatuses.map((status) => (
                                                <SelectItem key={status.value} value={status.value}>
                                                    {status.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            {/* Selected Filters Summary */}
                            {showClearPointsFilters && (
                                <div className="flex flex-wrap gap-2 items-center">
                                    <span className="text-sm text-muted-foreground">Selected filters:</span>
                                    {selectedPointsUsers.length > 0 && (
                                        <Badge variant="secondary" className="font-normal">
                                            {selectedPointsUsers.length} employee{selectedPointsUsers.length === 1 ? '' : 's'}
                                        </Badge>
                                    )}
                                    {selectedPointType && selectedPointType !== 'all' && (
                                        <Badge variant="secondary" className="font-normal">
                                            {pointTypes.find(t => t.value === selectedPointType)?.label || selectedPointType}
                                        </Badge>
                                    )}
                                    {selectedPointStatus && selectedPointStatus !== 'all' && (
                                        <Badge variant="secondary" className="font-normal">
                                            {pointStatuses.find(s => s.value === selectedPointStatus)?.label || selectedPointStatus}
                                        </Badge>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={clearPointsFilters}
                                        className="h-6 px-2 text-xs"
                                    >
                                        Clear filters
                                    </Button>
                                </div>
                            )}

                            {/* Export Button */}
                            <div className="flex flex-col gap-3 border-t pt-4">
                                {isPointsExporting && (
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="text-muted-foreground">{pointsExportStatus}</span>
                                            <span className="font-medium">{pointsExportProgress}%</span>
                                        </div>
                                        <Progress value={pointsExportProgress} className="h-2" />
                                    </div>
                                )}
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                                    <Button
                                        onClick={handlePointsExport}
                                        disabled={isPointsExporting}
                                        className="w-full sm:w-auto"
                                    >
                                        {isPointsExporting ? (
                                            <>
                                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                Exporting...
                                            </>
                                        ) : (
                                            <>
                                                <Download className="mr-2 h-4 w-4" />
                                                Export Points to Excel
                                            </>
                                        )}
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
