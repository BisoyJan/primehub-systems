import { useRef, useCallback, useEffect } from 'react';
import {
    Bold,
    Italic,
    Underline,
    Strikethrough,
    List,
    ListOrdered,
    IndentDecrease,
    IndentIncrease,
    Quote,
    RemoveFormatting,
    Undo2,
    Redo2,
    Baseline,
} from 'lucide-react';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

// Gmail-style color palette (10 columns × 8 rows)
const GMAIL_COLORS = [
    ['#000000', '#434343', '#666666', '#999999', '#b7b7b7', '#cccccc', '#d9d9d9', '#efefef', '#f3f3f3', '#ffffff'],
    ['#980000', '#ff0000', '#ff9900', '#ffff00', '#00ff00', '#00ffff', '#4a86e8', '#0000ff', '#9900ff', '#ff00ff'],
    ['#e6b8af', '#f4cccc', '#fce5cd', '#fff2cc', '#d9ead3', '#d0e0e3', '#c9daf8', '#cfe2f3', '#d9d2e9', '#ead1dc'],
    ['#dd7e6b', '#ea9999', '#f9cb9c', '#ffe599', '#b6d7a8', '#a2c4c9', '#a4c2f4', '#9fc5e8', '#b4a7d6', '#d5a6bd'],
    ['#cc4125', '#e06666', '#f6b26b', '#ffd966', '#93c47d', '#76a5af', '#6d9eeb', '#6fa8dc', '#8e7cc3', '#c27ba0'],
    ['#a61c00', '#cc0000', '#e69138', '#f1c232', '#6aa84f', '#45818e', '#3c78d8', '#3d85c6', '#674ea7', '#a64d79'],
    ['#85200c', '#990000', '#b45f06', '#bf9000', '#38761d', '#134f5c', '#1155cc', '#0b5394', '#351c75', '#741b47'],
    ['#5b0f00', '#660000', '#783f04', '#7f6000', '#274e13', '#0c343d', '#1c4587', '#073763', '#20124d', '#4c1130'],
];

// Ordered list style cycle: 1. -> a. -> i. (like MS Word)
const OL_STYLES = ['decimal', 'lower-alpha', 'lower-roman'] as const;

/** Walk up from a node to find the closest ancestor matching a tag name within a boundary */
function closestTag(node: Node | null, tag: string, boundary: HTMLElement): HTMLElement | null {
    let cur = node;
    while (cur && cur !== boundary) {
        if (cur instanceof HTMLElement && cur.tagName === tag.toUpperCase()) return cur;
        cur = cur.parentNode;
    }
    return null;
}

/** Toolbar icon button with tooltip */
function ToolbarButton({
    onClick,
    label,
    shortcut,
    children,
    className,
}: {
    onClick: () => void;
    label: string;
    shortcut?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'inline-flex h-7 w-7 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground',
                        className,
                    )}
                    aria-label={label}
                    onMouseDown={(e) => {
                        e.preventDefault();
                        onClick();
                    }}
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="text-xs">
                {label}{shortcut && <span className="ml-1.5 text-muted-foreground">({shortcut})</span>}
            </TooltipContent>
        </Tooltip>
    );
}

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
        const html = el.innerHTML;
        const isEmpty = !html || html === '<br>' || html === '<div><br></div>';
        onChange(isEmpty ? '' : html);
    }, [onChange]);

    const exec = useCallback((command: string, val?: string) => {
        editorRef.current?.focus();
        document.execCommand(command, false, val);
        emitChange();
    }, [emitChange]);

    // Formatting commands
    const handleUndo = () => exec('undo');
    const handleRedo = () => exec('redo');
    const handleBold = () => exec('bold');
    const handleItalic = () => exec('italic');
    const handleUnderline = () => exec('underline');
    const handleStrikethrough = () => exec('strikethrough');
    const handleBulletList = () => exec('insertUnorderedList');
    const handleNumberedList = () => exec('insertOrderedList');
    const handleIndent = () => {
        exec('indent');
        const editor = editorRef.current;
        if (editor) {
            requestAnimationFrame(() => {
                editor.querySelectorAll('ol').forEach((ol) => applyOlStyle(ol, editor));
                emitChange();
            });
        }
    };
    const handleOutdent = () => {
        exec('outdent');
        const editor = editorRef.current;
        if (editor) {
            requestAnimationFrame(() => {
                editor.querySelectorAll('ol').forEach((ol) => applyOlStyle(ol, editor));
                emitChange();
            });
        }
    };
    const handleBlockquote = () => exec('formatBlock', 'blockquote');
    const handleTextColor = (color: string) => {
        if (!color) {
            exec('removeFormat');
            return;
        }
        exec('foreColor', color);
    };
    const handleHighlight = (color: string) => {
        editorRef.current?.focus();
        if (!color) {
            document.execCommand('removeFormat', false);
        } else {
            document.execCommand('hiliteColor', false, color);
        }
        emitChange();
    };
    const handleRemoveFormatting = () => {
        editorRef.current?.focus();
        document.execCommand('removeFormat', false);
        document.execCommand('formatBlock', false, 'div');
        emitChange();
    };

    /** Apply the correct list-style-type to an <ol> based on its nesting depth */
    const applyOlStyle = useCallback((ol: HTMLElement, boundary: HTMLElement) => {
        let depth = 0;
        let cur: Node | null = ol.parentElement;
        while (cur && cur !== boundary) {
            if (cur instanceof HTMLElement && (cur.tagName === 'OL' || cur.tagName === 'UL')) {
                depth++;
            }
            cur = cur.parentNode;
        }
        const styleType = OL_STYLES[depth % OL_STYLES.length];
        ol.style.listStyleType = styleType;
    }, []);

    const handleKeyDown = useCallback((e: React.KeyboardEvent<HTMLDivElement>) => {
        const editor = editorRef.current;
        if (!editor) return;

        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;

        const anchorNode = sel.anchorNode;
        const li = closestTag(anchorNode, 'LI', editor);

        if (!li) return;

        const parentList = li.parentElement;
        if (!parentList || (parentList.tagName !== 'OL' && parentList.tagName !== 'UL')) return;

        // BACKSPACE on empty list item: exit the list
        if (e.key === 'Backspace') {
            const text = li.textContent || '';
            if (text.length === 0 || (text === '\n' && li.childNodes.length <= 1)) {
                e.preventDefault();

                if (parentList.parentElement && parentList.parentElement !== editor && closestTag(parentList.parentElement, 'LI', editor)) {
                    document.execCommand('outdent', false);
                    editor.querySelectorAll('ol').forEach((ol) => applyOlStyle(ol, editor));
                    emitChange();
                    return;
                }

                if (parentList.tagName === 'OL') {
                    document.execCommand('insertOrderedList', false);
                } else {
                    document.execCommand('insertUnorderedList', false);
                }
                emitChange();
                return;
            }
        }

        // TAB: indent list item
        if (e.key === 'Tab' && !e.shiftKey) {
            e.preventDefault();
            if (!li.previousElementSibling) return;

            document.execCommand('indent', false);

            requestAnimationFrame(() => {
                editor.querySelectorAll('ol').forEach((ol) => applyOlStyle(ol, editor));
                emitChange();
            });
            return;
        }

        // SHIFT+TAB: outdent list item
        if (e.key === 'Tab' && e.shiftKey) {
            e.preventDefault();

            document.execCommand('outdent', false);

            requestAnimationFrame(() => {
                editor.querySelectorAll('ol').forEach((ol) => applyOlStyle(ol, editor));
                emitChange();
            });
            return;
        }
    }, [emitChange, applyOlStyle]);

    const iconSize = 'h-4 w-4';

    return (
        <TooltipProvider delayDuration={300}>
            <div className="rounded-md border border-input bg-background ring-offset-background focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2">
                {/* Editable area (top, like Gmail) */}
                <div
                    ref={editorRef}
                    id={id}
                    contentEditable
                    role="textbox"
                    aria-multiline="true"
                    aria-label={placeholder || 'Rich text editor'}
                    title={placeholder || 'Rich text editor'}
                    className={cn(
                        'px-3 py-2 text-sm outline-none',
                        'bg-white text-black',
                        'empty:before:text-gray-400 empty:before:content-[attr(data-placeholder)]',
                        'overflow-y-auto rounded-t-md',
                        '[&_ul]:list-disc [&_ul]:pl-6 [&_ol]:list-decimal [&_ol]:pl-6 [&_li]:my-0.5',
                        '[&_ol_ol]:list-[lower-alpha] [&_ol_ol_ol]:list-[lower-roman]',
                        '[&_ul_ul]:list-[circle] [&_ul_ul_ul]:list-[square]',
                        '[&_blockquote]:border-l-4 [&_blockquote]:border-gray-300 [&_blockquote]:pl-3 [&_blockquote]:my-1',
                    )}
                    style={{ minHeight }}
                    data-placeholder={placeholder}
                    onInput={emitChange}
                    onBlur={emitChange}
                    onKeyDown={handleKeyDown}
                />

                {/* Bottom toolbar (Gmail-style) */}
                <div className="flex flex-wrap items-center gap-0.5 border-t px-1.5 py-1">
                    {/* Undo / Redo */}
                    <ToolbarButton onClick={handleUndo} label="Undo" shortcut="Ctrl+Z">
                        <Undo2 className={iconSize} />
                    </ToolbarButton>
                    <ToolbarButton onClick={handleRedo} label="Redo" shortcut="Ctrl+Y">
                        <Redo2 className={iconSize} />
                    </ToolbarButton>

                    <div className="mx-0.5 h-5 w-px bg-border" />

                    {/* Bold, Italic, Underline */}
                    <ToolbarButton onClick={handleBold} label="Bold" shortcut="Ctrl+B">
                        <Bold className={iconSize} />
                    </ToolbarButton>
                    <ToolbarButton onClick={handleItalic} label="Italic" shortcut="Ctrl+I">
                        <Italic className={iconSize} />
                    </ToolbarButton>
                    <ToolbarButton onClick={handleUnderline} label="Underline" shortcut="Ctrl+U">
                        <Underline className={iconSize} />
                    </ToolbarButton>

                    {/* Text & Background color (Gmail-style combined) */}
                    <Popover>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <PopoverTrigger asChild>
                                    <button
                                        type="button"
                                        className="inline-flex h-7 w-7 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                                        aria-label="Text color"
                                        onMouseDown={(e) => e.preventDefault()}
                                    >
                                        <Baseline className={iconSize} />
                                    </button>
                                </PopoverTrigger>
                            </TooltipTrigger>
                            <TooltipContent side="top" className="text-xs">Text color</TooltipContent>
                        </Tooltip>
                        <PopoverContent className="w-auto p-3" align="start" side="top">
                            <div className="flex gap-4">
                                {/* Background color column */}
                                <div>
                                    <p className="mb-1.5 text-xs font-medium">Background color</p>
                                    <div className="grid grid-cols-10 gap-px">
                                        {GMAIL_COLORS.flat().map((color) => (
                                            <button
                                                key={`bg-${color}`}
                                                type="button"
                                                title={color}
                                                className="h-4.5 w-4.5 border border-gray-200 transition-transform hover:scale-125 hover:z-10 hover:border-gray-400"
                                                style={{ backgroundColor: color }}
                                                onMouseDown={(e) => { e.preventDefault(); handleHighlight(color); }}
                                            />
                                        ))}
                                    </div>
                                    <button
                                        type="button"
                                        className="mt-1.5 text-xs text-muted-foreground hover:text-foreground"
                                        onMouseDown={(e) => { e.preventDefault(); handleHighlight(''); }}
                                    >
                                        Reset
                                    </button>
                                </div>

                                {/* Text color column */}
                                <div>
                                    <p className="mb-1.5 text-xs font-medium">Text color</p>
                                    <div className="grid grid-cols-10 gap-px">
                                        {GMAIL_COLORS.flat().map((color) => (
                                            <button
                                                key={`text-${color}`}
                                                type="button"
                                                title={color}
                                                className="h-4.5 w-4.5 border border-gray-200 transition-transform hover:scale-125 hover:z-10 hover:border-gray-400"
                                                style={{ backgroundColor: color }}
                                                onMouseDown={(e) => { e.preventDefault(); handleTextColor(color); }}
                                            />
                                        ))}
                                    </div>
                                    <button
                                        type="button"
                                        className="mt-1.5 text-xs text-muted-foreground hover:text-foreground"
                                        onMouseDown={(e) => { e.preventDefault(); handleTextColor(''); }}
                                    >
                                        Reset
                                    </button>
                                </div>
                            </div>
                        </PopoverContent>
                    </Popover>

                    <div className="mx-0.5 h-5 w-px bg-border" />

                    {/* Lists */}
                    <ToolbarButton onClick={handleNumberedList} label="Numbered list">
                        <ListOrdered className={iconSize} />
                    </ToolbarButton>
                    <ToolbarButton onClick={handleBulletList} label="Bulleted list">
                        <List className={iconSize} />
                    </ToolbarButton>

                    <div className="mx-0.5 h-5 w-px bg-border" />

                    {/* Indent */}
                    <ToolbarButton onClick={handleOutdent} label="Decrease indent" shortcut="Shift+Tab">
                        <IndentDecrease className={iconSize} />
                    </ToolbarButton>
                    <ToolbarButton onClick={handleIndent} label="Increase indent" shortcut="Tab">
                        <IndentIncrease className={iconSize} />
                    </ToolbarButton>

                    <div className="mx-0.5 h-5 w-px bg-border" />

                    {/* Blockquote */}
                    <ToolbarButton onClick={handleBlockquote} label="Quote">
                        <Quote className={iconSize} />
                    </ToolbarButton>

                    {/* Strikethrough */}
                    <ToolbarButton onClick={handleStrikethrough} label="Strikethrough">
                        <Strikethrough className={iconSize} />
                    </ToolbarButton>

                    {/* Remove formatting */}
                    <ToolbarButton onClick={handleRemoveFormatting} label="Remove formatting">
                        <RemoveFormatting className={iconSize} />
                    </ToolbarButton>
                </div>
            </div>
        </TooltipProvider>
    );
}
