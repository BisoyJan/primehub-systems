import { Head, Link, usePage, useForm } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { AlertTriangle, Calendar, CheckCircle2, Eye } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import PaginationNav from '@/components/pagination-nav';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { CoachingStatusBadge, AckStatusBadge, SeverityBadge } from '@/components/coaching/CoachingStatusBadge';

import { dashboard as coachingDashboard } from '@/routes/coaching';
import { show as sessionsShow, acknowledge as sessionsAcknowledge } from '@/routes/coaching/sessions';

import type { CoachingSession, CoachingSummary, CoachingPurposeLabels, CoachingStatusColors, PaginatedData } from '@/types';

interface Props extends InertiaPageProps {
    summary: CoachingSummary;
    sessions: PaginatedData<CoachingSession>;
    pendingSessions: number;
    purposes: CoachingPurposeLabels;
    statusColors: CoachingStatusColors;
}

export default function MyCoachingLogsIndex() {
    const { summary, sessions, pendingSessions, purposes } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'My Coaching Logs',
        breadcrumbs: [{ title: 'My Coaching Logs', href: coachingDashboard().url }],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const ackForm = useForm({ ack_comment: '' });

    const handleAcknowledge = (sessionId: number) => {
        ackForm.patch(sessionsAcknowledge(sessionId).url, {
            onSuccess: () => ackForm.reset(),
        });
    };

    const formatName = (user?: { first_name: string; last_name: string } | null) => {
        if (!user) return 'N/A';
        return `${user.first_name} ${user.last_name}`;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader title="My Coaching Logs" />

                {/* Pending Acknowledgements Banner */}
                {pendingSessions > 0 && (
                    <div className="flex items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950/30">
                        <AlertTriangle className="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div className="flex-1">
                            <p className="text-sm font-medium text-amber-800 dark:text-amber-300">
                                You have {pendingSessions} coaching session{pendingSessions > 1 ? 's' : ''} pending acknowledgement.
                            </p>
                            <p className="text-xs text-amber-600 dark:text-amber-400">
                                Please review and acknowledge your coaching sessions below.
                            </p>
                        </div>
                    </div>
                )}

                {/* Summary Card */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <div className="col-span-2 rounded-lg border bg-card p-4 shadow-sm sm:col-span-1">
                        <p className="text-xs font-medium text-muted-foreground">My Status</p>
                        <div className="mt-1">
                            <CoachingStatusBadge status={summary.coaching_status} />
                        </div>
                    </div>
                    <div className="rounded-lg border bg-card p-4 shadow-sm">
                        <p className="text-xs font-medium text-muted-foreground">Last Coached</p>
                        <p className="mt-1 text-lg font-bold">
                            {summary.last_coached_date ? new Date(summary.last_coached_date).toLocaleDateString() : 'Never'}
                        </p>
                    </div>
                    <div className="rounded-lg border bg-card p-4 shadow-sm">
                        <p className="text-xs font-medium text-muted-foreground">Previous</p>
                        <p className="mt-1 text-lg font-bold">
                            {summary.previous_coached_date ? new Date(summary.previous_coached_date).toLocaleDateString() : '—'}
                        </p>
                    </div>
                    <div className="rounded-lg border bg-card p-4 shadow-sm">
                        <p className="text-xs font-medium text-muted-foreground">Total Sessions</p>
                        <p className="mt-1 text-2xl font-bold">{summary.total_sessions}</p>
                    </div>
                    <div className="rounded-lg border bg-card p-4 shadow-sm">
                        <p className="text-xs font-medium text-muted-foreground">Pending Ack</p>
                        <p className={`mt-1 text-2xl font-bold ${summary.pending_acknowledgements > 0 ? 'text-amber-600' : ''}`}>
                            {summary.pending_acknowledgements}
                        </p>
                    </div>
                </div>

                {/* Sessions Table */}
                <div className="space-y-2">
                    <h3 className="flex items-center gap-2 text-sm font-semibold">
                        <Calendar className="h-4 w-4" /> Coaching Sessions ({sessions.total})
                    </h3>

                    {/* Desktop Table */}
                    <div className="hidden overflow-hidden rounded-md shadow md:block">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead>Date</TableHead>
                                        <TableHead>Team Lead</TableHead>
                                        <TableHead>Purpose</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Severity</TableHead>
                                        <TableHead className="text-center">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {sessions.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                                No coaching sessions found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        sessions.data.map((session) => (
                                            <TableRow key={session.id}>
                                                <TableCell className="whitespace-nowrap">
                                                    {new Date(session.session_date).toLocaleDateString()}
                                                </TableCell>
                                                <TableCell>{formatName(session.team_lead)}</TableCell>
                                                <TableCell>{purposes[session.purpose] ?? session.purpose}</TableCell>
                                                <TableCell>
                                                    <AckStatusBadge status={session.ack_status} />
                                                </TableCell>
                                                <TableCell>
                                                    <SeverityBadge flag={session.severity_flag} />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center justify-center gap-1">
                                                        <Link href={sessionsShow.url(session.id)}>
                                                            <Button variant="ghost" size="icon" title="View">
                                                                <Eye className="h-4 w-4" />
                                                            </Button>
                                                        </Link>
                                                        {session.ack_status === 'Pending' && (
                                                            <AlertDialog>
                                                                <AlertDialogTrigger asChild>
                                                                    <Button variant="ghost" size="icon" className="text-green-600 hover:text-green-700" title="Acknowledge">
                                                                        <CheckCircle2 className="h-4 w-4" />
                                                                    </Button>
                                                                </AlertDialogTrigger>
                                                                <AlertDialogContent className="max-w-[90vw] sm:max-w-md">
                                                                    <AlertDialogHeader>
                                                                        <AlertDialogTitle>Acknowledge Session</AlertDialogTitle>
                                                                        <AlertDialogDescription>
                                                                            Confirm you have reviewed this coaching session from {formatName(session.team_lead)} on{' '}
                                                                            {new Date(session.session_date).toLocaleDateString()}.
                                                                        </AlertDialogDescription>
                                                                    </AlertDialogHeader>
                                                                    <div className="space-y-3 py-2">
                                                                        <Label htmlFor={`ack-comment-${session.id}`}>Comment (optional)</Label>
                                                                        <Textarea
                                                                            id={`ack-comment-${session.id}`}
                                                                            value={ackForm.data.ack_comment}
                                                                            onChange={(e) => ackForm.setData('ack_comment', e.target.value)}
                                                                            placeholder="Add any comments..."
                                                                            rows={3}
                                                                        />
                                                                    </div>
                                                                    <AlertDialogFooter>
                                                                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                        <AlertDialogAction
                                                                            onClick={() => handleAcknowledge(session.id)}
                                                                            disabled={ackForm.processing}
                                                                            className="bg-green-600 hover:bg-green-700"
                                                                        >
                                                                            {ackForm.processing ? 'Submitting...' : 'Acknowledge'}
                                                                        </AlertDialogAction>
                                                                    </AlertDialogFooter>
                                                                </AlertDialogContent>
                                                            </AlertDialog>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </div>

                    {/* Mobile Card View */}
                    <div className="space-y-3 md:hidden">
                        {sessions.data.length === 0 ? (
                            <div className="py-8 text-center text-muted-foreground">No coaching sessions found.</div>
                        ) : (
                            sessions.data.map((session) => (
                                <div key={session.id} className="rounded-lg border bg-card p-4 shadow-sm space-y-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="text-xs text-muted-foreground">
                                                {new Date(session.session_date).toLocaleDateString()}
                                            </p>
                                            <p className="text-sm font-medium">{formatName(session.team_lead)}</p>
                                            <p className="text-xs text-muted-foreground">{purposes[session.purpose] ?? session.purpose}</p>
                                        </div>
                                        <div className="flex flex-col items-end gap-1">
                                            <AckStatusBadge status={session.ack_status} />
                                            <SeverityBadge flag={session.severity_flag} />
                                        </div>
                                    </div>
                                    <div className="flex flex-col gap-2 sm:flex-row">
                                        <Link href={sessionsShow.url(session.id)} className="flex-1">
                                            <Button variant="outline" size="sm" className="w-full">
                                                <Eye className="mr-1.5 h-3.5 w-3.5" /> View
                                            </Button>
                                        </Link>
                                        {session.ack_status === 'Pending' && (
                                            <AlertDialog>
                                                <AlertDialogTrigger asChild>
                                                    <Button size="sm" className="flex-1 bg-green-600 hover:bg-green-700 text-white">
                                                        <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" /> Acknowledge
                                                    </Button>
                                                </AlertDialogTrigger>
                                                <AlertDialogContent className="max-w-[90vw] sm:max-w-md">
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>Acknowledge Session</AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            Confirm you have reviewed this coaching session.
                                                        </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <div className="space-y-3 py-2">
                                                        <Label htmlFor={`ack-mobile-${session.id}`}>Comment (optional)</Label>
                                                        <Textarea
                                                            id={`ack-mobile-${session.id}`}
                                                            value={ackForm.data.ack_comment}
                                                            onChange={(e) => ackForm.setData('ack_comment', e.target.value)}
                                                            placeholder="Add any comments..."
                                                            rows={3}
                                                        />
                                                    </div>
                                                    <AlertDialogFooter>
                                                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                        <AlertDialogAction
                                                            onClick={() => handleAcknowledge(session.id)}
                                                            disabled={ackForm.processing}
                                                            className="bg-green-600 hover:bg-green-700"
                                                        >
                                                            {ackForm.processing ? 'Submitting...' : 'Acknowledge'}
                                                        </AlertDialogAction>
                                                    </AlertDialogFooter>
                                                </AlertDialogContent>
                                            </AlertDialog>
                                        )}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>

                    {/* Pagination */}
                    {sessions.last_page > 1 && (
                        <PaginationNav links={sessions.links} />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
