import { Head, usePage, useForm, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import { Can } from '@/components/authorization';
import {
    store as storeRoute,
    update as updateRoute,
    destroy as destroyRoute,
    toggle as toggleRoute,
} from '@/routes/break-timer/policies';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { DeleteConfirmDialog } from '@/components/DeleteConfirmDialog';
import { useState, FormEvent } from 'react';

interface BreakPolicyData {
    id: number;
    name: string;
    max_breaks: number;
    break_duration_minutes: number;
    max_lunch: number;
    lunch_duration_minutes: number;
    grace_period_seconds: number;
    allowed_pause_reasons: string[] | null;
    is_active: boolean;
    shift_reset_time: string;
}

interface PageProps extends Record<string, unknown> {
    policies: BreakPolicyData[];
}

type PolicyForm = {
    name: string;
    max_breaks: number;
    break_duration_minutes: number;
    max_lunch: number;
    lunch_duration_minutes: number;
    grace_period_seconds: number;
    allowed_pause_reasons: string;
    is_active: boolean;
    shift_reset_time: string;
};

const defaultForm: PolicyForm = {
    name: '',
    max_breaks: 2,
    break_duration_minutes: 15,
    max_lunch: 1,
    lunch_duration_minutes: 60,
    grace_period_seconds: 0,
    allowed_pause_reasons: '',
    is_active: true,
    shift_reset_time: '06:00',
};

export default function BreakTimerPolicies() {
    const { policies } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Break Policies',
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Break Timer', href: '/break-timer' },
            { title: 'Policies' },
        ],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [editingPolicy, setEditingPolicy] = useState<BreakPolicyData | null>(null);

    const form = useForm<PolicyForm>(defaultForm);

    function openCreate() {
        setEditingPolicy(null);
        form.setData(defaultForm);
        form.clearErrors();
        setIsDialogOpen(true);
    }

    function openEdit(policy: BreakPolicyData) {
        setEditingPolicy(policy);
        form.setData({
            name: policy.name,
            max_breaks: policy.max_breaks,
            break_duration_minutes: policy.break_duration_minutes,
            max_lunch: policy.max_lunch,
            lunch_duration_minutes: policy.lunch_duration_minutes,
            grace_period_seconds: policy.grace_period_seconds,
            allowed_pause_reasons: policy.allowed_pause_reasons?.join(', ') ?? '',
            is_active: policy.is_active,
            shift_reset_time: policy.shift_reset_time ?? '06:00',
        });
        form.clearErrors();
        setIsDialogOpen(true);
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();

        const payload = {
            ...form.data,
            allowed_pause_reasons: form.data.allowed_pause_reasons
                ? form.data.allowed_pause_reasons.split(',').map((s) => s.trim()).filter(Boolean)
                : [],
        };

        if (editingPolicy) {
            router.put(updateRoute(editingPolicy.id).url, payload, {
                preserveScroll: true,
                onSuccess: () => setIsDialogOpen(false),
            });
        } else {
            router.post(storeRoute().url, payload, {
                preserveScroll: true,
                onSuccess: () => setIsDialogOpen(false),
            });
        }
    }

    function handleDelete(policy: BreakPolicyData) {
        router.delete(destroyRoute(policy.id).url, {
            preserveScroll: true,
        });
    }

    function handleToggle(policy: BreakPolicyData) {
        router.post(toggleRoute(policy.id).url, {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isLoading} />

            <div className="space-y-6 p-4 md:p-6">
                <PageHeader title={title} description="Manage break time policies">
                    <Can permission="break_timer.manage_policy">
                        <Button onClick={openCreate}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Policy
                        </Button>
                    </Can>
                </PageHeader>

                {/* Desktop Table */}
                <div className="hidden md:block">
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Max Breaks</TableHead>
                                        <TableHead>Break Duration</TableHead>
                                        <TableHead>Max Lunch</TableHead>
                                        <TableHead>Lunch Duration</TableHead>
                                        <TableHead>Grace Period</TableHead>
                                        <TableHead>Shift Reset</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {policies.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={9} className="text-muted-foreground py-8 text-center">
                                                No policies configured.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        policies.map((policy) => (
                                            <TableRow key={policy.id}>
                                                <TableCell className="font-medium">{policy.name}</TableCell>
                                                <TableCell>{policy.max_breaks}</TableCell>
                                                <TableCell>{policy.break_duration_minutes} min</TableCell>
                                                <TableCell>{policy.max_lunch}</TableCell>
                                                <TableCell>{policy.lunch_duration_minutes} min</TableCell>
                                                <TableCell>{policy.grace_period_seconds}s</TableCell>
                                                <TableCell>{policy.shift_reset_time ?? '06:00'}</TableCell>
                                                <TableCell>
                                                    <Badge variant={policy.is_active ? 'default' : 'secondary'}>
                                                        {policy.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <Can permission="break_timer.manage_policy">
                                                        <div className="flex justify-end gap-2">
                                                            <div
                                                                className="inline-flex cursor-pointer items-center"
                                                                onClick={() => handleToggle(policy)}
                                                            >
                                                                <Switch checked={policy.is_active} />
                                                            </div>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => openEdit(policy)}
                                                            >
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                            <DeleteConfirmDialog
                                                                onConfirm={() => handleDelete(policy)}
                                                                title="Delete Policy"
                                                                description={`Are you sure you want to delete "${policy.name}"? This cannot be undone.`}
                                                                trigger={
                                                                    <Button
                                                                        size="sm"
                                                                        variant="ghost"
                                                                        className="text-red-500"
                                                                    >
                                                                        <Trash2 className="h-4 w-4" />
                                                                    </Button>
                                                                }
                                                            />
                                                        </div>
                                                    </Can>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {policies.length === 0 ? (
                        <Card>
                            <CardContent className="text-muted-foreground py-8 text-center">
                                No policies configured.
                            </CardContent>
                        </Card>
                    ) : (
                        policies.map((policy) => (
                            <div key={policy.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                <div className="flex items-center justify-between">
                                    <span className="font-medium">{policy.name}</span>
                                    <Badge variant={policy.is_active ? 'default' : 'secondary'}>
                                        {policy.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </div>
                                <div className="text-muted-foreground grid grid-cols-2 gap-1 text-sm">
                                    <span>Breaks: {policy.max_breaks} x {policy.break_duration_minutes}min</span>
                                    <span>Lunch: {policy.max_lunch} x {policy.lunch_duration_minutes}min</span>
                                    <span>Grace: {policy.grace_period_seconds}s</span>
                                    <span>Reset: {policy.shift_reset_time ?? '06:00'}</span>
                                </div>
                                <Can permission="break_timer.manage_policy">
                                    <div className="flex gap-2">
                                        <Button size="sm" variant="outline" onClick={() => handleToggle(policy)} className="flex-1">
                                            {policy.is_active ? 'Deactivate' : 'Activate'}
                                        </Button>
                                        <Button size="sm" variant="outline" onClick={() => openEdit(policy)} className="flex-1">
                                            <Pencil className="mr-1 h-3 w-3" /> Edit
                                        </Button>
                                        <DeleteConfirmDialog
                                            onConfirm={() => handleDelete(policy)}
                                            title="Delete Policy"
                                            description={`Are you sure you want to delete "${policy.name}"? This cannot be undone.`}
                                            trigger={
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="text-red-500 flex-1"
                                                >
                                                    <Trash2 className="mr-1 h-3 w-3" /> Delete
                                                </Button>
                                            }
                                        />
                                    </div>
                                </Can>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* Create / Edit Dialog */}
            <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
                <DialogContent className="max-w-[90vw] sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editingPolicy ? 'Edit Policy' : 'Create Policy'}</DialogTitle>
                        <DialogDescription>
                            {editingPolicy ? 'Update the break policy settings.' : 'Configure a new break policy.'}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Name</Label>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. Standard Policy"
                            />
                            {form.errors.name && <p className="text-sm text-red-500">{form.errors.name}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label>Max Breaks</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    max={10}
                                    value={form.data.max_breaks}
                                    onChange={(e) => form.setData('max_breaks', parseInt(e.target.value) || 0)}
                                />
                                <p className="text-muted-foreground text-[11px]">0–10 breaks per day</p>
                                {form.errors.max_breaks && <p className="text-sm text-red-500">{form.errors.max_breaks}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Break Duration (min)</Label>
                                <Input
                                    type="number"
                                    min={1}
                                    max={120}
                                    value={form.data.break_duration_minutes}
                                    onChange={(e) => form.setData('break_duration_minutes', parseInt(e.target.value) || 15)}
                                />
                                <p className="text-muted-foreground text-[11px]">1–120 minutes</p>
                                {form.errors.break_duration_minutes && <p className="text-sm text-red-500">{form.errors.break_duration_minutes}</p>}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label>Max Lunch</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    max={5}
                                    value={form.data.max_lunch}
                                    onChange={(e) => form.setData('max_lunch', parseInt(e.target.value) || 0)}
                                />
                                <p className="text-muted-foreground text-[11px]">0–3 per day</p>
                                {form.errors.max_lunch && <p className="text-sm text-red-500">{form.errors.max_lunch}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Lunch Duration (min)</Label>
                                <Input
                                    type="number"
                                    min={1}
                                    max={240}
                                    value={form.data.lunch_duration_minutes}
                                    onChange={(e) => form.setData('lunch_duration_minutes', parseInt(e.target.value) || 60)}
                                />
                                <p className="text-muted-foreground text-[11px]">1–180 minutes</p>
                                {form.errors.lunch_duration_minutes && <p className="text-sm text-red-500">{form.errors.lunch_duration_minutes}</p>}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Grace Period (seconds)</Label>
                            <Input
                                type="number"
                                min={0}
                                max={1800}
                                value={form.data.grace_period_seconds}
                                onChange={(e) => form.setData('grace_period_seconds', parseInt(e.target.value) || 0)}
                            />
                            <p className="text-muted-foreground text-[11px]">0–1800 seconds before overage (e.g. 30 = 30s, 60 = 1min)</p>
                            {form.errors.grace_period_seconds && <p className="text-sm text-red-500">{form.errors.grace_period_seconds}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label>Shift Reset Time</Label>
                            <Input
                                type="time"
                                value={form.data.shift_reset_time}
                                onChange={(e) => form.setData('shift_reset_time', e.target.value)}
                            />
                            <p className="text-muted-foreground text-[11px]">Time when orphaned sessions from the previous day auto-end (24h format)</p>
                            {form.errors.shift_reset_time && <p className="text-sm text-red-500">{form.errors.shift_reset_time}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label>Allowed Pause Reasons (comma-separated)</Label>
                            <Input
                                value={form.data.allowed_pause_reasons}
                                onChange={(e) => form.setData('allowed_pause_reasons', e.target.value)}
                                placeholder="e.g. Bathroom, Coaching, IT Issue"
                            />
                            <p className="text-muted-foreground text-[11px]">Leave empty to allow free-text reasons</p>
                            {form.errors.allowed_pause_reasons && <p className="text-sm text-red-500">{form.errors.allowed_pause_reasons}</p>}
                        </div>

                        <div className="flex items-center gap-2">
                            <Switch
                                checked={form.data.is_active}
                                onCheckedChange={(checked) => form.setData('is_active', checked)}
                            />
                            <Label>Active</Label>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setIsDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {editingPolicy ? 'Update' : 'Create'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

        </AppLayout>
    );
}
