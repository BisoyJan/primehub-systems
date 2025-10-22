import { useState, FormEvent } from 'react';
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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Save, Calendar } from 'lucide-react';
import { PageHeader } from '@/components/PageHeader';
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

interface Maintenance {
    id: number;
    station_id: number;
    last_maintenance_date: string;
    next_due_date: string;
    maintenance_type: string | null;
    notes: string | null;
    performed_by: string | null;
    status: 'completed' | 'pending' | 'overdue';
    station: Station;
}

interface EditProps {
    maintenance: Maintenance;
    stations: Station[];
    sites: Site[];
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    [key: string]: any;
}

interface FormData {
    station_id: number;
    last_maintenance_date: string;
    next_due_date: string;
    maintenance_type: string;
    notes: string;
    performed_by: string;
    status: 'completed' | 'pending' | 'overdue';
}

export default function Edit() {
    const { maintenance, stations } = usePage<EditProps>().props;
    const [loading, setLoading] = useState(false);
    const [formData, setFormData] = useState<FormData>({
        station_id: maintenance.station_id,
        last_maintenance_date: maintenance.last_maintenance_date,
        next_due_date: maintenance.next_due_date,
        maintenance_type: maintenance.maintenance_type || '',
        notes: maintenance.notes || '',
        performed_by: maintenance.performed_by || '',
        status: maintenance.status,
    });

    const { breadcrumbs } = usePageMeta({
        title: 'Edit PC Maintenance',
        breadcrumbs: [
            { title: 'PC Maintenance', href: '/pc-maintenance' },
            { title: 'Edit', href: `/pc-maintenance/${maintenance.id}/edit` }
        ]
    });

    // Get display name for a station
    const getStationDisplay = (station: Station): string => {
        const pcNumber = station.pc_spec?.pc_number || 'No PC';
        return `${station.station_number} - ${pcNumber} (${station.site.name})`;
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();

        if (!formData.last_maintenance_date || !formData.next_due_date) {
            toast.error('Please fill in all required fields');
            return;
        }

        setLoading(true);

        router.put(`/pc-maintenance/${maintenance.id}`, {
            station_id: formData.station_id,
            last_maintenance_date: formData.last_maintenance_date,
            next_due_date: formData.next_due_date,
            maintenance_type: formData.maintenance_type,
            notes: formData.notes,
            performed_by: formData.performed_by,
            status: formData.status,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Maintenance record updated successfully');
            },
            onError: (errors) => {
                console.error('Validation errors:', errors);
                toast.error('Failed to update maintenance record. Please check the form.');
            },
            onFinish: () => setLoading(false),
        });
    };

    const handleCancel = () => {
        router.visit('/pc-maintenance');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit PC Maintenance Record" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Edit PC Maintenance Record"
                    description={`Editing maintenance record for Station ${maintenance.station.station_number}`}
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* PC Selection Section */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Calendar className="h-5 w-5" />
                                Station Information
                            </CardTitle>
                            <CardDescription>
                                Select the station for this maintenance record
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div>
                                <Label htmlFor="station_id">
                                    Station <span className="text-red-500">*</span>
                                </Label>
                                <Select
                                    value={formData.station_id.toString()}
                                    onValueChange={(value) =>
                                        setFormData({ ...formData, station_id: parseInt(value) })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {stations.map(station => (
                                            <SelectItem key={station.id} value={station.id.toString()}>
                                                {getStationDisplay(station)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Maintenance Details Section */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Maintenance Details</CardTitle>
                            <CardDescription>
                                Fill in the maintenance information
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
                        <Button type="submit" disabled={loading}>
                            <Save className="h-4 w-4 mr-2" />
                            {loading ? 'Updating...' : 'Update'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
