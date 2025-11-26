import React, { useEffect, useMemo, useState } from 'react';
import { Calendar as CalendarComponent } from '@/components/ui/calendar';
import { Badge } from '@/components/ui/badge';
import { Calendar as CalendarIcon, Loader2 } from 'lucide-react';

// Types based on Nager.Date API
export interface Holiday {
    date: string; // ISO date (YYYY-MM-DD)
    name: string; // English name
    localName: string;
    countryCode: string;
    fixed: boolean;
    global: boolean;
    counties: string[] | null;
    launchYear: number | null;
    types: string[]; // e.g., ["Public"]
}

interface CalendarWithHolidaysProps {
    countryCode?: string | string[]; // defaults to 'PH'
    initialDate?: Date; // defaults to today
    width?: number; // calendar width in px, defaults to 420
    className?: string;
}

const CalendarWithHolidays: React.FC<CalendarWithHolidaysProps> = ({
    countryCode = 'PH',
    initialDate = new Date(),
    width = 420,
    className,
}) => {
    const [selectedDate, setSelectedDate] = useState<Date | undefined>(initialDate);
    const [currentMonth, setCurrentMonth] = useState<Date>(new Date(initialDate));
    const [holidays, setHolidays] = useState<Holiday[]>([]);
    const [loading, setLoading] = useState<boolean>(false);

    // Fetch holidays for the visible year
    const fetchHolidays = async (year: number) => {
        setLoading(true);
        try {
            const codes = Array.isArray(countryCode) ? countryCode : [countryCode];
            const promises = codes.map(async (code) => {
                const res = await fetch(`https://date.nager.at/api/v3/PublicHolidays/${year}/${code}`);
                if (!res.ok) throw new Error(`Failed to fetch holidays for ${code}`);
                return res.json();
            });

            const results = await Promise.all(promises);
            const allHolidays = results.flat() as Holiday[];
            setHolidays(allHolidays);
        } catch (e) {
            console.error('Holiday fetch failed:', e);
            setHolidays([]);
        } finally {
            setLoading(false);
        }
    };

    // Load holidays on mount and whenever the year changes
    useEffect(() => {
        fetchHolidays(currentMonth.getFullYear());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [currentMonth.getFullYear(), Array.isArray(countryCode) ? countryCode.join(',') : countryCode]);

    // Holidays for the current visible month
    const monthHolidays = useMemo(() => {
        return holidays.filter((h) => {
            const d = new Date(h.date);
            return (
                d.getFullYear() === currentMonth.getFullYear() && d.getMonth() === currentMonth.getMonth()
            );
        });
    }, [holidays, currentMonth]);

    const containerStyle: React.CSSProperties = { width: `${width}px`, maxWidth: '100%' };

    const selectedISO = selectedDate ? selectedDate.toISOString().slice(0, 10) : null;
    const selectedHolidays = selectedISO
        ? holidays.filter((h) => h.date === selectedISO)
        : [];

    return (
        <div className={className}>
            <div style={containerStyle}>
                {loading && (
                    <div className="flex items-center justify-center py-2 mb-2">
                        <Loader2 className="h-4 w-4 animate-spin mr-2" />
                        <span className="text-sm text-muted-foreground">Loading holidays...</span>
                    </div>
                )}

                <CalendarComponent
                    selected={selectedDate}
                    onSelect={(d) => setSelectedDate(d ?? undefined)}
                    mode="single"
                    required
                    month={currentMonth}
                    onMonthChange={setCurrentMonth}
                    className="rounded-lg border shadow-md w-full"
                    modifiers={{ holiday: monthHolidays.map((h) => new Date(h.date)) }}
                    modifiersClassNames={{
                        holiday:
                            'relative bg-yellow-200 dark:bg-yellow-900/40 text-yellow-900 dark:text-yellow-100 font-bold',
                    }}
                    components={{
                        DayButton: ({ day, modifiers, ...props }) => {
                            const isHoliday = modifiers.holiday;
                            const dateStr = day.date.toISOString().slice(0, 10);
                            const dayHolidays = monthHolidays.filter((h) => h.date === dateStr);
                            return (
                                <div className="relative group">
                                    <button {...props} className={props.className}>
                                        {day.date.getDate()}
                                        {isHoliday && (
                                            <span
                                                className="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"
                                                title="Holiday"
                                            ></span>
                                        )}
                                    </button>
                                    {isHoliday && dayHolidays.length > 0 && (
                                        <div className="absolute z-50 bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 bg-gray-900 text-white text-xs rounded whitespace-nowrap opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-opacity pointer-events-none">
                                            {dayHolidays.map((h, i) => (
                                                <div key={i}>
                                                    🎉 {h.name} ({h.countryCode})
                                                </div>
                                            ))}
                                            <div className="absolute top-full left-1/2 -translate-x-1/2 -mt-1 border-4 border-transparent border-t-gray-900"></div>
                                        </div>
                                    )}
                                </div>
                            );
                        },
                    }}
                />

                {/* Selected Holiday Banner */}
                {selectedHolidays.length > 0 && (
                    <div className="mt-4 space-y-2">
                        {selectedHolidays.map((holiday, idx) => (
                            <div key={idx} className="p-3 rounded-lg bg-yellow-100 dark:bg-yellow-900/20 border border-yellow-300 dark:border-yellow-700">
                                <div className="flex items-start gap-2">
                                    <span className="text-xl">🎉</span>
                                    <div className="flex-1">
                                        <div className="font-semibold text-yellow-900 dark:text-yellow-100">
                                            {holiday.name} <span className="text-xs font-normal text-yellow-800 dark:text-yellow-200">({holiday.countryCode})</span>
                                        </div>
                                        <div className="text-xs text-yellow-700 dark:text-yellow-300 mt-1">
                                            {holiday.types.join(', ')} • {holiday.date}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {/* Holiday List Panel */}
                <div className="mt-4 p-4 rounded-lg border bg-card">
                    <div className="font-semibold text-lg mb-3 flex items-center gap-2">
                        <CalendarIcon className="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                        Holidays This Month
                    </div>
                    {loading ? (
                        <div className="flex items-center justify-center py-4">
                            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                        </div>
                    ) : monthHolidays.length === 0 ? (
                        <div className="text-sm text-muted-foreground text-center py-4">No holidays this month</div>
                    ) : (
                        <div className="space-y-2">
                            {monthHolidays.map((h, idx) => {
                                const holidayDate = new Date(h.date);
                                const formattedDate = holidayDate.toLocaleDateString('en-US', {
                                    weekday: 'short',
                                    month: 'short',
                                    day: 'numeric',
                                });
                                return (
                                    <div
                                        key={`${h.date}-${h.countryCode}-${idx}`}
                                        className="flex items-center justify-between p-2 rounded-lg hover:bg-muted/50 transition-colors"
                                    >
                                        <div className="flex items-center gap-3">
                                            <div className="w-2 h-2 bg-red-500 rounded-full"></div>
                                            <div>
                                                <span className="font-medium text-sm">🎉 {h.name} <span className="text-xs text-muted-foreground">({h.countryCode})</span></span>
                                                <div className="text-xs text-muted-foreground">{h.types.join(', ')}</div>
                                            </div>
                                        </div>
                                        <Badge variant="outline" className="text-xs">
                                            {formattedDate}
                                        </Badge>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default CalendarWithHolidays;
