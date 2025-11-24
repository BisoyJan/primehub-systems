import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Shield, Plus, Pencil, Trash2, Loader2 } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Can } from '@/components/authorization';

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
    form_type: 'all' | 'leave_request' | 'it_concern' | 'medication_request';
    priority: number;
    is_active: boolean;
}

interface FormData {
    name: string;
    description: string;
    retention_months: number;
    applies_to_type: 'global' | 'site';
    applies_to_id: number | null;
    form_type: 'all' | 'leave_request' | 'it_concern' | 'medication_request';
    priority: number;
    is_active: boolean;
}

export default function RetentionPolicies({ policies, sites }: { policies: Policy[]; sites: Site[] }) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Form Request Retention Policies',
        breadcrumbs: [
            { title: 'Form Requests', href: '/form-requests' },
            { title: 'Retention Policies', href: '/form-requests/retention-policies' },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [editingPolicy, setEditingPolicy] = useState<Policy | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [formData, setFormData] = useState<FormData>({
        name: '',
        description: '',
        retention_months: 12,
        applies_to_type: 'global',
        applies_to_id: null,
        form_type: 'all',
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
            form_type: 'all',
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
            form_type: policy.form_type,
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
            form_type: formData.form_type,
            priority: formData.priority,
            is_active: formData.is_active,
        };

        if (editingPolicy) {
            router.put(`/form-requests/retention-policies/${editingPolicy.id}`, payload, {
                onSuccess: () => {
                    setIsDialogOpen(false);
                    resetForm();
                },
                onFinish: () => setIsSubmitting(false),
            });
        } else {
            router.post('/form-requests/retention-policies', payload, {
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

        router.delete(`/form-requests/retention-policies/${policyId}`);
    };

    const handleToggle = (policyId: number) => {
        router.post(`/form-requests/retention-policies/${policyId}/toggle`);
    };

    const getFormTypeBadge = (formType: string) => {
        const config: Record<string, { label: string; className: string }> = {
            all: { label: 'All Forms', className: 'bg-purple-500' },
            leave_request: { label: 'Leave Requests', className: 'bg-blue-500' },
            it_concern: { label: 'IT Concerns', className: 'bg-orange-500' },
            medication_request: { label: 'Medication Requests', className: 'bg-green-500' },
        };

        const { label, className } = config[formType] || { label: formType, className: 'bg-gray-500' };
        return <Badge className={className}>{label}</Badge>;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isPageLoading} />

            <div className="container mx-auto px-4 py-8">
                <div className="flex justify-between items-center mb-6">
                    <div>
                        <h1 className="text-3xl font-bold">Retention Policies</h1>
                        <p className="text-muted-foreground mt-2">
                            Manage data retention rules for form request records
                        </p>
                    </div>
                    <Can permission="form_requests.retention">
                        <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
                            <DialogTrigger asChild>
                                <Button onClick={openCreateDialog}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Policy
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-w-2xl">
                                <DialogHeader>
                                    <DialogTitle>
                                        {editingPolicy ? 'Edit' : 'Create'} Retention Policy
                                    </DialogTitle>
                                    <DialogDescription>
                                        Define rules for how long form request records should be retained
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
                                            placeholder="e.g., Standard Form Retention Policy"
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
                                        <Label htmlFor="form-type">Form Type *</Label>
                                        <select
                                            id="form-type"
                                            value={formData.form_type}
                                            onChange={(e) => setFormData({
                                                ...formData,
                                                form_type: e.target.value as FormData['form_type']
                                            })}
                                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            <option value="all">All Form Types</option>
                                            <option value="leave_request">Leave Requests Only</option>
                                            <option value="it_concern">IT Concerns Only</option>
                                            <option value="medication_request">Medication Requests Only</option>
                                        </select>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="applies-to-type">Applies To *</Label>
                                        <select
                                            id="applies-to-type"
                                            value={formData.applies_to_type}
                                            onChange={(e) => setFormData({
                                                ...formData,
                                                applies_to_type: e.target.value as 'global' | 'site',
                                                applies_to_id: e.target.value === 'global' ? null : formData.applies_to_id
                                            })}
                                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            <option value="global">All Sites (Global)</option>
                                            <option value="site">Specific Site</option>
                                        </select>
                                    </div>

                                    {formData.applies_to_type === 'site' && (
                                        <div className="space-y-2">
                                            <Label htmlFor="site">Select Site *</Label>
                                            <select
                                                id="site"
                                                value={formData.applies_to_id || ''}
                                                onChange={(e) => setFormData({
                                                    ...formData,
                                                    applies_to_id: parseInt(e.target.value)
                                                })}
                                                required
                                                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                <option value="">Select a site...</option>
                                                {sites.map((site) => (
                                                    <option key={site.id} value={site.id}>
                                                        {site.name}
                                                    </option>
                                                ))}
                                            </select>
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
                                Retention policies control how long form request records (Leave Requests, IT Concerns,
                                Medication Requests) are kept. Higher priority policies override lower priority ones.
                                Global policies apply to all sites unless a site-specific policy exists.
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="overflow-x-auto">
                            {policies.length === 0 ? (
                                <p className="text-center text-muted-foreground py-8">
                                    No retention policies configured. Click "Add Policy" to create one.
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Form Type</TableHead>
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
                                                <TableCell>
                                                    {getFormTypeBadge(policy.form_type)}
                                                </TableCell>
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
                                                        <Can permission="form_requests.retention">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => openEditDialog(policy)}
                                                            >
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                        </Can>
                                                        <Can permission="form_requests.retention">
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
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
