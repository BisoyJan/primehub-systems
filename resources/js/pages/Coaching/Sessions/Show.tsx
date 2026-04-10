import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { ArrowLeft, Pencil, Printer, CheckCircle2, ShieldCheck, ShieldX, ZoomIn, ZoomOut, RotateCcw, History, SendHorizonal } from 'lucide-react';
import DOMPurify from 'dompurify';

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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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
    show as sessionsShow,
    edit as sessionsEdit,
    acknowledge as sessionsAcknowledge,
    review as sessionsReview,
    submit as sessionsSubmit,
    attachment as sessionsAttachment,
} from '@/routes/coaching/sessions';

import type { CoachingSession, CoachingPurposeLabels } from '@/types';

/** Strip near-black inline color styles that break dark mode readability */
function sanitizeRichHtml(html: string | null | undefined): string {
    if (!html) return '';
    return DOMPurify.sanitize(
        html.replace(/color:\s*(?:#(?:1a1a1a|000000|111|222|333)|rgb\(\s*26,\s*26,\s*26\s*\))/gi, 'color: inherit')
    );
}

interface CoachingHistoryItem {
    id: number;
    session_date: string;
    purpose: string;
    severity_flag: string | null;
    compliance_status: string;
    ack_status: string;
    coach: { id: number; first_name: string; last_name: string } | null;
}

interface Props extends InertiaPageProps {
    session: CoachingSession;
    canAcknowledge: boolean;
    canReview: boolean;
    canEdit: boolean;
    canSubmitDraft: boolean;
    purposes: CoachingPurposeLabels;
    coaching_history: CoachingHistoryItem[];
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
    const { session, canAcknowledge, canReview, canEdit, canSubmitDraft, purposes, coaching_history } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Coaching Session Details',
        breadcrumbs: [
            { title: 'Coaching Sessions', href: sessionsIndex().url },
            { title: 'Details' },
        ],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    // Dialog open states
    const [ackDialogOpen, setAckDialogOpen] = useState(false);
    const [reviewDialogOpen, setReviewDialogOpen] = useState(false);

    // Attachment lightbox
    const [selectedAttachment, setSelectedAttachment] = useState<{
        id: number;
        original_filename: string;
    } | null>(null);
    const [imageZoom, setImageZoom] = useState(100);

    // Acknowledge form
    const ackForm = useForm({ ack_comment: '', agent_response: '' });
    const handleAcknowledge = () => {
        ackForm.patch(sessionsAcknowledge(session.id).url);
    };

    // Review form
    const reviewForm = useForm({ compliance_status: '' as string, compliance_notes: '' });
    const handleReview = () => {
        reviewForm.patch(sessionsReview(session.id).url);
    };

    const formatName = (user?: { first_name: string; last_name: string } | null) => {
        if (!user) return 'N/A';
        return `${user.first_name} ${user.last_name}`;
    };

    const formatDate = (date: string | null) => {
        if (!date) return 'N/A';
        return new Date(date).toLocaleDateString('en-US', {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const formatDateTime = (date: string | null) => {
        if (!date) return 'N/A';
        return new Date(date).toLocaleString('en-US', {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <style>{`
                @media print {
                    nav, header, [data-sidebar], .no-print, button { display: none !important; }
                    .print-only { display: block !important; }
                    body { background: white !important; }
                }
            `}</style>

            <div className="relative mx-auto flex w-full max-w-4xl flex-col gap-4 rounded-xl p-3 md:p-6">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader
                    title="Coaching Session Details"
                    actions={
                        <div className="no-print flex flex-wrap gap-2">
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
                            <Button variant="outline" size="sm" onClick={() => window.print()}>
                                <Printer className="mr-1.5 h-4 w-4" /> Print
                            </Button>
                        </div>
                    }
                />

                {/* Draft Banner */}
                {session.is_draft && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 shadow-sm dark:border-amber-800 dark:bg-amber-950/30">
                        <div className="flex items-center gap-2">
                            <span className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-400">
                                Draft
                            </span>
                            <span className="text-sm text-amber-800 dark:text-amber-300">
                                This coaching session has not been submitted yet. The coachee will not see it until it is submitted.
                            </span>
                        </div>
                        {canSubmitDraft && (
                            <Link href={sessionsEdit.url(session.id)}>
                                <Button size="sm" className="bg-green-600 hover:bg-green-700 text-white">
                                    <SendHorizonal className="mr-2 h-4 w-4" />
                                    Edit &amp; Submit
                                </Button>
                            </Link>
                        )}
                    </div>
                )}

                {/* Status Bar */}
                <div className="flex flex-wrap items-center gap-3 rounded-lg border bg-card p-3 shadow-sm">
                    <AckStatusBadge status={session.ack_status} />
                    <ComplianceStatusBadge status={session.compliance_status} />
                    <SeverityBadge flag={session.severity_flag} />
                </div>

                {/* Session Details */}
                <SectionCard title="Session Details">
                    <dl className="space-y-3">
                        <InfoRow label="Coachee">{formatName(session.coachee)}</InfoRow>
                        <InfoRow label="Coach">{formatName(session.coach)}</InfoRow>
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
                    <div className="whitespace-pre-wrap text-sm text-gray-900 bg-white rounded-md p-3 prose prose-sm max-w-none [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:list-decimal [&_ol]:pl-6" dangerouslySetInnerHTML={{ __html: sanitizeRichHtml(session.performance_description) }} />
                </SectionCard>

                {/* Attachments */}
                {session.attachments && session.attachments.length > 0 && (
                    <SectionCard title="Attachments">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                            {session.attachments.map((attachment) => (
                                <button
                                    key={attachment.id}
                                    type="button"
                                    className="group relative aspect-square overflow-hidden rounded-lg border bg-muted/50 focus:outline-none focus:ring-2 focus:ring-ring"
                                    onClick={() => {
                                        setSelectedAttachment(attachment);
                                        setImageZoom(100);
                                    }}
                                >
                                    <img
                                        src={sessionsAttachment({ session: session.id, attachment: attachment.id }).url}
                                        alt={attachment.original_filename}
                                        className="h-full w-full object-cover transition-transform group-hover:scale-105"
                                    />
                                    <div className="absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/20">
                                        <ZoomIn className="h-6 w-6 text-white opacity-0 transition-opacity group-hover:opacity-100" />
                                    </div>
                                    <p className="absolute bottom-0 left-0 right-0 truncate bg-black/50 px-2 py-1 text-xs text-white">
                                        {attachment.original_filename}
                                    </p>
                                </button>
                            ))}
                        </div>
                        <p className="mt-2 text-xs text-muted-foreground">
                            {session.attachments.length} attachment{session.attachments.length !== 1 ? 's' : ''}
                        </p>
                    </SectionCard>
                )}

                {/* Root Causes */}
                <SectionCard title="Root Causes">
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <CheckItem checked={session.root_cause_lack_of_skills} label="Lack of Skills / Knowledge" />
                        <CheckItem checked={session.root_cause_lack_of_clarity} label="Lack of Clarity on Expectations" />
                        <CheckItem checked={session.root_cause_personal_issues} label="Personal Issues" />
                        <CheckItem checked={session.root_cause_motivation_engagement} label="Motivation / Engagement" />
                        <CheckItem checked={session.root_cause_health_fatigue} label="Health / Fatigue" />
                        <CheckItem checked={session.root_cause_workload_process} label="Workload or Process Issues" />
                        <CheckItem checked={session.root_cause_peer_conflict} label="Peer / Team Conflict" />
                        <CheckItem checked={session.root_cause_others} label="Progress Update" />
                    </div>
                </SectionCard>

                {/* Agent Strengths */}
                {session.agent_strengths_wins && (
                    <SectionCard title="Agent Strengths / Wins">
                        <div className="whitespace-pre-wrap text-sm text-gray-900 bg-white rounded-md p-3 prose prose-sm max-w-none [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:list-decimal [&_ol]:pl-6" dangerouslySetInnerHTML={{ __html: sanitizeRichHtml(session.agent_strengths_wins) }} />
                    </SectionCard>
                )}

                {/* SMART Action Plan */}
                <SectionCard title="SMART Action Plan">
                    <div className="whitespace-pre-wrap text-sm text-gray-900 bg-white rounded-md p-3 prose prose-sm max-w-none [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:list-decimal [&_ol]:pl-6" dangerouslySetInnerHTML={{ __html: sanitizeRichHtml(session.smart_action_plan) }} />
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
                        {session.agent_response && (
                            <InfoRow label="Agent Response">
                                <div className="space-y-1">
                                    <div className="whitespace-pre-wrap">{session.agent_response}</div>
                                    {session.agent_response_at && (
                                        <p className="text-xs text-muted-foreground">
                                            Responded on {formatDateTime(session.agent_response_at)}
                                        </p>
                                    )}
                                </div>
                            </InfoRow>
                        )}
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

                {/* Coaching History */}
                {coaching_history && coaching_history.length > 0 && (
                    <div className="rounded-lg border bg-card p-4 shadow-sm">
                        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                            <History className="h-4 w-4" />
                            Recent Coaching History ({coaching_history.length})
                        </h3>
                        <div className="space-y-2">
                            {coaching_history.map((item) => (
                                <Link key={item.id} href={sessionsShow.url(item.id)}>
                                    <div className="flex items-center justify-between rounded-md border p-3 transition-colors hover:bg-muted/50">
                                        <div className="flex flex-col gap-0.5">
                                            <span className="text-sm font-medium">
                                                {formatDate(item.session_date)}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {item.purpose}{item.coach ? ` \u2022 Coach: ${item.coach.first_name} ${item.coach.last_name}` : ''}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <ComplianceStatusBadge status={item.compliance_status} />
                                        </div>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </div>
                )}

                {/* Action Buttons */}
                <div className="flex flex-col gap-3 sm:flex-row">
                    {/* Acknowledge Dialog */}
                    {canAcknowledge && (
                        <Dialog open={ackDialogOpen} onOpenChange={setAckDialogOpen}>
                            <DialogTrigger asChild>
                                <Button className="flex-1 bg-green-600 hover:bg-green-700 text-white sm:flex-none">
                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                    Acknowledge Session
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-w-[90vw] sm:max-w-md">
                                <DialogHeader>
                                    <DialogTitle>Acknowledge Coaching Session</DialogTitle>
                                    <DialogDescription>
                                        By acknowledging, you confirm you have reviewed this coaching session. You may
                                        optionally add a comment.
                                    </DialogDescription>
                                </DialogHeader>
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
                                    <div className="space-y-2">
                                        <Label htmlFor="agent_response">Your Reflection / Response (optional)</Label>
                                        <Textarea
                                            id="agent_response"
                                            value={ackForm.data.agent_response}
                                            onChange={(e) => ackForm.setData('agent_response', e.target.value)}
                                            placeholder="Share your thoughts, reflections, or commitments from this coaching session..."
                                            rows={4}
                                        />
                                        {ackForm.errors.agent_response && (
                                            <p className="text-sm text-red-600">{ackForm.errors.agent_response}</p>
                                        )}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button variant="outline" onClick={() => setAckDialogOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button
                                        onClick={handleAcknowledge}
                                        disabled={ackForm.processing}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                        {ackForm.processing ? 'Submitting...' : 'Acknowledge'}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    )}

                    {/* Review Dialog */}
                    {canReview && (
                        <Dialog open={reviewDialogOpen} onOpenChange={setReviewDialogOpen}>
                            <DialogTrigger asChild>
                                <Button className="flex-1 bg-blue-600 hover:bg-blue-700 text-white sm:flex-none">
                                    <ShieldCheck className="mr-2 h-4 w-4" />
                                    Review Session
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-w-[90vw] sm:max-w-md">
                                <DialogHeader>
                                    <DialogTitle>Review Coaching Session</DialogTitle>
                                    <DialogDescription>
                                        Verify or reject this coaching session for compliance.
                                    </DialogDescription>
                                </DialogHeader>
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
                                <DialogFooter>
                                    <Button variant="outline" onClick={() => setReviewDialogOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button
                                        onClick={handleReview}
                                        disabled={reviewForm.processing || !reviewForm.data.compliance_status}
                                        className="bg-blue-600 hover:bg-blue-700"
                                    >
                                        {reviewForm.processing ? 'Submitting...' : 'Submit Review'}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                {/* Timestamps */}
                <div className="text-xs text-muted-foreground">
                    Created: {formatDateTime(session.created_at)} · Updated: {formatDateTime(session.updated_at)}
                </div>

                {/* Attachment Lightbox Dialog */}
                <Dialog open={!!selectedAttachment} onOpenChange={(open) => !open && setSelectedAttachment(null)}>
                    <DialogContent className="max-w-[95vw] sm:max-w-3xl">
                        <DialogHeader>
                            <DialogTitle className="truncate pr-8">{selectedAttachment?.original_filename}</DialogTitle>
                            <DialogDescription className="sr-only">Image attachment preview</DialogDescription>
                        </DialogHeader>
                        <div className="flex items-center gap-3 pb-2 border-b px-2">
                            <ZoomOut className="h-4 w-4 shrink-0 text-muted-foreground" />
                            <input
                                type="range"
                                min={25}
                                max={300}
                                step={5}
                                value={imageZoom}
                                onChange={(e) => setImageZoom(Number(e.target.value))}
                                className="w-full h-2 accent-primary cursor-pointer"
                                title="Zoom level"
                            />
                            <ZoomIn className="h-4 w-4 shrink-0 text-muted-foreground" />
                            <span className="text-sm font-medium min-w-12.5 text-center tabular-nums">{imageZoom}%</span>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setImageZoom(100)}
                                className="h-7 px-2"
                                title="Reset zoom"
                            >
                                <RotateCcw className="h-3.5 w-3.5" />
                            </Button>
                        </div>
                        <div className="overflow-auto max-h-[70vh] rounded-lg bg-muted/30">
                            <div className="min-w-full min-h-full flex justify-center items-start p-4">
                                {selectedAttachment && (
                                    <img
                                        src={sessionsAttachment({ session: session.id, attachment: selectedAttachment.id }).url}
                                        alt={selectedAttachment.original_filename}
                                        className="object-contain rounded-lg border transition-transform duration-200 origin-top"
                                        style={{ transform: `scale(${imageZoom / 100})` }}
                                    />
                                )}
                            </div>
                        </div>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
