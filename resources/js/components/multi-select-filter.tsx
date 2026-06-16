"use client";

import * as React from "react";
import { ChevronsUpDown } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
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
    description?: string;
}

interface MultiSelectFilterProps {
    options: MultiSelectOption[];
    value: string[];
    onChange: (value: string[]) => void;
    placeholder?: string;
    emptyMessage?: string;
    className?: string;
    disabled?: boolean;
    /** Label shown when a single item is selected; defaults to that option's label */
    singleSelectionLabel?: (label: string) => string;
    /** Label for multiple selections; defaults to "N selected" */
    multipleSelectionLabel?: (count: number) => string;
}

export function MultiSelectFilter({
    options,
    value,
    onChange,
    placeholder = "Select items...",
    emptyMessage = "No items found.",
    className,
    disabled = false,
    singleSelectionLabel,
    multipleSelectionLabel,
}: MultiSelectFilterProps) {
    const [open, setOpen] = React.useState(false);
    const [searchQuery, setSearchQuery] = React.useState("");

    const filteredOptions = React.useMemo(() => {
        if (!searchQuery) return options;
        const q = searchQuery.toLowerCase();
        return options.filter((opt) =>
            opt.label.toLowerCase().includes(q) ||
            (opt.description ?? "").toLowerCase().includes(q)
        );
    }, [options, searchQuery]);

    const handleSelect = (optionValue: string) => {
        if (value.includes(optionValue)) {
            onChange(value.filter(v => v !== optionValue));
        } else {
            onChange([...value, optionValue]);
        }
    };

    const triggerLabel = React.useMemo(() => {
        if (value.length === 0) return placeholder;
        if (value.length === 1) {
            const label = options.find(opt => opt.value === value[0])?.label ?? value[0];
            return singleSelectionLabel ? singleSelectionLabel(label) : label;
        }
        return multipleSelectionLabel
            ? multipleSelectionLabel(value.length)
            : `${value.length} selected`;
    }, [value, options, placeholder, singleSelectionLabel, multipleSelectionLabel]);

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
                    <span className="truncate">{triggerLabel}</span>
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-full min-w-60 p-0" align="start">
                <Command shouldFilter={false}>
                    <CommandInput
                        placeholder="Search..."
                        value={searchQuery}
                        onValueChange={setSearchQuery}
                    />
                    <CommandList>
                        <CommandEmpty>{emptyMessage}</CommandEmpty>
                        <CommandGroup>
                            {filteredOptions.map((option) => {
                                const isSelected = value.includes(option.value);
                                return (
                                    <CommandItem
                                        key={option.value}
                                        value={option.value}
                                        onSelect={() => handleSelect(option.value)}
                                        className="cursor-pointer"
                                    >
                                        <Checkbox
                                            checked={isSelected}
                                            className="mr-2"
                                            onCheckedChange={() => handleSelect(option.value)}
                                            onClick={(e) => e.stopPropagation()}
                                        />
                                        <div className="flex flex-col">
                                            <span>{option.label}</span>
                                            {option.description && (
                                                <span className="text-xs text-muted-foreground">{option.description}</span>
                                            )}
                                        </div>
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
                {value.length > 0 && (
                    <div className="flex items-center justify-between border-t p-2 text-xs">
                        <span className="text-muted-foreground">
                            {value.length} selected
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2"
                            onClick={() => onChange([])}
                        >
                            Clear
                        </Button>
                    </div>
                )}
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
