import { useRef, useCallback, useEffect } from 'react';
import { Bold, Italic, Underline, Highlighter, Type } from 'lucide-react';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

const TEXT_COLORS = [
    { name: 'Red', value: '#ef4444' },
    { name: 'Orange', value: '#f97316' },
    { name: 'Yellow', value: '#eab308' },
    { name: 'Green', value: '#22c55e' },
    { name: 'Blue', value: '#3b82f6' },
    { name: 'Purple', value: '#a855f7' },
    { name: 'Pink', value: '#ec4899' },
    { name: 'Gray', value: '#6b7280' },
] as const;

const HIGHLIGHT_COLORS = [
    { name: 'Yellow', value: '#fef08a' },
    { name: 'Green', value: '#bbf7d0' },
    { name: 'Blue', value: '#bfdbfe' },
    { name: 'Pink', value: '#fbcfe8' },
    { name: 'Orange', value: '#fed7aa' },
    { name: 'Purple', value: '#e9d5ff' },
    { name: 'Red', value: '#fecaca' },
] as const;

interface RichTextareaProps {
    id?: string;
    value: string;
    onChange: (html: string) => void;
    placeholder?: string;
    minHeight?: string;
}

export function RichTextarea({ id, value, onChange, placeholder, minHeight = '120px' }: RichTextareaProps) {
    const editorRef = useRef<HTMLDivElement>(null);
    const isInternalChange = useRef(false);

    // Sync external value changes into the editor
    useEffect(() => {
        if (isInternalChange.current) {
            isInternalChange.current = false;
            return;
        }
        const el = editorRef.current;
        if (el && el.innerHTML !== value) {
            el.innerHTML = value;
        }
    }, [value]);

    const emitChange = useCallback(() => {
        const el = editorRef.current;
        if (!el) return;
        isInternalChange.current = true;
        // If editor only has <br> or is empty, set to empty string
        const html = el.innerHTML;
        const isEmpty = !html || html === '<br>' || html === '<div><br></div>';
        onChange(isEmpty ? '' : html);
    }, [onChange]);

    const exec = useCallback((command: string, value?: string) => {
        editorRef.current?.focus();
        document.execCommand(command, false, value);
        emitChange();
    }, [emitChange]);

    const handleBold = () => exec('bold');
    const handleItalic = () => exec('italic');
    const handleUnderline = () => exec('underline');
    const handleTextColor = (color: string) => exec('foreColor', color);
    const handleHighlight = (color: string) => {
        editorRef.current?.focus();
        document.execCommand('hiliteColor', false, color);
        document.execCommand('foreColor', false, '#1a1a1a');
        emitChange();
    };
    const handleRemoveHighlight = () => {
        editorRef.current?.focus();
        document.execCommand('removeFormat', false);
        emitChange();
    };

    return (
        <div className="rounded-md border border-input bg-background ring-offset-background focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2">
            {/* Toolbar */}
            <div className="flex items-center gap-1 border-b px-2 py-1.5">
                <ToggleGroup type="multiple" className="gap-0.5">
                    <ToggleGroupItem
                        value="bold"
                        aria-label="Bold"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onMouseDown={(e) => { e.preventDefault(); handleBold(); }}
                    >
                        <Bold className="h-3.5 w-3.5" />
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="italic"
                        aria-label="Italic"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onMouseDown={(e) => { e.preventDefault(); handleItalic(); }}
                    >
                        <Italic className="h-3.5 w-3.5" />
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="underline"
                        aria-label="Underline"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onMouseDown={(e) => { e.preventDefault(); handleUnderline(); }}
                    >
                        <Underline className="h-3.5 w-3.5" />
                    </ToggleGroupItem>
                </ToggleGroup>

                {/* Text color picker */}
                <Popover>
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            className="inline-flex h-7 w-7 items-center justify-center rounded-md text-sm font-medium hover:bg-muted hover:text-muted-foreground"
                            aria-label="Text color"
                        >
                            <Type className="h-3.5 w-3.5" />
                        </button>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-2" align="start">
                        <div className="grid grid-cols-4 gap-1.5">
                            {TEXT_COLORS.map((color) => (
                                <button
                                    key={color.value}
                                    type="button"
                                    title={color.name}
                                    className="h-6 w-6 rounded-md border border-input transition-transform hover:scale-110"
                                    style={{ backgroundColor: color.value }}
                                    onMouseDown={(e) => { e.preventDefault(); handleTextColor(color.value); }}
                                />
                            ))}
                        </div>
                        <button
                            type="button"
                            className="mt-1.5 w-full rounded-md px-2 py-1 text-xs text-muted-foreground hover:bg-muted"
                            onMouseDown={(e) => { e.preventDefault(); handleRemoveHighlight(); }}
                        >
                            Reset color
                        </button>
                    </PopoverContent>
                </Popover>

                {/* Highlight color picker */}
                <Popover>
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            className="inline-flex h-7 w-7 items-center justify-center rounded-md text-sm font-medium hover:bg-muted hover:text-muted-foreground"
                            aria-label="Highlight color"
                        >
                            <Highlighter className="h-3.5 w-3.5" />
                        </button>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-2" align="start">
                        <div className="grid grid-cols-3 gap-1.5">
                            {HIGHLIGHT_COLORS.map((color) => (
                                <button
                                    key={color.value}
                                    type="button"
                                    title={color.name}
                                    className="h-6 w-6 rounded-md border border-input transition-transform hover:scale-110"
                                    style={{ backgroundColor: color.value }}
                                    onMouseDown={(e) => { e.preventDefault(); handleHighlight(color.value); }}
                                />
                            ))}
                        </div>
                        <button
                            type="button"
                            className="mt-1.5 w-full rounded-md px-2 py-1 text-xs text-muted-foreground hover:bg-muted"
                            onMouseDown={(e) => { e.preventDefault(); handleRemoveHighlight(); }}
                        >
                            Remove highlight
                        </button>
                    </PopoverContent>
                </Popover>
            </div>

            {/* Editable area */}
            <div
                ref={editorRef}
                id={id}
                contentEditable
                role="textbox"
                aria-multiline="true"
                aria-label={placeholder || 'Rich text editor'}
                title={placeholder || 'Rich text editor'}
                className={cn(
                    'px-3 py-2 text-sm outline-none empty:before:text-muted-foreground empty:before:content-[attr(data-placeholder)]',
                    'overflow-y-auto',
                )}
                style={{ minHeight }}
                data-placeholder={placeholder}
                onInput={emitChange}
                onBlur={emitChange}
            />
        </div>
    );
}
