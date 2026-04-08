import React, { useCallback, useEffect, useMemo } from 'react';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { ArrowLeft, CheckCircle2, Circle, Users, UserPlus, X } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { CoachingFormFields } from '@/components/coaching/CoachingFormFields';

import {
    index as sessionsIndex,
    create as sessionsCreate,
    store as sessionsStore,
} from '@/routes/coaching/sessions';

import type { CoachingMode, CoachingPurposeLabels, User, Campaign } from '@/types';

interface Props extends InertiaPageProps {
    agents: User[];
    teamLeads: User[];
    coachableTeamLeads: User[];
    campaigns: Campaign[];
    isAdmin: boolean;
    coachingMode: CoachingMode;
    selectedAgentId: number | null;
    purposes: CoachingPurposeLabels;
    severityFlags: string[];
    coachedThisWeekIds: number[];
}

export default function CoachingSessionsCreate() {
    const { agents, teamLeads, coachableTeamLeads, isAdmin, coachingMode, selectedAgentId, purposes, severityFlags, coachedThisWeekIds } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Create Coaching Session',
        breadcrumbs: [
            { title: 'Coaching Sessions', href: sessionsIndex().url },
            { title: 'Create', href: sessionsCreate().url },
        ],
    });
    useFlashMessage();
    const isPageLoading = usePageLoading();

    // Restore per-agent form data from sessionStorage if in bulk coaching mode
    const getSavedFormData = () => {
        if (!selectedAgentId) return null;
        try {
            const queueRaw = sessionStorage.getItem('coaching_queue');
            const queue = queueRaw ? JSON.parse(queueRaw) : [];
            if (queue.length === 0) return null;
            const raw = sessionStorage.getItem(`coaching_form_${selectedAgentId}`);
            if (raw) return JSON.parse(raw);
        } catch { /* ignore */ }
        return null;
    };
    const savedForm = getSavedFormData();

    const { data, setData, post, errors, processing } = useForm({
        coaching_mode: coachingMode ?? 'assign',
        coach_id: '' as number | '',
        coachee_id: selectedAgentId ?? ('' as number | ''),
        session_date: savedForm?.session_date ?? new Date().toISOString().split('T')[0],
        // Agent Profile
        profile_new_hire: savedForm?.profile_new_hire ?? false,
        profile_tenured: savedForm?.profile_tenured ?? false,
        profile_returning: savedForm?.profile_returning ?? false,
        profile_previously_coached_same_issue: savedForm?.profile_previously_coached_same_issue ?? false,
        // Purpose
        purpose: savedForm?.purpose ?? ('' as string),
        // Focus Areas
        focus_attendance_tardiness: savedForm?.focus_attendance_tardiness ?? false,
        focus_productivity: savedForm?.focus_productivity ?? false,
        focus_compliance: savedForm?.focus_compliance ?? false,
        focus_callouts: savedForm?.focus_callouts ?? false,
        focus_recognition_milestones: savedForm?.focus_recognition_milestones ?? false,
        focus_growth_development: savedForm?.focus_growth_development ?? false,
        focus_other: savedForm?.focus_other ?? false,
        focus_other_notes: savedForm?.focus_other_notes ?? '',
        // Narrative
        performance_description: savedForm?.performance_description ?? '',
        // Root Causes
        root_cause_lack_of_skills: savedForm?.root_cause_lack_of_skills ?? false,
        root_cause_lack_of_clarity: savedForm?.root_cause_lack_of_clarity ?? false,
        root_cause_personal_issues: savedForm?.root_cause_personal_issues ?? false,
        root_cause_motivation_engagement: savedForm?.root_cause_motivation_engagement ?? false,
        root_cause_health_fatigue: savedForm?.root_cause_health_fatigue ?? false,
        root_cause_workload_process: savedForm?.root_cause_workload_process ?? false,
        root_cause_peer_conflict: savedForm?.root_cause_peer_conflict ?? false,
        root_cause_others: savedForm?.root_cause_others ?? false,
        root_cause_others_notes: savedForm?.root_cause_others_notes ?? '',
        // More Narrative
        agent_strengths_wins: savedForm?.agent_strengths_wins ?? '',
        smart_action_plan: savedForm?.smart_action_plan ?? '',
        follow_up_date: savedForm?.follow_up_date ?? '',
        severity_flag: savedForm?.severity_flag ?? 'Normal',
        attachments: [] as File[],
    });

    interface QueueAgent {
        id: number;
        name: string;
        coaching_status: string;
        done: boolean;
    }

    const getQueue = useCallback((): QueueAgent[] => {
        try {
            const raw = sessionStorage.getItem('coaching_queue');
            if (raw) return JSON.parse(raw);
        } catch { /* ignore */ }
        return [];
    }, []);

    // Clean up all per-agent form data when queue is empty
    useEffect(() => {
        if (getQueue().length === 0) {
            Object.keys(sessionStorage).forEach(key => {
                if (key.startsWith('coaching_form_')) sessionStorage.removeItem(key);
            });
        }
    }, [getQueue]);

    const handleModeSwitch = (mode: CoachingMode) => {
        if (mode === coachingMode) return;
        router.get(sessionsCreate.url({ query: { coaching_mode: mode } }), {}, { preserveState: false });
    };

    const queueAgents = useMemo(() => getQueue(), [getQueue]);

    const getFormFieldsToSave = () => {
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { coachee_id, attachments, coaching_mode, coach_id, ...formFields } = data;
        return formFields;
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(sessionsStore().url, {
            forceFormData: true,
            onSuccess: () => {
                // Mark current agent as done in the queue
                const queue = getQueue();
                if (queue.length > 0) {
                    const updated = queue.map(a => a.id === selectedAgentId ? { ...a, done: true } : a);
                    const nextAgent = updated.find(a => !a.done);
                    if (nextAgent) {
                        sessionStorage.setItem('coaching_queue', JSON.stringify(updated));
                        // Keep completed agent's form data so they can review it later
                        if (selectedAgentId) {
                            sessionStorage.setItem(`coaching_form_${selectedAgentId}`, JSON.stringify(getFormFieldsToSave()));
                        }
                        router.visit(sessionsCreate().url + `?coachee_id=${nextAgent.id}`);
                        return;
                    } else {
                        sessionStorage.removeItem('coaching_queue');
                        Object.keys(sessionStorage).forEach(key => {
                            if (key.startsWith('coaching_form_')) sessionStorage.removeItem(key);
                        });
                    }
                }
            },
        });
    };

    const handleSwitchToAgent = (agentId: number) => {
        // Save current agent's form data before switching
        if (selectedAgentId) {
            sessionStorage.setItem(`coaching_form_${selectedAgentId}`, JSON.stringify(getFormFieldsToSave()));
        }
        router.visit(sessionsCreate().url + `?coachee_id=${agentId}`);
    };

    const handleAddAgentToQueue = (agent: { id: number; first_name?: string; last_name?: string; name?: string; active_schedule?: { campaign?: { name?: string } } | null }) => {
        const queue = getQueue();
        // Don't add duplicates
        if (queue.some(a => a.id === agent.id)) return;

        const agentName = agent.name ?? `${agent.first_name ?? ''} ${agent.last_name ?? ''}`.trim();

        // If no queue exists yet, create one with the current agent + the new agent
        if (queue.length === 0 && selectedAgentId) {
            const currentAgent = agents.find(a => a.id === selectedAgentId);
            const currentName = currentAgent
                ? `${currentAgent.first_name ?? ''} ${currentAgent.last_name ?? ''}`.trim()
                : 'Unknown';
            const newQueue: QueueAgent[] = [
                { id: selectedAgentId, name: currentName, coaching_status: '', done: false },
                { id: agent.id, name: agentName, coaching_status: '', done: false },
            ];
            sessionStorage.setItem('coaching_queue', JSON.stringify(newQueue));
        } else {
            // Add to existing queue
            const updated = [...queue, { id: agent.id, name: agentName, coaching_status: '', done: false }];
            sessionStorage.setItem('coaching_queue', JSON.stringify(updated));
        }

        // Save current form data before switching
        if (selectedAgentId) {
            sessionStorage.setItem(`coaching_form_${selectedAgentId}`, JSON.stringify(getFormFieldsToSave()));
        }

        router.visit(sessionsCreate().url + `?coachee_id=${agent.id}`);
    };

    const handleRemoveFromQueue = (agentId: number) => {
        const queue = getQueue();
        const updated = queue.filter(a => a.id !== agentId);
        sessionStorage.removeItem(`coaching_form_${agentId}`);

        if (updated.length <= 1) {
            // If only 1 or 0 agents left, dissolve the queue
            sessionStorage.removeItem('coaching_queue');
            Object.keys(sessionStorage).forEach(key => {
                if (key.startsWith('coaching_form_')) sessionStorage.removeItem(key);
            });
            if (updated.length === 1 && updated[0].id !== selectedAgentId) {
                router.visit(sessionsCreate().url + `?coachee_id=${updated[0].id}`);
            } else {
                window.location.reload();
            }
        } else if (agentId === selectedAgentId) {
            // Removing the currently active agent — switch to next undone or first
            sessionStorage.setItem('coaching_queue', JSON.stringify(updated));
            const next = updated.find(a => !a.done) ?? updated[0];
            router.visit(sessionsCreate().url + `?coachee_id=${next.id}`);
        } else {
            sessionStorage.setItem('coaching_queue', JSON.stringify(updated));
            window.location.reload();
        }
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

                {queueAgents.length > 0 && (
                    <div className="rounded-lg border border-primary/30 bg-primary/5 p-3 space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="flex items-center gap-2 text-sm font-medium">
                                <Users className="h-4 w-4 text-primary" />
                                Bulk Coaching Queue ({queueAgents.filter(a => a.done).length}/{queueAgents.length} done)
                            </span>
                            <button
                                type="button"
                                className="text-xs text-muted-foreground hover:text-foreground underline"
                                onClick={() => {
                                    sessionStorage.removeItem('coaching_queue');
                                    Object.keys(sessionStorage).forEach(key => {
                                        if (key.startsWith('coaching_form_')) sessionStorage.removeItem(key);
                                    });
                                    window.location.reload();
                                }}
                            >
                                Cancel queue
                            </button>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {queueAgents.map((agent) => (
                                <div key={agent.id} className="flex items-center gap-0">
                                    <button
                                        type="button"
                                        disabled={agent.id === selectedAgentId}
                                        onClick={() => agent.id !== selectedAgentId && handleSwitchToAgent(agent.id)}
                                        className={`flex items-center gap-1.5 rounded-l-md border px-2.5 py-1.5 text-xs transition-colors ${agent.id === selectedAgentId
                                            ? 'border-primary bg-primary/10 font-medium text-primary'
                                            : agent.done
                                                ? 'border-green-300 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950/30 dark:text-green-400 cursor-pointer hover:border-green-400 hover:bg-green-100 dark:hover:bg-green-950/50'
                                                : 'border-muted-foreground/20 hover:border-primary/50 hover:bg-primary/5 cursor-pointer'
                                            }`}
                                    >
                                        {agent.done ? (
                                            <CheckCircle2 className="h-3.5 w-3.5 text-green-600 dark:text-green-400" />
                                        ) : agent.id === selectedAgentId ? (
                                            <Circle className="h-3.5 w-3.5 fill-primary text-primary" />
                                        ) : (
                                            <Circle className="h-3.5 w-3.5" />
                                        )}
                                        <span>{agent.name}</span>
                                        {agent.coaching_status && !agent.done && (
                                            <Badge variant={agent.coaching_status === 'Please Coach ASAP' ? 'destructive' : 'secondary'} className="px-1 py-0 text-[9px]">
                                                {agent.coaching_status}
                                            </Badge>
                                        )}
                                        {agent.done && (
                                            <span className="text-[9px] text-green-600 dark:text-green-400">Done</span>
                                        )}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            handleRemoveFromQueue(agent.id);
                                        }}
                                        className={`flex items-center rounded-r-md border border-l-0 px-1 py-1.5 text-xs transition-colors hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-950/30 dark:hover:text-red-400 ${agent.id === selectedAgentId
                                            ? 'border-primary text-primary/50'
                                            : agent.done
                                                ? 'border-green-300 text-green-600/50 dark:border-green-800 dark:text-green-400/50'
                                                : 'border-muted-foreground/20 text-muted-foreground/50'
                                            }`}
                                        title={`Remove ${agent.name} from queue`}
                                    >
                                        <X className="h-3 w-3" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {isAdmin && (
                    <div className="flex rounded-lg border bg-muted/30 p-1 w-fit">
                        <button
                            type="button"
                            onClick={() => handleModeSwitch('assign')}
                            className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${coachingMode === 'assign'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                                }`}
                        >
                            <Users className="h-4 w-4" />
                            Assign TL → Agent
                        </button>
                        <button
                            type="button"
                            onClick={() => handleModeSwitch('direct')}
                            className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${coachingMode === 'direct'
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                                }`}
                        >
                            <UserPlus className="h-4 w-4" />
                            Coach a Team Lead
                        </button>
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-2">
                    <CoachingFormFields
                        data={data}
                        setData={setData}
                        errors={errors}
                        agents={agents}
                        teamLeads={teamLeads}
                        coachableTeamLeads={coachableTeamLeads}
                        isAdmin={isAdmin}
                        coachingMode={coachingMode}
                        purposes={purposes}
                        severityFlags={severityFlags}
                        showAgentSelect={true}
                        selectedAgentId={selectedAgentId}
                        onAgentAddToQueue={handleAddAgentToQueue}
                        queueAgentIds={queueAgents.map(a => a.id)}
                        coachedThisWeekIds={coachedThisWeekIds ?? []}
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
