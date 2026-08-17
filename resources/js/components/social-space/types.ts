export interface UserSummary {
    id: number;
    first_name: string;
    last_name: string;
    role?: string;
    avatar_url?: string | null;
    last_read_at?: string | null;
}

export interface Attachment {
    id: number;
    original_name: string;
    mime: string;
    size: number;
}

export interface Reaction {
    id: number;
    emoji: string;
    user_id: number;
}

export interface MessageParent {
    id: number;
    user?: Pick<UserSummary, 'id' | 'first_name' | 'last_name'> | null;
    message: string;
    deleted_at?: string | null;
}

export interface Message {
    id: number;
    message: string;
    created_at: string;
    edited_at?: string | null;
    user?: UserSummary;
    attachments?: Attachment[];
    reactions?: Reaction[];
    parent_id?: number | null;
    parent?: MessageParent | null;
}

export interface Group {
    id: number;
    name: string;
    visibility: 'public' | 'private' | 'campaign';
    members_count: number;
    image_url?: string | null;
    theme_color?: string | null;
    theme_background?: string | null;
    theme_background_image_url?: string | null;
    campaign?: {
        id: number;
        name: string;
    } | null;
    members?: UserSummary[];
}

export interface GroupConversation {
    id: number;
    type: 'group';
    name: string;
    visibility: 'public' | 'private' | 'campaign';
    members_count: number;
    theme_color?: string | null;
    theme_background?: string | null;
    theme_background_image_url?: string | null;
    last_message: string | null;
    last_message_at: string | null;
    unread_count: number;
    is_pinned?: boolean;
}

export interface DirectConversation {
    id: number;
    type: 'direct';
    name?: string | null;
    is_group?: boolean;
    image_url?: string | null;
    theme_color?: string | null;
    theme_background?: string | null;
    theme_background_image_url?: string | null;
    other_user: UserSummary | null;
    participants?: UserSummary[];
    last_message: string | null;
    last_message_at: string | null;
    unread_count: number;
    is_pinned?: boolean;
}

export interface Person {
    id: number;
    first_name: string;
    last_name: string;
    role: string;
    avatar_url?: string | null;
    campaign_id?: number | null;
    campaign_name?: string | null;
    direct_thread_id?: number;
}

export interface DirectThread {
    id: number;
    name?: string | null;
    is_group?: boolean;
    image_url?: string | null;
    theme_color?: string | null;
    theme_background?: string | null;
    theme_background_image_url?: string | null;
    is_pinned?: boolean;
    participants?: UserSummary[];
}

export interface MessageCursor {
    has_more: boolean;
    next_before_id: number | null;
}

export interface DirectReadMarker {
    user_id: number;
    name: string;
    last_read_at: string | null;
}

export type ThemeBackground = 'default' | 'aurora' | 'ocean' | 'sunset' | 'forest' | 'carbon' | 'grid' | 'waves' | 'image';

export const QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '😡'];
