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
import type { CoachingPurposeLabels, User } from '@/types';

interface CoachingFormFieldsProps {
    data: Record<string, unknown>;
    setData: (key: string, value: unknown) => void;
    errors: Record<string, string>;
    agents?: User[];
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
    purposes,
    severityFlags,
    showAgentSelect = true,
    selectedAgentId,
}: CoachingFormFieldsProps) {
    const [agentSearchOpen, setAgentSearchOpen] = useState(false);
    const [agentSearchQuery, setAgentSearchQuery] = useState('');

    const selectedAgent = useMemo(() => {
        const id = Number(data.agent_id || selectedAgentId);
        return agents.find((a) => a.id === id) ?? null;
    }, [data.agent_id, selectedAgentId, agents]);

    const getAgentCampaign = (agent: User): string | null => {
        const schedule = (agent as Record<string, unknown>).active_schedule as { campaign?: { name?: string } } | null;
        return schedule?.campaign?.name ?? null;
    };

    const filteredAgents = useMemo(() => {
        if (!agentSearchQuery) return agents.slice(0, 50);
        const q = agentSearchQuery.toLowerCase();
        return agents
            .filter((a) => {
                const name = `${a.first_name} ${a.last_name}`.toLowerCase();
                const campaign = getAgentCampaign(a)?.toLowerCase() ?? '';
                return name.includes(q) || campaign.includes(q);
            })
            .slice(0, 50);
    }, [agents, agentSearchQuery]);

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
                    {showAgentSelect && (
                        <div>
                            <Label htmlFor="agent_id">Agent <span className="text-red-500">*</span></Label>
                            <Popover open={agentSearchOpen} onOpenChange={setAgentSearchOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={agentSearchOpen}
                                        className="w-full justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {selectedAgent
                                                ? `${selectedAgent.first_name} ${selectedAgent.last_name}${getAgentCampaign(selectedAgent) ? ` — ${getAgentCampaign(selectedAgent)}` : ''}`
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
                                                            setData('agent_id', agent.id);
                                                            setAgentSearchOpen(false);
                                                            setAgentSearchQuery('');
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${
                                                                selectedAgent?.id === agent.id ? 'opacity-100' : 'opacity-0'
                                                            }`}
                                                        />
                                                        <div className="flex flex-col">
                                                            <span>{agent.first_name} {agent.last_name}</span>
                                                            {getAgentCampaign(agent) && (
                                                                <span className="text-xs text-muted-foreground">{getAgentCampaign(agent)}</span>
                                                            )}
                                                        </div>
                                                    </CommandItem>
                                                ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                            {errors.agent_id && <p className="text-red-600 text-sm mt-1">{errors.agent_id}</p>}
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
                        { field: 'root_cause_lack_of_clarity', label: 'Lack of Clarity' },
                        { field: 'root_cause_personal_issues', label: 'Personal Issues' },
                        { field: 'root_cause_motivation_engagement', label: 'Motivation / Engagement' },
                        { field: 'root_cause_health_fatigue', label: 'Health / Fatigue' },
                        { field: 'root_cause_workload_process', label: 'Workload / Process' },
                        { field: 'root_cause_peer_conflict', label: 'Peer Conflict' },
                        { field: 'root_cause_others', label: 'Others' },
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
                {!!data.root_cause_others && (
                    <div>
                        <Label htmlFor="root_cause_others_notes">Other Root Cause Details</Label>
                        <Input
                            id="root_cause_others_notes"
                            value={String(data.root_cause_others_notes || '')}
                            onChange={(e) => setData('root_cause_others_notes', e.target.value)}
                            placeholder="Specify other root cause..."
                        />
                        {errors.root_cause_others_notes && <p className="text-red-600 text-sm mt-1">{errors.root_cause_others_notes}</p>}
                    </div>
                )}
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
                            minDate={String(data.session_date || '')}
                            defaultMonth={String(data.session_date || '')}
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
