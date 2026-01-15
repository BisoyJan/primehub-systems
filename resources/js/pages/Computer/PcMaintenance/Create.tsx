import { useState, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { DatePicker } from '@/components/ui/date-picker';
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
import {
    index as pcMaintenanceIndexRoute,
    create as pcMaintenanceCreateRoute,
    store as pcMaintenanceStoreRoute,
} from '@/routes/pc-maintenance';
import { index as pcSpecsIndexRoute } from '@/routes/pcspecs';

interface Site {
    id: number;
    name: string;
}

interface CurrentStation {
    id: number;
    station_number: string;
    site: Site | null;
}

interface PcSpecItem {
    id: number;
    pc_number: string;
    model: string;
    manufacturer: string;
    current_station: CurrentStation | null;
}

interface CreateProps {
    pcSpecs: PcSpecItem[];
    sites: Site[];
}

interface FormData {
    last_maintenance_date: string;
    next_due_date: string;
    maintenance_type: string;
    notes: string;
    performed_by: string;
    status: 'completed' | 'pending' | 'overdue';
}

// Helper function to add months to a date
const addMonths = (dateString: string, months: number): string => {
    const date = new Date(dateString);
    date.setMonth(date.getMonth() + months);
    return date.toISOString().split('T')[0];
};

export default function Create({ pcSpecs, sites }: CreateProps) {
    const [loading, setLoading] = useState(false);
    const [selectedPcSpecIds, setSelectedPcSpecIds] = useState<number[]>([]);
    const [siteFilter, setSiteFilter] = useState<string>('all');
    const [assignmentFilter, setAssignmentFilter] = useState<string>('all');
    const [search, setSearch] = useState('');

    const today = new Date().toISOString().split('T')[0];
    const [formData, setFormData] = useState<FormData>({
        last_maintenance_date: today,
        next_due_date: addMonths(today, 4),
        maintenance_type: 'Routine Maintenance',
        notes: '',
        performed_by: '',
        status: 'completed',
    });

    // Auto-update next due date when last maintenance date changes
    const handleLastMaintenanceDateChange = (value: string) => {
        setFormData({
            ...formData,
            last_maintenance_date: value,
            next_due_date: addMonths(value, 4),
        });
    };

    const { title, breadcrumbs } = usePageMeta({
        title: 'Create PC Maintenance',
        breadcrumbs: [
            { title: 'PC Specs', href: pcSpecsIndexRoute().url },
            { title: 'PC Maintenance', href: pcMaintenanceIndexRoute().url },
            { title: 'Create', href: pcMaintenanceCreateRoute().url }
        ]
    });

    // Filter PC specs by site, assignment status, and search
    const filteredPcSpecs = useMemo(() => {
        let filtered = pcSpecs;

        // Filter by assignment status
        if (assignmentFilter === 'assigned') {
            filtered = filtered.filter(pcSpec => pcSpec.current_station !== null);
        } else if (assignmentFilter === 'not_assigned') {
            filtered = filtered.filter(pcSpec => pcSpec.current_station === null);
        }

        // Filter by site (via current_station.site)
        if (siteFilter !== 'all') {
            filtered = filtered.filter(pcSpec =>
                pcSpec.current_station?.site?.id === parseInt(siteFilter)
            );
        }

        // Filter by search (PC number or model)
        if (search) {
            filtered = filtered.filter(pcSpec =>
                pcSpec.pc_number.toLowerCase().includes(search.toLowerCase()) ||
                pcSpec.model.toLowerCase().includes(search.toLowerCase())
            );
        }

        return filtered;
    }, [pcSpecs, siteFilter, assignmentFilter, search]);

    // Toggle PC spec selection
    const togglePcSpecSelection = (pcSpecId: number) => {
        setSelectedPcSpecIds(prev =>
            prev.includes(pcSpecId)
                ? prev.filter(id => id !== pcSpecId)
                : [...prev, pcSpecId]
        );
    };

    // Select/Deselect all filtered PC specs
    const toggleSelectAll = () => {
        const filteredIds = filteredPcSpecs.map(pcSpec => pcSpec.id);
        const allSelected = filteredIds.every(id => selectedPcSpecIds.includes(id));

        if (allSelected) {
            setSelectedPcSpecIds(prev => prev.filter(id => !filteredIds.includes(id)));
        } else {
            setSelectedPcSpecIds(prev => [...new Set([...prev, ...filteredIds])]);
        }
    };

    const isAllFilteredSelected = useMemo(() => {
        if (filteredPcSpecs.length === 0) return false;
        return filteredPcSpecs.every(pcSpec => selectedPcSpecIds.includes(pcSpec.id));
    }, [filteredPcSpecs, selectedPcSpecIds]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (selectedPcSpecIds.length === 0) {
            toast.error('Please select at least one PC');
            return;
        }

        if (!formData.last_maintenance_date || !formData.next_due_date) {
            toast.error('Please fill in all required fields');
            return;
        }

        setLoading(true);

        router.post(pcMaintenanceStoreRoute().url, {
            pc_spec_ids: selectedPcSpecIds,
            ...formData,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`${selectedPcSpecIds.length} maintenance record(s) created successfully`);
            },
            onError: (errors) => {
                console.error('Validation errors:', errors);
                toast.error('Failed to create maintenance records. Please check the form.');
            },
            onFinish: () => setLoading(false),
        });
    };

    const handleCancel = () => {
        router.visit(pcMaintenanceIndexRoute().url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Create PC Maintenance Record"
                    description="Select one or multiple PCs and fill in maintenance details"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* PC Selection Section */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Calendar className="h-5 w-5" />
                                Select PCs for Maintenance
                            </CardTitle>
                            <CardDescription>
                                {selectedPcSpecIds.length > 0
                                    ? `${selectedPcSpecIds.length} PC(s) selected`
                                    : 'Filter and select PCs that received maintenance'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Filters */}
                            <div className="flex flex-col sm:flex-row gap-3">
                                <div className="flex-1">
                                    <Label htmlFor="search">Search PC</Label>
                                    <SearchBar
                                        value={search}
                                        onChange={setSearch}
                                        onSubmit={(e) => e.preventDefault()}
                                        placeholder="Search by PC number or model..."
                                    />
                                </div>
                                <div className="w-full sm:w-48">
                                    <Label htmlFor="assignment">Assignment Status</Label>
                                    <Select value={assignmentFilter} onValueChange={setAssignmentFilter}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="All PCs" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All PCs</SelectItem>
                                            <SelectItem value="assigned">Assigned</SelectItem>
                                            <SelectItem value="not_assigned">Not Assigned</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="w-full sm:w-48">
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

                            {/* PC Selection Table */}
                            <div className="border rounded-lg max-h-96 overflow-auto">
                                <Table>
                                    <TableHeader className="sticky top-0 bg-background z-10">
                                        <TableRow>
                                            <TableHead className="w-12">
                                                <Checkbox
                                                    checked={isAllFilteredSelected}
                                                    onCheckedChange={toggleSelectAll}
                                                    disabled={filteredPcSpecs.length === 0}
                                                />
                                            </TableHead>
                                            <TableHead>PC Number</TableHead>
                                            <TableHead>Model</TableHead>
                                            <TableHead>Current Station</TableHead>
                                            <TableHead>Site</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredPcSpecs.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="text-center py-8">
                                                    <p className="text-muted-foreground">
                                                        No PCs found matching your filters
                                                    </p>
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            filteredPcSpecs.map((pcSpec) => (
                                                <TableRow
                                                    key={pcSpec.id}
                                                    className={selectedPcSpecIds.includes(pcSpec.id) ? 'bg-muted/50' : ''}
                                                >
                                                    <TableCell>
                                                        <Checkbox
                                                            checked={selectedPcSpecIds.includes(pcSpec.id)}
                                                            onCheckedChange={() => togglePcSpecSelection(pcSpec.id)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="font-medium">{pcSpec.pc_number}</TableCell>
                                                    <TableCell>{pcSpec.model}</TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {pcSpec.current_station?.station_number || (
                                                            <span className="italic">Not assigned</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {pcSpec.current_station?.site?.name || '-'}
                                                    </TableCell>
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
                                Fill in the maintenance information (applies to all selected PCs)
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="last_maintenance_date">
                                        Last Maintenance Date <span className="text-red-500">*</span>
                                    </Label>
                                    <DatePicker
                                        value={formData.last_maintenance_date}
                                        onChange={(value) => handleLastMaintenanceDateChange(value)}
                                        placeholder="Select last maintenance date"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="next_due_date">
                                        Next Due Date <span className="text-red-500">*</span>
                                        <span className="text-xs text-muted-foreground ml-2">(Auto: 4 months ahead)</span>
                                    </Label>
                                    <DatePicker
                                        value={formData.next_due_date}
                                        onChange={(value) => setFormData({ ...formData, next_due_date: value })}
                                        placeholder="Select next due date"
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
                        <Button type="submit" disabled={loading || selectedPcSpecIds.length === 0}>
                            <Save className="h-4 w-4 mr-2" />
                            {loading ? 'Creating...' : `Create ${selectedPcSpecIds.length > 0 ? `(${selectedPcSpecIds.length})` : ''}`}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
