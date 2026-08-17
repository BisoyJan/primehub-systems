import { CSSProperties, FormEvent, useEffect, useMemo, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { Page, PageProps } from '@inertiajs/core';
import { useFlashMessage } from '@/hooks';
import { getEcho } from '@/echo';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { ConversationSidebar } from '@/components/social-space/conversation-sidebar';
import { ChatHeader } from '@/components/social-space/chat-header';
import { ChatMessages } from '@/components/social-space/chat-messages';
import { ConversationInfoPanel } from '@/components/social-space/conversation-info-panel';
import { MessageComposer } from '@/components/social-space/message-composer';
import { NewMessageDialog } from '@/components/social-space/new-message-dialog';
import {
    DirectConversation,
    DirectReadMarker,
    DirectThread,
    Group,
    GroupConversation,
    Message,
    MessageCursor,
    Person,
    ThemeBackground,
} from '@/components/social-space/types';

interface Props extends PageProps {
    groupConversations: GroupConversation[];
    directConversations: DirectConversation[];
    people: Person[];
    selectedGroup: Group | null;
    messages: Message[];
    selectedDirectThread: DirectThread | null;
    directMessages: Message[];
    canCreateGroup: boolean;
    currentUserId: number;
    tab: 'all' | 'unread' | 'people' | 'groups';
    groupMessageCursor: MessageCursor;
    directMessageCursor: MessageCursor;
    directReadMarkers: DirectReadMarker[];
    unreadCounts: {
        groups: number;
        people: number;
    };
}

const themeBackgroundClass: Record<ThemeBackground, string> = {
    default: 'bg-background',
    aurora: 'bg-[radial-gradient(120%_120%_at_0%_0%,rgba(16,185,129,0.18),transparent_45%),radial-gradient(120%_120%_at_100%_0%,rgba(59,130,246,0.18),transparent_48%),var(--background)]',
    ocean: 'bg-[radial-gradient(140%_100%_at_0%_0%,rgba(14,116,144,0.2),transparent_45%),radial-gradient(120%_120%_at_100%_100%,rgba(2,132,199,0.18),transparent_48%),var(--background)]',
    sunset: 'bg-[radial-gradient(120%_120%_at_0%_100%,rgba(249,115,22,0.2),transparent_44%),radial-gradient(120%_120%_at_100%_0%,rgba(244,63,94,0.18),transparent_50%),var(--background)]',
    forest: 'bg-[radial-gradient(120%_120%_at_0%_0%,rgba(34,197,94,0.18),transparent_45%),radial-gradient(120%_120%_at_100%_100%,rgba(74,222,128,0.14),transparent_50%),var(--background)]',
    carbon: 'bg-[radial-gradient(120%_120%_at_0%_0%,rgba(71,85,105,0.22),transparent_45%),radial-gradient(120%_120%_at_100%_100%,rgba(51,65,85,0.2),transparent_48%),var(--background)]',
    grid: "bg-[linear-gradient(rgba(148,163,184,0.12)_1px,transparent_1px),linear-gradient(90deg,rgba(148,163,184,0.12)_1px,transparent_1px),url('/images/chat-bg-grid.svg')] bg-[size:28px_28px,28px_28px,cover] bg-center",
    waves: "bg-[url('/images/chat-bg-waves.svg')] bg-cover bg-center",
    image: 'bg-cover bg-center bg-no-repeat',
};

function mergeMessages(existing: Message[], incoming: Message[]): Message[] {
    const byId = new Map<number, Message>();

    for (const message of existing) {
        byId.set(message.id, message);
    }

    for (const message of incoming) {
        byId.set(message.id, message);
    }

    return Array.from(byId.values()).sort((a, b) => a.id - b.id);
}

export default function SocialSpaceIndex() {
    const {
        groupConversations,
        directConversations,
        people,
        selectedGroup,
        messages,
        selectedDirectThread,
        directMessages,
        canCreateGroup,
        currentUserId,
        tab,
        groupMessageCursor,
        directMessageCursor,
        directReadMarkers: directReadMarkersFromProps,
    } = usePage<Props>().props;

    useFlashMessage();

    const [isNewMessageOpen, setIsNewMessageOpen] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [typingUsers, setTypingUsers] = useState<Record<number, string>>({});
    const [onlineUserIds, setOnlineUserIds] = useState<number[]>([]);
    const [isLoadingOlder, setIsLoadingOlder] = useState(false);
    const [isInfoPanelOpen, setIsInfoPanelOpen] = useState(false);
    const [replyingTo, setReplyingTo] = useState<{ id: number; authorName: string; snippet: string } | null>(null);

    const activeType: 'group' | 'direct' = selectedDirectThread ? 'direct' : 'group';
    const activeGroupId = selectedGroup?.id ?? null;
    const activeDirectThreadId = selectedDirectThread?.id ?? null;

    const [visibleMessages, setVisibleMessages] = useState<Message[]>(
        activeType === 'direct' ? directMessages : messages,
    );
    const [activeGroupCursor, setActiveGroupCursor] = useState<MessageCursor>(groupMessageCursor);
    const [activeDirectCursor, setActiveDirectCursor] = useState<MessageCursor>(directMessageCursor);
    const [directReadMarkers, setDirectReadMarkers] = useState<DirectReadMarker[]>(directReadMarkersFromProps);

    const themeForm = useForm<{
        theme_color: string;
        theme_background: ThemeBackground;
        background_image: File | null;
    }>({
        theme_color: '#2563EB',
        theme_background: 'default' as ThemeBackground,
        background_image: null,
    });

    const groupNameForm = useForm({ name: '' });
    const groupImageForm = useForm({ image: null as File | null });

    const directGroupForm = useForm({
        name: '',
        participant_ids: [] as number[],
    });

    const groupMessageForm = useForm({
        message: '',
        attachments: [] as File[],
        parent_id: null as number | null,
    });

    const directMessageForm = useForm({
        message: '',
        attachments: [] as File[],
        parent_id: null as number | null,
    });

    const filteredConversations = useMemo(() => {
        const all = [
            ...groupConversations.map((conversation) => ({ ...conversation, kind: 'group' as const })),
            ...directConversations.map((conversation) => ({ ...conversation, kind: 'direct' as const })),
        ];

        const byTab =
            tab === 'groups'
                ? all.filter((conversation) => conversation.kind === 'group')
                : tab === 'people'
                  ? all.filter((conversation) => conversation.kind === 'direct')
                  : tab === 'unread'
                    ? all.filter((conversation) => conversation.unread_count > 0)
                    : all;

        const term = searchTerm.trim().toLowerCase();
        if (!term) {
            return byTab;
        }

        return byTab.filter((conversation) => {
            const label =
                conversation.kind === 'direct' && conversation.is_group
                    ? (conversation.name ?? 'Group Chat')
                    : conversation.kind === 'direct' && conversation.other_user
                      ? `${conversation.other_user.first_name} ${conversation.other_user.last_name}`
                      : (conversation.name ?? 'Conversation');

            return (
                label.toLowerCase().includes(term) ||
                (conversation.last_message ?? '').toLowerCase().includes(term)
            );
        });
    }, [tab, searchTerm, groupConversations, directConversations]);

    const activeConversationTitle = useMemo(() => {
        if (activeType === 'group' && selectedGroup) {
            return selectedGroup.name;
        }

        if (activeDirectThreadId) {
            const thread = directConversations.find((conversation) => conversation.id === activeDirectThreadId);
            if (!thread) {
                return 'Conversation';
            }

            if (thread.is_group) {
                return thread.name ?? 'Group Chat';
            }

            if (thread.other_user) {
                return `${thread.other_user.first_name} ${thread.other_user.last_name}`;
            }
        }

        return 'Conversation';
    }, [activeType, selectedGroup, activeDirectThreadId, directConversations]);

    const activeConversationSubtitle = useMemo(() => {
        if (activeType === 'group' && selectedGroup) {
            return `${selectedGroup.members_count} members`;
        }

        if (activeDirectThreadId) {
            const thread = directConversations.find((conversation) => conversation.id === activeDirectThreadId);
            if (thread?.is_group) {
                return `${thread.participants?.length ?? 0} members`;
            }

            return thread?.other_user?.role ?? 'Direct chat';
        }

        return 'Pick a conversation to start chatting';
    }, [activeType, selectedGroup, activeDirectThreadId, directConversations]);

    const activeOtherUser = useMemo(() => {
        if (activeType !== 'direct' || !activeDirectThreadId) {
            return null;
        }

        const thread = directConversations.find((conversation) => conversation.id === activeDirectThreadId);
        return thread?.is_group ? null : (thread?.other_user ?? null);
    }, [activeType, activeDirectThreadId, directConversations]);

    const activeParticipantNames = useMemo(() => {
        if (activeType === 'group') {
            return (selectedGroup?.members ?? []).map(
                (member) => `${member.first_name} ${member.last_name}`,
            );
        }

        if (!activeDirectThreadId) {
            return [];
        }

        const thread = directConversations.find((conversation) => conversation.id === activeDirectThreadId);
        if (!thread?.is_group) {
            return [];
        }

        return (thread.participants ?? []).map(
            (participant) => `${participant.first_name} ${participant.last_name}`,
        );
    }, [activeType, selectedGroup, activeDirectThreadId, directConversations]);

    const typingNames = useMemo(() => {
        return Object.values(typingUsers).join(', ');
    }, [typingUsers]);

    const activeConversationTheme = useMemo(() => {
        if (activeType === 'group' && selectedGroup) {
            return {
                theme_color: selectedGroup.theme_color ?? '#2563EB',
                theme_background: (selectedGroup.theme_background ?? 'default') as ThemeBackground,
                theme_background_image_url: selectedGroup.theme_background_image_url ?? null,
            };
        }

        if (activeDirectThreadId) {
            const threadFromList = directConversations.find((conversation) => conversation.id === activeDirectThreadId);

            return {
                theme_color: selectedDirectThread?.theme_color ?? threadFromList?.theme_color ?? '#2563EB',
                theme_background: ((selectedDirectThread?.theme_background ?? threadFromList?.theme_background ??
                    'default') as ThemeBackground),
                theme_background_image_url:
                    selectedDirectThread?.theme_background_image_url ?? threadFromList?.theme_background_image_url ?? null,
            };
        }

        return {
            theme_color: '#2563EB',
            theme_background: 'default' as ThemeBackground,
            theme_background_image_url: null as string | null,
        };
    }, [activeType, selectedGroup, selectedDirectThread, activeDirectThreadId, directConversations]);

    const activeThemeBackground = activeConversationTheme.theme_background;
    const activeThemeColor = activeConversationTheme.theme_color;
    const activeThemeBackgroundImageUrl = activeConversationTheme.theme_background_image_url;

    const activeConversationStyle: CSSProperties | undefined =
        activeGroupId || activeDirectThreadId
            ? {
                  borderTop: `2px solid ${activeThemeColor}`,
                  ...(activeThemeBackground === 'image' && activeThemeBackgroundImageUrl
                      ? {
                            backgroundImage: `url(${activeThemeBackgroundImageUrl})`,
                            backgroundSize: 'cover',
                            backgroundPosition: 'center',
                            backgroundRepeat: 'no-repeat',
                        }
                      : {}),
              }
            : undefined;

    useEffect(() => {
        const next = activeType === 'direct' ? directMessages : messages;
        setVisibleMessages(next);
        setActiveGroupCursor(groupMessageCursor);
        setActiveDirectCursor(directMessageCursor);
        setDirectReadMarkers(directReadMarkersFromProps);
        setReplyingTo(null);
    }, [
        activeType,
        activeGroupId,
        activeDirectThreadId,
        groupMessageCursor,
        directMessageCursor,
        directReadMarkersFromProps,
    ]);

    useEffect(() => {
        themeForm.setData({
            theme_color: activeConversationTheme.theme_color,
            theme_background: activeConversationTheme.theme_background,
        });
    }, [activeConversationTheme]);

    useEffect(() => {
        const next = activeType === 'direct' ? directMessages : messages;
        setVisibleMessages((previous) => mergeMessages(previous, next));
        if (activeType === 'direct') {
            setDirectReadMarkers(directReadMarkersFromProps);
        }
    }, [messages, directMessages, directReadMarkersFromProps, activeType]);

    const switchTab = (value: string) => {
        router.visit('/social-space', {
            method: 'get',
            data: { tab: value },
            only: [
                'tab',
                'groupConversations',
                'directConversations',
                'selectedGroup',
                'selectedDirectThread',
                'messages',
                'directMessages',
                'groupMessageCursor',
                'directMessageCursor',
                'directReadMarkers',
                'unreadCounts',
            ],
            replace: true,
            preserveScroll: true,
            preserveState: true,
        });
    };

    const openGroup = (groupId: number) => {
        router.visit('/social-space', {
            method: 'get',
            data: {
                tab,
                group_id: groupId,
                thread_id: null,
            },
            only: [
                'selectedGroup',
                'messages',
                'groupMessageCursor',
                'selectedDirectThread',
                'directMessages',
                'directMessageCursor',
                'directReadMarkers',
                'groupConversations',
                'directConversations',
                'unreadCounts',
                'tab',
            ],
            replace: true,
            preserveScroll: true,
            preserveState: true,
        });
    };

    const openDirectThread = (threadId: number) => {
        router.visit('/social-space', {
            method: 'get',
            data: {
                tab: 'people',
                thread_id: threadId,
                group_id: null,
            },
            only: [
                'selectedGroup',
                'messages',
                'groupMessageCursor',
                'selectedDirectThread',
                'directMessages',
                'directMessageCursor',
                'directReadMarkers',
                'groupConversations',
                'directConversations',
                'unreadCounts',
                'tab',
            ],
            replace: true,
            preserveScroll: true,
            preserveState: true,
        });
    };

    const openPersonChat = (person: Person) => {
        if (person.direct_thread_id) {
            openDirectThread(person.direct_thread_id);
            return;
        }

        router.post(`/social-space/people/${person.id}/start`, {}, { preserveScroll: true });
    };

    const handleSendGroupMessage = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!activeGroupId) {
            return;
        }

        groupMessageForm.setData('parent_id', replyingTo?.id ?? null);

        groupMessageForm.post(`/social-space/groups/${activeGroupId}/messages`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                groupMessageForm.reset('message', 'attachments', 'parent_id');
                setReplyingTo(null);
            },
        });
    };

    const handleSendDirectMessage = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!activeDirectThreadId) {
            return;
        }

        directMessageForm.setData('parent_id', replyingTo?.id ?? null);

        directMessageForm.post(`/social-space/threads/${activeDirectThreadId}/messages`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                directMessageForm.reset('message', 'attachments', 'parent_id');
                setReplyingTo(null);
            },
        });
    };

    const handleCreateDmGroup = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        directGroupForm.post('/social-space/threads/group', {
            preserveScroll: true,
            onSuccess: () => {
                directGroupForm.reset();
                setIsNewMessageOpen(false);
            },
        });
    };

    const sendTypingWhisper = () => {
        if (!activeDirectThreadId) {
            return;
        }

        const echo = getEcho();
        if (!echo) {
            return;
        }

        echo.private(`social.direct-thread.${activeDirectThreadId}`).whisper('typing', {
            user_id: currentUserId,
            name: 'Someone',
        });
    };

    const handleToggleReaction = async (messageId: number, emoji: string): Promise<void> => {
        const endpoint =
            activeType === 'group' && activeGroupId
                ? `/social-space/groups/${activeGroupId}/messages/${messageId}/reactions`
                : activeType === 'direct' && activeDirectThreadId
                  ? `/social-space/threads/${activeDirectThreadId}/messages/${messageId}/reactions`
                  : null;

        if (!endpoint) {
            return;
        }

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ emoji }),
            });

            if (!response.ok) {
                return;
            }

            router.visit(`${window.location.pathname}${window.location.search}`, {
                method: 'get',
                only:
                    activeType === 'group'
                        ? ['messages', 'groupMessageCursor', 'groupConversations', 'unreadCounts']
                        : ['directMessages', 'directMessageCursor', 'directReadMarkers', 'directConversations', 'unreadCounts'],
                preserveScroll: true,
                preserveState: true,
            });
        } catch (error) {
            console.error('Failed to toggle reaction:', error);
        }
    };

    const handleEditMessage = (messageId: number, nextMessage: string) => {
        if (activeType === 'group' && activeGroupId) {
            router.patch(
                `/social-space/groups/${activeGroupId}/messages/${messageId}`,
                { message: nextMessage },
                { preserveScroll: true, preserveState: true },
            );
            return;
        }

        if (activeType === 'direct' && activeDirectThreadId) {
            router.patch(
                `/social-space/threads/${activeDirectThreadId}/messages/${messageId}`,
                { message: nextMessage },
                { preserveScroll: true, preserveState: true },
            );
        }
    };

    const handleDeleteMessage = (messageId: number) => {
        if (activeType === 'group' && activeGroupId) {
            router.delete(`/social-space/groups/${activeGroupId}/messages/${messageId}`, {
                preserveScroll: true,
                preserveState: true,
            });
            return;
        }

        if (activeType === 'direct' && activeDirectThreadId) {
            router.delete(`/social-space/threads/${activeDirectThreadId}/messages/${messageId}`, {
                preserveScroll: true,
                preserveState: true,
            });
        }
    };

    const loadOlderMessages = () => {
        if (isLoadingOlder) {
            return;
        }

        if (activeType === 'group' && activeGroupId) {
            const beforeId = activeGroupCursor.next_before_id ?? visibleMessages[0]?.id ?? null;
            if (!beforeId || !activeGroupCursor.has_more) {
                return;
            }

            router.visit('/social-space', {
                method: 'get',
                data: {
                    tab,
                    group_id: activeGroupId,
                    thread_id: null,
                    group_before_id: beforeId,
                },
                only: ['messages', 'groupMessageCursor'],
                preserveScroll: true,
                preserveState: true,
                onStart: () => setIsLoadingOlder(true),
                onFinish: () => setIsLoadingOlder(false),
                onSuccess: (page: Page) => {
                    const pageProps = page.props as unknown as Partial<Props>;
                    setVisibleMessages((previous) => mergeMessages(previous, pageProps.messages ?? []));
                    if (pageProps.groupMessageCursor) {
                        setActiveGroupCursor(pageProps.groupMessageCursor);
                    }
                },
            });

            return;
        }

        if (activeType === 'direct' && activeDirectThreadId) {
            const beforeId = activeDirectCursor.next_before_id ?? visibleMessages[0]?.id ?? null;
            if (!beforeId || !activeDirectCursor.has_more) {
                return;
            }

            router.visit('/social-space', {
                method: 'get',
                data: {
                    tab,
                    group_id: null,
                    thread_id: activeDirectThreadId,
                    thread_before_id: beforeId,
                },
                only: ['directMessages', 'directMessageCursor', 'directReadMarkers'],
                preserveScroll: true,
                preserveState: true,
                onStart: () => setIsLoadingOlder(true),
                onFinish: () => setIsLoadingOlder(false),
                onSuccess: (page: Page) => {
                    const pageProps = page.props as unknown as Partial<Props>;
                    setVisibleMessages((previous) => mergeMessages(previous, pageProps.directMessages ?? []));
                    if (pageProps.directMessageCursor) {
                        setActiveDirectCursor(pageProps.directMessageCursor);
                    }
                    if (pageProps.directReadMarkers) {
                        setDirectReadMarkers(pageProps.directReadMarkers);
                    }
                },
            });
        }
    };

    const markDirectThreadAsRead = async (threadId: number): Promise<void> => {
        try {
            await fetch(`/social-space/threads/${threadId}/read`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
        } catch (error) {
            console.error('Failed to mark thread as read:', error);
        }
    };

    useEffect(() => {
        if (!activeDirectThreadId) {
            return;
        }

        void markDirectThreadAsRead(activeDirectThreadId);
    }, [activeDirectThreadId]);

    const saveConversationTheme = () => {
        if (activeType === 'group' && activeGroupId) {
            themeForm.patch(`/social-space/groups/${activeGroupId}/theme`, {
                preserveScroll: true,
                preserveState: true,
                forceFormData: true,
            });

            return;
        }

        if (activeType === 'direct' && activeDirectThreadId) {
            themeForm.patch(`/social-space/threads/${activeDirectThreadId}/theme`, {
                preserveScroll: true,
                preserveState: true,
                forceFormData: true,
            });
        }
    };

    const handleToggleGroupPin = (groupId: number) => {
        router.post(
            `/social-space/groups/${groupId}/pin`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['groupConversations', 'directConversations'],
            },
        );
    };

    const handleToggleDirectPin = (threadId: number) => {
        router.post(
            `/social-space/threads/${threadId}/pin`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['groupConversations', 'directConversations'],
            },
        );
    };

    const handleSaveGroupName = (name: string) => {
        if (!activeDirectThreadId) {
            return;
        }
        groupNameForm.setData('name', name);
        groupNameForm.patch(`/social-space/threads/${activeDirectThreadId}/name`, {
            preserveScroll: true,
            preserveState: true,
            only: ['directConversations', 'selectedDirectThread'],
        });
    };

    const handleSaveGroupImage = (file: File) => {
        if (!activeDirectThreadId) {
            return;
        }
        groupImageForm.setData('image', file);
        groupImageForm.post(`/social-space/threads/${activeDirectThreadId}/image`, {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            only: ['directConversations', 'selectedDirectThread'],
        });
    };

    useEffect(() => {
        const echo = getEcho();

        if (!echo) {
            return;
        }

        const presence = echo.join('social.presence');
        presence.here((users: Array<{ id: number }>) => {
            setOnlineUserIds(users.map((user) => user.id));
        });
        presence.joining((user: { id: number }) => {
            setOnlineUserIds((prev) => (prev.includes(user.id) ? prev : [...prev, user.id]));
        });
        presence.leaving((user: { id: number }) => {
            setOnlineUserIds((prev) => prev.filter((id) => id !== user.id));
        });

        return () => {
            echo.leave('social.presence');
        };
    }, []);

    useEffect(() => {
        const echo = getEcho();

        if (!echo) {
            return;
        }

        if (activeGroupId) {
            const channelName = `social.group.${activeGroupId}`;
            const channel = echo.private(channelName);
            const refetchGroup = () => {
                router.visit('/social-space', {
                    method: 'get',
                    data: { tab, group_id: activeGroupId, thread_id: null },
                    only: ['messages', 'groupConversations', 'groupMessageCursor', 'unreadCounts'],
                    preserveScroll: true,
                    preserveState: true,
                });
            };
            channel.listen('.social.group.message.created', refetchGroup);
            channel.listen('.social.group.message.updated', refetchGroup);

            return () => {
                echo.leave(channelName);
            };
        }

        if (activeDirectThreadId) {
            const channelName = `social.direct-thread.${activeDirectThreadId}`;
            const channel = echo.private(channelName);

            const refetchDirect = () => {
                router.visit('/social-space', {
                    method: 'get',
                    data: { tab, thread_id: activeDirectThreadId, group_id: null },
                    only: ['directMessages', 'directConversations', 'directMessageCursor', 'directReadMarkers', 'unreadCounts'],
                    preserveScroll: true,
                    preserveState: true,
                });
            };

            channel.listen('.social.direct.message.created', refetchDirect);
            channel.listen('.social.direct.message.updated', refetchDirect);

            channel.listenForWhisper('typing', (payload: { user_id: number; name: string }) => {
                if (payload.user_id === currentUserId) {
                    return;
                }

                setTypingUsers((prev) => ({ ...prev, [payload.user_id]: payload.name }));
                window.setTimeout(() => {
                    setTypingUsers((prev) => {
                        const next = { ...prev };
                        delete next[payload.user_id];
                        return next;
                    });
                }, 1600);
            });

            return () => {
                echo.leave(channelName);
            };
        }
    }, [activeGroupId, activeDirectThreadId, currentUserId, tab]);

    return (
        <>
            <Head title="Social Space" />

            <div className="fixed inset-0 flex bg-background">
                <div className="flex h-full w-90 shrink-0 flex-col border-r">
                    <ConversationSidebar
                        tab={tab}
                        searchTerm={searchTerm}
                        filteredConversations={filteredConversations}
                        activeType={activeType}
                        activeGroupId={activeGroupId}
                        activeDirectThreadId={activeDirectThreadId}
                        onlineUserIds={onlineUserIds}
                        onSearchChange={setSearchTerm}
                        onSwitchTab={switchTab}
                        onOpenGroup={openGroup}
                        onOpenDirectThread={openDirectThread}
                        onOpenNewMessage={() => setIsNewMessageOpen(true)}
                        onToggleGroupPin={handleToggleGroupPin}
                        onToggleDirectPin={handleToggleDirectPin}
                    />
                </div>

                <section
                    className={cn('flex h-full flex-1 flex-col transition-colors', themeBackgroundClass[activeThemeBackground])}
                    style={activeConversationStyle}
                >
                    <ChatHeader
                        title={activeConversationTitle}
                        subtitle={activeConversationSubtitle}
                        avatarUrl={
                            activeType === 'direct'
                                ? (selectedDirectThread?.image_url ??
                                   directConversations.find((c) => c.id === activeDirectThreadId)?.image_url ??
                                   activeOtherUser?.avatar_url)
                                : (selectedGroup?.image_url ?? null)
                        }
                        isOnline={!!activeOtherUser && onlineUserIds.includes(activeOtherUser.id)}
                        onOpenInfo={activeGroupId || activeDirectThreadId ? () => setIsInfoPanelOpen(true) : undefined}
                    />

                    {activeGroupId === null && activeDirectThreadId === null ? (
                        <div className="flex flex-1 items-center justify-center px-4">
                            <Card className="border-dashed p-8 text-center text-sm text-muted-foreground">
                                Pick a conversation from the left to begin chatting.
                            </Card>
                        </div>
                    ) : (
                        <>
                            <ChatMessages
                                activeType={activeType}
                                currentUserId={currentUserId}
                                messages={visibleMessages}
                                directReadMarkers={directReadMarkers}
                                hasMore={
                                    activeType === 'group'
                                        ? activeGroupCursor.has_more
                                        : activeDirectCursor.has_more
                                }
                                loadingOlder={isLoadingOlder}
                                onLoadOlder={loadOlderMessages}
                                onToggleReaction={handleToggleReaction}
                                onEditMessage={handleEditMessage}
                                onDeleteMessage={handleDeleteMessage}
                                onReplyMessage={(target) => {
                                    const authorName = target.user
                                        ? `${target.user.first_name} ${target.user.last_name}`.trim()
                                        : 'Unknown';
                                    const snippet = target.message?.trim() || 'Attachment';
                                    setReplyingTo({ id: target.id, authorName, snippet });
                                }}
                            />

                            {typingNames && (
                                <div className="px-4 pb-2 text-xs italic text-muted-foreground">{typingNames} typing...</div>
                            )}

                            <MessageComposer
                                onSubmit={activeType === 'group' ? handleSendGroupMessage : handleSendDirectMessage}
                                message={
                                    activeType === 'group'
                                        ? groupMessageForm.data.message
                                        : directMessageForm.data.message
                                }
                                processing={
                                    activeType === 'group'
                                        ? groupMessageForm.processing
                                        : directMessageForm.processing
                                }
                                replyingTo={replyingTo}
                                onCancelReply={() => setReplyingTo(null)}
                                onMessageChange={(value) => {
                                    if (activeType === 'group') {
                                        groupMessageForm.setData('message', value);
                                    } else {
                                        directMessageForm.setData('message', value);
                                        sendTypingWhisper();
                                    }
                                }}
                                onAttachmentChange={(files) => {
                                    if (activeType === 'group') {
                                        groupMessageForm.setData('attachments', files);
                                    } else {
                                        directMessageForm.setData('attachments', files);
                                    }
                                }}
                            />
                        </>
                    )}
                </section>
            </div>

            <NewMessageDialog
                open={isNewMessageOpen}
                people={people}
                processing={directGroupForm.processing}
                groupName={directGroupForm.data.name}
                selectedParticipantIds={directGroupForm.data.participant_ids}
                canCreateGroup={canCreateGroup}
                onOpenChange={setIsNewMessageOpen}
                onSubmitGroup={handleCreateDmGroup}
                onGroupNameChange={(name) => directGroupForm.setData('name', name)}
                onToggleParticipant={(personId, checked) => {
                    if (checked) {
                        directGroupForm.setData('participant_ids', [...directGroupForm.data.participant_ids, personId]);
                        return;
                    }

                    directGroupForm.setData(
                        'participant_ids',
                        directGroupForm.data.participant_ids.filter((id) => id !== personId),
                    );
                }}
                onStartDirectChat={(person) => {
                    setIsNewMessageOpen(false);
                    openPersonChat(person);
                }}
            />

            <ConversationInfoPanel
                open={isInfoPanelOpen}
                onOpenChange={setIsInfoPanelOpen}
                title={activeConversationTitle}
                subtitle={activeConversationSubtitle}
                conversationKind={activeType}
                isDirectGroupChat={
                    activeType === 'direct' &&
                    !!(
                        selectedDirectThread?.is_group ??
                        directConversations.find((c) => c.id === activeDirectThreadId)?.is_group
                    )
                }
                currentGroupChatName={
                    selectedDirectThread?.name ??
                    directConversations.find((c) => c.id === activeDirectThreadId)?.name
                }
                groupChatImageUrl={
                    selectedDirectThread?.image_url ??
                    directConversations.find((c) => c.id === activeDirectThreadId)?.image_url
                }
                campaignName={selectedGroup?.campaign?.name ?? null}
                participantCount={
                    activeType === 'group'
                        ? selectedGroup?.members_count
                        : (selectedDirectThread?.participants?.length ??
                          directConversations.find((conversation) => conversation.id === activeDirectThreadId)?.participants
                              ?.length)
                }
                participantNames={activeParticipantNames}
                themeColor={themeForm.data.theme_color}
                themeBackground={themeForm.data.theme_background}
                themeBackgroundImageUrl={
                    activeType === 'group'
                        ? (selectedGroup?.theme_background_image_url ?? null)
                        : (selectedDirectThread?.theme_background_image_url ?? null)
                }
                processing={themeForm.processing}
                groupInfoProcessing={groupNameForm.processing || groupImageForm.processing}
                onThemeColorChange={(value) => themeForm.setData('theme_color', value)}
                onThemeBackgroundChange={(value) => themeForm.setData('theme_background', value)}
                onThemeImageChange={(file) => {
                    themeForm.setData('background_image', file);
                    if (file) {
                        themeForm.setData('theme_background', 'image');
                    }
                }}
                onSave={saveConversationTheme}
                onGroupNameSave={handleSaveGroupName}
                onGroupImageSave={handleSaveGroupImage}
            />
        </>
    );
}
