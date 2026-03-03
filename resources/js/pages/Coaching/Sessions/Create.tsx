import React from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { ArrowLeft } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { CoachingFormFields } from '@/components/coaching/CoachingFormFields';

import {
    index as sessionsIndex,
    create as sessionsCreate,
    store as sessionsStore,
} from '@/routes/coaching/sessions';

import type { CoachingPurposeLabels, User, Campaign } from '@/types';

interface Props extends InertiaPageProps {
    agents: User[];
    teamLeads: User[];
    campaigns: Campaign[];
    isAdmin: boolean;
    selectedAgentId: number | null;
    purposes: CoachingPurposeLabels;
    severityFlags: string[];
}

export default function CoachingSessionsCreate() {
    const { agents, teamLeads, isAdmin, selectedAgentId, purposes, severityFlags } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Create Coaching Session',
        breadcrumbs: [
            { title: 'Coaching Sessions', href: sessionsIndex().url },
            { title: 'Create', href: sessionsCreate().url },
        ],
    });
    useFlashMessage();
    const isPageLoading = usePageLoading();

    const { data, setData, post, errors, processing } = useForm({
        team_lead_id: '' as number | '',
        agent_id: selectedAgentId ?? ('' as number | ''),
        session_date: new Date().toISOString().split('T')[0],
        // Agent Profile
        profile_new_hire: false,
        profile_tenured: false,
        profile_returning: false,
        profile_previously_coached_same_issue: false,
        // Purpose
        purpose: '' as string,
        // Focus Areas
        focus_attendance_tardiness: false,
        focus_productivity: false,
        focus_compliance: false,
        focus_callouts: false,
        focus_recognition_milestones: false,
        focus_growth_development: false,
        focus_other: false,
        focus_other_notes: '',
        // Narrative
        performance_description: '',
        // Root Causes
        root_cause_lack_of_skills: false,
        root_cause_lack_of_clarity: false,
        root_cause_personal_issues: false,
        root_cause_motivation_engagement: false,
        root_cause_health_fatigue: false,
        root_cause_workload_process: false,
        root_cause_peer_conflict: false,
        root_cause_others: false,
        root_cause_others_notes: '',
        // More Narrative
        agent_strengths_wins: '',
        smart_action_plan: '',
        follow_up_date: '',
        severity_flag: 'Normal',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(sessionsStore().url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative mx-auto flex w-full max-w-4xl flex-col gap-4 rounded-xl p-3 md:p-6">
                <LoadingOverlay isLoading={isPageLoading || processing} message={processing ? 'Saving session...' : undefined} />

                <PageHeader
                    title="Create Coaching Session"
                    description="Fill in the coaching form to log a session with an agent"
                    actions={
                        <Link href={sessionsIndex().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to list
                            </Button>
                        </Link>
                    }
                />

                <form onSubmit={handleSubmit} className="space-y-2">
                    <CoachingFormFields
                        data={data}
                        setData={setData}
                        errors={errors}
                        agents={agents}
                        teamLeads={teamLeads}
                        isAdmin={isAdmin}
                        purposes={purposes}
                        severityFlags={severityFlags}
                        showAgentSelect={true}
                        selectedAgentId={selectedAgentId}
                    />

                    <div className="flex justify-end gap-3 pt-6">
                        <Link href={sessionsIndex().url}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing} className="bg-blue-600 hover:bg-blue-700 text-white">
                            {processing ? 'Saving...' : 'Create Coaching Session'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
