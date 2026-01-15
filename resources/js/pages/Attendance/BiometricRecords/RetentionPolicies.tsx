import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Shield, Plus, Pencil, Trash2, Loader2, BarChart3, Fingerprint, AlertCircle, Eye, AlertTriangle } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Can } from '@/components/authorization';
import { index as attendanceIndex } from '@/routes/attendance';
import {
    index as biometricRetentionPoliciesIndex,
    store as biometricRetentionPoliciesStore,
    update as biometricRetentionPoliciesUpdate,
    destroy as biometricRetentionPoliciesDestroy,
    toggle as biometricRetentionPoliciesToggle,
    preview as biometricRetentionPoliciesPreview,
} from '@/routes/biometric-retention-policies';

interface Site {
    id: number;
    name: string;
}

interface Policy {
    id: number;
    name: string;
    description: string | null;
    retention_months: number;
    applies_to_type: 'global' | 'site';
    applies_to_id: number | null;
    site?: Site;
    record_type: 'all' | 'biometric_record' | 'attendance_point';
    priority: number;
    is_active: boolean;
}

interface FormData {
    name: string;
    description: string;
    retention_months: number;
    applies_to_type: 'global' | 'site';
    applies_to_id: number | null;
    record_type: 'all' | 'biometric_record' | 'attendance_point';
    priority: number;
    is_active: boolean;
}

interface AgeRange {
    range: string;
    count: number;
}

interface RecordTypeStats {
    label: string;
    total: number;
    byAge: AgeRange[];
}

interface RetentionStats {
    biometric_record: RecordTypeStats;
    attendance_point: RecordTypeStats;
}

interface PreviewData {
    policy: {
        id: number;
        name: string;
        retention_months: number;
        applies_to_type: string;
        record_type: string;
    };
    cutoff_date: string;
    preview: {
        record_type: string;
        label: string;
        count: number;
        oldest_date: string | null;
        newest_date: string | null;
    }[];
    total_affected: number;
}

interface Props {
    policies: Policy[];
    sites: Site[];
    retentionStats?: RetentionStats;
}

export default function RetentionPolicies({ policies, sites, retentionStats }: Props) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Retention Policies',
        breadcrumbs: [
            { title: 'Attendance', href: attendanceIndex().url },
            { title: 'Retention Policies', href: biometricRetentionPoliciesIndex().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [editingPolicy, setEditingPolicy] = useState<Policy | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isPreviewOpen, setIsPreviewOpen] = useState(false);
    const [isLoadingPreview, setIsLoadingPreview] = useState(false);
    const [previewData, setPreviewData] = useState<PreviewData | null>(null);
    const [formData, setFormData] = useState<FormData>({
        name: '',
        description: '',
        retention_months: 12,
        applies_to_type: 'global',
        applies_to_id: null,
        record_type: 'all',
        priority: 100,
        is_active: true,
    });

    const resetForm = () => {
        setFormData({
            name: '',
            description: '',
            retention_months: 12,
            applies_to_type: 'global',
            applies_to_id: null,
            record_type: 'all',
            priority: 100,
            is_active: true,
        });
        setEditingPolicy(null);
    };

    const openCreateDialog = () => {
        resetForm();
        setIsDialogOpen(true);
    };

    const openEditDialog = (policy: Policy) => {
        setEditingPolicy(policy);
        setFormData({
            name: policy.name,
            description: policy.description || '',
            retention_months: policy.retention_months,
            applies_to_type: policy.applies_to_type,
            applies_to_id: policy.applies_to_id,
            record_type: policy.record_type || 'all',
            priority: policy.priority,
            is_active: policy.is_active,
        });
        setIsDialogOpen(true);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);

        const payload = {
            name: formData.name,
            description: formData.description,
            retention_months: formData.retention_months,
            applies_to_type: formData.applies_to_type,
            applies_to_id: formData.applies_to_id,
            record_type: formData.record_type,
            priority: formData.priority,
            is_active: formData.is_active,
        };

        if (editingPolicy) {
            router.put(biometricRetentionPoliciesUpdate(editingPolicy.id).url, payload, {
                onSuccess: () => {
                    setIsDialogOpen(false);
                    resetForm();
                },
                onFinish: () => setIsSubmitting(false),
            });
        } else {
            router.post(biometricRetentionPoliciesStore().url, payload, {
                onSuccess: () => {
                    setIsDialogOpen(false);
                    resetForm();
                },
                onFinish: () => setIsSubmitting(false),
            });
        }
    };

    const handleDelete = (policyId: number) => {
        if (!confirm('Are you sure you want to delete this retention policy?')) {
            return;
        }

        router.delete(biometricRetentionPoliciesDestroy(policyId).url);
    };

    const handleToggle = (policyId: number) => {
        router.post(biometricRetentionPoliciesToggle(policyId).url);
    };

    const handlePreview = async (policyId: number) => {
        setIsLoadingPreview(true);
        setIsPreviewOpen(true);
        setPreviewData(null);

        try {
            const response = await fetch(biometricRetentionPoliciesPreview(policyId).url);
            const data = await response.json();
            setPreviewData(data);
        } catch (error) {
            console.error('Failed to load preview:', error);
        } finally {
            setIsLoadingPreview(false);
        }
    };

    const getRecordTypeBadge = (recordType: string) => {
        const config: Record<string, { label: string; className: string }> = {
            all: { label: 'All Records', className: 'bg-purple-500' },
            biometric_record: { label: 'Biometric Records', className: 'bg-blue-500' },
            attendance_point: { label: 'Attendance Points', className: 'bg-orange-500' },
        };

        const { label, className } = config[recordType] || { label: recordType, className: 'bg-gray-500' };
        return <Badge className={className}>{label}</Badge>;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isPageLoading} />

            <div className="container mx-auto px-4 py-8">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold">Retention Policies</h1>
                        <p className="text-muted-foreground mt-2 text-sm sm:text-base">
                            Manage data retention rules for attendance records
                        </p>
                    </div>
                    <Can permission="biometric.retention">
                        <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
                            <DialogTrigger asChild>
                                <Button onClick={openCreateDialog} className="w-full sm:w-auto">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Policy
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-w-[95vw] sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                                <DialogHeader>
                                    <DialogTitle>
                                        {editingPolicy ? 'Edit' : 'Create'} Retention Policy
                                    </DialogTitle>
                                    <DialogDescription>
                                        Define rules for how long attendance records should be retained
                                    </DialogDescription>
                                </DialogHeader>
                                <form onSubmit={handleSubmit} className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Policy Name *</Label>
                                        <Input
                                            id="name"
                                            value={formData.name}
                                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                            required
                                            placeholder="e.g., Standard Retention Policy"
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="description">Description</Label>
                                        <Textarea
                                            id="description"
                                            value={formData.description}
                                            onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                                            placeholder="Optional description of the policy"
                                            rows={3}
                                        />
                                    </div>

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="retention-months">Retention Period (Months) *</Label>
                                            <Input
                                                id="retention-months"
                                                type="number"
                                                min="1"
                                                value={formData.retention_months}
                                                onChange={(e) => setFormData({
                                                    ...formData,
                                                    retention_months: parseInt(e.target.value)
                                                })}
                                                required
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="priority">Priority *</Label>
                                            <Input
                                                id="priority"
                                                type="number"
                                                min="1"
                                                value={formData.priority}
                                                onChange={(e) => setFormData({
                                                    ...formData,
                                                    priority: parseInt(e.target.value)
                                                })}
                                                required
                                                placeholder="Higher number = higher priority"
                                            />
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="record-type">Record Type *</Label>
                                        <Select
                                            value={formData.record_type}
                                            onValueChange={(value) => setFormData({
                                                ...formData,
                                                record_type: value as FormData['record_type'],
                                            })}
                                        >
                                            <SelectTrigger id="record-type" className="w-full">
                                                <SelectValue placeholder="Select record type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All Record Types</SelectItem>
                                                <SelectItem value="biometric_record">Biometric Records Only</SelectItem>
                                                <SelectItem value="attendance_point">Attendance Points Only</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="applies-to-type">Applies To *</Label>
                                        <Select
                                            value={formData.applies_to_type}
                                            onValueChange={(value: 'global' | 'site') => setFormData({
                                                ...formData,
                                                applies_to_type: value,
                                                applies_to_id: value === 'global' ? null : formData.applies_to_id,
                                            })}
                                        >
                                            <SelectTrigger id="applies-to-type" className="w-full">
                                                <SelectValue placeholder="Select scope" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="global">All Sites (Global)</SelectItem>
                                                <SelectItem value="site">Specific Site</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {formData.applies_to_type === 'site' && (
                                        <div className="space-y-2">
                                            <Label htmlFor="site">Select Site *</Label>
                                            <Select
                                                value={formData.applies_to_id ? String(formData.applies_to_id) : undefined}
                                                onValueChange={(value) => setFormData({
                                                    ...formData,
                                                    applies_to_id: value ? parseInt(value, 10) : null,
                                                })}
                                            >
                                                <SelectTrigger id="site" className="w-full" aria-required="true">
                                                    <SelectValue placeholder="Select a site..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {sites.map((site) => (
                                                        <SelectItem key={site.id} value={String(site.id)}>
                                                            {site.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    )}

                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="is-active"
                                            checked={formData.is_active}
                                            onCheckedChange={(checked) => setFormData({
                                                ...formData,
                                                is_active: checked
                                            })}
                                        />
                                        <Label htmlFor="is-active" className="cursor-pointer">
                                            Active Policy
                                        </Label>
                                    </div>

                                    <div className="flex justify-end gap-2 pt-4">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setIsDialogOpen(false)}
                                        >
                                            Cancel
                                        </Button>
                                        <Button type="submit" disabled={isSubmitting}>
                                            {isSubmitting ? (
                                                <>
                                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                    Saving...
                                                </>
                                            ) : (
                                                editingPolicy ? 'Update' : 'Create'
                                            )}
                                        </Button>
                                    </div>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </Can>
                </div>

                {/* Info Alert */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <Alert>
                            <Shield className="h-4 w-4" />
                            <AlertDescription>
                                Retention policies control how long attendance records (Biometric Records, Attendance Points)
                                are kept. Higher priority policies override lower priority ones.
                                Global policies apply to all sites unless a site-specific policy exists.
                                Policies with record type "All Records" act as fallback when no specific policy matches.
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                </Card>

                {/* Retention Stats */}
                {retentionStats && (
                    <div className="mb-6">
                        <div className="flex items-center gap-2 mb-4">
                            <BarChart3 className="h-5 w-5 text-muted-foreground" />
                            <h2 className="text-lg font-semibold">Record Statistics by Age</h2>
                        </div>
                        <div className="grid gap-4 md:grid-cols-2">
                            {/* Biometric Records Stats */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Fingerprint className="h-4 w-4 text-blue-500" />
                                        Biometric Records
                                    </CardTitle>
                                    <p className="text-2xl font-bold">{retentionStats.biometric_record.total.toLocaleString()}</p>
                                </CardHeader>
                                <CardContent className="pt-0">
                                    <div className="space-y-1">
                                        {retentionStats.biometric_record.byAge.map((age) => (
                                            <div key={age.range} className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">{age.range}</span>
                                                <span className="font-medium">{age.count.toLocaleString()}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Attendance Points Stats */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <AlertCircle className="h-4 w-4 text-orange-500" />
                                        Attendance Points
                                    </CardTitle>
                                    <p className="text-2xl font-bold">{retentionStats.attendance_point.total.toLocaleString()}</p>
                                </CardHeader>
                                <CardContent className="pt-0">
                                    <div className="space-y-1">
                                        {retentionStats.attendance_point.byAge.map((age) => (
                                            <div key={age.range} className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">{age.range}</span>
                                                <span className="font-medium">{age.count.toLocaleString()}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                )}

                {/* Table */}
                <Card>
                    <CardContent className="pt-6">
                        {policies.length === 0 ? (
                            <p className="text-center text-muted-foreground py-8">
                                No retention policies configured. Click "Add Policy" to create one.
                            </p>
                        ) : (
                            <>
                                {/* Desktop Table */}
                                <div className="hidden md:block overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="bg-muted/50">
                                                <TableHead>Name</TableHead>
                                                <TableHead>Record Type</TableHead>
                                                <TableHead>Retention Period</TableHead>
                                                <TableHead>Applies To</TableHead>
                                                <TableHead>Priority</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead className="text-right">Actions</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {policies.map((policy) => (
                                                <TableRow key={policy.id}>
                                                    <TableCell>
                                                        <div>
                                                            <p className="font-medium">{policy.name}</p>
                                                            {policy.description && (
                                                                <p className="text-sm text-muted-foreground">
                                                                    {policy.description}
                                                                </p>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>{getRecordTypeBadge(policy.record_type || 'all')}</TableCell>
                                                    <TableCell>
                                                        {policy.retention_months} {policy.retention_months === 1 ? 'month' : 'months'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {policy.applies_to_type === 'global' ? (
                                                            <Badge variant="outline">Global</Badge>
                                                        ) : (
                                                            <Badge variant="secondary">{policy.site?.name}</Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge>{policy.priority}</Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center gap-2">
                                                            <Switch
                                                                checked={policy.is_active}
                                                                onCheckedChange={() => handleToggle(policy.id)}
                                                            />
                                                            <span className="text-sm">
                                                                {policy.is_active ? 'Active' : 'Inactive'}
                                                            </span>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handlePreview(policy.id)}
                                                                title="Preview affected records"
                                                            >
                                                                <Eye className="h-4 w-4" />
                                                            </Button>
                                                            <Can permission="biometric.retention">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() => openEditDialog(policy)}
                                                                >
                                                                    <Pencil className="h-4 w-4" />
                                                                </Button>
                                                            </Can>
                                                            <Can permission="biometric.retention">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() => handleDelete(policy.id)}
                                                                >
                                                                    <Trash2 className="h-4 w-4 text-red-600" />
                                                                </Button>
                                                            </Can>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                {/* Mobile Cards */}
                                <div className="md:hidden space-y-4">
                                    {policies.map((policy) => (
                                        <div
                                            key={policy.id}
                                            className="bg-card border rounded-lg p-4 shadow-sm space-y-3"
                                        >
                                            <div className="flex items-start justify-between">
                                                <div className="flex-1">
                                                    <p className="font-medium">{policy.name}</p>
                                                    {policy.description && (
                                                        <p className="text-sm text-muted-foreground mt-1">
                                                            {policy.description}
                                                        </p>
                                                    )}
                                                </div>
                                                {getRecordTypeBadge(policy.record_type || 'all')}
                                            </div>

                                            <div className="grid grid-cols-2 gap-3 text-sm">
                                                <div>
                                                    <span className="text-muted-foreground">Retention:</span>
                                                    <p className="font-medium">
                                                        {policy.retention_months} {policy.retention_months === 1 ? 'month' : 'months'}
                                                    </p>
                                                </div>
                                                <div>
                                                    <span className="text-muted-foreground">Priority:</span>
                                                    <p className="font-medium">{policy.priority}</p>
                                                </div>
                                            </div>

                                            <div className="text-sm">
                                                <span className="text-muted-foreground">Applies To:</span>
                                                <div className="mt-1">
                                                    {policy.applies_to_type === 'global' ? (
                                                        <Badge variant="outline">Global</Badge>
                                                    ) : (
                                                        <Badge variant="secondary">{policy.site?.name}</Badge>
                                                    )}
                                                </div>
                                            </div>

                                            <div className="flex items-center justify-between pt-2 border-t">
                                                <div className="flex items-center gap-2">
                                                    <Switch
                                                        checked={policy.is_active}
                                                        onCheckedChange={() => handleToggle(policy.id)}
                                                    />
                                                    <span className="text-sm">
                                                        {policy.is_active ? 'Active' : 'Inactive'}
                                                    </span>
                                                </div>
                                                <div className="flex gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handlePreview(policy.id)}
                                                    >
                                                        <Eye className="h-4 w-4 mr-1" />
                                                        Preview
                                                    </Button>
                                                    <Can permission="biometric.retention">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => openEditDialog(policy)}
                                                        >
                                                            <Pencil className="h-4 w-4 mr-1" />
                                                            Edit
                                                        </Button>
                                                    </Can>
                                                    <Can permission="biometric.retention">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => handleDelete(policy.id)}
                                                            className="text-red-600 hover:text-red-700"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </Can>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Preview Dialog */}
            <Dialog open={isPreviewOpen} onOpenChange={setIsPreviewOpen}>
                <DialogContent className="max-w-[95vw] sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Eye className="h-5 w-5" />
                            Policy Impact Preview
                        </DialogTitle>
                        <DialogDescription>
                            Records that would be affected by this retention policy
                        </DialogDescription>
                    </DialogHeader>

                    {isLoadingPreview ? (
                        <div className="flex items-center justify-center py-8">
                            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                        </div>
                    ) : previewData ? (
                        <div className="space-y-4">
                            <div className="rounded-lg bg-muted p-4">
                                <div className="grid gap-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Policy:</span>
                                        <span className="font-medium">{previewData.policy.name}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Retention Period:</span>
                                        <span className="font-medium">{previewData.policy.retention_months} months</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Cutoff Date:</span>
                                        <span className="font-medium">{previewData.cutoff_date}</span>
                                    </div>
                                </div>
                            </div>

                            {previewData.total_affected > 0 ? (
                                <>
                                    <Alert variant="destructive">
                                        <AlertTriangle className="h-4 w-4" />
                                        <AlertDescription>
                                            <strong>{previewData.total_affected.toLocaleString()}</strong> records would be deleted
                                            when this policy is enforced.
                                        </AlertDescription>
                                    </Alert>

                                    <div className="space-y-3">
                                        {previewData.preview.map((item) => (
                                            <div
                                                key={item.record_type}
                                                className="flex items-center justify-between rounded-lg border p-3"
                                            >
                                                <div>
                                                    <p className="font-medium">{item.label}</p>
                                                    {item.oldest_date && item.newest_date && (
                                                        <p className="text-xs text-muted-foreground">
                                                            From {new Date(item.oldest_date).toLocaleDateString()} to{' '}
                                                            {new Date(item.newest_date).toLocaleDateString()}
                                                        </p>
                                                    )}
                                                </div>
                                                <Badge variant={item.count > 0 ? 'destructive' : 'secondary'}>
                                                    {item.count.toLocaleString()} records
                                                </Badge>
                                            </div>
                                        ))}
                                    </div>
                                </>
                            ) : (
                                <Alert>
                                    <Shield className="h-4 w-4" />
                                    <AlertDescription>
                                        No records would be affected by this policy. All current records are within the
                                        retention period.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="flex justify-end">
                                <Button variant="outline" onClick={() => setIsPreviewOpen(false)}>
                                    Close
                                </Button>
                            </div>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
