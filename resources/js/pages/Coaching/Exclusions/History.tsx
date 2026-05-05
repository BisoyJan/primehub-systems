import { Head, Link, usePage } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ChevronLeft } from 'lucide-react';

import { usePageMeta, useFlashMessage } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';

interface HistoryRow {
    id: number;
    reason: string;
    notes: string | null;
    excluded_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    revoke_notes: string | null;
    excluded_by: string | null;
    revoked_by: string | null;
    is_active: boolean;
}

interface Props extends InertiaPageProps {
    user: { id: number; name: string; role: string };
    history: HistoryRow[];
}

export default function CoachingExclusionsHistory() {
    const { user, history } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: `Exclusion History — ${user.name}`,
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Coaching', href: '/coaching/dashboard' },
            { title: 'Exclusions', href: '/coaching/exclusions' },
            { title: user.name },
        ],
    });
    useFlashMessage();

    const fmt = (s: string | null) => (s ? new Date(s).toLocaleString() : '—');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <PageHeader title={title} description={`Role: ${user.role}`}>
                    <Link href="/coaching/exclusions">
                        <Button variant="outline" size="sm">
                            <ChevronLeft className="mr-1 h-4 w-4" /> Back
                        </Button>
                    </Link>
                </PageHeader>

                <div className="bg-card rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Reason</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Excluded At</TableHead>
                                <TableHead>Expires</TableHead>
                                <TableHead>Revoked At</TableHead>
                                <TableHead>Excluded By</TableHead>
                                <TableHead>Revoked By</TableHead>
                                <TableHead>Notes</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {history.map((h) => (
                                <TableRow key={h.id}>
                                    <TableCell className="font-medium">{h.reason}</TableCell>
                                    <TableCell>
                                        {h.is_active ? (
                                            <Badge variant="destructive">Active</Badge>
                                        ) : (
                                            <Badge variant="secondary">Ended</Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>{fmt(h.excluded_at)}</TableCell>
                                    <TableCell>{fmt(h.expires_at)}</TableCell>
                                    <TableCell>{fmt(h.revoked_at)}</TableCell>
                                    <TableCell>{h.excluded_by ?? '—'}</TableCell>
                                    <TableCell>{h.revoked_by ?? '—'}</TableCell>
                                    <TableCell className="text-xs">
                                        {h.notes && <div><strong>Note:</strong> {h.notes}</div>}
                                        {h.revoke_notes && <div><strong>Revoke:</strong> {h.revoke_notes}</div>}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {history.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={8} className="text-center text-muted-foreground py-8">
                                        No exclusion history.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
