import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { ArrowLeft, Pencil, CheckCircle2, ShieldCheck, ShieldX } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import {
    AckStatusBadge,
    ComplianceStatusBadge,
    SeverityBadge,
} from '@/components/coaching/CoachingStatusBadge';

import {
    index as sessionsIndex,
    edit as sessionsEdit,
    acknowledge as sessionsAcknowledge,
    review as sessionsReview,
} from '@/routes/coaching/sessions';

import type { CoachingSession, CoachingPurposeLabels } from '@/types';

interface Props extends InertiaPageProps {
    session: CoachingSession;
    canAcknowledge: boolean;
    canReview: boolean;
    canEdit: boolean;
    purposes: CoachingPurposeLabels;
}

function InfoRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <dl className="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:gap-4">
            <dt className="text-sm font-medium text-muted-foreground">{label}</dt>
            <dd className="text-sm sm:col-span-2">{children}</dd>
        </dl>
    );
}

function CheckItem({ checked, label }: { checked: boolean; label: string }) {
    return (
        <div className="flex items-center gap-2 text-sm">
            <span className={checked ? 'text-green-600' : 'text-muted-foreground/40'}>
                {checked ? '✓' : '○'}
            </span>
            <span className={checked ? '' : 'text-muted-foreground/60'}>{label}</span>
        </div>
    );
}

function SectionCard({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="rounded-lg border bg-card p-4 shadow-sm">
            <h3 className="mb-3 border-b pb-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                {title}
            </h3>
            {children}
        </div>
    );
}

export default function CoachingSessionsShow() {
    const { session, canAcknowledge, canReview, canEdit, purposes } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Coaching Session Details',
        breadcrumbs: [
            { title: 'Coaching Sessions', href: sessionsIndex().url },
            { title: 'Details' },
        ],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    // Acknowledge form
    const ackForm = useForm({ ack_comment: '' });
    const handleAcknowledge = (e: React.MouseEvent) => {
        e.preventDefault();
        ackForm.patch(sessionsAcknowledge(session.id).url);
    };

    // Review form
    const reviewForm = useForm({ compliance_status: '' as string, compliance_notes: '' });
    const handleReview = (e: React.MouseEvent) => {
        e.preventDefault();
        reviewForm.patch(sessionsReview(session.id).url);
    };

    const formatName = (user?: { first_name: string; last_name: string } | null) => {
        if (!user) return 'N/A';
        return `${user.first_name} ${user.last_name}`;
    };

    const formatDate = (date: string | null) => {
        if (!date) return 'N/A';
        return new Date(date).toLocaleDateString();
    };

    const formatDateTime = (date: string | null) => {
        if (!date) return 'N/A';
        return new Date(date).toLocaleString();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative mx-auto flex w-full max-w-4xl flex-col gap-4 rounded-xl p-3 md:p-6">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader
                    title="Coaching Session Details"
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Link href={sessionsIndex().url}>
                                <Button variant="outline">
                                    <ArrowLeft className="mr-2 h-4 w-4" />
                                    Back to list
                                </Button>
                            </Link>
                            {canEdit && (
                                <Link href={sessionsEdit.url(session.id)}>
                                    <Button variant="outline">
                                        <Pencil className="mr-2 h-4 w-4" />
                                        Edit
                                    </Button>
                                </Link>
                            )}
                        </div>
                    }
                />

                {/* Status Bar */}
                <div className="flex flex-wrap items-center gap-3 rounded-lg border bg-card p-3 shadow-sm">
                    <AckStatusBadge status={session.ack_status} />
                    <ComplianceStatusBadge status={session.compliance_status} />
                    <SeverityBadge flag={session.severity_flag} />
                </div>

                {/* Session Details */}
                <SectionCard title="Session Details">
                    <dl className="space-y-3">
                        <InfoRow label="Agent">{formatName(session.agent)}</InfoRow>
                        <InfoRow label="Team Lead">{formatName(session.team_lead)}</InfoRow>
                        <InfoRow label="Session Date">{formatDate(session.session_date)}</InfoRow>
                        <InfoRow label="Purpose">{purposes[session.purpose] ?? session.purpose}</InfoRow>
                        <InfoRow label="Severity">{session.severity_flag}</InfoRow>
                        {session.follow_up_date && (
                            <InfoRow label="Follow-up Date">{formatDate(session.follow_up_date)}</InfoRow>
                        )}
                    </dl>
                </SectionCard>

                {/* Agent Profile */}
                <SectionCard title="Agent Profile">
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <CheckItem checked={session.profile_new_hire} label="New Hire" />
                        <CheckItem checked={session.profile_tenured} label="Tenured" />
                        <CheckItem checked={session.profile_returning} label="Returning" />
                        <CheckItem checked={session.profile_previously_coached_same_issue} label="Previously Coached (Same Issue)" />
                    </div>
                </SectionCard>

                {/* Focus Areas */}
                <SectionCard title="Focus Areas">
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <CheckItem checked={session.focus_attendance_tardiness} label="Attendance / Tardiness" />
                        <CheckItem checked={session.focus_productivity} label="Productivity" />
                        <CheckItem checked={session.focus_compliance} label="Compliance" />
                        <CheckItem checked={session.focus_callouts} label="Callouts" />
                        <CheckItem checked={session.focus_recognition_milestones} label="Recognition / Milestones" />
                        <CheckItem checked={session.focus_growth_development} label="Growth / Development" />
                        <CheckItem checked={session.focus_other} label="Other" />
                    </div>
                    {session.focus_other && session.focus_other_notes && (
                        <p className="mt-3 rounded bg-muted/50 p-3 text-sm">{session.focus_other_notes}</p>
                    )}
                </SectionCard>

                {/* Performance Description */}
                <SectionCard title="Performance Description">
                    <p className="whitespace-pre-wrap text-sm">{session.performance_description}</p>
                </SectionCard>

                {/* Root Causes */}
                <SectionCard title="Root Causes">
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <CheckItem checked={session.root_cause_lack_of_skills} label="Lack of Skills / Knowledge" />
                        <CheckItem checked={session.root_cause_lack_of_clarity} label="Lack of Clarity" />
                        <CheckItem checked={session.root_cause_personal_issues} label="Personal Issues" />
                        <CheckItem checked={session.root_cause_motivation_engagement} label="Motivation / Engagement" />
                        <CheckItem checked={session.root_cause_health_fatigue} label="Health / Fatigue" />
                        <CheckItem checked={session.root_cause_workload_process} label="Workload / Process" />
                        <CheckItem checked={session.root_cause_peer_conflict} label="Peer Conflict" />
                        <CheckItem checked={session.root_cause_others} label="Others" />
                    </div>
                    {session.root_cause_others && session.root_cause_others_notes && (
                        <p className="mt-3 rounded bg-muted/50 p-3 text-sm">{session.root_cause_others_notes}</p>
                    )}
                </SectionCard>

                {/* Agent Strengths */}
                {session.agent_strengths_wins && (
                    <SectionCard title="Agent Strengths / Wins">
                        <p className="whitespace-pre-wrap text-sm">{session.agent_strengths_wins}</p>
                    </SectionCard>
                )}

                {/* SMART Action Plan */}
                <SectionCard title="SMART Action Plan">
                    <p className="whitespace-pre-wrap text-sm">{session.smart_action_plan}</p>
                </SectionCard>

                {/* Acknowledgement & Compliance Info */}
                <SectionCard title="Acknowledgement & Compliance">
                    <dl className="space-y-3">
                        <InfoRow label="Ack Status">
                            <AckStatusBadge status={session.ack_status} />
                        </InfoRow>
                        {session.ack_timestamp && (
                            <InfoRow label="Acknowledged At">{formatDateTime(session.ack_timestamp)}</InfoRow>
                        )}
                        {session.ack_comment && <InfoRow label="Ack Comment">{session.ack_comment}</InfoRow>}
                        <InfoRow label="Compliance Status">
                            <ComplianceStatusBadge status={session.compliance_status} />
                        </InfoRow>
                        {session.compliance_reviewer && (
                            <InfoRow label="Reviewed By">{formatName(session.compliance_reviewer)}</InfoRow>
                        )}
                        {session.compliance_review_timestamp && (
                            <InfoRow label="Reviewed At">{formatDateTime(session.compliance_review_timestamp)}</InfoRow>
                        )}
                        {session.compliance_notes && (
                            <InfoRow label="Compliance Notes">{session.compliance_notes}</InfoRow>
                        )}
                    </dl>
                </SectionCard>

                {/* Action Buttons */}
                <div className="flex flex-col gap-3 sm:flex-row">
                    {/* Acknowledge Dialog */}
                    {canAcknowledge && (
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button className="flex-1 bg-green-600 hover:bg-green-700 text-white sm:flex-none">
                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                    Acknowledge Session
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent className="max-w-[90vw] sm:max-w-md">
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Acknowledge Coaching Session</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        By acknowledging, you confirm you have reviewed this coaching session. You may
                                        optionally add a comment.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <div className="space-y-3 py-2">
                                    <Label htmlFor="ack_comment">Comment (optional)</Label>
                                    <Textarea
                                        id="ack_comment"
                                        value={ackForm.data.ack_comment}
                                        onChange={(e) => ackForm.setData('ack_comment', e.target.value)}
                                        placeholder="Add any comments about this coaching session..."
                                        rows={3}
                                    />
                                    {ackForm.errors.ack_comment && (
                                        <p className="text-sm text-red-600">{ackForm.errors.ack_comment}</p>
                                    )}
                                </div>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={handleAcknowledge}
                                        disabled={ackForm.processing}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                        {ackForm.processing ? 'Submitting...' : 'Acknowledge'}
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    )}

                    {/* Review Dialog */}
                    {canReview && (
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button className="flex-1 bg-blue-600 hover:bg-blue-700 text-white sm:flex-none">
                                    <ShieldCheck className="mr-2 h-4 w-4" />
                                    Review Session
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent className="max-w-[90vw] sm:max-w-md">
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Review Coaching Session</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Verify or reject this coaching session for compliance.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <div className="space-y-3 py-2">
                                    <div>
                                        <Label htmlFor="compliance_status">Decision</Label>
                                        <Select
                                            value={reviewForm.data.compliance_status}
                                            onValueChange={(val) => reviewForm.setData('compliance_status', val)}
                                        >
                                            <SelectTrigger id="compliance_status">
                                                <SelectValue placeholder="Select decision" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Verified">
                                                    <span className="flex items-center gap-2">
                                                        <ShieldCheck className="h-4 w-4 text-green-600" /> Verify
                                                    </span>
                                                </SelectItem>
                                                <SelectItem value="Rejected">
                                                    <span className="flex items-center gap-2">
                                                        <ShieldX className="h-4 w-4 text-red-600" /> Reject
                                                    </span>
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {reviewForm.errors.compliance_status && (
                                            <p className="text-sm text-red-600">{reviewForm.errors.compliance_status}</p>
                                        )}
                                    </div>
                                    <div>
                                        <Label htmlFor="compliance_notes">Notes (optional)</Label>
                                        <Textarea
                                            id="compliance_notes"
                                            value={reviewForm.data.compliance_notes}
                                            onChange={(e) => reviewForm.setData('compliance_notes', e.target.value)}
                                            placeholder="Add compliance review notes..."
                                            rows={3}
                                        />
                                        {reviewForm.errors.compliance_notes && (
                                            <p className="text-sm text-red-600">{reviewForm.errors.compliance_notes}</p>
                                        )}
                                    </div>
                                </div>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={handleReview}
                                        disabled={reviewForm.processing || !reviewForm.data.compliance_status}
                                        className="bg-blue-600 hover:bg-blue-700"
                                    >
                                        {reviewForm.processing ? 'Submitting...' : 'Submit Review'}
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    )}
                </div>

                {/* Timestamps */}
                <div className="text-xs text-muted-foreground">
                    Created: {formatDateTime(session.created_at)} · Updated: {formatDateTime(session.updated_at)}
                </div>
            </div>
        </AppLayout>
    );
}
