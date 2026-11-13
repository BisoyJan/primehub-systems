import { DeleteConfirmDialog } from '@/components/DeleteConfirmDialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
    destroy as destroyRoute,
    preview as previewRoute,
    store as storeRoute,
    toggle as toggleRoute,
    update as updateRoute,
} from '@/routes/break-timer/retention-policies';
import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Coffee,
    Eye,
    Layers,
    Loader2,
    type LucideIcon,
    Pencil,
    Plus,
    Shield,
    Trash2,
    Utensils,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

type BreakType = 'break' | 'lunch' | 'combined';
type PolicyBreakType = 'all' | BreakType;

export interface RetentionSite {
    id: number;
    name: string;
}

export interface RetentionPolicy {
    id: number;
    name: string;
    description: string | null;
    retention_months: number;
    applies_to_type: 'global' | 'site';
    applies_to_id: number | null;
    site?: RetentionSite | null;
    break_type: PolicyBreakType;
    priority: number;
    is_active: boolean;
}

interface BreakTypeStats {
    label: string;
    total: number;
    byAge: { range: string; count: number }[];
}

export type RetentionStats = Record<BreakType, BreakTypeStats>;

interface PreviewData {
    policy: {
        id: number;
        name: string;
        retention_months: number;
        applies_to_type: string;
        break_type: string;
    };
    cutoff_date: string;
    preview: {
        break_type: string;
        label: string;
        count: number;
        oldest_date: string | null;
        newest_date: string | null;
    }[];
    total_affected: number;
}

type RetentionForm = {
    name: string;
    description: string;
    retention_months: number | '';
    applies_to_type: 'global' | 'site';
    applies_to_id: number | null;
    break_type: PolicyBreakType;
    priority: number | '';
    is_active: boolean;
};

const defaultForm: RetentionForm = {
    name: '',
    description: '',
    retention_months: 12,
    applies_to_type: 'global',
    applies_to_id: null,
    break_type: 'all',
    priority: 100,
    is_active: true,
};

const breakTypeConfig: Record<
    PolicyBreakType,
    { label: string; badgeClassName: string }
> = {
    all: { label: 'All Types', badgeClassName: 'bg-purple-500' },
    break: { label: 'Breaks', badgeClassName: 'bg-blue-500' },
    lunch: { label: 'Lunch', badgeClassName: 'bg-orange-500' },
    combined: { label: 'Combined', badgeClassName: 'bg-green-500' },
};

const statsCards: {
    type: BreakType;
    icon: LucideIcon;
    iconClassName: string;
}[] = [
    { type: 'break', icon: Coffee, iconClassName: 'text-blue-500' },
    { type: 'lunch', icon: Utensils, iconClassName: 'text-orange-500' },
    { type: 'combined', icon: Layers, iconClassName: 'text-green-500' },
];

function months(count: number): string {
    return `${count} ${count === 1 ? 'month' : 'months'}`;
}

interface Props {
    policies: RetentionPolicy[];
    sites: RetentionSite[];
    retentionStats: RetentionStats | null;
}

export default function RetentionPoliciesTab({
    policies,
    sites,
    retentionStats,
}: Props) {
    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [editingPolicy, setEditingPolicy] = useState<RetentionPolicy | null>(
        null,
    );
    const [isPreviewOpen, setIsPreviewOpen] = useState(false);
    const [isLoadingPreview, setIsLoadingPreview] = useState(false);
    const [previewFailed, setPreviewFailed] = useState(false);
    const [previewData, setPreviewData] = useState<PreviewData | null>(null);

    const form = useForm<RetentionForm>(defaultForm);

    function openCreate() {
        setEditingPolicy(null);
        form.setData(defaultForm);
        form.clearErrors();
        setIsDialogOpen(true);
    }

    function openEdit(policy: RetentionPolicy) {
        setEditingPolicy(policy);
        form.setData({
            name: policy.name,
            description: policy.description ?? '',
            retention_months: policy.retention_months,
            applies_to_type: policy.applies_to_type,
            applies_to_id: policy.applies_to_id,
            break_type: policy.break_type,
            priority: policy.priority,
            is_active: policy.is_active,
        });
        form.clearErrors();
        setIsDialogOpen(true);
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => setIsDialogOpen(false),
        };

        if (editingPolicy) {
            form.put(updateRoute(editingPolicy.id).url, options);
        } else {
            form.post(storeRoute().url, options);
        }
    }

    function handleDelete(policy: RetentionPolicy) {
        router.delete(destroyRoute(policy.id).url, { preserveScroll: true });
    }

    function handleToggle(policy: RetentionPolicy) {
        router.post(toggleRoute(policy.id).url, {}, { preserveScroll: true });
    }

    async function handlePreview(policy: RetentionPolicy) {
        setIsPreviewOpen(true);
        setIsLoadingPreview(true);
        setPreviewFailed(false);
        setPreviewData(null);

        try {
            const response = await fetch(previewRoute(policy.id).url, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(`Preview failed with ${response.status}`);
            }

            setPreviewData(await response.json());
        } catch {
            setPreviewFailed(true);
        } finally {
            setIsLoadingPreview(false);
        }
    }

    function breakTypeBadge(breakType: PolicyBreakType) {
        const { label, badgeClassName } = breakTypeConfig[breakType];

        return <Badge className={badgeClassName}>{label}</Badge>;
    }

    function scopeBadge(policy: RetentionPolicy) {
        return policy.applies_to_type === 'global' ? (
            <Badge variant="outline">Global</Badge>
        ) : (
            <Badge variant="secondary">{policy.site?.name}</Badge>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                <p className="text-sm text-muted-foreground">
                    Manage how long break session records are kept
                </p>
                <Button
                    size="sm"
                    onClick={openCreate}
                    className="w-full sm:w-auto"
                >
                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                    Add Retention Policy
                </Button>
            </div>

            <Alert>
                <Shield className="h-4 w-4" />
                <AlertDescription>
                    Break sessions and their events are deleted once they are
                    older than the matching policy's retention period. A policy
                    for a specific break type wins over an "All Types" policy,
                    and a site policy wins over a global one. Higher priority
                    breaks ties. Sessions with no matching policy are kept for
                    12 months. Active and paused sessions are never deleted.
                </AlertDescription>
            </Alert>

            {retentionStats && (
                <div className="space-y-4">
                    <div className="flex items-center gap-2">
                        <BarChart3 className="h-5 w-5 text-muted-foreground" />
                        <h2 className="text-lg font-semibold">
                            Session Statistics by Age
                        </h2>
                    </div>
                    <div className="grid gap-4 md:grid-cols-3">
                        {statsCards.map(
                            ({ type, icon: Icon, iconClassName }) => (
                                <Card key={type}>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Icon
                                                className={`h-4 w-4 ${iconClassName}`}
                                            />
                                            {retentionStats[type].label}
                                        </CardTitle>
                                        <p className="text-2xl font-bold">
                                            {retentionStats[
                                                type
                                            ].total.toLocaleString()}
                                        </p>
                                    </CardHeader>
                                    <CardContent className="pt-0">
                                        <div className="space-y-1">
                                            {retentionStats[type].byAge.map(
                                                (age) => (
                                                    <div
                                                        key={age.range}
                                                        className="flex justify-between text-sm"
                                                    >
                                                        <span className="text-muted-foreground">
                                                            {age.range}
                                                        </span>
                                                        <span className="font-medium">
                                                            {age.count.toLocaleString()}
                                                        </span>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            ),
                        )}
                    </div>
                </div>
            )}

            {/* Desktop Table */}
            <div className="hidden md:block">
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Break Type</TableHead>
                                    <TableHead>Retention Period</TableHead>
                                    <TableHead>Applies To</TableHead>
                                    <TableHead>Priority</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">
                                        Actions
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {policies.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No retention policies configured.
                                            Sessions are kept for 12 months.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    policies.map((policy) => (
                                        <TableRow key={policy.id}>
                                            <TableCell>
                                                <p className="font-medium">
                                                    {policy.name}
                                                </p>
                                                {policy.description && (
                                                    <p className="text-sm text-muted-foreground">
                                                        {policy.description}
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {breakTypeBadge(
                                                    policy.break_type,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {months(
                                                    policy.retention_months,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {scopeBadge(policy)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge>{policy.priority}</Badge>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Switch
                                                        checked={
                                                            policy.is_active
                                                        }
                                                        onCheckedChange={() =>
                                                            handleToggle(policy)
                                                        }
                                                    />
                                                    <span className="text-sm">
                                                        {policy.is_active
                                                            ? 'Active'
                                                            : 'Inactive'}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex justify-end gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            handlePreview(
                                                                policy,
                                                            )
                                                        }
                                                        title="Preview affected sessions"
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            openEdit(policy)
                                                        }
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                    <DeleteConfirmDialog
                                                        onConfirm={() =>
                                                            handleDelete(policy)
                                                        }
                                                        title="Delete Retention Policy"
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
            <div className="space-y-4 md:hidden">
                {policies.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No retention policies configured. Sessions are kept
                            for 12 months.
                        </CardContent>
                    </Card>
                ) : (
                    policies.map((policy) => (
                        <div
                            key={policy.id}
                            className="space-y-3 rounded-lg border bg-card p-4 shadow-sm"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">
                                        {policy.name}
                                    </p>
                                    {policy.description && (
                                        <p className="line-clamp-2 text-sm text-muted-foreground">
                                            {policy.description}
                                        </p>
                                    )}
                                </div>
                                {breakTypeBadge(policy.break_type)}
                            </div>
                            <div className="grid grid-cols-2 gap-2 text-sm">
                                <span className="text-muted-foreground">
                                    Retention:{' '}
                                    <span className="font-medium text-foreground">
                                        {months(policy.retention_months)}
                                    </span>
                                </span>
                                <span className="text-muted-foreground">
                                    Priority:{' '}
                                    <span className="font-medium text-foreground">
                                        {policy.priority}
                                    </span>
                                </span>
                                <span className="flex items-center gap-1 text-muted-foreground">
                                    Applies to: {scopeBadge(policy)}
                                </span>
                                <span className="flex items-center gap-2">
                                    <Switch
                                        checked={policy.is_active}
                                        onCheckedChange={() =>
                                            handleToggle(policy)
                                        }
                                    />
                                    {policy.is_active ? 'Active' : 'Inactive'}
                                </span>
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => handlePreview(policy)}
                                    className="flex-1"
                                >
                                    <Eye className="mr-1 h-3 w-3" /> Preview
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => openEdit(policy)}
                                    className="flex-1"
                                >
                                    <Pencil className="mr-1 h-3 w-3" /> Edit
                                </Button>
                                <DeleteConfirmDialog
                                    onConfirm={() => handleDelete(policy)}
                                    title="Delete Retention Policy"
                                    description={`Are you sure you want to delete "${policy.name}"? This cannot be undone.`}
                                    trigger={
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="flex-1 text-red-500"
                                        >
                                            <Trash2 className="mr-1 h-3 w-3" />{' '}
                                            Delete
                                        </Button>
                                    }
                                />
                            </div>
                        </div>
                    ))
                )}
            </div>

            {/* Create / Edit Dialog */}
            <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
                <DialogContent className="max-h-[90vh] max-w-[90vw] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingPolicy
                                ? 'Edit Retention Policy'
                                : 'Create Retention Policy'}
                        </DialogTitle>
                        <DialogDescription>
                            Define how long break session records are retained.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="retention-name">Policy Name</Label>
                            <Input
                                id="retention-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="e.g. Standard Break Retention"
                            />
                            {form.errors.name && (
                                <p className="text-sm text-red-500">
                                    {form.errors.name}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="retention-description">
                                Description
                            </Label>
                            <Textarea
                                id="retention-description"
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Optional description of the policy"
                                rows={3}
                            />
                            {form.errors.description && (
                                <p className="text-sm text-red-500">
                                    {form.errors.description}
                                </p>
                            )}
                        </div>

                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="retention-months">
                                    Retention Period (months)
                                </Label>
                                <Input
                                    id="retention-months"
                                    type="number"
                                    min={1}
                                    max={120}
                                    value={form.data.retention_months}
                                    onChange={(e) =>
                                        form.setData(
                                            'retention_months',
                                            e.target.value === ''
                                                ? ''
                                                : parseInt(e.target.value),
                                        )
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    1–120 months
                                </p>
                                {form.errors.retention_months && (
                                    <p className="text-sm text-red-500">
                                        {form.errors.retention_months}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="retention-priority">
                                    Priority
                                </Label>
                                <Input
                                    id="retention-priority"
                                    type="number"
                                    min={0}
                                    value={form.data.priority}
                                    onChange={(e) =>
                                        form.setData(
                                            'priority',
                                            e.target.value === ''
                                                ? ''
                                                : parseInt(e.target.value),
                                        )
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Higher number wins a tie
                                </p>
                                {form.errors.priority && (
                                    <p className="text-sm text-red-500">
                                        {form.errors.priority}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="retention-break-type">
                                Break Type
                            </Label>
                            <Select
                                value={form.data.break_type}
                                onValueChange={(value) =>
                                    form.setData(
                                        'break_type',
                                        value as PolicyBreakType,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="retention-break-type"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select break type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Break Types
                                    </SelectItem>
                                    <SelectItem value="break">
                                        Breaks Only
                                    </SelectItem>
                                    <SelectItem value="lunch">
                                        Lunch Only
                                    </SelectItem>
                                    <SelectItem value="combined">
                                        Combined (Break + Lunch) Only
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {form.errors.break_type && (
                                <p className="text-sm text-red-500">
                                    {form.errors.break_type}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="retention-applies-to">
                                Applies To
                            </Label>
                            <Select
                                value={form.data.applies_to_type}
                                onValueChange={(value) =>
                                    form.setData((data) => ({
                                        ...data,
                                        applies_to_type: value as
                                            'global' | 'site',
                                        applies_to_id:
                                            value === 'global'
                                                ? null
                                                : data.applies_to_id,
                                    }))
                                }
                            >
                                <SelectTrigger
                                    id="retention-applies-to"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select scope" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="global">
                                        All Sites (Global)
                                    </SelectItem>
                                    <SelectItem value="site">
                                        Specific Site
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {form.errors.applies_to_type && (
                                <p className="text-sm text-red-500">
                                    {form.errors.applies_to_type}
                                </p>
                            )}
                        </div>

                        {form.data.applies_to_type === 'site' && (
                            <div className="space-y-2">
                                <Label htmlFor="retention-site">Site</Label>
                                <Select
                                    value={
                                        form.data.applies_to_id
                                            ? String(form.data.applies_to_id)
                                            : ''
                                    }
                                    onValueChange={(value) =>
                                        form.setData(
                                            'applies_to_id',
                                            parseInt(value, 10),
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="retention-site"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select a site..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sites.map((site) => (
                                            <SelectItem
                                                key={site.id}
                                                value={String(site.id)}
                                            >
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-[11px] text-muted-foreground">
                                    Matches sessions of users whose active
                                    schedule is at this site
                                </p>
                                {form.errors.applies_to_id && (
                                    <p className="text-sm text-red-500">
                                        {form.errors.applies_to_id}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="flex items-center gap-2">
                            <Switch
                                id="retention-active"
                                checked={form.data.is_active}
                                onCheckedChange={(checked) =>
                                    form.setData('is_active', checked)
                                }
                            />
                            <Label htmlFor="retention-active">Active</Label>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsDialogOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {editingPolicy ? 'Update' : 'Create'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Preview Dialog */}
            <Dialog open={isPreviewOpen} onOpenChange={setIsPreviewOpen}>
                <DialogContent className="max-w-[90vw] sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Eye className="h-5 w-5" />
                            Policy Impact Preview
                        </DialogTitle>
                        <DialogDescription>
                            Sessions that would be deleted if this policy is the
                            one applied to them
                        </DialogDescription>
                    </DialogHeader>

                    {isLoadingPreview && (
                        <div className="flex items-center justify-center py-8">
                            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                        </div>
                    )}

                    {previewFailed && (
                        <Alert variant="destructive">
                            <AlertTriangle className="h-4 w-4" />
                            <AlertDescription>
                                Could not load the preview. Please try again.
                            </AlertDescription>
                        </Alert>
                    )}

                    {previewData && (
                        <div className="space-y-4">
                            <div className="grid gap-2 rounded-lg bg-muted p-4 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Policy:
                                    </span>
                                    <span className="font-medium">
                                        {previewData.policy.name}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Retention Period:
                                    </span>
                                    <span className="font-medium">
                                        {months(
                                            previewData.policy.retention_months,
                                        )}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Cutoff Date:
                                    </span>
                                    <span className="font-medium">
                                        {previewData.cutoff_date}
                                    </span>
                                </div>
                            </div>

                            {previewData.total_affected > 0 ? (
                                <>
                                    <Alert variant="destructive">
                                        <AlertTriangle className="h-4 w-4" />
                                        <AlertDescription>
                                            <strong>
                                                {previewData.total_affected.toLocaleString()}
                                            </strong>{' '}
                                            sessions would be deleted when this
                                            policy is enforced.
                                        </AlertDescription>
                                    </Alert>

                                    <div className="space-y-3">
                                        {previewData.preview.map((item) => (
                                            <div
                                                key={item.break_type}
                                                className="flex items-center justify-between rounded-lg border p-3"
                                            >
                                                <div>
                                                    <p className="font-medium">
                                                        {item.label}
                                                    </p>
                                                    {item.oldest_date &&
                                                        item.newest_date && (
                                                            <p className="text-xs text-muted-foreground">
                                                                From{' '}
                                                                {
                                                                    item.oldest_date
                                                                }{' '}
                                                                to{' '}
                                                                {
                                                                    item.newest_date
                                                                }
                                                            </p>
                                                        )}
                                                </div>
                                                <Badge
                                                    variant={
                                                        item.count > 0
                                                            ? 'destructive'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {item.count.toLocaleString()}{' '}
                                                    sessions
                                                </Badge>
                                            </div>
                                        ))}
                                    </div>
                                </>
                            ) : (
                                <Alert>
                                    <Shield className="h-4 w-4" />
                                    <AlertDescription>
                                        No sessions would be affected. All
                                        current sessions are within the
                                        retention period.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="flex justify-end">
                                <Button
                                    variant="outline"
                                    onClick={() => setIsPreviewOpen(false)}
                                >
                                    Close
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
