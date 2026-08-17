import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { FormEvent, Suspense, lazy, useEffect, useRef, useState } from 'react';
import { Paperclip, Reply, SendHorizontal, Smile, X } from 'lucide-react';
import type { EmojiClickData, Theme as EmojiTheme } from 'emoji-picker-react';

const EmojiPicker = lazy(() => import('emoji-picker-react'));

interface ReplyPreview {
    id: number;
    authorName: string;
    snippet: string;
}

interface MessageComposerProps {
    message: string;
    processing: boolean;
    replyingTo?: ReplyPreview | null;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    onMessageChange: (value: string) => void;
    onAttachmentChange: (files: File[]) => void;
    onCancelReply?: () => void;
}

function isDarkMode(): boolean {
    if (typeof document === 'undefined') {
        return false;
    }
    return document.documentElement.classList.contains('dark');
}

export function MessageComposer({
    message,
    processing,
    replyingTo,
    onSubmit,
    onMessageChange,
    onAttachmentChange,
    onCancelReply,
}: MessageComposerProps) {
    const fileInputRef = useRef<HTMLInputElement | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);
    const caretRef = useRef<number | null>(null);
    const [isEmojiOpen, setIsEmojiOpen] = useState(false);
    const [pickerTheme, setPickerTheme] = useState<'light' | 'dark'>(() =>
        isDarkMode() ? 'dark' : 'light',
    );

    useEffect(() => {
        if (replyingTo) {
            textareaRef.current?.focus();
        }
    }, [replyingTo?.id]);

    useEffect(() => {
        if (!isEmojiOpen) {
            return;
        }
        setPickerTheme(isDarkMode() ? 'dark' : 'light');
    }, [isEmojiOpen]);

    const rememberCaret = () => {
        const el = textareaRef.current;
        if (!el) {
            return;
        }
        caretRef.current = el.selectionStart ?? el.value.length;
    };

    const handleEmojiSelect = (data: EmojiClickData) => {
        const emoji = data.emoji;
        const caret = caretRef.current ?? message.length;
        const before = message.slice(0, caret);
        const after = message.slice(caret);
        const next = `${before}${emoji}${after}`;

        onMessageChange(next);

        const nextCaret = caret + emoji.length;
        caretRef.current = nextCaret;

        requestAnimationFrame(() => {
            const target = textareaRef.current;
            if (!target) {
                return;
            }
            target.focus();
            target.setSelectionRange(nextCaret, nextCaret);
        });
    };

    return (
        <div className="border-t bg-background/90 backdrop-blur">
            {replyingTo && (
                <div className="flex items-start gap-2 border-l-4 border-primary bg-muted/60 px-4 py-2 text-sm">
                    <Reply className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <div className="min-w-0 flex-1">
                        <div className="text-xs font-medium text-primary">Replying to {replyingTo.authorName}</div>
                        <div className="truncate text-xs text-muted-foreground">{replyingTo.snippet}</div>
                    </div>
                    <button
                        type="button"
                        onClick={onCancelReply}
                        aria-label="Cancel reply"
                        className="rounded-full p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            )}

            <form onSubmit={onSubmit} className="flex items-end gap-2 px-4 py-3">
                <input
                    ref={fileInputRef}
                    type="file"
                    multiple
                    className="hidden"
                    onChange={(event) => {
                        onAttachmentChange(Array.from(event.target.files ?? []));
                    }}
                />
                <button
                    type="button"
                    onClick={() => fileInputRef.current?.click()}
                    className="shrink-0 rounded-full p-2 text-muted-foreground hover:bg-accent hover:text-foreground"
                    aria-label="Attach file"
                >
                    <Paperclip className="h-5 w-5" />
                </button>

                <Popover
                    open={isEmojiOpen}
                    onOpenChange={(open) => {
                        if (open) {
                            rememberCaret();
                        }
                        setIsEmojiOpen(open);
                    }}
                >
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            className="shrink-0 rounded-full p-2 text-muted-foreground hover:bg-accent hover:text-foreground"
                            aria-label="Insert emoji"
                        >
                            <Smile className="h-5 w-5" />
                        </button>
                    </PopoverTrigger>
                    <PopoverContent
                        side="top"
                        align="start"
                        sideOffset={8}
                        className="w-auto border-none bg-transparent p-0 shadow-none"
                    >
                        <Suspense
                            fallback={
                                <div className="rounded-lg border bg-background px-4 py-6 text-xs text-muted-foreground shadow-md">
                                    Loading emoji…
                                </div>
                            }
                        >
                            <EmojiPicker
                                onEmojiClick={handleEmojiSelect}
                                theme={pickerTheme as EmojiTheme}
                                lazyLoadEmojis
                                width={320}
                                height={380}
                                previewConfig={{ showPreview: false }}
                                skinTonesDisabled
                            />
                        </Suspense>
                    </PopoverContent>
                </Popover>

                <Textarea
                    ref={textareaRef}
                    value={message}
                    onChange={(event) => onMessageChange(event.target.value)}
                    onSelect={rememberCaret}
                    onKeyUp={rememberCaret}
                    onClick={rememberCaret}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape' && replyingTo && onCancelReply) {
                            event.preventDefault();
                            onCancelReply();
                            return;
                        }

                        if (event.key !== 'Enter' || event.shiftKey || event.nativeEvent.isComposing) {
                            return;
                        }

                        event.preventDefault();
                        event.currentTarget.form?.requestSubmit();
                    }}
                    placeholder="Aa"
                    rows={1}
                    className="max-h-32 min-h-0 flex-1 resize-none rounded-3xl bg-muted py-2"
                />

                <Button
                    type="submit"
                    size="icon"
                    disabled={processing}
                    className="shrink-0 rounded-full"
                    aria-label="Send message"
                >
                    <SendHorizontal className="h-4 w-4" />
                </Button>
            </form>
        </div>
    );
}
