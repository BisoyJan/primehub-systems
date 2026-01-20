"use client"

import * as React from "react"
import { CalendarIcon } from "lucide-react"
import { format, parse, isValid, subDays } from "date-fns"
import type { Matcher } from "react-day-picker"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import { Input } from "@/components/ui/input"
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover"

interface DatePickerProps {
    value?: string // YYYY-MM-DD format
    onChange?: (value: string) => void
    placeholder?: string
    className?: string
    disabled?: boolean
    minDate?: string // YYYY-MM-DD format - dates before this will be disabled
    maxDate?: string // YYYY-MM-DD format - dates after this will be disabled
    defaultMonth?: string // YYYY-MM-DD format - initial month to show when picker opens (useful for end date to match start date)
}

export function DatePicker({
    value,
    onChange,
    placeholder = "Select date",
    className,
    disabled = false,
    minDate,
    maxDate,
    defaultMonth,
}: DatePickerProps) {
    const [open, setOpen] = React.useState(false)

    // Parse the string value to Date
    const date = React.useMemo(() => {
        if (!value) return undefined
        const parsed = parse(value, "yyyy-MM-dd", new Date())
        return isValid(parsed) ? parsed : undefined
    }, [value])

    // Parse defaultMonth for initial display
    const initialMonth = React.useMemo(() => {
        if (date) return date
        if (defaultMonth) {
            const parsed = parse(defaultMonth, "yyyy-MM-dd", new Date())
            return isValid(parsed) ? parsed : undefined
        }
        return undefined
    }, [date, defaultMonth])

    const [month, setMonth] = React.useState<Date | undefined>(initialMonth)

    // Update month when date or defaultMonth changes externally
    React.useEffect(() => {
        if (date) {
            setMonth(date)
        } else if (defaultMonth && !value) {
            const parsed = parse(defaultMonth, "yyyy-MM-dd", new Date())
            if (isValid(parsed)) {
                setMonth(parsed)
            }
        }
    }, [date, defaultMonth, value])

    // Create disabled matcher for Calendar based on minDate/maxDate
    const disabledMatcher = React.useMemo((): Matcher | Matcher[] | undefined => {
        const matchers: Matcher[] = [];
        
        if (minDate) {
            const min = parse(minDate, "yyyy-MM-dd", new Date());
            if (isValid(min)) {
                // Disable all dates before minDate (exclusive, so we subtract 1 day)
                matchers.push({ before: min });
            }
        }
        
        if (maxDate) {
            const max = parse(maxDate, "yyyy-MM-dd", new Date());
            if (isValid(max)) {
                // Disable all dates after maxDate
                matchers.push({ after: max });
            }
        }
        
        return matchers.length > 0 ? matchers : undefined;
    }, [minDate, maxDate]);

    const handleSelect = (selectedDate: Date | undefined) => {
        if (selectedDate && onChange) {
            onChange(format(selectedDate, "yyyy-MM-dd"))
        }
        setOpen(false)
    }

    return (
        <div className={cn("relative", className)}>
            <Input
                type="text"
                value={date ? format(date, "MMM dd, yyyy") : ""}
                placeholder={placeholder}
                className="bg-background pr-10"
                readOnly
                disabled={disabled}
                onClick={() => !disabled && setOpen(true)}
            />
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="absolute top-1/2 right-1 size-7 -translate-y-1/2"
                        disabled={disabled}
                    >
                        <CalendarIcon className="size-4" />
                        <span className="sr-only">Select date</span>
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-auto overflow-hidden p-0"
                    align="end"
                    sideOffset={8}
                >
                    <Calendar
                        mode="single"
                        selected={date}
                        onSelect={handleSelect}
                        month={month}
                        onMonthChange={setMonth}
                        captionLayout="dropdown"
                        disabled={disabledMatcher}
                    />
                    <div className="border-t p-2 flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            className="flex-1"
                            onClick={() => handleSelect(new Date())}
                        >
                            Today
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="flex-1"
                            onClick={() => {
                                if (onChange) onChange("")
                                setOpen(false)
                            }}
                        >
                            Clear
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
        </div>
    )
}
