import { useState, FormEvent } from 'react';
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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Save, Calendar } from 'lucide-react';
import { PageHeader } from '@/components/PageHeader';
import { usePageMeta } from '@/hooks';
import {
    index as pcMaintenanceIndexRoute,
    edit as pcMaintenanceEditRoute,
    update as pcMaintenanceUpdateRoute,
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

interface PcSpec {
    id: number;
    pc_number: string;
    model: string;
    manufacturer: string;
}

interface PcSpecItem {
    id: number;
    pc_number: string;
    model: string;
    manufacturer: string;
    current_station: CurrentStation | null;
}

interface Maintenance {
    id: number;
    pc_spec_id: number;
    last_maintenance_date: string;
    next_due_date: string;
    maintenance_type: string | null;
    notes: string | null;
    performed_by: string | null;
    status: 'completed' | 'pending' | 'overdue';
    pc_spec: PcSpec;
    current_station: CurrentStation | null;
}

interface EditProps {
    maintenance: Maintenance;
    pcSpecs: PcSpecItem[];
}

interface FormData {
    pc_spec_id: number;
    last_maintenance_date: string;
    next_due_date: string;
    maintenance_type: string;
    notes: string;
    performed_by: string;
    status: 'completed' | 'pending' | 'overdue';
}

export default function Edit({ maintenance, pcSpecs }: EditProps) {
    const [loading, setLoading] = useState(false);
    const [formData, setFormData] = useState<FormData>({
        pc_spec_id: maintenance.pc_spec_id,
        last_maintenance_date: maintenance.last_maintenance_date,
        next_due_date: maintenance.next_due_date,
        maintenance_type: maintenance.maintenance_type || '',
        notes: maintenance.notes || '',
        performed_by: maintenance.performed_by || '',
        status: maintenance.status,
    });

    const { title, breadcrumbs } = usePageMeta({
        title: 'Edit PC Maintenance',
        breadcrumbs: [
            { title: 'PC Specs', href: pcSpecsIndexRoute().url },
            { title: 'PC Maintenance', href: pcMaintenanceIndexRoute().url },
            { title: 'Edit', href: pcMaintenanceEditRoute(maintenance.id).url }
        ]
    });

    // Get display name for a PC spec
    const getPcSpecDisplay = (pcSpec: PcSpecItem): string => {
        const stationInfo = pcSpec.current_station
            ? `${pcSpec.current_station.station_number} (${pcSpec.current_station.site?.name || 'N/A'})`
            : 'Not assigned';
        return `${pcSpec.pc_number} - ${pcSpec.model} | Station: ${stationInfo}`;
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();

        if (!formData.last_maintenance_date || !formData.next_due_date) {
            toast.error('Please fill in all required fields');
            return;
        }

        setLoading(true);

        router.put(pcMaintenanceUpdateRoute(maintenance.id).url, {
            pc_spec_id: formData.pc_spec_id,
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
        router.visit(pcMaintenanceIndexRoute().url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Edit PC Maintenance Record"
                    description={`Editing maintenance record for PC ${maintenance.pc_spec.pc_number}`}
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* PC Selection Section */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Calendar className="h-5 w-5" />
                                PC Information
                            </CardTitle>
                            <CardDescription>
                                Select the PC for this maintenance record
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div>
                                <Label htmlFor="pc_spec_id">
                                    PC <span className="text-red-500">*</span>
                                </Label>
                                <Select
                                    value={formData.pc_spec_id.toString()}
                                    onValueChange={(value) =>
                                        setFormData({ ...formData, pc_spec_id: parseInt(value) })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {pcSpecs.map(pcSpec => (
                                            <SelectItem key={pcSpec.id} value={pcSpec.id.toString()}>
                                                {getPcSpecDisplay(pcSpec)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Display current station info */}
                            {maintenance.current_station && (
                                <div className="mt-4 p-3 bg-muted rounded-md">
                                    <p className="text-sm text-muted-foreground">
                                        <span className="font-medium">Current Station:</span>{' '}
                                        {maintenance.current_station.station_number}
                                        {maintenance.current_station.site && (
                                            <> ({maintenance.current_station.site.name})</>
                                        )}
                                    </p>
                                </div>
                            )}
                            {!maintenance.current_station && (
                                <div className="mt-4 p-3 bg-muted rounded-md">
                                    <p className="text-sm text-muted-foreground italic">
                                        This PC is not currently assigned to any station.
                                    </p>
                                </div>
                            )}
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
                                    <DatePicker
                                        value={formData.last_maintenance_date}
                                        onChange={(value) => setFormData({ ...formData, last_maintenance_date: value })}
                                        placeholder="Select last maintenance date"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="next_due_date">
                                        Next Due Date <span className="text-red-500">*</span>
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
