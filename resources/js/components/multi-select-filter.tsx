"use client";

import * as React from "react";
import { Check, ChevronsUpDown, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from "@/components/ui/command";
import { cn } from "@/lib/utils";

export interface MultiSelectOption {
    label: string;
    value: string;
}

interface MultiSelectFilterProps {
    options: MultiSelectOption[];
    value: string[];
    onChange: (value: string[]) => void;
    placeholder?: string;
    emptyMessage?: string;
    className?: string;
    disabled?: boolean;
}

export function MultiSelectFilter({
    options,
    value,
    onChange,
    placeholder = "Select items...",
    emptyMessage = "No items found.",
    className,
    disabled = false,
}: MultiSelectFilterProps) {
    const [open, setOpen] = React.useState(false);
    const [searchQuery, setSearchQuery] = React.useState("");

    // Filter options based on search query
    const filteredOptions = React.useMemo(() => {
        if (!searchQuery) return options;
        return options.filter((opt) =>
            opt.label.toLowerCase().includes(searchQuery.toLowerCase())
        );
    }, [options, searchQuery]);

    // Get selected option labels
    const selectedLabels = React.useMemo(() => {
        return value.map(v => options.find(opt => opt.value === v)?.label || v);
    }, [options, value]);

    const handleSelect = (optionValue: string) => {
        if (value.includes(optionValue)) {
            onChange(value.filter(v => v !== optionValue));
        } else {
            onChange([...value, optionValue]);
        }
    };

    const handleRemove = (optionValue: string, e: React.MouseEvent) => {
        e.stopPropagation();
        onChange(value.filter(v => v !== optionValue));
    };

    const handleClearAll = (e: React.MouseEvent) => {
        e.stopPropagation();
        onChange([]);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled}
                    className={cn(
                        "w-full justify-between font-normal",
                        !value.length && "text-muted-foreground",
                        className
                    )}
                >
                    <span className="flex flex-1 flex-wrap gap-1 truncate">
                        {value.length === 0 ? (
                            placeholder
                        ) : value.length <= 2 ? (
                            selectedLabels.map((label, i) => (
                                <Badge
                                    key={value[i]}
                                    variant="secondary"
                                    className="text-xs px-1.5 py-0 h-5"
                                >
                                    {label}
                                    <span
                                        role="button"
                                        tabIndex={0}
                                        title={`Remove ${label}`}
                                        aria-label={`Remove ${label}`}
                                        className="ml-1 hover:bg-muted rounded-full cursor-pointer"
                                        onClick={(e) => handleRemove(value[i], e)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' || e.key === ' ') {
                                                handleRemove(value[i], e as unknown as React.MouseEvent);
                                            }
                                        }}
                                    >
                                        <X className="h-3 w-3" />
                                    </span>
                                </Badge>
                            ))
                        ) : (
                            <Badge variant="secondary" className="text-xs">
                                {value.length} selected
                                <span
                                    role="button"
                                    tabIndex={0}
                                    title="Clear all"
                                    aria-label="Clear all"
                                    className="ml-1 hover:bg-muted rounded-full cursor-pointer"
                                    onClick={handleClearAll}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' || e.key === ' ') {
                                            handleClearAll(e as unknown as React.MouseEvent);
                                        }
                                    }}
                                >
                                    <X className="h-3 w-3" />
                                </span>
                            </Badge>
                        )}
                    </span>
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-full p-0" align="start">
                <Command shouldFilter={false}>
                    <CommandInput
                        placeholder={`Search...`}
                        value={searchQuery}
                        onValueChange={setSearchQuery}
                    />
                    <CommandList>
                        <CommandEmpty>{emptyMessage}</CommandEmpty>
                        <CommandGroup>
                            {filteredOptions.map((option) => (
                                <CommandItem
                                    key={option.value}
                                    value={option.value}
                                    onSelect={() => handleSelect(option.value)}
                                    className="cursor-pointer"
                                >
                                    <Check
                                        className={cn(
                                            "mr-2 h-4 w-4",
                                            value.includes(option.value) ? "opacity-100" : "opacity-0"
                                        )}
                                    />
                                    {option.label}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

// Utility function to convert URL param string to multi-select array
// Note: Not a React hook - just a pure utility function
export function parseMultiSelectParam(paramValue: string | undefined): string[] {
    if (!paramValue) return [];
    return paramValue.split(",").filter(Boolean);
}

// Alias for backwards compatibility
export const useMultiSelectParam = parseMultiSelectParam;

export function multiSelectToParam(values: string[]): string {
    return values.join(",");
}
