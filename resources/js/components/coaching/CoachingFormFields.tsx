import { useState, useMemo } from 'react';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Check, ChevronsUpDown } from 'lucide-react';
import type { CoachingMode, CoachingPurposeLabels, User } from '@/types';

interface CoachingFormFieldsProps {
    data: Record<string, unknown>;
    setData: (key: string, value: unknown) => void;
    errors: Record<string, string>;
    agents?: User[];
    teamLeads?: User[];
    coachableTeamLeads?: User[];
    isAdmin?: boolean;
    coachingMode?: CoachingMode;
    purposes: CoachingPurposeLabels;
    severityFlags: string[];
    showAgentSelect?: boolean;
    selectedAgentId?: number | null;
}

export function CoachingFormFields({
    data,
    setData,
    errors,
    agents = [],
    teamLeads = [],
    coachableTeamLeads = [],
    isAdmin = false,
    coachingMode = 'assign',
    purposes,
    severityFlags,
    showAgentSelect = true,
    selectedAgentId,
}: CoachingFormFieldsProps) {
    const [agentSearchOpen, setAgentSearchOpen] = useState(false);
    const [agentSearchQuery, setAgentSearchQuery] = useState('');
    const [tlSearchOpen, setTlSearchOpen] = useState(false);
    const [tlSearchQuery, setTlSearchQuery] = useState('');
    const [coacheeSearchOpen, setCoacheeSearchOpen] = useState(false);
    const [coacheeSearchQuery, setCoacheeSearchQuery] = useState('');

    const isDirectMode = coachingMode === 'direct';

    const selectedTeamLead = useMemo(() => {
        const id = Number(data.coach_id);
        return teamLeads.find((tl) => tl.id === id) ?? null;
    }, [data.coach_id, teamLeads]);

    const getAgentCampaign = (agent: User): { id: number; name: string } | null => {
        const schedule = (agent as Record<string, unknown>).active_schedule as { campaign?: { id?: number; name?: string } } | null;
        if (!schedule?.campaign?.id || !schedule?.campaign?.name) return null;
        return { id: schedule.campaign.id, name: schedule.campaign.name };
    };

    const selectedTlCampaignId = useMemo(() => {
        if (!isAdmin || !selectedTeamLead) return null;
        return getAgentCampaign(selectedTeamLead)?.id ?? null;
    }, [isAdmin, selectedTeamLead]);

    const selectedAgent = useMemo(() => {
        const id = Number(data.coachee_id || selectedAgentId);
        return agents.find((a) => a.id === id) ?? null;
    }, [data.coachee_id, selectedAgentId, agents]);

    const selectedCoacheeTl = useMemo(() => {
        if (!isDirectMode) return null;
        const id = Number(data.coachee_id);
        return coachableTeamLeads.find((tl) => tl.id === id) ?? null;
    }, [data.coachee_id, coachableTeamLeads, isDirectMode]);

    // For admin: filter agents to the selected TL's campaign; for TL: show all (already pre-filtered by backend)
    const campaignFilteredAgents = useMemo(() => {
        if (!isAdmin || !selectedTlCampaignId) return isAdmin ? [] : agents;
        return agents.filter((a) => getAgentCampaign(a)?.id === selectedTlCampaignId);
    }, [agents, isAdmin, selectedTlCampaignId]);

    const filteredAgents = useMemo(() => {
        if (!agentSearchQuery) return campaignFilteredAgents.slice(0, 50);
        const q = agentSearchQuery.toLowerCase();
        return campaignFilteredAgents
            .filter((a) => {
                const name = `${a.first_name} ${a.last_name}`.toLowerCase();
                const campaign = getAgentCampaign(a)?.name?.toLowerCase() ?? '';
                return name.includes(q) || campaign.includes(q);
            })
            .slice(0, 50);
    }, [campaignFilteredAgents, agentSearchQuery]);

    const filteredTeamLeads = useMemo(() => {
        if (!tlSearchQuery) return teamLeads.slice(0, 50);
        const q = tlSearchQuery.toLowerCase();
        return teamLeads
            .filter((tl) => {
                const name = `${tl.first_name} ${tl.last_name}`.toLowerCase();
                const campaign = getAgentCampaign(tl)?.name?.toLowerCase() ?? '';
                return name.includes(q) || campaign.includes(q);
            })
            .slice(0, 50);
    }, [teamLeads, tlSearchQuery]);

    const filteredCoachableTeamLeads = useMemo(() => {
        if (!coacheeSearchQuery) return coachableTeamLeads.slice(0, 50);
        const q = coacheeSearchQuery.toLowerCase();
        return coachableTeamLeads
            .filter((tl) => {
                const name = `${tl.first_name} ${tl.last_name}`.toLowerCase();
                const campaign = getAgentCampaign(tl)?.name?.toLowerCase() ?? '';
                return name.includes(q) || campaign.includes(q);
            })
            .slice(0, 50);
    }, [coachableTeamLeads, coacheeSearchQuery]);

    // Follow-up date must be next week or later (next Monday onwards)
    const nextMonday = useMemo(() => {
        const d = new Date();
        const day = d.getDay(); // 0=Sun, 1=Mon, ..., 6=Sat
        const daysUntilNextMon = day === 0 ? 1 : 8 - day;
        d.setDate(d.getDate() + daysUntilNextMon);
        return d.toISOString().split('T')[0];
    }, []);

    const handleCheckbox = (field: string, checked: boolean | 'indeterminate') => {
        setData(field, checked === true);
    };

    return (
        <div className="space-y-8">
            {/* Section 1: Agent & Date */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Session Details
                </h3>
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {/* Team Lead (Coach) selector (Admin assign mode only) */}
                    {showAgentSelect && isAdmin && !isDirectMode && (
                        <div>
                            <Label htmlFor="coach_id">Team Lead (Coach) <span className="text-red-500">*</span></Label>
                            <Popover open={tlSearchOpen} onOpenChange={setTlSearchOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={tlSearchOpen}
                                        className="w-full justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {selectedTeamLead
                                                ? `${selectedTeamLead.first_name} ${selectedTeamLead.last_name}${getAgentCampaign(selectedTeamLead) ? ` — ${getAgentCampaign(selectedTeamLead)!.name}` : ''}`
                                                : 'Select a team lead'}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-full p-0" align="start">
                                    <Command shouldFilter={false}>
                                        <CommandInput
                                            placeholder="Search team lead..."
                                            value={tlSearchQuery}
                                            onValueChange={setTlSearchQuery}
                                        />
                                        <CommandList>
                                            <CommandEmpty>No team lead found.</CommandEmpty>
                                            <CommandGroup>
                                                {filteredTeamLeads.map((tl) => (
                                                    <CommandItem
                                                        key={tl.id}
                                                        value={String(tl.id)}
                                                        onSelect={() => {
                                                            setData('coach_id', tl.id);
                                                            // Reset coachee when coach changes
                                                            setData('coachee_id', '');
                                                            setTlSearchOpen(false);
                                                            setTlSearchQuery('');
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${selectedTeamLead?.id === tl.id ? 'opacity-100' : 'opacity-0'
                                                                }`}
                                                        />
                                                        <div className="flex flex-col">
                                                            <span>{tl.first_name} {tl.last_name}</span>
                                                            {getAgentCampaign(tl) && (
                                                                <span className="text-xs text-muted-foreground">{getAgentCampaign(tl)!.name}</span>
                                                            )}
                                                        </div>
                                                    </CommandItem>
                                                ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                            {errors.coach_id && <p className="text-red-600 text-sm mt-1">{errors.coach_id}</p>}
                        </div>
                    )}
                    {/* Coachee TL selector (Admin direct mode only) */}
                    {showAgentSelect && isAdmin && isDirectMode && (
                        <div>
                            <Label htmlFor="coachee_id">Team Lead (Coachee) <span className="text-red-500">*</span></Label>
                            <Popover open={coacheeSearchOpen} onOpenChange={setCoacheeSearchOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={coacheeSearchOpen}
                                        className="w-full justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {selectedCoacheeTl
                                                ? `${selectedCoacheeTl.first_name} ${selectedCoacheeTl.last_name}${getAgentCampaign(selectedCoacheeTl) ? ` — ${getAgentCampaign(selectedCoacheeTl)!.name}` : ''}`
                                                : 'Select a team lead to coach'}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-full p-0" align="start">
                                    <Command shouldFilter={false}>
                                        <CommandInput
                                            placeholder="Search team lead..."
                                            value={coacheeSearchQuery}
                                            onValueChange={setCoacheeSearchQuery}
                                        />
                                        <CommandList>
                                            <CommandEmpty>No team lead found.</CommandEmpty>
                                            <CommandGroup>
                                                {filteredCoachableTeamLeads.map((tl) => (
                                                    <CommandItem
                                                        key={tl.id}
                                                        value={String(tl.id)}
                                                        onSelect={() => {
                                                            setData('coachee_id', tl.id);
                                                            setCoacheeSearchOpen(false);
                                                            setCoacheeSearchQuery('');
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${selectedCoacheeTl?.id === tl.id ? 'opacity-100' : 'opacity-0'}`}
                                                        />
                                                        <div className="flex flex-col">
                                                            <span>{tl.first_name} {tl.last_name}</span>
                                                            {getAgentCampaign(tl) && (
                                                                <span className="text-xs text-muted-foreground">{getAgentCampaign(tl)!.name}</span>
                                                            )}
                                                        </div>
                                                    </CommandItem>
                                                ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                            {errors.coachee_id && <p className="text-red-600 text-sm mt-1">{errors.coachee_id}</p>}
                        </div>
                    )}
                    {/* Agent (Coachee) selector (assign mode) */}
                    {showAgentSelect && !isDirectMode && (
                        <div>
                            <Label htmlFor="coachee_id">Agent <span className="text-red-500">*</span></Label>
                            <Popover open={agentSearchOpen} onOpenChange={setAgentSearchOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={agentSearchOpen}
                                        className="w-full justify-between font-normal"
                                        disabled={isAdmin && !selectedTeamLead}
                                    >
                                        <span className="truncate">
                                            {isAdmin && !selectedTeamLead
                                                ? 'Select a team lead first'
                                                : selectedAgent
                                                    ? `${selectedAgent.first_name} ${selectedAgent.last_name}${getAgentCampaign(selectedAgent) ? ` — ${getAgentCampaign(selectedAgent)!.name}` : ''}`
                                                    : 'Select an agent'}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-full p-0" align="start">
                                    <Command shouldFilter={false}>
                                        <CommandInput
                                            placeholder="Search agent..."
                                            value={agentSearchQuery}
                                            onValueChange={setAgentSearchQuery}
                                        />
                                        <CommandList>
                                            <CommandEmpty>No agent found.</CommandEmpty>
                                            <CommandGroup>
                                                {filteredAgents.map((agent) => (
                                                    <CommandItem
                                                        key={agent.id}
                                                        value={String(agent.id)}
                                                        onSelect={() => {
                                                            setData('coachee_id', agent.id);
                                                            setAgentSearchOpen(false);
                                                            setAgentSearchQuery('');
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${selectedAgent?.id === agent.id ? 'opacity-100' : 'opacity-0'
                                                                }`}
                                                        />
                                                        <div className="flex flex-col">
                                                            <span>{agent.first_name} {agent.last_name}</span>
                                                            {getAgentCampaign(agent) && (
                                                                <span className="text-xs text-muted-foreground">{getAgentCampaign(agent)!.name}</span>
                                                            )}
                                                        </div>
                                                    </CommandItem>
                                                ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                            {errors.coachee_id && <p className="text-red-600 text-sm mt-1">{errors.coachee_id}</p>}
                        </div>
                    )}
                    <div>
                        <Label htmlFor="session_date">Session Date <span className="text-red-500">*</span></Label>
                        <DatePicker
                            value={String(data.session_date || '')}
                            onChange={(val) => setData('session_date', val)}
                            placeholder="Select session date"
                        />
                        {errors.session_date && <p className="text-red-600 text-sm mt-1">{errors.session_date}</p>}
                    </div>
                </div>
            </section>

            {/* Section 2: Agent Profile */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Agent's Profile
                </h3>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {[
                        { field: 'profile_new_hire', label: 'New Hire' },
                        { field: 'profile_tenured', label: 'Tenured' },
                        { field: 'profile_returning', label: 'Returning' },
                        { field: 'profile_previously_coached_same_issue', label: 'Previously Coached (Same Issue)' },
                    ].map(({ field, label }) => (
                        <div key={field} className="flex items-center gap-2">
                            <Checkbox
                                id={field}
                                checked={!!data[field]}
                                onCheckedChange={(checked) => handleCheckbox(field, checked)}
                            />
                            <Label htmlFor={field} className="cursor-pointer text-sm">{label}</Label>
                        </div>
                    ))}
                </div>
            </section>

            {/* Section 3: Purpose */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Purpose of Coaching Session <span className="text-red-500">*</span>
                </h3>
                <Select
                    value={String(data.purpose || '')}
                    onValueChange={(val) => setData('purpose', val)}
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Select purpose" />
                    </SelectTrigger>
                    <SelectContent>
                        {Object.entries(purposes).map(([value, label]) => (
                            <SelectItem key={value} value={value}>
                                {label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {errors.purpose && <p className="text-red-600 text-sm mt-1">{errors.purpose}</p>}
            </section>

            {/* Section 4: Focus Areas */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Focus Area(s)
                </h3>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {[
                        { field: 'focus_attendance_tardiness', label: 'Attendance / Tardiness' },
                        { field: 'focus_productivity', label: 'Productivity' },
                        { field: 'focus_compliance', label: 'Compliance' },
                        { field: 'focus_callouts', label: 'Callouts' },
                        { field: 'focus_recognition_milestones', label: 'Recognition / Milestones' },
                        { field: 'focus_growth_development', label: 'Growth / Development' },
                        { field: 'focus_other', label: 'Other' },
                    ].map(({ field, label }) => (
                        <div key={field} className="flex items-center gap-2">
                            <Checkbox
                                id={field}
                                checked={!!data[field]}
                                onCheckedChange={(checked) => handleCheckbox(field, checked)}
                            />
                            <Label htmlFor={field} className="cursor-pointer text-sm">{label}</Label>
                        </div>
                    ))}
                </div>
                {!!data.focus_other && (
                    <div>
                        <Label htmlFor="focus_other_notes">Other Focus Area Details</Label>
                        <Input
                            id="focus_other_notes"
                            value={String(data.focus_other_notes || '')}
                            onChange={(e) => setData('focus_other_notes', e.target.value)}
                            placeholder="Specify other focus area..."
                        />
                        {errors.focus_other_notes && <p className="text-red-600 text-sm mt-1">{errors.focus_other_notes}</p>}
                    </div>
                )}
            </section>

            {/* Section 5: Performance Description */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Performance Description <span className="text-red-500">*</span>
                </h3>
                <Textarea
                    id="performance_description"
                    value={String(data.performance_description || '')}
                    onChange={(e) => setData('performance_description', e.target.value)}
                    placeholder="Describe the employee's current performance, specific incidents, or areas for improvement..."
                    rows={5}
                />
                {errors.performance_description && <p className="text-red-600 text-sm mt-1">{errors.performance_description}</p>}
            </section>

            {/* Section 6: Root Causes */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Root Cause(s)
                </h3>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {[
                        { field: 'root_cause_lack_of_skills', label: 'Lack of Skills / Knowledge' },
                        { field: 'root_cause_lack_of_clarity', label: 'Lack of Clarity on Expectations' },
                        { field: 'root_cause_personal_issues', label: 'Personal Issues' },
                        { field: 'root_cause_motivation_engagement', label: 'Motivation / Engagement' },
                        { field: 'root_cause_health_fatigue', label: 'Health / Fatigue' },
                        { field: 'root_cause_workload_process', label: 'Workload or Process Issues' },
                        { field: 'root_cause_peer_conflict', label: 'Peer / Team Conflict' },
                        { field: 'root_cause_others', label: 'Progress Update' },
                    ].map(({ field, label }) => (
                        <div key={field} className="flex items-center gap-2">
                            <Checkbox
                                id={field}
                                checked={!!data[field]}
                                onCheckedChange={(checked) => handleCheckbox(field, checked)}
                            />
                            <Label htmlFor={field} className="cursor-pointer text-sm">{label}</Label>
                        </div>
                    ))}
                </div>
            </section>

            {/* Section 7: Agent Strengths */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Agent Strengths or Wins
                </h3>
                <Textarea
                    id="agent_strengths_wins"
                    value={String(data.agent_strengths_wins || '')}
                    onChange={(e) => setData('agent_strengths_wins', e.target.value)}
                    placeholder="Highlight what the agent is doing well..."
                    rows={3}
                />
                {errors.agent_strengths_wins && <p className="text-red-600 text-sm mt-1">{errors.agent_strengths_wins}</p>}
            </section>

            {/* Section 8: SMART Action Plan */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    SMART Action Plan <span className="text-red-500">*</span>
                </h3>
                <Textarea
                    id="smart_action_plan"
                    value={String(data.smart_action_plan || '')}
                    onChange={(e) => setData('smart_action_plan', e.target.value)}
                    placeholder="Define specific, measurable, achievable, relevant, and time-bound action items..."
                    rows={5}
                />
                {errors.smart_action_plan && <p className="text-red-600 text-sm mt-1">{errors.smart_action_plan}</p>}
            </section>

            {/* Section 9 & 10: Follow-up Date & Severity */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Additional Details
                </h3>
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <Label htmlFor="follow_up_date">Follow-up Date</Label>
                        <DatePicker
                            value={String(data.follow_up_date || '')}
                            onChange={(val) => setData('follow_up_date', val)}
                            placeholder="Select follow-up date"
                            minDate={nextMonday}
                            defaultMonth={nextMonday}
                        />
                        {errors.follow_up_date && <p className="text-red-600 text-sm mt-1">{errors.follow_up_date}</p>}
                    </div>
                    <div>
                        <Label htmlFor="severity_flag">Severity Flag</Label>
                        <Select
                            value={String(data.severity_flag || 'Normal')}
                            onValueChange={(val) => setData('severity_flag', val)}
                        >
                            <SelectTrigger id="severity_flag">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {severityFlags.map((flag) => (
                                    <SelectItem key={flag} value={flag}>
                                        {flag}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.severity_flag && <p className="text-red-600 text-sm mt-1">{errors.severity_flag}</p>}
                    </div>
                </div>
            </section>
        </div>
    );
}
