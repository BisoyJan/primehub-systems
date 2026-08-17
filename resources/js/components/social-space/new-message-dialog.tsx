import { FormEvent, useMemo, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Search } from 'lucide-react';
import { Person } from './types';
import { getAvatarColor, getInitials } from './utils';

interface NewMessageDialogProps {
    open: boolean;
    people: Person[];
    processing: boolean;
    groupName: string;
    selectedParticipantIds: number[];
    canCreateGroup: boolean;
    onOpenChange: (open: boolean) => void;
    onSubmitGroup: (event: FormEvent<HTMLFormElement>) => void;
    onGroupNameChange: (name: string) => void;
    onToggleParticipant: (personId: number, checked: boolean) => void;
    onStartDirectChat: (person: Person) => void;
}

export function NewMessageDialog({
    open,
    people,
    processing,
    groupName,
    selectedParticipantIds,
    canCreateGroup,
    onOpenChange,
    onSubmitGroup,
    onGroupNameChange,
    onToggleParticipant,
    onStartDirectChat,
}: NewMessageDialogProps) {
    const [search, setSearch] = useState('');
    const [selectedCampaign, setSelectedCampaign] = useState('all');

    const campaignOptions = useMemo(() => {
        const byId = new Map<number, string>();

        for (const person of people) {
            if (person.campaign_id && person.campaign_name) {
                byId.set(person.campaign_id, person.campaign_name);
            }
        }

        return Array.from(byId.entries())
            .map(([id, name]) => ({ id, name }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }, [people]);

    const filteredPeople = useMemo(() => {
        const term = search.trim().toLowerCase();
        return people.filter((person) => {
            const campaignMatches =
                selectedCampaign === 'all' || String(person.campaign_id ?? '') === selectedCampaign;

            if (!campaignMatches) {
                return false;
            }

            if (!term) {
                return true;
            }

            const fullName = `${person.first_name} ${person.last_name}`.toLowerCase();
            const campaignName = (person.campaign_name ?? '').toLowerCase();

            return fullName.includes(term) || campaignName.includes(term);
        });
    }, [people, search, selectedCampaign]);

    const isMultiSelect = selectedParticipantIds.length >= 2;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>New Message</DialogTitle>
                    <DialogDescription>
                        Pick a person to start chatting, or select multiple to create a group.
                    </DialogDescription>
                </DialogHeader>

                <div className="relative">
                    <Search className="absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search people..."
                        className="pl-8"
                    />
                </div>

                <div className="space-y-1">
                    <label htmlFor="new-message-campaign-filter" className="text-sm font-medium">
                        Campaign
                    </label>
                    <select
                        id="new-message-campaign-filter"
                        value={selectedCampaign}
                        onChange={(event) => setSelectedCampaign(event.target.value)}
                        className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    >
                        <option value="all">All campaigns</option>
                        {campaignOptions.map((campaign) => (
                            <option key={campaign.id} value={String(campaign.id)}>
                                {campaign.name}
                            </option>
                        ))}
                    </select>
                </div>

                {isMultiSelect && (
                    <div className="space-y-1">
                        <label htmlFor="new-message-group-name" className="text-sm font-medium">
                            Group Name
                        </label>
                        <Input
                            id="new-message-group-name"
                            value={groupName}
                            onChange={(event) => onGroupNameChange(event.target.value)}
                            placeholder="QA War Room"
                        />
                    </div>
                )}

                <div className="max-h-72 space-y-1 overflow-y-auto rounded-lg border p-2">
                    {filteredPeople.length === 0 ? (
                        <p className="p-3 text-center text-sm text-muted-foreground">No people found for the selected filters.</p>
                    ) : (
                        filteredPeople.map((person) => {
                            const fullName = `${person.first_name} ${person.last_name}`;
                            const checked = selectedParticipantIds.includes(person.id);

                            return (
                                <label
                                    key={person.id}
                                    className="flex cursor-pointer items-center justify-between gap-2 rounded-md px-2 py-1.5 hover:bg-accent"
                                >
                                    <span className="flex min-w-0 items-center gap-2">
                                        <Avatar className="size-8">
                                            <AvatarImage src={person.avatar_url ?? undefined} alt={fullName} />
                                            <AvatarFallback className={`text-xs text-white ${getAvatarColor(fullName)}`}>
                                                {getInitials(fullName)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm">{fullName}</span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {person.campaign_name ?? 'No campaign'}
                                            </span>
                                        </span>
                                    </span>
                                    <input
                                        type="checkbox"
                                        checked={checked}
                                        onChange={(event) => onToggleParticipant(person.id, event.target.checked)}
                                    />
                                </label>
                            );
                        })
                    )}
                </div>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    {isMultiSelect ? (
                        <Button
                            type="button"
                            disabled={processing || !canCreateGroup}
                            title={canCreateGroup ? undefined : 'You do not have permission to create group chats'}
                            onClick={(event) => onSubmitGroup(event as unknown as FormEvent<HTMLFormElement>)}
                        >
                            Create Group Chat
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            disabled={processing || selectedParticipantIds.length !== 1}
                            onClick={() => {
                                const person = people.find((candidate) => candidate.id === selectedParticipantIds[0]);
                                if (person) {
                                    onStartDirectChat(person);
                                }
                            }}
                        >
                            Start Chat
                        </Button>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
