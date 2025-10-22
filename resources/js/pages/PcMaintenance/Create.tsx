import { useState, useMemo } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Save, Calendar } from 'lucide-react';
import { PageHeader } from '@/components/PageHeader';
import { SearchBar } from '@/components/SearchBar';
import { usePageMeta } from '@/hooks';

interface Site {
    id: number;
    name: string;
}

interface PcSpec {
    id: number;
    pc_number: string;
    model: string;
    manufacturer: string;
}

interface Station {
    id: number;
    station_number: string;
    site_id: number;
    pc_spec_id: number | null;
    site: Site;
    pc_spec: PcSpec | null;
}

interface CreateProps {
    stations: Station[];
    sites: Site[];
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    [key: string]: any;
}

interface FormData {
    last_maintenance_date: string;
    next_due_date: string;
    maintenance_type: string;
    notes: string;
    performed_by: string;
    status: 'completed' | 'pending' | 'overdue';
}

export default function Create() {
    const { stations, sites } = usePage<CreateProps>().props;
    const [loading, setLoading] = useState(false);
    const [selectedStationIds, setSelectedStationIds] = useState<number[]>([]);
    const [siteFilter, setSiteFilter] = useState<string>('all');
    const [search, setSearch] = useState('');
    const [formData, setFormData] = useState<FormData>({
        last_maintenance_date: new Date().toISOString().split('T')[0],
        next_due_date: '',
        maintenance_type: 'Routine Maintenance',
        notes: '',
        performed_by: '',
        status: 'completed',
    });

    const { breadcrumbs } = usePageMeta({
        title: 'Create PC Maintenance',
        breadcrumbs: [
            { title: 'PC Maintenance', href: '/pc-maintenance' },
            { title: 'Create', href: '/pc-maintenance/create' }
        ]
    });

    // Filter stations by site and search
    const filteredStations = useMemo(() => {
        let filtered = stations;

        // Filter by site
        if (siteFilter !== 'all') {
            filtered = filtered.filter(station =>
                station.site_id === parseInt(siteFilter)
            );
        }

        // Filter by search (Station number or PC number)
        if (search) {
            filtered = filtered.filter(station =>
                station.station_number.toLowerCase().includes(search.toLowerCase()) ||
                (station.pc_spec?.pc_number && station.pc_spec.pc_number.toLowerCase().includes(search.toLowerCase()))
            );
        }

        return filtered;
    }, [stations, siteFilter, search]);

    // Toggle station selection
    const toggleStationSelection = (stationId: number) => {
        setSelectedStationIds(prev =>
            prev.includes(stationId)
                ? prev.filter(id => id !== stationId)
                : [...prev, stationId]
        );
    };

    // Select/Deselect all filtered stations
    const toggleSelectAll = () => {
        const filteredIds = filteredStations.map(station => station.id);
        const allSelected = filteredIds.every(id => selectedStationIds.includes(id));

        if (allSelected) {
            setSelectedStationIds(prev => prev.filter(id => !filteredIds.includes(id)));
        } else {
            setSelectedStationIds(prev => [...new Set([...prev, ...filteredIds])]);
        }
    };

    const isAllFilteredSelected = useMemo(() => {
        if (filteredStations.length === 0) return false;
        return filteredStations.every(station => selectedStationIds.includes(station.id));
    }, [filteredStations, selectedStationIds]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (selectedStationIds.length === 0) {
            toast.error('Please select at least one station');
            return;
        }

        if (!formData.last_maintenance_date || !formData.next_due_date) {
            toast.error('Please fill in all required fields');
            return;
        }

        setLoading(true);

        router.post('/pc-maintenance', {
            station_ids: selectedStationIds,
            ...formData,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`${selectedStationIds.length} maintenance record(s) created successfully`);
            },
            onError: (errors) => {
                console.error('Validation errors:', errors);
                toast.error('Failed to create maintenance records. Please check the form.');
            },
            onFinish: () => setLoading(false),
        });
    };

    const handleCancel = () => {
        router.visit('/pc-maintenance');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create PC Maintenance Record" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Create PC Maintenance Record"
                    description="Select one or multiple stations and fill in maintenance details"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* PC Selection Section */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Calendar className="h-5 w-5" />
                                Select Stations for Maintenance
                            </CardTitle>
                            <CardDescription>
                                {selectedStationIds.length > 0
                                    ? `${selectedStationIds.length} station(s) selected`
                                    : 'Filter and select stations that received maintenance'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Filters */}
                            <div className="flex flex-col sm:flex-row gap-3">
                                <div className="flex-1">
                                    <Label htmlFor="search">Search Station/PC</Label>
                                    <SearchBar
                                        value={search}
                                        onChange={setSearch}
                                        onSubmit={(e) => e.preventDefault()}
                                        placeholder="Search by station number or PC number..."
                                    />
                                </div>
                                <div className="w-full sm:w-64">
                                    <Label htmlFor="site">Filter by Site</Label>
                                    <Select value={siteFilter} onValueChange={setSiteFilter}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="All sites" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All sites</SelectItem>
                                            {sites.map(site => (
                                                <SelectItem key={site.id} value={site.id.toString()}>
                                                    {site.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            {/* Station Selection Table */}
                            <div className="border rounded-lg max-h-96 overflow-auto">
                                <Table>
                                    <TableHeader className="sticky top-0 bg-background z-10">
                                        <TableRow>
                                            <TableHead className="w-12">
                                                <Checkbox
                                                    checked={isAllFilteredSelected}
                                                    onCheckedChange={toggleSelectAll}
                                                    disabled={filteredStations.length === 0}
                                                />
                                            </TableHead>
                                            <TableHead>Station Number</TableHead>
                                            <TableHead>PC Number</TableHead>
                                            <TableHead>Model</TableHead>
                                            <TableHead>Site</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredStations.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="text-center py-8">
                                                    <p className="text-muted-foreground">
                                                        No stations found matching your filters
                                                    </p>
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            filteredStations.map((station) => (
                                                <TableRow
                                                    key={station.id}
                                                    className={selectedStationIds.includes(station.id) ? 'bg-muted/50' : ''}
                                                >
                                                    <TableCell>
                                                        <Checkbox
                                                            checked={selectedStationIds.includes(station.id)}
                                                            onCheckedChange={() => toggleStationSelection(station.id)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="font-medium">{station.station_number}</TableCell>
                                                    <TableCell className="text-muted-foreground">{station.pc_spec?.pc_number || 'N/A'}</TableCell>
                                                    <TableCell>{station.pc_spec?.model || 'N/A'}</TableCell>
                                                    <TableCell>{station.site.name}</TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Maintenance Details Section */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Maintenance Details</CardTitle>
                            <CardDescription>
                                Fill in the maintenance information (applies to all selected stations)
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="last_maintenance_date">
                                        Last Maintenance Date <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="last_maintenance_date"
                                        type="date"
                                        value={formData.last_maintenance_date}
                                        onChange={(e) => setFormData({ ...formData, last_maintenance_date: e.target.value })}
                                        required
                                        max={new Date().toISOString().split('T')[0]}
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="next_due_date">
                                        Next Due Date <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="next_due_date"
                                        type="date"
                                        value={formData.next_due_date}
                                        onChange={(e) => setFormData({ ...formData, next_due_date: e.target.value })}
                                        required
                                        min={formData.last_maintenance_date}
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="maintenance_type">Maintenance Type</Label>
                                    <Input
                                        id="maintenance_type"
                                        type="text"
                                        value={formData.maintenance_type}
                                        onChange={(e) => setFormData({ ...formData, maintenance_type: e.target.value })}
                                        placeholder="e.g., Routine Maintenance, Deep Cleaning, Hardware Check"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="performed_by">Performed By</Label>
                                    <Input
                                        id="performed_by"
                                        type="text"
                                        value={formData.performed_by}
                                        onChange={(e) => setFormData({ ...formData, performed_by: e.target.value })}
                                        placeholder="Name of technician"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="status">Status</Label>
                                    <Select
                                        value={formData.status}
                                        onValueChange={(value: 'completed' | 'pending' | 'overdue') =>
                                            setFormData({ ...formData, status: value })
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="completed">Completed</SelectItem>
                                            <SelectItem value="pending">Pending</SelectItem>
                                            <SelectItem value="overdue">Overdue</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={formData.notes}
                                    onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                                    placeholder="Additional notes about the maintenance..."
                                    rows={4}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Action Buttons */}
                    <div className="flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleCancel}
                            disabled={loading}
                        >
                            <ArrowLeft className="h-4 w-4 mr-2" />
                            Cancel
                        </Button>
                        <Button type="submit" disabled={loading || selectedStationIds.length === 0}>
                            <Save className="h-4 w-4 mr-2" />
                            {loading ? 'Creating...' : `Create ${selectedStationIds.length > 0 ? `(${selectedStationIds.length})` : ''}`}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
