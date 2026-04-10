import { useState, useMemo, useRef, useCallback, useEffect } from 'react';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { RichTextarea } from '@/components/coaching/RichTextarea';
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
import { AlertTriangle, Check, ChevronsUpDown, ImagePlus, Users, X, ZoomIn, ZoomOut, RotateCcw } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { CoachingMode, CoachingPurposeLabels, CoachingSessionAttachment, User } from '@/types';

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
    onAgentAddToQueue?: (agent: User) => void;
    queueAgentIds?: number[];
    coachedThisWeekIds?: number[];
    draftedThisWeekIds?: number[];
    existingAttachments?: CoachingSessionAttachment[];
    removedAttachmentIds?: number[];
    onRemoveExistingAttachment?: (id: number) => void;
    attachmentViewUrl?: (sessionId: number, attachmentId: number) => string;
}

const MAX_ATTACHMENTS = 10;
const MAX_FILE_SIZE = 4 * 1024 * 1024; // 4MB
const ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];

function ImageAttachments({
    data,
    setData,
    errors,
    existingAttachments = [],
    removedAttachmentIds = [],
    onRemoveExistingAttachment,
    attachmentViewUrl,
}: Pick<CoachingFormFieldsProps, 'data' | 'setData' | 'errors' | 'existingAttachments' | 'removedAttachmentIds' | 'onRemoveExistingAttachment' | 'attachmentViewUrl'>) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [previews, setPreviews] = useState<string[]>([]);
    const [warning, setWarning] = useState<string | null>(null);
    const [previewImage, setPreviewImage] = useState<{ src: string; name: string } | null>(null);
    const [imageZoom, setImageZoom] = useState(100);

    const newFiles = (data.attachments as File[]) || [];
    const activeExisting = existingAttachments.filter((a) => !removedAttachmentIds.includes(a.id));
    const totalCount = activeExisting.length + newFiles.length;
    const canAddMore = totalCount < MAX_ATTACHMENTS;

    const addFiles = useCallback(
        (files: File[]) => {
            if (files.length === 0) return;

            setWarning(null);

            const currentFiles = (data.attachments as File[]) || [];
            const currentExisting = existingAttachments.filter((a) => !removedAttachmentIds.includes(a.id));
            const currentTotal = currentExisting.length + currentFiles.length;
            const availableSlots = MAX_ATTACHMENTS - currentTotal;

            if (availableSlots <= 0) {
                setWarning(`Maximum of ${MAX_ATTACHMENTS} images allowed. You already have ${currentTotal}.`);
                return;
            }

            const validFiles = files.filter((f) => ALLOWED_TYPES.includes(f.type) && f.size <= MAX_FILE_SIZE);
            const skippedCount = files.length - validFiles.length;
            const excessCount = Math.max(0, validFiles.length - availableSlots);
            const filesToAdd = validFiles.slice(0, availableSlots);

            const warnings: string[] = [];
            if (excessCount > 0) {
                warnings.push(`${excessCount} image${excessCount > 1 ? 's were' : ' was'} not added — limit is ${MAX_ATTACHMENTS}.`);
            }
            if (skippedCount > 0) {
                warnings.push(`${skippedCount} file${skippedCount > 1 ? 's were' : ' was'} skipped (invalid type or exceeds 4MB).`);
            }
            if (warnings.length > 0) {
                setWarning(warnings.join(' '));
            }

            if (filesToAdd.length > 0) {
                const newPreviewUrls = filesToAdd.map((f) => URL.createObjectURL(f));
                setData('attachments', [...currentFiles, ...filesToAdd]);
                setPreviews((prev) => [...prev, ...newPreviewUrls]);
            }
        },
        [data.attachments, existingAttachments, removedAttachmentIds, setData],
    );

    // Auto-dismiss warning after 5 seconds
    useEffect(() => {
        if (!warning) return;
        const timer = setTimeout(() => setWarning(null), 5000);
        return () => clearTimeout(timer);
    }, [warning]);

    const handleFileSelect = useCallback(
        (e: React.ChangeEvent<HTMLInputElement>) => {
            const selectedFiles = Array.from(e.target.files || []);
            addFiles(selectedFiles);

            // Reset file input so the same file can be re-selected
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        },
        [addFiles],
    );

    // Handle Ctrl+V paste from clipboard (screenshots, copied images)
    // Listens on the document so paste works anywhere on the page
    useEffect(() => {
        const handlePaste = (e: ClipboardEvent) => {
            const items = e.clipboardData?.items;
            if (!items) return;

            const imageFiles: File[] = [];
            for (const item of Array.from(items)) {
                if (item.kind === 'file' && ALLOWED_TYPES.includes(item.type)) {
                    const file = item.getAsFile();
                    if (file) {
                        const ext = file.type.split('/')[1] || 'png';
                        const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
                        const namedFile = new File([file], `pasted-image-${timestamp}.${ext}`, { type: file.type });
                        imageFiles.push(namedFile);
                    }
                }
            }

            if (imageFiles.length > 0) {
                e.preventDefault();
                addFiles(imageFiles);
            }
        };

        document.addEventListener('paste', handlePaste);
        return () => document.removeEventListener('paste', handlePaste);
    }, [addFiles]);

    const handleRemoveNewFile = useCallback(
        (index: number) => {
            const currentFiles = [...((data.attachments as File[]) || [])];
            currentFiles.splice(index, 1);
            setData('attachments', currentFiles);

            setPreviews((prev) => {
                const updated = [...prev];
                URL.revokeObjectURL(updated[index]);
                updated.splice(index, 1);
                return updated;
            });
        },
        [data.attachments, setData],
    );

    // Collect all attachment-related errors
    const attachmentErrors: string[] = [];
    if (errors.attachments) attachmentErrors.push(errors.attachments);
    Object.keys(errors).forEach((key) => {
        if (key.startsWith('attachments.')) attachmentErrors.push(errors[key]);
    });

    return (
        <section className="space-y-4">
            <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                Image Attachments
                <span className="ml-2 text-xs font-normal normal-case text-muted-foreground">
                    ({totalCount}/{MAX_ATTACHMENTS})
                </span>
            </h3>

            {/* Preview Grid */}
            {(activeExisting.length > 0 || previews.length > 0) && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    {/* Existing Attachments */}
                    {activeExisting.map((attachment) => (
                        <div key={`existing-${attachment.id}`} className="group relative aspect-square rounded-lg border bg-muted/30 overflow-hidden">
                            <button
                                type="button"
                                className="h-full w-full"
                                onClick={() => {
                                    setPreviewImage({
                                        src: attachmentViewUrl ? attachmentViewUrl(attachment.coaching_session_id, attachment.id) : '#',
                                        name: attachment.original_filename,
                                    });
                                    setImageZoom(100);
                                }}
                            >
                                <img
                                    src={attachmentViewUrl ? attachmentViewUrl(attachment.coaching_session_id, attachment.id) : '#'}
                                    alt={attachment.original_filename}
                                    className="h-full w-full object-cover"
                                />
                                <div className="absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/20">
                                    <ZoomIn className="h-5 w-5 text-white opacity-0 transition-opacity group-hover:opacity-100" />
                                </div>
                            </button>
                            {onRemoveExistingAttachment && (
                                <button
                                    type="button"
                                    title="Remove attachment"
                                    onClick={() => onRemoveExistingAttachment(attachment.id)}
                                    className="absolute top-1 right-1 rounded-full bg-red-600 p-1 text-white opacity-0 transition-opacity group-hover:opacity-100 z-10"
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            )}
                            <div className="absolute bottom-0 left-0 right-0 bg-black/50 px-1.5 py-0.5 text-[10px] text-white truncate pointer-events-none">
                                {attachment.original_filename}
                            </div>
                        </div>
                    ))}

                    {/* New File Previews */}
                    {previews.map((previewUrl, index) => (
                        <div key={`new-${index}`} className="group relative aspect-square rounded-lg border bg-muted/30 overflow-hidden">
                            <button
                                type="button"
                                className="h-full w-full"
                                onClick={() => {
                                    setPreviewImage({
                                        src: previewUrl,
                                        name: newFiles[index]?.name ?? 'New image',
                                    });
                                    setImageZoom(100);
                                }}
                            >
                                <img src={previewUrl} alt={newFiles[index]?.name ?? 'Preview'} className="h-full w-full object-cover" />
                                <div className="absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/20">
                                    <ZoomIn className="h-5 w-5 text-white opacity-0 transition-opacity group-hover:opacity-100" />
                                </div>
                            </button>
                            <button
                                type="button"
                                title="Remove image"
                                onClick={() => handleRemoveNewFile(index)}
                                className="absolute top-1 right-1 rounded-full bg-red-600 p-1 text-white opacity-0 transition-opacity group-hover:opacity-100 z-10"
                            >
                                <X className="h-3 w-3" />
                            </button>
                            <div className="absolute bottom-0 left-0 right-0 bg-black/50 px-1.5 py-0.5 text-[10px] text-white truncate pointer-events-none">
                                {newFiles[index]?.name ?? 'New image'}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Upload Button */}
            {canAddMore && (
                <div className="flex flex-wrap items-center gap-2">
                    <label htmlFor="coaching-attachments" className="sr-only">Upload image attachments</label>
                    <input
                        ref={fileInputRef}
                        type="file"
                        accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                        multiple
                        onChange={handleFileSelect}
                        className="hidden"
                        id="coaching-attachments"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => fileInputRef.current?.click()}
                        className="gap-2"
                    >
                        <ImagePlus className="h-4 w-4" />
                        Add Images
                    </Button>
                    <p className="text-xs text-muted-foreground">
                        JPEG, PNG, GIF, or WebP. Max 4MB per image. You can also <kbd className="rounded border bg-muted px-1 py-0.5 text-[10px] font-mono">Ctrl+V</kbd> to paste screenshots.
                    </p>
                </div>
            )}

            {/* Warning */}
            {warning && (
                <div className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950/50 dark:text-amber-200">
                    <span className="shrink-0 mt-0.5">⚠️</span>
                    <span>{warning}</span>
                </div>
            )}

            {/* Errors */}
            {attachmentErrors.map((err, i) => (
                <p key={i} className="text-red-600 text-sm">{err}</p>
            ))}

            {/* Image Preview Lightbox */}
            <Dialog open={!!previewImage} onOpenChange={(open) => !open && setPreviewImage(null)}>
                <DialogContent className="max-w-[95vw] sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle className="truncate pr-8">{previewImage?.name}</DialogTitle>
                        <DialogDescription className="sr-only">Image preview</DialogDescription>
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
                            type="button"
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
                            {previewImage && (
                                <img
                                    src={previewImage.src}
                                    alt={previewImage.name}
                                    className="object-contain rounded-lg border transition-transform duration-200 origin-top"
                                    style={{ transform: `scale(${imageZoom / 100})` }}
                                />
                            )}
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </section>
    );
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
    onAgentAddToQueue,
    queueAgentIds = [],
    coachedThisWeekIds = [],
    draftedThisWeekIds = [],
    existingAttachments = [],
    removedAttachmentIds = [],
    onRemoveExistingAttachment,
    attachmentViewUrl,
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
                                                {filteredAgents.map((agent) => {
                                                    const isInQueue = queueAgentIds.includes(agent.id);
                                                    const isCoachedThisWeek = coachedThisWeekIds.includes(agent.id);
                                                    const isDraftedThisWeek = !isCoachedThisWeek && draftedThisWeekIds.includes(agent.id);
                                                    const isSelected = selectedAgent?.id === agent.id;
                                                    return (
                                                        <CommandItem
                                                            key={agent.id}
                                                            value={String(agent.id)}
                                                            onSelect={() => {
                                                                if (onAgentAddToQueue && selectedAgent && agent.id !== selectedAgent.id) {
                                                                    onAgentAddToQueue(agent);
                                                                } else {
                                                                    setData('coachee_id', agent.id);
                                                                }
                                                                setAgentSearchOpen(false);
                                                                setAgentSearchQuery('');
                                                            }}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 shrink-0 ${isSelected || isInQueue ? 'opacity-100' : 'opacity-0'}`}
                                                            />
                                                            <div className="flex flex-col flex-1 min-w-0">
                                                                <div className="flex items-center gap-1.5">
                                                                    <span>{agent.first_name} {agent.last_name}</span>
                                                                    {isInQueue && !isSelected && (
                                                                        <span className="inline-flex items-center gap-0.5 rounded bg-primary/10 px-1 py-0.5 text-[9px] font-medium text-primary">
                                                                            <Users className="h-2.5 w-2.5" />
                                                                            In Queue
                                                                        </span>
                                                                    )}
                                                                    {isCoachedThisWeek && (
                                                                        <span className="inline-flex items-center gap-0.5 rounded bg-amber-500/10 px-1 py-0.5 text-[9px] font-medium text-amber-600 dark:text-amber-400">
                                                                            <AlertTriangle className="h-2.5 w-2.5" />
                                                                            Coached
                                                                        </span>
                                                                    )}
                                                                    {isDraftedThisWeek && (
                                                                        <span className="inline-flex items-center gap-0.5 rounded bg-blue-500/10 px-1 py-0.5 text-[9px] font-medium text-blue-600 dark:text-blue-400">
                                                                            Draft
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                {getAgentCampaign(agent) && (
                                                                    <span className="text-xs text-muted-foreground">{getAgentCampaign(agent)!.name}</span>
                                                                )}
                                                            </div>
                                                        </CommandItem>
                                                    );
                                                })}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                            {errors.coachee_id && <p className="text-red-600 text-sm mt-1">{errors.coachee_id}</p>}
                            {selectedAgent && coachedThisWeekIds.includes(selectedAgent.id) && (
                                <div className="mt-1.5 flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-700 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400">
                                    <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                                    <span>This agent has already been coached this week.</span>
                                </div>
                            )}
                            {selectedAgent && !coachedThisWeekIds.includes(selectedAgent.id) && draftedThisWeekIds.includes(selectedAgent.id) && (
                                <div className="mt-1.5 flex items-center gap-1.5 rounded-md border border-blue-300 bg-blue-50 px-2.5 py-1.5 text-xs text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-400">
                                    <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                                    <span>This agent has a draft coaching session this week.</span>
                                </div>
                            )}
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
                    Agent's Profile <span className="text-red-500">*</span>
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
                {errors.profile && <p className="text-red-600 text-sm mt-1">{errors.profile}</p>}
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
                    Focus Area(s) <span className="text-red-500">*</span>
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
                {errors.focus && <p className="text-red-600 text-sm mt-1">{errors.focus}</p>}
            </section>

            {/* Section 5: Performance Description */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Performance Description <span className="text-red-500">*</span>
                </h3>
                <RichTextarea
                    id="performance_description"
                    value={String(data.performance_description || '')}
                    onChange={(html) => setData('performance_description', html)}
                    placeholder="Describe the employee's current performance, specific incidents, or areas for improvement..."
                    minHeight="120px"
                />
                {errors.performance_description && <p className="text-red-600 text-sm mt-1">{errors.performance_description}</p>}
            </section>

            {/* Section 5b: Image Attachments */}
            <ImageAttachments
                data={data}
                setData={setData}
                errors={errors}
                existingAttachments={existingAttachments}
                removedAttachmentIds={removedAttachmentIds}
                onRemoveExistingAttachment={onRemoveExistingAttachment}
                attachmentViewUrl={attachmentViewUrl}
            />

            {/* Section 6: Root Causes */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Root Cause(s) <span className="text-red-500">*</span>
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
                {errors.root_cause && <p className="text-red-600 text-sm mt-1">{errors.root_cause}</p>}
            </section>

            {/* Section 7: Agent Strengths */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    Agent Strengths or Wins
                </h3>
                <RichTextarea
                    id="agent_strengths_wins"
                    value={String(data.agent_strengths_wins || '')}
                    onChange={(html) => setData('agent_strengths_wins', html)}
                    placeholder="Highlight what the agent is doing well..."
                    minHeight="80px"
                />
                {errors.agent_strengths_wins && <p className="text-red-600 text-sm mt-1">{errors.agent_strengths_wins}</p>}
            </section>

            {/* Section 8: SMART Action Plan */}
            <section className="space-y-4">
                <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide border-b pb-2">
                    SMART Action Plan <span className="text-red-500">*</span>
                </h3>
                <RichTextarea
                    id="smart_action_plan"
                    value={String(data.smart_action_plan || '')}
                    onChange={(html) => setData('smart_action_plan', html)}
                    placeholder="Define specific, measurable, achievable, relevant, and time-bound action items..."
                    minHeight="120px"
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
