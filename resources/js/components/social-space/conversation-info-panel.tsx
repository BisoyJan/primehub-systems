import { useState } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ThemeBackground } from './types';
import { getAvatarColor, getInitials } from './utils';

interface ConversationInfoPanelProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    subtitle: string;
    conversationKind: 'group' | 'direct';
    isDirectGroupChat?: boolean;
    currentGroupChatName?: string | null;
    groupChatImageUrl?: string | null;
    campaignName?: string | null;
    participantCount?: number;
    participantNames?: string[];
    themeColor: string;
    themeBackground: ThemeBackground;
    themeBackgroundImageUrl?: string | null;
    processing: boolean;
    groupInfoProcessing?: boolean;
    onThemeColorChange: (value: string) => void;
    onThemeBackgroundChange: (value: ThemeBackground) => void;
    onThemeImageChange: (file: File | null) => void;
    onSave: () => void;
    onGroupNameSave?: (name: string) => void;
    onGroupImageSave?: (file: File) => void;
}

const BACKGROUND_OPTIONS: Array<{ key: ThemeBackground; label: string }> = [
    { key: 'default', label: 'Default' },
    { key: 'aurora', label: 'Aurora' },
    { key: 'ocean', label: 'Ocean' },
    { key: 'sunset', label: 'Sunset' },
    { key: 'forest', label: 'Forest' },
    { key: 'carbon', label: 'Carbon' },
    { key: 'image', label: 'Image' },
];

export function ConversationInfoPanel({
    open,
    onOpenChange,
    title,
    subtitle,
    conversationKind,
    isDirectGroupChat = false,
    currentGroupChatName,
    groupChatImageUrl,
    campaignName,
    participantCount,
    participantNames,
    themeColor,
    themeBackground,
    themeBackgroundImageUrl,
    processing,
    groupInfoProcessing = false,
    onThemeColorChange,
    onThemeBackgroundChange,
    onThemeImageChange,
    onSave,
    onGroupNameSave,
    onGroupImageSave,
}: ConversationInfoPanelProps) {
    const [editingName, setEditingName] = useState('');
    const [isEditingName, setIsEditingName] = useState(false);

    const startEditName = () => {
        setEditingName(currentGroupChatName ?? '');
        setIsEditingName(true);
    };

    const handleNameSave = () => {
        if (editingName.trim()) {
            onGroupNameSave?.(editingName.trim());
        }
        setIsEditingName(false);
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full max-w-md overflow-y-auto">
                <SheetHeader>
                    <SheetTitle>{title}</SheetTitle>
                    <SheetDescription>{subtitle}</SheetDescription>
                </SheetHeader>

                <div className="space-y-6 px-4 pb-6">
                    {isDirectGroupChat && (
                        <section className="space-y-3 rounded-lg border p-3">
                            <h3 className="text-sm font-semibold">Group info</h3>

                            <div className="flex flex-col items-center gap-3">
                                <div className="relative">
                                    <Avatar className="size-20">
                                        <AvatarImage src={groupChatImageUrl ?? undefined} alt={currentGroupChatName ?? 'Group'} />
                                        <AvatarFallback className={`text-xl text-white ${getAvatarColor(currentGroupChatName ?? 'G')}`}>
                                            {getInitials(currentGroupChatName ?? 'Group')}
                                        </AvatarFallback>
                                    </Avatar>
                                    <label
                                        htmlFor="group-photo"
                                        className="absolute right-0 bottom-0 flex size-6 cursor-pointer items-center justify-center rounded-full bg-primary text-primary-foreground shadow"
                                        title="Change group photo"
                                    >
                                        <span className="text-xs">✎</span>
                                    </label>
                                    <input
                                        id="group-photo"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        className="sr-only"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            if (file) {
                                                onGroupImageSave?.(file);
                                            }
                                            e.target.value = '';
                                        }}
                                    />
                                </div>
                                {groupInfoProcessing && (
                                    <p className="text-xs text-muted-foreground">Saving...</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="group-name">Group name</Label>
                                {isEditingName ? (
                                    <div className="flex gap-2">
                                        <Input
                                            id="group-name"
                                            value={editingName}
                                            onChange={(e) => setEditingName(e.target.value)}
                                            maxLength={100}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') handleNameSave();
                                                if (e.key === 'Escape') setIsEditingName(false);
                                            }}
                                            autoFocus
                                        />
                                        <Button type="button" size="sm" onClick={handleNameSave} disabled={groupInfoProcessing}>
                                            Save
                                        </Button>
                                        <Button type="button" size="sm" variant="outline" onClick={() => setIsEditingName(false)}>
                                            Cancel
                                        </Button>
                                    </div>
                                ) : (
                                    <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2">
                                        <span className="text-sm">{currentGroupChatName ?? 'Group Chat'}</span>
                                        <Button type="button" size="sm" variant="ghost" onClick={startEditName}>
                                            Edit
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </section>
                    )}
                    <section className="space-y-3 rounded-lg border p-3">
                        <h3 className="text-sm font-semibold">Conversation details</h3>
                        <dl className="space-y-2 text-sm">
                            <div className="flex items-center justify-between gap-2">
                                <dt className="text-muted-foreground">Type</dt>
                                <dd className="font-medium capitalize">{conversationKind}</dd>
                            </div>
                            {typeof participantCount === 'number' && (
                                <div className="flex items-center justify-between gap-2">
                                    <dt className="text-muted-foreground">Participants</dt>
                                    <dd className="font-medium">{participantCount}</dd>
                                </div>
                            )}
                            {participantNames && participantNames.length > 0 && (
                                <div className="space-y-1">
                                    <dt className="text-muted-foreground">Members</dt>
                                    <dd className="space-y-1">
                                        {participantNames.map((name) => (
                                            <div key={name} className="rounded-md bg-muted px-2 py-1 text-xs font-medium">
                                                {name}
                                            </div>
                                        ))}
                                    </dd>
                                </div>
                            )}
                            {campaignName && (
                                <div className="flex items-center justify-between gap-2">
                                    <dt className="text-muted-foreground">Campaign</dt>
                                    <dd className="font-medium">{campaignName}</dd>
                                </div>
                            )}
                        </dl>
                    </section>

                    <section className="space-y-4 rounded-lg border p-3">
                        <h3 className="text-sm font-semibold">Shared chat theme</h3>

                        <div className="space-y-2">
                            <Label htmlFor="theme-color">Accent color</Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="theme-color"
                                    type="color"
                                    value={themeColor}
                                    onChange={(event) => onThemeColorChange(event.target.value)}
                                    className="h-10 w-14 cursor-pointer rounded border p-1"
                                />
                                <Input
                                    value={themeColor}
                                    onChange={(event) => onThemeColorChange(event.target.value)}
                                    className="font-mono uppercase"
                                    placeholder="#2563EB"
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Background</Label>
                            <div className="grid grid-cols-2 gap-2">
                                {BACKGROUND_OPTIONS.map((option) => (
                                    <Button
                                        key={option.key}
                                        type="button"
                                        variant={themeBackground === option.key ? 'default' : 'outline'}
                                        onClick={() => onThemeBackgroundChange(option.key)}
                                        className="justify-start"
                                    >
                                        {option.label}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="theme-background-image">Custom background image</Label>
                            <Input
                                id="theme-background-image"
                                type="file"
                                accept="image/png,image/jpeg,image/jpg,image/webp"
                                onChange={(event) => {
                                    onThemeImageChange(event.target.files?.[0] ?? null);
                                    onThemeBackgroundChange('image');
                                }}
                            />
                            {themeBackgroundImageUrl && (
                                <div className="overflow-hidden rounded-lg border">
                                    <img
                                        src={themeBackgroundImageUrl}
                                        alt="Current chat background"
                                        className="h-24 w-full object-cover"
                                    />
                                </div>
                            )}
                        </div>

                        <Button type="button" onClick={onSave} disabled={processing} className="w-full">
                            {processing ? 'Saving theme...' : 'Save theme'}
                        </Button>
                    </section>
                </div>
            </SheetContent>
        </Sheet>
    );
}
