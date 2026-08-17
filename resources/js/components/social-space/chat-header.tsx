import { Info } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getAvatarColor, getInitials } from './utils';

interface ChatHeaderProps {
    title: string;
    subtitle: string;
    avatarUrl?: string | null;
    isOnline?: boolean;
    onOpenInfo?: () => void;
}

export function ChatHeader({ title, subtitle, avatarUrl, isOnline, onOpenInfo }: ChatHeaderProps) {
    return (
        <header className="flex items-center justify-between gap-3 border-b bg-background/90 px-4 py-3 backdrop-blur">
            <div className="flex items-center gap-3">
                <span className="relative shrink-0">
                    <Avatar className="size-10">
                        <AvatarImage src={avatarUrl ?? undefined} alt={title} />
                        <AvatarFallback className={`text-white ${getAvatarColor(title)}`}>
                            {getInitials(title)}
                        </AvatarFallback>
                    </Avatar>
                    {isOnline && (
                        <span className="absolute right-0 bottom-0 size-2.5 rounded-full border-2 border-background bg-emerald-500" />
                    )}
                </span>
                <div>
                    <h2 className="text-sm font-semibold">{title}</h2>
                    <p className="text-xs text-muted-foreground">{isOnline ? 'Active now' : subtitle}</p>
                </div>
            </div>
            <button
                type="button"
                onClick={onOpenInfo}
                disabled={!onOpenInfo}
                className="rounded-full p-2 text-muted-foreground hover:bg-accent hover:text-foreground"
                aria-label="Conversation info"
            >
                <Info className="h-5 w-5" />
            </button>
        </header>
    );
}
