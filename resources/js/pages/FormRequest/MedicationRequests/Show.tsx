import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ArrowLeft, CheckCircle, XCircle, Package } from 'lucide-react';
import { Can } from '@/components/authorization';

interface MedicationRequest {
    id: number;
    name: string;
    work_email: string;
    medication_type: string;
    reason: string;
    onset_of_symptoms: string;
    agrees_to_policy: boolean;
    status: 'pending' | 'approved' | 'dispensed' | 'rejected';
    admin_notes: string | null;
    created_at: string;
    approved_at: string | null;
    user?: {
        name: string;
    };
    approved_by_user?: {
        name: string;
    };
}

interface Props {
    medicationRequest: MedicationRequest;
}

export default function Show({ medicationRequest }: Props) {
    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [actionType, setActionType] = useState<'approved' | 'dispensed' | 'rejected'>('approved');

    const { data, setData, post, processing, errors, reset } = useForm({
        status: '',
        admin_notes: medicationRequest.admin_notes || '',
    });

    const openDialog = (type: 'approved' | 'dispensed' | 'rejected') => {
        setActionType(type);
        setIsDialogOpen(true);
    };

    const handleStatusUpdate = () => {
        setData('status', actionType);
        post(`/form-requests/medication-requests/${medicationRequest.id}/status`, {
            preserveScroll: true,
            onSuccess: () => {
                setIsDialogOpen(false);
                reset();
            },
        });
    };

    const getStatusBadge = (status: string) => {
        const colors = {
            pending: 'bg-yellow-100 text-yellow-800 border-yellow-300',
            approved: 'bg-blue-100 text-blue-800 border-blue-300',
            dispensed: 'bg-green-100 text-green-800 border-green-300',
            rejected: 'bg-red-100 text-red-800 border-red-300',
        };

        return (
            <Badge variant="outline" className={colors[status as keyof typeof colors]}>
                {status.charAt(0).toUpperCase() + status.slice(1)}
            </Badge>
        );
    };

    return (
        <AppLayout>
            <Head title={`Medication Request - ${medicationRequest.name}`} />

            <div className="container mx-auto px-4 py-8">
                <div className="flex items-center justify-between mb-6">
                    <div className="flex items-center gap-4">
                        <Link href="/form-requests/medication-requests">
                            <Button variant="ghost" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back
                            </Button>
                        </Link>
                        <div>
                            <h1 className="text-3xl font-bold">Medication Request Details</h1>
                            <p className="text-muted-foreground mt-2">
                                Request ID: #{medicationRequest.id}
                            </p>
                        </div>
                    </div>
                    {getStatusBadge(medicationRequest.status)}
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Request Information</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label className="text-muted-foreground">Employee Name</Label>
                                <p className="text-lg font-medium">{medicationRequest.name}</p>
                            </div>

                            <Separator />

                            <div>
                                <Label className="text-muted-foreground">Medication Type</Label>
                                <p className="text-lg font-medium">{medicationRequest.medication_type}</p>
                            </div>

                            <Separator />

                            <div>
                                <Label className="text-muted-foreground">Onset of Symptoms</Label>
                                <p className="text-lg capitalize">{medicationRequest.onset_of_symptoms.replace(/_/g, ' ')}</p>
                            </div>

                            <Separator />

                            <div>
                                <Label className="text-muted-foreground">Reason for Request</Label>
                                <p className="text-base whitespace-pre-wrap mt-2">{medicationRequest.reason}</p>
                            </div>

                            <Separator />

                            <div>
                                <Label className="text-muted-foreground">Policy Agreement</Label>
                                <p className="text-base">
                                    {medicationRequest.agrees_to_policy ? (
                                        <span className="text-green-600 font-medium">✓ Agreed to policy</span>
                                    ) : (
                                        <span className="text-red-600 font-medium">✗ Did not agree to policy</span>
                                    )}
                                </p>
                            </div>

                            <Separator />

                            <div>
                                <Label className="text-muted-foreground">Requested Date</Label>
                                <p className="text-base">
                                    {new Date(medicationRequest.created_at).toLocaleString()}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Status Timeline</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-start gap-3">
                                    <div className="rounded-full bg-blue-100 p-2">
                                        <Package className="h-4 w-4 text-blue-600" />
                                    </div>
                                    <div>
                                        <p className="font-medium">Request Submitted</p>
                                        <p className="text-sm text-muted-foreground">
                                            {new Date(medicationRequest.created_at).toLocaleString()}
                                        </p>
                                    </div>
                                </div>

                                {medicationRequest.approved_at && medicationRequest.approved_by_user && (
                                    <div className="flex items-start gap-3">
                                        <div className="rounded-full bg-green-100 p-2">
                                            <CheckCircle className="h-4 w-4 text-green-600" />
                                        </div>
                                        <div>
                                            <p className="font-medium">
                                                {medicationRequest.status === 'rejected' ? 'Rejected' : 'Approved'}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                By {medicationRequest.approved_by_user.name}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {new Date(medicationRequest.approved_at).toLocaleString()}
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {medicationRequest.status === 'dispensed' && (
                                    <div className="flex items-start gap-3">
                                        <div className="rounded-full bg-purple-100 p-2">
                                            <Package className="h-4 w-4 text-purple-600" />
                                        </div>
                                        <div>
                                            <p className="font-medium">Medication Dispensed</p>
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Can permission="medication_requests.update">
                            {medicationRequest.status === 'pending' && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Update Request Status</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="flex gap-2">
                                            <Button
                                                onClick={() => openDialog('approved')}
                                                disabled={processing}
                                                className="flex-1"
                                            >
                                                <CheckCircle className="mr-2 h-4 w-4" />
                                                Approve
                                            </Button>
                                            <Button
                                                onClick={() => openDialog('rejected')}
                                                disabled={processing}
                                                variant="destructive"
                                                className="flex-1"
                                            >
                                                <XCircle className="mr-2 h-4 w-4" />
                                                Reject
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {medicationRequest.status === 'approved' && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Mark as Dispensed</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <Button
                                            onClick={() => openDialog('dispensed')}
                                            disabled={processing}
                                            className="w-full"
                                        >
                                            <Package className="mr-2 h-4 w-4" />
                                            Mark as Dispensed
                                        </Button>
                                    </CardContent>
                                </Card>
                            )}
                        </Can>

                        {medicationRequest.admin_notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Admin Notes</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="whitespace-pre-wrap">{medicationRequest.admin_notes}</p>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>

                <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {actionType === 'approved' && 'Approve Medication Request'}
                                {actionType === 'rejected' && 'Reject Medication Request'}
                                {actionType === 'dispensed' && 'Mark as Dispensed'}
                            </DialogTitle>
                            <DialogDescription>
                                {actionType === 'dispensed'
                                    ? 'Confirm that the medication has been dispensed to the employee.'
                                    : 'Add optional notes and confirm your decision.'
                                }
                            </DialogDescription>
                        </DialogHeader>

                        {actionType !== 'dispensed' && (
                            <div className="space-y-2">
                                <Label htmlFor="admin_notes">Admin Notes (Optional)</Label>
                                <Textarea
                                    id="admin_notes"
                                    placeholder="Add any notes about this request..."
                                    value={data.admin_notes}
                                    onChange={(e) => setData('admin_notes', e.target.value)}
                                    rows={4}
                                />
                                {errors.admin_notes && (
                                    <p className="text-sm text-red-600">{errors.admin_notes}</p>
                                )}
                            </div>
                        )}

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsDialogOpen(false)}
                                disabled={processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                onClick={handleStatusUpdate}
                                disabled={processing}
                                variant={actionType === 'rejected' ? 'destructive' : 'default'}
                            >
                                {processing ? 'Processing...' : 'Confirm'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
