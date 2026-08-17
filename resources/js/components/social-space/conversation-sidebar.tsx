import { useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft, MoreHorizontal, Pin, PinOff, Search, SquarePen } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { DirectConversation, GroupConversation } from './types';
import { formatRelativeTime, getAvatarColor, getInitials } from './utils';

type ConversationItem =
    | (GroupConversation & { kind: 'group' })
    | (DirectConversation & { kind: 'direct' });

interface ConversationSidebarProps {
    tab: 'all' | 'unread' | 'people' | 'groups';
    searchTerm: string;
    filteredConversations: ConversationItem[];
    activeType: 'group' | 'direct';
    activeGroupId: number | null;
    activeDirectThreadId: number | null;
    onlineUserIds: number[];
    onSearchChange: (value: string) => void;
    onSwitchTab: (value: string) => void;
    onOpenGroup: (groupId: number) => void;
    onOpenDirectThread: (threadId: number) => void;
    onOpenNewMessage: () => void;
    onToggleGroupPin: (groupId: number) => void;
    onToggleDirectPin: (threadId: number) => void;
}

export function ConversationSidebar({
    tab,
    searchTerm,
    filteredConversations,
    activeType,
    activeGroupId,
    activeDirectThreadId,
    onlineUserIds,
    onSearchChange,
    onSwitchTab,
    onOpenGroup,
    onOpenDirectThread,
    onOpenNewMessage,
    onToggleGroupPin,
    onToggleDirectPin,
}: ConversationSidebarProps) {
    const sorted = useMemo(() => {
        return [...filteredConversations].sort((a, b) => {
            return (b.is_pinned ? 1 : 0) - (a.is_pinned ? 1 : 0);
        });
    }, [filteredConversations]);
    return (
        <aside className="flex h-full flex-col bg-background">
            <div className="flex items-center justify-between gap-2 px-4 pt-4 pb-2">
                <div className="flex items-center gap-2">
                    <Link
                        href="/dashboard"
                        className="rounded-full p-1.5 text-muted-foreground hover:bg-accent"
                        aria-label="Back to app"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>
                    <h1 className="text-xl font-semibold tracking-tight">Chats</h1>
                </div>
                <button
                    type="button"
                    onClick={onOpenNewMessage}
                    className="rounded-full p-2 text-muted-foreground hover:bg-accent hover:text-foreground"
                    aria-label="New message"
                >
                    <SquarePen className="h-5 w-5" />
                </button>
            </div>

            <div className="px-4 pb-2">
                <div className="relative">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={searchTerm}
                        onChange={(event) => onSearchChange(event.target.value)}
                        placeholder="Search Messenger"
                        className="rounded-full bg-muted pl-9"
                    />
                </div>
            </div>

            <div className="px-4 pb-2">
                <Tabs value={tab} onValueChange={onSwitchTab}>
                    <TabsList className="grid w-full grid-cols-4 bg-transparent p-0">
                        <TabsTrigger
                            value="all"
                            className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none"
                        >
                            All
                        </TabsTrigger>
                        <TabsTrigger
                            value="unread"
                            className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none"
                        >
                            Unread
                        </TabsTrigger>
                        <TabsTrigger
                            value="groups"
                            className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none"
                        >
                            Groups
                        </TabsTrigger>
                        <TabsTrigger
                            value="people"
                            className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none"
                        >
                            People
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
            </div>

            <div className="flex-1 space-y-0.5 overflow-y-auto px-2 pb-2">
                {sorted.length === 0 ? (
                    <p className="p-6 text-center text-sm text-muted-foreground">No conversations found.</p>
                ) : (
                    sorted.map((conversation) => {
                        const isGroup = conversation.kind === 'group';
                        const isActive =
                            (isGroup && activeType === 'group' && activeGroupId === conversation.id) ||
                            (!isGroup && activeType === 'direct' && activeDirectThreadId === conversation.id);

                        const label =
                            !isGroup && conversation.is_group
                                ? (conversation.name ?? 'Group Chat')
                                : !isGroup && conversation.other_user
                                  ? `${conversation.other_user.first_name} ${conversation.other_user.last_name}`
                                  : (conversation.name ?? 'Conversation');

                        const avatarUrl = isGroup
                            ? null
                            : conversation.is_group
                              ? (conversation.image_url ?? null)
                              : (conversation.other_user?.avatar_url ?? null);

                        const isOnline =
                            !isGroup &&
                            !conversation.is_group &&
                            !!conversation.other_user &&
                            onlineUserIds.includes(conversation.other_user.id);

                        const isPinned = conversation.is_pinned ?? false;

                        const handleTogglePin = (e: React.MouseEvent) => {
                            e.stopPropagation();
                            if (isGroup) {
                                onToggleGroupPin(conversation.id);
                            } else {
                                onToggleDirectPin(conversation.id);
                            }
                        };

                        return (
                            <div
                                key={`${conversation.kind}-${conversation.id}`}
                                className={`group relative flex items-center rounded-lg transition ${
                                    isActive ? 'bg-accent' : 'hover:bg-accent/60'
                                }`}
                            >
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (isGroup) {
                                            onOpenGroup(conversation.id);
                                        } else {
                                            onOpenDirectThread(conversation.id);
                                        }
                                    }}
                                    className="flex min-w-0 flex-1 items-center gap-3 px-2 py-2 text-left"
                                >
                                    <span className="relative shrink-0">
                                        <Avatar className="size-11">
                                            <AvatarImage src={avatarUrl ?? undefined} alt={label} />
                                            <AvatarFallback className={`text-white ${getAvatarColor(label)}`}>
                                                {getInitials(label)}
                                            </AvatarFallback>
                                        </Avatar>
                                        {isOnline && (
                                            <span className="absolute right-0 bottom-0 size-3 rounded-full border-2 border-background bg-emerald-500" />
                                        )}
                                    </span>

                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center justify-between gap-1">
                                            <span className="truncate text-sm font-semibold">{label}</span>
                                            <span className="flex shrink-0 items-center gap-1">
                                                {isPinned && <Pin className="h-3 w-3 text-muted-foreground" />}
                                                <span className="text-xs text-muted-foreground">
                                                    {formatRelativeTime(conversation.last_message_at)}
                                                </span>
                                            </span>
                                        </span>
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="truncate text-xs text-muted-foreground">
                                                {conversation.last_message ?? 'No messages yet'}
                                            </span>
                                            {conversation.unread_count > 0 && (
                                                <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-[10px] font-semibold text-primary-foreground">
                                                    {conversation.unread_count}
                                                </span>
                                            )}
                                        </span>
                                    </span>
                                </button>

                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <button
                                            type="button"
                                            onClick={(e) => e.stopPropagation()}
                                            className="mr-1 hidden shrink-0 rounded-full p-1 text-muted-foreground hover:bg-background/80 hover:text-foreground group-hover:flex"
                                            aria-label="Conversation options"
                                        >
                                            <MoreHorizontal className="h-4 w-4" />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem onClick={handleTogglePin}>
                                            {isPinned ? (
                                                <>
                                                    <PinOff className="mr-2 h-4 w-4" />
                                                    Unpin conversation
                                                </>
                                            ) : (
                                                <>
                                                    <Pin className="mr-2 h-4 w-4" />
                                                    Pin conversation
                                                </>
                                            )}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        );
                    })
                )}
            </div>
        </aside>
    );
}
