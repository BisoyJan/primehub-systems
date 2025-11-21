import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Shield, Plus, Pencil, Trash2, Loader2 } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
    priority: number;
    is_active: boolean;
}

interface FormData {
    name: string;
    description: string;
    retention_months: number;
    applies_to_type: 'global' | 'site';
    applies_to_id: number | null;
    priority: number;
    is_active: boolean;
}

export default function RetentionPolicies({ policies, sites }: { policies: Policy[]; sites: Site[] }) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Retention Policies',
        breadcrumbs: [
            { title: 'Attendance', href: '/attendance' },
            { title: 'Retention Policies', href: '/biometric-retention-policies' },
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
            priority: formData.priority,
            is_active: formData.is_active,
        };

        if (editingPolicy) {
            router.put(`/biometric-retention-policies/${editingPolicy.id}`, payload, {
                onSuccess: () => {
                    setIsDialogOpen(false);
                    resetForm();
                },
                onFinish: () => setIsSubmitting(false),
            });
        } else {
            router.post('/biometric-retention-policies', payload, {
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

        router.delete(`/biometric-retention-policies/${policyId}`);
    };

    const handleToggle = (policyId: number) => {
        router.post(`/biometric-retention-policies/${policyId}/toggle`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <PageHeader title={title} />
            <LoadingOverlay isLoading={isPageLoading} />

            <div className="py-6">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="space-y-6">
                        <Alert>
                            <Shield className="h-4 w-4" />
                            <AlertDescription>
                                Retention policies control how long biometric scan records are kept.
                                Higher priority policies override lower priority ones. Global policies apply
                                to all sites unless a site-specific policy exists.
                            </AlertDescription>
                        </Alert>

                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle>Retention Policies</CardTitle>
                                        <CardDescription>
                                            Manage data retention rules for biometric records
                                        </CardDescription>
                                    </div>
                                    <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
                                        <Can permission="biometric.retention">
                                            <DialogTrigger asChild>
                                                <Button onClick={openCreateDialog}>
                                                    <Plus className="mr-2 h-4 w-4" />
                                                    Add Policy
                                                </Button>
                                            </DialogTrigger>
                                        </Can>
                                        <DialogContent className="max-w-2xl">
                                            <DialogHeader>
                                                <DialogTitle>
                                                    {editingPolicy ? 'Edit' : 'Create'} Retention Policy
                                                </DialogTitle>
                                                <DialogDescription>
                                                    Define rules for how long biometric records should be retained
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
                                </div>
                            </CardHeader>
                            <CardContent>
                                {policies.length === 0 ? (
                                    <p className="text-center text-muted-foreground py-8">
                                        No retention policies configured. Click "Add Policy" to create one.
                                    </p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Name</TableHead>
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
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
