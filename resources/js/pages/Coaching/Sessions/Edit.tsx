import React, { useState, useCallback } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { ArrowLeft, SendHorizonal } from 'lucide-react';
import { toast } from 'sonner';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { CoachingFormFields } from '@/components/coaching/CoachingFormFields';

import {
    index as sessionsIndex,
    show as sessionsShow,
    update as sessionsUpdate,
    submit as sessionsSubmit,
    attachment as sessionsAttachment,
} from '@/routes/coaching/sessions';

import type { CoachingSession, CoachingPurposeLabels } from '@/types';

interface Props extends InertiaPageProps {
    session: CoachingSession;
    purposes: CoachingPurposeLabels;
    severityFlags: string[];
}

export default function CoachingSessionsEdit() {
    const { session, purposes, severityFlags } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Edit Coaching Session',
        breadcrumbs: [
            { title: 'Coaching Sessions', href: sessionsIndex().url },
            { title: 'Details', href: sessionsShow.url(session.id) },
            { title: 'Edit' },
        ],
    });
    useFlashMessage();
    const isPageLoading = usePageLoading();

    const { data, setData, put, patch, errors, processing } = useForm({
        session_date: session.session_date ? session.session_date.split('T')[0] : '',
        // Agent Profile
        profile_new_hire: session.profile_new_hire,
        profile_tenured: session.profile_tenured,
        profile_returning: session.profile_returning,
        profile_previously_coached_same_issue: session.profile_previously_coached_same_issue,
        // Purpose
        purpose: session.purpose,
        // Focus Areas
        focus_attendance_tardiness: session.focus_attendance_tardiness,
        focus_productivity: session.focus_productivity,
        focus_compliance: session.focus_compliance,
        focus_callouts: session.focus_callouts,
        focus_recognition_milestones: session.focus_recognition_milestones,
        focus_growth_development: session.focus_growth_development,
        focus_other: session.focus_other,
        focus_other_notes: session.focus_other_notes ?? '',
        // Narrative
        performance_description: session.performance_description,
        // Root Causes
        root_cause_lack_of_skills: session.root_cause_lack_of_skills,
        root_cause_lack_of_clarity: session.root_cause_lack_of_clarity,
        root_cause_personal_issues: session.root_cause_personal_issues,
        root_cause_motivation_engagement: session.root_cause_motivation_engagement,
        root_cause_health_fatigue: session.root_cause_health_fatigue,
        root_cause_workload_process: session.root_cause_workload_process,
        root_cause_peer_conflict: session.root_cause_peer_conflict,
        root_cause_others: session.root_cause_others,
        root_cause_others_notes: session.root_cause_others_notes ?? '',
        // More Narrative
        agent_strengths_wins: session.agent_strengths_wins ?? '',
        smart_action_plan: session.smart_action_plan,
        follow_up_date: session.follow_up_date ?? '',
        severity_flag: session.severity_flag,
        attachments: [] as File[],
        removed_attachments: [] as number[],
    });

    const [removedAttachmentIds, setRemovedAttachmentIds] = useState<number[]>([]);

    const handleRemoveExistingAttachment = useCallback((id: number) => {
        setRemovedAttachmentIds((prev) => {
            const updated = [...prev, id];
            setData('removed_attachments', updated);
            return updated;
        });
    }, [setData]);

    const getAttachmentViewUrl = useCallback((sessionId: number, attachmentId: number) => {
        return sessionsAttachment.url({ session: sessionId, attachment: attachmentId });
    }, []);

    const formatName = (user?: { first_name: string; last_name: string } | null) => {
        if (!user) return 'N/A';
        return `${user.first_name} ${user.last_name}`;
    };

    const [submittingDraft, setSubmittingDraft] = useState(false);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(sessionsUpdate(session.id).url, {
            forceFormData: true,
        });
    };

    const handleSubmitDraft = () => {
        // Frontend precheck for required fields before server round-trip
        const missing: string[] = [];
        if (!data.performance_description || data.performance_description.replace(/<[^>]*>/g, '').trim().length < 10) {
            missing.push('Performance Description (min 10 characters)');
        }
        if (!data.smart_action_plan || data.smart_action_plan.replace(/<[^>]*>/g, '').trim().length < 10) {
            missing.push('SMART Action Plan (min 10 characters)');
        }
        if (!data.purpose) {
            missing.push('Purpose');
        }
        if (!data.session_date) {
            missing.push('Session Date');
        }
        if (missing.length > 0) {
            toast.error('Please complete required fields before submitting', {
                description: missing.join(', '),
            });
            return;
        }

        setSubmittingDraft(true);
        patch(sessionsSubmit(session.id).url, {
            forceFormData: true,
            onFinish: () => setSubmittingDraft(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative mx-auto flex w-full max-w-4xl flex-col gap-4 rounded-xl p-3 md:p-6">
                <LoadingOverlay isLoading={isPageLoading || processing} message={processing ? 'Updating session...' : undefined} />

                <PageHeader
                    title="Edit Coaching Session"
                    description={`Editing session for ${formatName(session.coachee)} on ${new Date(session.session_date).toLocaleDateString()}`}
                    actions={
                        <Link href={sessionsShow.url(session.id)}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to details
                            </Button>
                        </Link>
                    }
                />

                <form onSubmit={handleSubmit} className="space-y-2">
                    <CoachingFormFields
                        data={data}
                        setData={setData}
                        errors={errors}
                        purposes={purposes}
                        severityFlags={severityFlags}
                        showAgentSelect={false}
                        existingAttachments={session.attachments ?? []}
                        removedAttachmentIds={removedAttachmentIds}
                        onRemoveExistingAttachment={handleRemoveExistingAttachment}
                        attachmentViewUrl={getAttachmentViewUrl}
                    />

                    <div className="flex justify-end gap-3 pt-6">
                        <Link href={sessionsShow.url(session.id)}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing || submittingDraft} className="bg-blue-600 hover:bg-blue-700 text-white">
                            {processing ? 'Updating...' : session.is_draft ? 'Save Draft' : 'Update Coaching Session'}
                        </Button>
                        {session.is_draft && (
                            <Button
                                type="button"
                                disabled={processing || submittingDraft}
                                onClick={handleSubmitDraft}
                                className="bg-green-600 hover:bg-green-700 text-white"
                            >
                                <SendHorizonal className="mr-2 h-4 w-4" />
                                {submittingDraft ? 'Submitting...' : 'Submit Coaching Session'}
                            </Button>
                        )}
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
