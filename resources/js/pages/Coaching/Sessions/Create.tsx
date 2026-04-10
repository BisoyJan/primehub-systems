import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { ArrowLeft, CheckCircle2, Circle, Users, UserPlus, X, Save, SaveAll, CloudUpload, Check, AlertTriangle } from 'lucide-react';
import { toast } from 'sonner';

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
    draft as sessionsDraft,
    autoSaveDraft as sessionsAutoSaveDraft,
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
    draftedThisWeekIds: number[];
    existingDraft: Record<string, unknown> | null;
}

export default function CoachingSessionsCreate() {
    const { agents, teamLeads, coachableTeamLeads, isAdmin, coachingMode, selectedAgentId, purposes, severityFlags, coachedThisWeekIds, draftedThisWeekIds, existingDraft } = usePage<Props>().props;

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

    // Priority: savedForm (queue session storage) > existingDraft (server draft) > defaults
    const prefill = savedForm ?? existingDraft;

    const { data, setData, post, errors, processing } = useForm({
        coaching_mode: coachingMode ?? 'assign',
        coach_id: '' as number | '',
        coachee_id: selectedAgentId ?? ('' as number | ''),
        session_date: prefill?.session_date ?? new Date().toISOString().split('T')[0],
        // Agent Profile
        profile_new_hire: prefill?.profile_new_hire ?? false,
        profile_tenured: prefill?.profile_tenured ?? false,
        profile_returning: prefill?.profile_returning ?? false,
        profile_previously_coached_same_issue: prefill?.profile_previously_coached_same_issue ?? false,
        // Purpose
        purpose: prefill?.purpose ?? ('' as string),
        // Focus Areas
        focus_attendance_tardiness: prefill?.focus_attendance_tardiness ?? false,
        focus_productivity: prefill?.focus_productivity ?? false,
        focus_compliance: prefill?.focus_compliance ?? false,
        focus_callouts: prefill?.focus_callouts ?? false,
        focus_recognition_milestones: prefill?.focus_recognition_milestones ?? false,
        focus_growth_development: prefill?.focus_growth_development ?? false,
        focus_other: prefill?.focus_other ?? false,
        focus_other_notes: prefill?.focus_other_notes ?? '',
        // Narrative
        performance_description: prefill?.performance_description ?? '',
        // Root Causes
        root_cause_lack_of_skills: prefill?.root_cause_lack_of_skills ?? false,
        root_cause_lack_of_clarity: prefill?.root_cause_lack_of_clarity ?? false,
        root_cause_personal_issues: prefill?.root_cause_personal_issues ?? false,
        root_cause_motivation_engagement: prefill?.root_cause_motivation_engagement ?? false,
        root_cause_health_fatigue: prefill?.root_cause_health_fatigue ?? false,
        root_cause_workload_process: prefill?.root_cause_workload_process ?? false,
        root_cause_peer_conflict: prefill?.root_cause_peer_conflict ?? false,
        root_cause_others: prefill?.root_cause_others ?? false,
        root_cause_others_notes: prefill?.root_cause_others_notes ?? '',
        // More Narrative
        agent_strengths_wins: prefill?.agent_strengths_wins ?? '',
        smart_action_plan: prefill?.smart_action_plan ?? '',
        follow_up_date: prefill?.follow_up_date ?? '',
        severity_flag: prefill?.severity_flag ?? 'Normal',
        attachments: [] as File[],
    });

    interface QueueAgent {
        id: number;
        name: string;
        coaching_status: string;
        done: boolean;
        status?: 'done' | 'draft';
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

    // ─── Auto-save draft logic ──────────────────────────────────────
    const [draftId, setDraftId] = useState<number | null>(existingDraft?.id as number | null ?? null);
    const [autoSaveStatus, setAutoSaveStatus] = useState<'idle' | 'saving' | 'saved' | 'error'>(existingDraft ? 'saved' : 'idle');
    const [autoSaveError, setAutoSaveError] = useState<string | null>(null);
    const [lastSavedAt, setLastSavedAt] = useState<string | null>(null);
    const autoSaveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const autoSaveAbortRef = useRef<AbortController | null>(null);
    const isSavingRef = useRef(false);
    const isSubmittingRef = useRef(false);
    const isNavigatingRef = useRef(false);
    const formDataRef = useRef(data);
    const initialDataRef = useRef(JSON.stringify(data));

    // Keep formDataRef in sync
    useEffect(() => {
        formDataRef.current = data;
    }, [data]);

    const isFormDirty = useCallback(() => {
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { attachments: _a, ...currentWithout } = formDataRef.current;
        const initialParsed = JSON.parse(initialDataRef.current);
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { attachments: _b, ...initialWithout } = initialParsed;
        return JSON.stringify(currentWithout) !== JSON.stringify(initialWithout);
    }, []);

    const performAutoSave = useCallback(async () => {
        if (isSubmittingRef.current || !formDataRef.current.coachee_id || processing) return;

        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        if (!csrfToken) return;

        // Abort any previous in-flight auto-save
        autoSaveAbortRef.current?.abort();
        const controller = new AbortController();
        autoSaveAbortRef.current = controller;

        setAutoSaveStatus('saving');
        isSavingRef.current = true;

        try {
            // eslint-disable-next-line @typescript-eslint/no-unused-vars
            const { attachments, ...formFields } = formDataRef.current;

            const response = await fetch(sessionsAutoSaveDraft().url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    ...formFields,
                    draft_id: draftId,
                }),
                signal: controller.signal,
            });

            if (response.ok) {
                const result = await response.json();
                setDraftId(result.draft_id);
                setAutoSaveStatus('saved');
                setAutoSaveError(null);
                isSavingRef.current = false;
                setLastSavedAt(new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
            } else {
                const errorBody = await response.json().catch(() => null);
                const message = errorBody?.message || `Server error (${response.status})`;
                setAutoSaveError(message);
                setAutoSaveStatus('error');
                isSavingRef.current = false;
            }
        } catch (err) {
            if (err instanceof DOMException && err.name === 'AbortError') return;
            setAutoSaveError('Network error');
            setAutoSaveStatus('error');
            isSavingRef.current = false;
        }
    }, [draftId, processing]);

    // Debounced auto-save: trigger 5 seconds after the last form change
    useEffect(() => {
        if (!data.coachee_id || isSubmittingRef.current) return;

        // Don't auto-save if form hasn't been meaningfully changed
        if (!isFormDirty() && !draftId) return;

        if (autoSaveTimerRef.current) {
            clearTimeout(autoSaveTimerRef.current);
        }

        autoSaveTimerRef.current = setTimeout(() => {
            performAutoSave();
        }, 5000);

        return () => {
            if (autoSaveTimerRef.current) {
                clearTimeout(autoSaveTimerRef.current);
            }
        };
    }, [data, performAutoSave, isFormDirty, draftId]);

    // Warn on browser tab close / refresh
    useEffect(() => {
        const handleBeforeUnload = (e: BeforeUnloadEvent) => {
            if (isSubmittingRef.current || isNavigatingRef.current) return;
            if (isFormDirty() || isSavingRef.current) {
                e.preventDefault();
            }
        };

        window.addEventListener('beforeunload', handleBeforeUnload);
        return () => window.removeEventListener('beforeunload', handleBeforeUnload);
    }, [isFormDirty]);

    // Warn on Inertia in-app navigation
    useEffect(() => {
        const removeListener = router.on('before', (event) => {
            if (isSubmittingRef.current || isNavigatingRef.current) return true;
            if (isFormDirty()) {
                if (!window.confirm('You have unsaved changes. Your draft has been auto-saved, but any recent changes may be lost. Leave anyway?')) {
                    event.preventDefault();
                    return false;
                }
            }
            return true;
        });

        return removeListener;
    }, [isFormDirty]);
    // ─── End auto-save logic ────────────────────────────────────────

    const handleModeSwitch = (mode: CoachingMode) => {
        if (mode === coachingMode) return;
        isNavigatingRef.current = true;
        router.get(sessionsCreate.url({ query: { coaching_mode: mode } }), {}, { preserveState: false });
    };

    const queueAgents = useMemo(() => getQueue(), [getQueue]);

    const getFormFieldsToSave = () => {
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { coachee_id, attachments, coaching_mode, coach_id, ...formFields } = data;
        return formFields;
    };

    const [savingDraft, setSavingDraft] = useState(false);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        isSubmittingRef.current = true;
        post(sessionsStore().url, {
            forceFormData: true,
            onError: () => { isSubmittingRef.current = false; },
            onSuccess: () => {
                // Mark current agent as done (submitted) in the queue
                const queue = getQueue();
                if (queue.length > 0) {
                    const updated = queue.map(a => a.id === selectedAgentId ? { ...a, done: true, status: 'done' as const } : a);
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

    const handleSaveDraft = () => {
        isSubmittingRef.current = true;
        setSavingDraft(true);
        post(sessionsDraft().url, {
            forceFormData: true,
            onError: () => { isSubmittingRef.current = false; },
            onFinish: () => setSavingDraft(false),
            onSuccess: () => {
                // Mark current agent as draft in the queue and move to next
                const queue = getQueue();
                if (queue.length > 0) {
                    const updated = queue.map(a => a.id === selectedAgentId ? { ...a, done: true, status: 'draft' as const } : a);
                    const nextAgent = updated.find(a => !a.done);
                    if (nextAgent) {
                        sessionStorage.setItem('coaching_queue', JSON.stringify(updated));
                        if (selectedAgentId) {
                            sessionStorage.setItem(`coaching_form_${selectedAgentId}`, JSON.stringify(getFormFieldsToSave()));
                        }
                        isNavigatingRef.current = true;
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

    const handleSaveAllAsDraft = () => {
        isSubmittingRef.current = true;
        setSavingDraft(true);
        post(sessionsDraft().url, {
            forceFormData: true,
            onError: () => { isSubmittingRef.current = false; },
            onFinish: () => setSavingDraft(false),
            onSuccess: async () => {
                const queue = getQueue();
                const remainingAgents = queue.filter(a => a.id !== selectedAgentId && !a.done);
                const failedAgents: string[] = [];

                if (remainingAgents.length > 0) {
                    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
                    if (csrfToken) {
                        for (const agent of remainingAgents) {
                            try {
                                // Use saved form data if available, otherwise minimal data
                                const savedRaw = sessionStorage.getItem(`coaching_form_${agent.id}`);
                                const savedData = savedRaw ? JSON.parse(savedRaw) : {};

                                const response = await fetch(sessionsAutoSaveDraft().url, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': csrfToken,
                                        'Accept': 'application/json',
                                    },
                                    body: JSON.stringify({
                                        coachee_id: agent.id,
                                        coaching_mode: data.coaching_mode,
                                        coach_id: data.coach_id || undefined,
                                        session_date: savedData.session_date || data.session_date,
                                        ...savedData,
                                    }),
                                });

                                if (!response.ok) {
                                    failedAgents.push(agent.name);
                                }
                            } catch {
                                failedAgents.push(agent.name);
                            }
                        }
                    }
                }

                if (failedAgents.length > 0) {
                    toast.error(`Failed to save drafts for: ${failedAgents.join(', ')}. Please save them individually.`);
                }

                // Clear queue entirely
                sessionStorage.removeItem('coaching_queue');
                Object.keys(sessionStorage).forEach(key => {
                    if (key.startsWith('coaching_form_')) sessionStorage.removeItem(key);
                });

                isNavigatingRef.current = true;
                router.visit(sessionsIndex().url + '?tab=drafts');
            },
        });
    };

    const handleSwitchToAgent = (agentId: number) => {
        // Cancel pending auto-save timer and abort in-flight request
        if (autoSaveTimerRef.current) clearTimeout(autoSaveTimerRef.current);
        autoSaveAbortRef.current?.abort();

        // Save current agent's form data before switching
        if (selectedAgentId) {
            sessionStorage.setItem(`coaching_form_${selectedAgentId}`, JSON.stringify(getFormFieldsToSave()));
        }
        isNavigatingRef.current = true;
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

        isNavigatingRef.current = true;
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
                isNavigatingRef.current = true;
                router.visit(sessionsCreate().url + `?coachee_id=${updated[0].id}`);
            } else {
                isNavigatingRef.current = true;
                window.location.reload();
            }
        } else if (agentId === selectedAgentId) {
            // Removing the currently active agent — switch to next undone or first
            sessionStorage.setItem('coaching_queue', JSON.stringify(updated));
            const next = updated.find(a => !a.done) ?? updated[0];
            isNavigatingRef.current = true;
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
                                Bulk Coaching Queue ({queueAgents.filter(a => a.done).length}/{queueAgents.length} completed)
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
                                            : agent.done && agent.status === 'draft'
                                                ? 'border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-400 cursor-pointer hover:border-blue-400 hover:bg-blue-100 dark:hover:bg-blue-950/50'
                                                : agent.done
                                                    ? 'border-green-300 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950/30 dark:text-green-400 cursor-pointer hover:border-green-400 hover:bg-green-100 dark:hover:bg-green-950/50'
                                                    : 'border-muted-foreground/20 hover:border-primary/50 hover:bg-primary/5 cursor-pointer'
                                            }`}
                                    >
                                        {agent.done && agent.status === 'draft' ? (
                                            <Save className="h-3.5 w-3.5 text-blue-600 dark:text-blue-400" />
                                        ) : agent.done ? (
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
                                        {agent.done && agent.status === 'draft' && (
                                            <Badge variant="secondary" className="px-1 py-0 text-[9px] bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-400">Draft</Badge>
                                        )}
                                        {agent.done && agent.status !== 'draft' && (
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
                                            : agent.done && agent.status === 'draft'
                                                ? 'border-blue-300 text-blue-600/50 dark:border-blue-800 dark:text-blue-400/50'
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
                        draftedThisWeekIds={draftedThisWeekIds ?? []}
                    />

                    <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pt-6">
                        {/* Auto-save status indicator */}
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            {autoSaveStatus === 'saving' && (
                                <>
                                    <CloudUpload className="h-3.5 w-3.5 animate-pulse text-blue-500" />
                                    <span>Auto-saving draft...</span>
                                </>
                            )}
                            {autoSaveStatus === 'saved' && lastSavedAt && (
                                <>
                                    <Check className="h-3.5 w-3.5 text-green-500" />
                                    <span>Draft auto-saved at {lastSavedAt}</span>
                                </>
                            )}
                            {autoSaveStatus === 'error' && (
                                <>
                                    <AlertTriangle className="h-3.5 w-3.5 text-amber-500" />
                                    <span>Auto-save failed{autoSaveError ? `: ${autoSaveError}` : ''}</span>
                                </>
                            )}
                        </div>

                        <div className="flex gap-3">
                            <Link href={sessionsIndex().url}>
                                <Button type="button" variant="outline">
                                    Cancel
                                </Button>
                            </Link>
                            {queueAgents.length > 0 ? (
                                <>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={processing || savingDraft || !data.coachee_id}
                                        onClick={handleSaveDraft}
                                    >
                                        <Save className="mr-2 h-4 w-4" />
                                        {savingDraft ? 'Saving...' : 'Save Draft & Next'}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={processing || savingDraft || !data.coachee_id}
                                        onClick={handleSaveAllAsDraft}
                                        className="border-dashed"
                                    >
                                        <SaveAll className="mr-2 h-4 w-4" />
                                        {savingDraft ? 'Saving...' : 'Save All as Draft'}
                                    </Button>
                                </>
                            ) : (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={processing || savingDraft || !data.coachee_id}
                                    onClick={handleSaveDraft}
                                >
                                    <Save className="mr-2 h-4 w-4" />
                                    {savingDraft ? 'Saving Draft...' : 'Save as Draft'}
                                </Button>
                            )}
                            <Button type="submit" disabled={processing || savingDraft} className="bg-blue-600 hover:bg-blue-700 text-white">
                                {processing ? 'Saving...' : 'Create Coaching Session'}
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
