import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { MoreHorizontal, Reply, SmilePlus } from 'lucide-react';
import { DirectReadMarker, Message, MessageParent, QUICK_REACTIONS } from './types';
import { getAvatarColor, getInitials } from './utils';

interface ChatMessagesProps {
    activeType: 'group' | 'direct';
    currentUserId: number;
    messages: Message[];
    directReadMarkers: DirectReadMarker[];
    hasMore: boolean;
    loadingOlder: boolean;
    onLoadOlder: () => void;
    onToggleReaction: (messageId: number, emoji: string) => void;
    onEditMessage: (messageId: number, nextMessage: string) => void;
    onDeleteMessage: (messageId: number) => void;
    onReplyMessage: (message: Message) => void;
}

function groupReactionCounts(reactions: Message['reactions']): Array<{ emoji: string; count: number }> {
    const counts = new Map<string, number>();

    for (const reaction of reactions ?? []) {
        counts.set(reaction.emoji, (counts.get(reaction.emoji) ?? 0) + 1);
    }

    return Array.from(counts.entries()).map(([emoji, count]) => ({ emoji, count }));
}

const TIME_DIVIDER_GAP_MS = 30 * 60 * 1000;

function formatTimeDivider(date: Date): string {
    const now = new Date();
    const time = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    const isSameDay = date.toDateString() === now.toDateString();

    if (isSameDay) {
        return time;
    }

    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) {
        return `Yesterday ${time}`;
    }

    const withinWeek = now.getTime() - date.getTime() < 7 * 24 * 60 * 60 * 1000;
    if (withinWeek) {
        return `${date.toLocaleDateString([], { weekday: 'short' })} ${time}`;
    }

    return `${date.toLocaleDateString([], { month: 'short', day: 'numeric' })}, ${time}`;
}

function shouldShowTimeDivider(current: Message, previous?: Message): boolean {
    if (!previous) {
        return true;
    }

    const currentDate = new Date(current.created_at);
    const previousDate = new Date(previous.created_at);

    if (currentDate.toDateString() !== previousDate.toDateString()) {
        return true;
    }

    return currentDate.getTime() - previousDate.getTime() >= TIME_DIVIDER_GAP_MS;
}

export function ChatMessages({
    activeType,
    currentUserId,
    messages,
    directReadMarkers,
    hasMore,
    loadingOlder,
    onLoadOlder,
    onToggleReaction,
    onEditMessage,
    onDeleteMessage,
    onReplyMessage,
}: ChatMessagesProps) {
    const containerRef = useRef<HTMLDivElement | null>(null);
    const messageRefs = useRef<Map<number, HTMLDivElement>>(new Map());
    const [editingMessageId, setEditingMessageId] = useState<number | null>(null);
    const [editingDraft, setEditingDraft] = useState('');
    const [openReactionFor, setOpenReactionFor] = useState<number | null>(null);
    const [highlightedMessageId, setHighlightedMessageId] = useState<number | null>(null);

    const registerMessageRef = useCallback((id: number) => (node: HTMLDivElement | null) => {
        if (node) {
            messageRefs.current.set(id, node);
        } else {
            messageRefs.current.delete(id);
        }
    }, []);

    const scrollToMessage = useCallback((id: number) => {
        const node = messageRefs.current.get(id);
        if (!node) {
            return;
        }

        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setHighlightedMessageId(id);
    }, []);

    useEffect(() => {
        if (highlightedMessageId === null) {
            return;
        }

        const timeout = window.setTimeout(() => setHighlightedMessageId(null), 1600);
        return () => window.clearTimeout(timeout);
    }, [highlightedMessageId]);

    const seenMap = useMemo(() => {
        const map = new Map<number, string[]>();

        for (const message of messages) {
            if (message.user?.id !== currentUserId || activeType !== 'direct') {
                continue;
            }

            const seenBy = directReadMarkers
                .filter((marker) => marker.user_id !== currentUserId)
                .filter((marker) => {
                    if (!marker.last_read_at) {
                        return false;
                    }

                    return new Date(marker.last_read_at).getTime() >= new Date(message.created_at).getTime();
                })
                .map((marker) => marker.name);

            map.set(message.id, seenBy);
        }

        return map;
    }, [messages, currentUserId, activeType, directReadMarkers]);

    return (
        <div
            ref={containerRef}
            onScroll={() => {
                const element = containerRef.current;
                if (!element || loadingOlder || !hasMore) {
                    return;
                }

                if (element.scrollTop <= 120) {
                    onLoadOlder();
                }
            }}
            className="flex-1 space-y-3 overflow-y-auto bg-transparent px-4 py-4"
        >
            {hasMore && (
                <div className="flex justify-center">
                    <Button type="button" variant="outline" size="sm" disabled={loadingOlder} onClick={onLoadOlder}>
                        {loadingOlder ? 'Loading...' : 'Load older'}
                    </Button>
                </div>
            )}

            {messages.length === 0 ? (
                <Card className="border-dashed p-8 text-center text-sm text-muted-foreground">
                    Start the conversation.
                </Card>
            ) : (
                messages.map((message, messageIndex) => {
                    const previousMessage = messageIndex > 0 ? messages[messageIndex - 1] : undefined;
                    const nextMessage = messageIndex < messages.length - 1 ? messages[messageIndex + 1] : undefined;
                    const showTimeDivider = shouldShowTimeDivider(message, previousMessage);
                    const isMine = message.user?.id === currentUserId;
                    const fullName = message.user
                        ? `${message.user.first_name} ${message.user.last_name}`
                        : 'Unknown';
                    const reactionCounts = groupReactionCounts(message.reactions);
                    const isEditing = editingMessageId === message.id;
                    const seenBy = seenMap.get(message.id) ?? [];
                    const sameAuthorAsPrev = !showTimeDivider && previousMessage?.user?.id === message.user?.id;
                    const nextShowsTimeDivider = nextMessage ? shouldShowTimeDivider(nextMessage, message) : true;
                    const sameAuthorAsNext = !nextShowsTimeDivider && nextMessage?.user?.id === message.user?.id;
                    const isFirstInGroup = !sameAuthorAsPrev;
                    const isLastInGroup = !sameAuthorAsNext;

                    const reactionPicker = (
                        <Popover
                            open={openReactionFor === message.id}
                            onOpenChange={(open) => setOpenReactionFor(open ? message.id : null)}
                        >
                            <PopoverTrigger asChild>
                                <button
                                    type="button"
                                    aria-label="Add reaction"
                                    className="pointer-events-none shrink-0 self-center rounded-full border bg-background/90 p-1.5 text-muted-foreground opacity-0 transition hover:bg-accent hover:text-foreground group-hover:pointer-events-auto group-hover:opacity-100 focus-visible:pointer-events-auto focus-visible:opacity-100 data-[state=open]:pointer-events-auto data-[state=open]:opacity-100"
                                >
                                    <SmilePlus className="h-4 w-4" />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent
                                side="top"
                                align={isMine ? 'end' : 'start'}
                                sideOffset={8}
                                className="w-auto rounded-full p-1"
                            >
                                <div className="flex items-center gap-0.5">
                                    {QUICK_REACTIONS.map((emoji) => (
                                        <button
                                            key={`${message.id}-${emoji}`}
                                            type="button"
                                            onClick={() => {
                                                onToggleReaction(message.id, emoji);
                                                setOpenReactionFor(null);
                                            }}
                                            className="rounded-full px-1.5 py-1 text-lg transition hover:scale-125 hover:bg-accent"
                                        >
                                            {emoji}
                                        </button>
                                    ))}
                                </div>
                            </PopoverContent>
                        </Popover>
                    );

                    const replyButton = (
                        <button
                            type="button"
                            onClick={() => onReplyMessage(message)}
                            aria-label="Reply to message"
                            className="pointer-events-none shrink-0 self-center rounded-full border bg-background/90 p-1.5 text-muted-foreground opacity-0 transition hover:bg-accent hover:text-foreground group-hover:pointer-events-auto group-hover:opacity-100 focus-visible:pointer-events-auto focus-visible:opacity-100"
                        >
                            <Reply className="h-4 w-4" />
                        </button>
                    );

                    const parentQuote = message.parent ? (
                        <MessageQuote
                            parent={message.parent}
                            align={isMine ? 'end' : 'start'}
                            onJump={scrollToMessage}
                        />
                    ) : null;

                    return (
                        <Fragment key={message.id}>
                            {showTimeDivider && (
                                <div className="flex justify-center py-2">
                                    <span className="px-3 py-1 text-[11px] font-medium text-muted-foreground">
                                        {formatTimeDivider(new Date(message.created_at))}
                                    </span>
                                </div>
                            )}
                            <div className={`group flex flex-col gap-1 ${sameAuthorAsPrev ? 'mt-0.5' : 'mt-2'}`}>
                            <div className={`flex items-end gap-2 ${isMine ? 'flex-row-reverse' : 'flex-row'}`}>
                                {!isMine && (
                                    isLastInGroup ? (
                                        <Avatar className="size-8 shrink-0 self-end">
                                            <AvatarImage src={message.user?.avatar_url ?? undefined} alt={fullName} />
                                            <AvatarFallback className={`text-xs text-white ${getAvatarColor(fullName)}`}>
                                                {getInitials(fullName)}
                                            </AvatarFallback>
                                        </Avatar>
                                    ) : (
                                        <div className="size-8 shrink-0" aria-hidden="true" />
                                    )
                                )}
                                <div className={`flex max-w-[82%] flex-col gap-1 ${isMine ? 'items-end' : 'items-start'}`}>
                                    {isFirstInGroup && (
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs text-muted-foreground">{fullName}</span>
                                    </div>
                                    )}
                                    {parentQuote}
                                    <div
                                        ref={registerMessageRef(message.id)}
                                        className={`relative flex items-center gap-1 ${isMine ? 'justify-end' : 'justify-start'} ${reactionCounts.length > 0 ? 'pb-3' : ''} ${highlightedMessageId === message.id ? 'rounded-2xl ring-2 ring-primary/60 ring-offset-2 ring-offset-background transition-shadow duration-500' : ''}`}
                                    >
                                        {isMine && (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <button
                                                        type="button"
                                                        className="pointer-events-none shrink-0 self-center rounded-md p-1 text-muted-foreground opacity-0 transition hover:bg-accent hover:text-foreground group-hover:pointer-events-auto group-hover:opacity-100 focus-visible:pointer-events-auto focus-visible:opacity-100 data-[state=open]:pointer-events-auto data-[state=open]:opacity-100"
                                                        aria-label="Message actions"
                                                    >
                                                        <MoreHorizontal className="h-4 w-4" />
                                                    </button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onClick={() => {
                                                            setEditingMessageId(message.id);
                                                            setEditingDraft(message.message);
                                                        }}
                                                    >
                                                        Edit message
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        className="text-red-600 focus:text-red-700"
                                                        onClick={() => onDeleteMessage(message.id)}
                                                    >
                                                        Delete message
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        )}
                                        {isMine && replyButton}
                                        {isMine && reactionPicker}
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <div
                                                    className={`w-fit rounded-2xl px-3 py-2 text-sm shadow-sm ${
                                                        isMine
                                                            ? 'bg-primary text-primary-foreground'
                                                            : 'border bg-muted text-foreground'
                                                    }`}
                                                >
                                            {isEditing ? (
                                                <div className="space-y-2">
                                                    <Textarea
                                                        value={editingDraft}
                                                        onChange={(event) => setEditingDraft(event.target.value)}
                                                        rows={2}
                                                        className="bg-background text-foreground"
                                                    />
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => {
                                                                setEditingMessageId(null);
                                                                setEditingDraft('');
                                                            }}
                                                        >
                                                            Cancel
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            onClick={() => {
                                                                const next = editingDraft.trim();
                                                                if (next.length === 0 || next === message.message) {
                                                                    setEditingMessageId(null);
                                                                    setEditingDraft('');
                                                                    return;
                                                                }

                                                                onEditMessage(message.id, next);
                                                                setEditingMessageId(null);
                                                                setEditingDraft('');
                                                            }}
                                                        >
                                                            Save
                                                        </Button>
                                                    </div>
                                                </div>
                                            ) : (
                                                message.message || 'Attachment'
                                            )}
                                                </div>
                                            </TooltipTrigger>
                                            <TooltipContent side={isMine ? 'left' : 'right'}>
                                                {new Date(message.created_at).toLocaleString()}
                                                {message.edited_at ? ' • edited' : ''}
                                                {seenBy.length > 0 ? ` • Seen by ${seenBy.join(', ')}` : ''}
                                            </TooltipContent>
                                        </Tooltip>
                                        {!isMine && reactionPicker}
                                        {!isMine && replyButton}

                                        {reactionCounts.length > 0 && (
                                            <div
                                                className={`absolute -bottom-2 z-10 flex max-w-55 flex-wrap items-center gap-1 rounded-full border bg-background/95 px-1 py-0.5 shadow ${isMine ? 'right-2' : 'left-2'}`}
                                            >
                                                {reactionCounts.map((entry) => (
                                                    <button
                                                        key={`${message.id}-rx-${entry.emoji}`}
                                                        type="button"
                                                        onClick={() => onToggleReaction(message.id, entry.emoji)}
                                                        className="flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs text-foreground hover:bg-accent"
                                                    >
                                                        <span>{entry.emoji}</span>
                                                        <span className="text-[10px] text-muted-foreground">{entry.count}</span>
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    {message.attachments && message.attachments.length > 0 && (
                                        <div className="flex flex-wrap gap-2">
                                            {message.attachments.map((attachment) => (
                                                <a
                                                    key={attachment.id}
                                                    href={`/social-space/attachments/${attachment.id}`}
                                                    className="rounded-md border bg-background px-2 py-1 text-xs text-foreground hover:bg-accent"
                                                >
                                                    {attachment.original_name}
                                                </a>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>
                            </div>
                        </Fragment>
                    );
                })
            )}
        </div>
    );
}

interface MessageQuoteProps {
    parent: MessageParent;
    align: 'start' | 'end';
    onJump: (messageId: number) => void;
}

function MessageQuote({ parent, align, onJump }: MessageQuoteProps) {
    const isDeleted = Boolean(parent.deleted_at);
    const authorName = parent.user
        ? `${parent.user.first_name} ${parent.user.last_name}`.trim()
        : 'Unknown';
    const snippet = isDeleted
        ? 'Original message was deleted'
        : parent.message?.trim() || 'Attachment';

    const classes = `mb-[-6px] flex max-w-full flex-col rounded-t-xl border border-b-0 bg-muted/60 px-3 pt-1.5 pb-2 text-xs ${align === 'end' ? 'items-end self-end' : 'items-start self-start'}`;

    if (isDeleted) {
        return (
            <div className={classes}>
                <span className="text-[10px] font-medium text-muted-foreground">
                    Replying to {authorName}
                </span>
                <span className="italic text-muted-foreground">{snippet}</span>
            </div>
        );
    }

    return (
        <button
            type="button"
            onClick={() => onJump(parent.id)}
            className={`${classes} cursor-pointer text-left hover:bg-muted`}
            aria-label={`Jump to message from ${authorName}`}
        >
            <span className="text-[10px] font-medium text-muted-foreground">
                Replying to {authorName}
            </span>
            <span className="line-clamp-2 max-w-[60ch] text-muted-foreground">{snippet}</span>
        </button>
    );
}
