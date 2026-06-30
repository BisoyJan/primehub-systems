import { Head, router, useForm, usePage } from "@inertiajs/react";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { createContext, useContext, useMemo, useEffect, useRef, useState, memo, startTransition, Fragment } from "react";
import AppLayout from "@/layouts/app-layout";
import { type SharedData } from "@/types";
import { useFlashMessage, usePageMeta, usePermission } from "@/hooks";
import { getEcho } from "@/echo";
import { PageHeader } from "@/components/PageHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from "@/components/ui/tooltip";
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from "@/components/ui/command";
import { Check, CheckCircle, ChevronDown, ChevronLeft, ChevronRight, ChevronsUpDown, Clock, Loader2, RefreshCw, Search, AlertCircle, Edit, X } from "lucide-react";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    hub as attendanceHub,
    review as attendanceReview,
    spreadsheet as attendanceSpreadsheet,
    partialApprove as attendancePartialApprove,
} from "@/routes/attendance";
import {
    updateCell as spreadsheetUpdateCell,
    createCell as spreadsheetCreateCell,
    calculateWeek as spreadsheetCalculateWeek,
    removeWeek as spreadsheetRemoveWeek,
} from "@/routes/attendance/spreadsheet";
import { recalculateGbro as recalculateGbroRoute } from "@/routes/attendance-points";
import { toast } from "sonner";

import { Checkbox } from "@/components/ui/checkbox";
import { Switch } from "@/components/ui/switch";
import { Badge } from "@/components/ui/badge";

// =====================================================================
// Types
// =====================================================================

type CellKind = "empty" | "hours" | "leave" | "absent" | "off" | "bio";

// Point values for each violation type (matches backend AttendancePoint::POINT_VALUES)
const POINT_VALUES: Record<string, number> = {
    whole_day_absence: 1.00,
    half_day_absence: 0.50,
    undertime: 0.25,
    undertime_more_than_hour: 0.50,
    tardy: 0.25,
    ncns: 1.00,
};

const getPointValue = (status: string): number => POINT_VALUES[status] ?? 0;

interface Cell {
    attendance_id: number;
    kind: CellKind;
    code: string | null;
    hours: number | null;
    status: string;
    secondary_status: string | null;
    verified: boolean;
    color: string;
    actual_time_in: string | null;
    actual_time_out: string | null;
    has_bio: boolean;
    unverified_bio: boolean;
    tardy_minutes: number;
    undertime_minutes: number;
    overtime_minutes: number;
    overtime_approved: boolean;
    is_set_home: boolean;
    is_critical_day: boolean;
    is_partially_verified: boolean;
    undertime_approval_status: 'pending' | 'approved' | 'rejected' | null;
    undertime_approval_reason: 'generate_points' | 'skip_points' | 'lunch_used' | null;
    warnings: Array<string | { type: string; message: string; severity?: string }>;
    scheduled_time_in: string | null;
    scheduled_time_out: string | null;
    shift_type: string | null;
    notes: string | null;
    verification_notes: string | null;
    is_partial_day_sl?: boolean;
}

interface EmployeeSchedule {
    shift_type: string | null;
    scheduled_time_in: string | null;
    scheduled_time_out: string | null;
    work_days: string[] | null;
    campaign: string | null;
    grace_period_minutes: number;
}

interface Employee {
    id: number;
    name: string;
    role: string;
    avatar_url: string | null;
    points: number;
    schedule: EmployeeSchedule | null;
    cells: Record<string, Cell>;
}

interface Group {
    campaign: string;
    employees: Employee[];
}

interface DayMeta {
    date: string;
    day: number;
    weekday: string;
    is_weekend: boolean;
    is_saturday: boolean;
    is_overflow: boolean;
}

interface Campaign {
    id: number;
    name: string;
}

interface PageProps extends SharedData {
    groups: Group[];
    /** Calculated payroll totals keyed by user_id → Saturday (display anchor) → hours. */
    weekTotals: Record<string, Record<string, number>>;
    days: DayMeta[];
    month: number;
    year: number;
    campaigns: Campaign[];
    teamLeadCampaignIds?: number[];
    halfDayThreshold: number;
    filters: {
        campaign_id: string | null;
        search: string;
    };
}

// =====================================================================
// Color helpers (mirror the spreadsheet screenshot)
// =====================================================================

const cellClass = (cell: Cell | undefined, isWeekend: boolean): string => {
    if (!cell) {
        return isWeekend
            ? "bg-slate-200 dark:bg-slate-800/60"
            : "bg-white dark:bg-slate-900/30";
    }
    switch (cell.color) {
        case "hours-ok":
            return "bg-pink-50 text-pink-900 dark:bg-pink-950/40 dark:text-pink-200";
        case "hours-tardy":
            return "bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100";
        case "absent":
            return "bg-red-500 text-white font-semibold";
        case "off":
            return "bg-slate-400 text-white";
        case "bio":
            return "bg-purple-400 text-white";
        case "partial":
            return "bg-orange-400 text-white font-semibold";
        case "leave-vl":
            return "bg-green-500 text-white font-semibold";
        case "leave-sl":
            return "bg-cyan-400 text-white font-semibold";
        case "leave-ml":
            return "bg-fuchsia-500 text-white font-semibold";
        case "leave-loa":
            return "bg-rose-400 text-white font-semibold";
        case "leave-upto":
            return "bg-sky-500 text-white font-semibold";
        case "leave-bl":
            return "bg-indigo-500 text-white font-semibold";
        case "leave-spl":
            return "bg-teal-500 text-white font-semibold";
        case "leave-ldv":
            return "bg-violet-500 text-white font-semibold";
        case "leave-other":
            return "bg-blue-500 text-white font-semibold";
        case "leave-partial-sl":
            return "bg-cyan-200 text-cyan-900 dark:bg-cyan-900/40 dark:text-cyan-100 font-semibold";
        case "leave-partial-sl-hours":
            // Worked hours under a Partial-day SL day: keep the numeric label visible
            // but tint the background cyan so reviewers can tell the day is P-SL.
            return "bg-cyan-50 text-cyan-900 dark:bg-cyan-950/40 dark:text-cyan-100 ring-1 ring-cyan-300 dark:ring-cyan-700";
        case "leave-iw":
            return "bg-lime-500 text-white font-semibold";
        case "leave-iw-hours":
            // Worked hours under an Incomplete Workday: keep hours visible
            // but tint the background lime so reviewers can tell it's IW.
            return "bg-lime-50 text-lime-900 dark:bg-lime-950/40 dark:text-lime-100 ring-1 ring-lime-300 dark:ring-lime-700";
        case "review":
            return "bg-amber-400 text-white font-semibold";
        default:
            return "bg-white dark:bg-slate-900/30";
    }
};

const SHOW_EXACT_HOURS_STATUSES = new Set([
    "tardy",
    "undertime",
    "undertime_more_than_hour",
    "half_day_absence",
]);

const cellLabel = (cell: Cell | undefined): string => {
    if (!cell) return "";
    if (cell.kind === "off") return "OFF";
    if (cell.code) return cell.code;
    if (cell.hours !== null) {
        // Overtime only counts when approved; otherwise cap at the standard 8 hours.
        const effective = cell.overtime_approved ? cell.hours : Math.min(cell.hours, 8);
        // Tardy/undertime statuses: show exact hours so the shortfall is visible
        if (SHOW_EXACT_HOURS_STATUSES.has(cell.status) || cell.tardy_minutes > 0 || cell.undertime_minutes > 0) {
            const exact = effective.toFixed(2);
            return exact.endsWith(".00") ? String(Math.round(effective)) : exact;
        }
        // Approved overtime: show exact hours including the overtime portion
        if (cell.overtime_approved) {
            const exact = effective.toFixed(2);
            return exact.endsWith(".00") ? String(Math.round(effective)) : exact;
        }
        return String(Math.round(effective));
    }
    return "";
};

const unverifiedRing = (cell: Cell | undefined): string =>
    cell && (cell.unverified_bio || cell.is_partially_verified || (!cell.verified && cell.kind !== "off" && cell.kind !== "leave"))
        ? "ring-2 ring-inset ring-yellow-400 dark:ring-yellow-500"
        : "";

// =====================================================================
// Page
// =====================================================================

const MONTH_NAMES = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
];

// =====================================================================
// Live presence (who is currently editing what)
// =====================================================================

interface PresenceUser {
    id: number;
    name: string;
    avatar_url: string | null;
}

// Map of "employeeId|date" -> list of users currently editing that cell.
type EditingMap = Record<string, PresenceUser[]>;

interface PresenceContextValue {
    selfId: number | null;
    editingMap: EditingMap;
    whisperFocus: (cellKey: string | null) => void;
    colorFor: (userId: number) => string;
}

const PresenceContext = createContext<PresenceContextValue>({
    selfId: null,
    editingMap: {},
    whisperFocus: () => { /* noop */ },
    colorFor: () => '#0ea5e9',
});

const cellKey = (employeeId: number, date: string) => `${employeeId}|${date}`;

// Distinct, high-contrast colors used to tag each collaborator (Google Sheets
// style). The order is shuffled per page-load so a returning user gets a
// different color than last session.
const PRESENCE_COLOR_PALETTE = [
    '#2563eb', // blue-600
    '#dc2626', // red-600
    '#16a34a', // green-600
    '#9333ea', // purple-600
    '#ea580c', // orange-600
    '#0891b2', // cyan-600
    '#db2777', // pink-600
    '#ca8a04', // yellow-600
    '#0d9488', // teal-600
    '#4f46e5', // indigo-600
    '#65a30d', // lime-600
    '#e11d48', // rose-600
];

function shufflePalette(): string[] {
    const arr = [...PRESENCE_COLOR_PALETTE];
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}
void shufflePalette;

export default function AttendanceSpreadsheet() {
    const {
        groups,
        weekTotals,
        days,
        month,
        year,
        campaigns,
        filters,
        auth,
    } = usePage<PageProps>().props;

    useFlashMessage();
    const { can } = usePermission();
    const canEdit = can("attendance.create");

    const { title, breadcrumbs } = usePageMeta({
        title: "Attendance Spreadsheet",
        breadcrumbs: [
            { title: "Attendance", href: attendanceHub().url },
            { title: "Spreadsheet" },
        ],
    });

    const [search, setSearch] = useState(filters.search ?? "");
    const [campaignFilter, setCampaignFilter] = useState<string>(
        filters.campaign_id ? String(filters.campaign_id) : "all"
    );
    const [employeePopoverOpen, setEmployeePopoverOpen] = useState(false);
    const [employeeQuery, setEmployeeQuery] = useState("");
    const [selectedEmployeeId, setSelectedEmployeeId] = useState<number | null>(null);
    const [isNavigating, setIsNavigating] = useState(false);

    const allEmployees = useMemo(
        () => groups.flatMap((g) => g.employees),
        [groups]
    );
    const filteredEmployeeList = useMemo(
        () =>
            !employeeQuery
                ? allEmployees
                : allEmployees.filter((e) =>
                    e.name.toLowerCase().includes(employeeQuery.toLowerCase())
                ),
        [allEmployees, employeeQuery]
    );

    const navigate = (overrides: Record<string, string | number | undefined>) => {
        const params: Record<string, string | number> = {
            month,
            year,
        };
        const merged: Record<string, string | number | undefined> = {
            search: search || undefined,
            campaign_id: campaignFilter !== "all" ? campaignFilter : undefined,
            ...overrides,
        };
        Object.entries(merged).forEach(([k, v]) => {
            if (v !== undefined && v !== "") params[k] = v;
        });
        setIsNavigating(true);
        router.get(attendanceSpreadsheet().url, params, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setIsNavigating(false),
        });
    };

    const handlePrevMonth = () => {
        const m = month === 1 ? 12 : month - 1;
        const y = month === 1 ? year - 1 : year;
        navigate({ month: m, year: y });
    };

    const handleNextMonth = () => {
        const m = month === 12 ? 1 : month + 1;
        const y = month === 12 ? year + 1 : year;
        navigate({ month: m, year: y });
    };

    const handleToday = () => {
        const now = new Date();
        navigate({ month: now.getMonth() + 1, year: now.getFullYear() });
    };

    const handleApplyFilters = (e: React.FormEvent) => {
        e.preventDefault();
        navigate({});
    };

    const totalEmployees = useMemo(
        () => groups.reduce((sum, g) => sum + g.employees.length, 0),
        [groups]
    );

    const saturdayCount = useMemo(
        () => days.filter((d) => d.is_saturday).length,
        [days]
    );
    const totalDayCols = days.length + saturdayCount;

    // Defer heavy table render to after first paint to eliminate the blank-screen
    // gap between the Inertia progress bar finishing and the grid becoming visible.
    const [tableReady, setTableReady] = useState(false);
    useEffect(() => {
        // Double-RAF: first RAF lets React commit the spinner to the DOM,
        // second RAF fires after the browser has actually painted it.
        // startTransition keeps the render interruptible so animations stay alive.
        let id2: number;
        const id1 = requestAnimationFrame(() => {
            id2 = requestAnimationFrame(() => {
                startTransition(() => setTableReady(true));
            });
        });
        return () => { cancelAnimationFrame(id1); cancelAnimationFrame(id2); };
    }, []);

    // Live sync via Reverb: subscribe to the private attendance.spreadsheet
    // channel and reload the affected props when any user mutates a cell or a
    // week total. The originating tab is filtered out server-side by
    // broadcast()->toOthers() (matched by socket id, so it works correctly
    // even when multiple tabs are signed in as the same account). Reloads are
    // debounced so a burst of updates collapses into a single round-trip.
    useEffect(() => {
        let echoInstance: ReturnType<typeof getEcho> = null;
        let reloadTimer: ReturnType<typeof setTimeout> | null = null;
        let pendingNeedsGroups = false;

        const schedule = (needsGroups: boolean) => {
            pendingNeedsGroups = pendingNeedsGroups || needsGroups;
            if (reloadTimer) return;
            reloadTimer = setTimeout(() => {
                reloadTimer = null;
                const only = pendingNeedsGroups
                    ? ['groups', 'weekTotals', 'flash']
                    : ['weekTotals', 'flash'];
                pendingNeedsGroups = false;
                router.reload({ only, async: true });
            }, 400);
        };

        echoInstance = getEcho();
        if (echoInstance) {
            echoInstance
                .private('attendance.spreadsheet')
                .listen('.spreadsheet.updated', (e: { type: string }) => {
                    schedule(e.type === 'cell');
                });
        }

        return () => {
            if (reloadTimer) clearTimeout(reloadTimer);
            if (echoInstance) {
                echoInstance.leave('attendance.spreadsheet');
            }
        };
    }, []);

    // Presence + cell-focus whispers. Subscribes to the presence channel so
    // every tab knows who else is on the spreadsheet, and uses client-side
    // whispers (no DB writes) to broadcast which cell each user currently has
    // open in the editor popover. Other tabs render a highlight ring on that
    // cell so collaborators can see live editing activity.
    const selfId = auth?.user?.id ?? null;
    const [activeUsers, setActiveUsers] = useState<PresenceUser[]>([]);
    const [editingMap, setEditingMap] = useState<EditingMap>({});
    const presenceChannelRef = useRef<ReturnType<NonNullable<ReturnType<typeof getEcho>>['join']> | null>(null);
    const selfRef = useRef<PresenceUser | null>(null);

    // Deterministic, collision-free color assignment synchronized across
    // browsers. Every tab sorts the currently-active users by id and assigns
    // palette[index] in order, so every browser computes the exact same map.
    // A small per-session salt (broadcast via `palette-salt` whisper from the
    // lowest-id user) rotates the starting offset so colors look "random"
    // between page loads without ever colliding.
    const colorPaletteRef = useRef<string[]>(PRESENCE_COLOR_PALETTE);
    const [paletteSalt, setPaletteSalt] = useState<number>(() => Math.floor(Math.random() * PRESENCE_COLOR_PALETTE.length));

    const colorMap = useMemo(() => {
        const palette = colorPaletteRef.current;
        const sortedIds = [...activeUsers.map((u) => u.id)].sort((a, b) => a - b);
        const map = new Map<number, string>();
        sortedIds.forEach((id, idx) => {
            map.set(id, palette[(idx + paletteSalt) % palette.length]);
        });
        return map;
    }, [activeUsers, paletteSalt]);

    const colorFor = useMemo(
        () => (userId: number) => {
            const palette = colorPaletteRef.current;
            return colorMap.get(userId) ?? palette[Math.abs(userId * 2654435761) % palette.length];
        },
        [colorMap]
    );

    useEffect(() => {
        const u = auth?.user as { id?: number; name?: string; first_name?: string; last_name?: string; avatar_url?: string | null } | undefined;
        selfRef.current = selfId
            ? {
                id: selfId,
                name: u?.name ?? ([u?.first_name, u?.last_name].filter(Boolean).join(' ') || 'You'),
                avatar_url: u?.avatar_url ?? null,
            }
            : null;
    }, [selfId, auth?.user]);

    useEffect(() => {
        let cancelled = false;
        let channel: ReturnType<NonNullable<ReturnType<typeof getEcho>>['join']> | null = null;

        const removeUserEverywhere = (userId: number) =>
            setEditingMap((prev) => {
                const next: EditingMap = {};
                for (const [k, list] of Object.entries(prev)) {
                    const filtered = list.filter((u) => u.id !== userId);
                    if (filtered.length) next[k] = filtered;
                }
                return next;
            });

        const echo = getEcho();
        if (echo && !cancelled) {

            // Broadcast our session's palette salt. Whoever has the lowest user
            // id among active users "wins" — their salt is adopted by everyone.
            const broadcastSalt = () => {
                if (!channel || selfId === null) return;
                channel.whisper('palette-salt', { from_user_id: selfId, salt: paletteSalt });
            };

            channel = echo.join('attendance.spreadsheet.presence')
                .here((users: PresenceUser[]) => {
                    setActiveUsers(users);
                    broadcastSalt();
                })
                .joining((user: PresenceUser) => {
                    setActiveUsers((prev) => [...prev.filter((u) => u.id !== user.id), user]);
                    // Re-announce so the newcomer adopts the existing salt.
                    broadcastSalt();
                })
                .leaving((user: PresenceUser) => {
                    setActiveUsers((prev) => prev.filter((u) => u.id !== user.id));
                    removeUserEverywhere(user.id);
                })
                .listenForWhisper('palette-salt', (payload: { from_user_id: number; salt: number }) => {
                    // Adopt the salt only if the sender has a smaller user id
                    // than us — guarantees every browser converges on the same
                    // value (whoever has the lowest id "wins").
                    if (selfId === null || payload.from_user_id < selfId) {
                        setPaletteSalt(payload.salt);
                    }
                })
                .listenForWhisper('cell-focus', (payload: { user: PresenceUser; cell_key: string | null }) => {
                    setEditingMap((prev) => {
                        const next: EditingMap = {};
                        for (const [k, list] of Object.entries(prev)) {
                            const filtered = list.filter((u) => u.id !== payload.user.id);
                            if (filtered.length) next[k] = filtered;
                        }
                        if (payload.cell_key) {
                            const list = next[payload.cell_key] ?? [];
                            next[payload.cell_key] = [...list, payload.user];
                        }
                        return next;
                    });
                });

            presenceChannelRef.current = channel;
        }

        return () => {
            cancelled = true;
            presenceChannelRef.current = null;
            if (channel) {
                window.Echo?.leave('attendance.spreadsheet.presence');
            }
        };
    }, []);

    const whisperFocus = useMemo(
        () => (key: string | null) => {
            const ch = presenceChannelRef.current;
            const me = selfRef.current;
            if (!ch || !me) return;
            ch.whisper('cell-focus', { user: me, cell_key: key });
        },
        []
    );

    const presenceValue = useMemo<PresenceContextValue>(
        () => ({ selfId, editingMap, whisperFocus, colorFor }),
        [selfId, editingMap, whisperFocus, colorFor]
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <PresenceContext.Provider value={presenceValue}>
                <div className="flex h-[calc(100dvh-4rem)] min-w-0 max-w-full flex-col gap-3 overflow-hidden rounded-xl p-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <PageHeader
                            title="Attendance Spreadsheet"
                            description={`Per-employee × per-day grid for ${MONTH_NAMES[month - 1]} ${year} — ${totalEmployees} employees`}
                        />
                        <ActiveEditorsPanel users={activeUsers} selfId={selfId} colorFor={colorFor} />
                    </div>

                    {/* Toolbar */}
                    <form onSubmit={handleApplyFilters} className="flex flex-col gap-3">
                        {/* Row 1: month nav + filters */}
                        <div className="flex flex-wrap items-center gap-3">
                            {/* Month navigation */}
                            <div className="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={handlePrevMonth}
                                    aria-label="Previous month"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                </Button>
                                <div className="min-w-44 px-2 text-center text-sm font-semibold text-foreground">
                                    {MONTH_NAMES[month - 1]} {year}
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={handleNextMonth}
                                    aria-label="Next month"
                                >
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={handleToday}
                                >
                                    Today
                                </Button>
                            </div>

                            {/* Search — combobox style */}
                            <Popover open={employeePopoverOpen} onOpenChange={setEmployeePopoverOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={employeePopoverOpen}
                                        className="w-52 justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {search || "All Employees"}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-80 p-0" align="start">
                                    <Command shouldFilter={false}>
                                        <CommandInput
                                            placeholder="Search employee..."
                                            value={employeeQuery}
                                            onValueChange={setEmployeeQuery}
                                        />
                                        <CommandList>
                                            <CommandEmpty>No employee found.</CommandEmpty>
                                            <CommandGroup>
                                                <CommandItem
                                                    value="all"
                                                    onSelect={() => {
                                                        setSearch("");
                                                        setEmployeePopoverOpen(false);
                                                        setEmployeeQuery("");
                                                    }}
                                                    className="cursor-pointer"
                                                >
                                                    <Check
                                                        className={`mr-2 h-4 w-4 ${search === "" ? "opacity-100" : "opacity-0"
                                                            }`}
                                                    />
                                                    All Employees
                                                </CommandItem>
                                                {filteredEmployeeList.map((emp) => (
                                                    <CommandItem
                                                        key={emp.id}
                                                        value={emp.name}
                                                        onSelect={() => {
                                                            setSearch(emp.name);
                                                            setEmployeePopoverOpen(false);
                                                            setEmployeeQuery("");
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${search === emp.name
                                                                ? "opacity-100"
                                                                : "opacity-0"
                                                                }`}
                                                        />
                                                        {emp.name}
                                                    </CommandItem>
                                                ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>

                            {/* Campaign */}
                            <Select value={campaignFilter} onValueChange={setCampaignFilter}>
                                <SelectTrigger id="ss-campaign" className="w-48">
                                    <SelectValue placeholder="All campaigns" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All campaigns</SelectItem>
                                    {campaigns.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            {/* Apply */}
                            <Button type="submit" className="w-full sm:w-auto">
                                <Search className="mr-2 h-4 w-4" />
                                Apply Filters
                            </Button>
                        </div>
                    </form>

                    {/* Legend */}
                    <Legend />

                    {/* Grid */}
                    <div className="relative min-h-0 w-full flex-1 overflow-auto rounded-lg border bg-card">
                        {isNavigating && (
                            <div className="absolute inset-0 z-10 flex items-center justify-center bg-background/60 backdrop-blur-[1px]">
                                <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                            </div>
                        )}
                        {!tableReady ? (
                            <div className="flex h-48 items-center justify-center">
                                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                            </div>
                        ) : (
                            <table className="w-max min-w-full border-separate border-spacing-0 text-xs">
                                <thead className="bg-slate-100 dark:bg-slate-800">
                                    <tr>
                                        <th
                                            className="sticky top-0 left-0 z-20 w-46 min-w-46 border-b border-r bg-slate-200 px-2 py-1 text-left font-semibold dark:bg-slate-700"
                                        >
                                            Name
                                        </th>
                                        <th className="sticky top-0 left-46 z-20 w-15 min-w-15 border-b border-r bg-slate-200 px-2 py-1 text-center font-semibold dark:bg-slate-700">
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <span className="cursor-help">Pts</span>
                                                    </TooltipTrigger>
                                                    <TooltipContent side="right">
                                                        Total active attendance points (all-time)
                                                    </TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                        </th>
                                        {days.map((d) => (
                                            <Fragment key={d.date}>
                                                <th
                                                    className={`sticky top-0 z-11 w-11 min-w-11 max-w-11 border-b border-r px-1 py-1 text-center font-semibold ${d.is_overflow ? "bg-slate-50 text-slate-400 dark:bg-slate-900 dark:text-slate-600" : d.is_saturday ? "bg-emerald-100 dark:bg-emerald-950" : d.is_weekend ? "bg-slate-300 dark:bg-slate-700" : "bg-slate-100 dark:bg-slate-800"}`}
                                                >
                                                    <div className="leading-tight">{d.day}</div>
                                                    <div className="text-[10px] font-normal text-muted-foreground leading-tight">
                                                        {d.weekday}
                                                    </div>
                                                </th>
                                                {d.is_saturday && (
                                                    <th className="sticky top-0 z-11 w-16 min-w-16 max-w-16 border-b border-r bg-emerald-200 px-1 py-1 text-center font-semibold dark:bg-emerald-900">
                                                        <div className="leading-tight">Wk Hrs</div>
                                                        <div className="text-[10px] font-normal text-muted-foreground leading-tight">
                                                            ending {d.day}
                                                        </div>
                                                    </th>
                                                )}
                                            </Fragment>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {groups.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={2 + totalDayCols}
                                                className="px-4 py-6 text-center text-muted-foreground"
                                            >
                                                No employees match the current filters.
                                            </td>
                                        </tr>
                                    )}
                                    {groups.map((g) => (
                                        <GroupRows
                                            key={g.campaign}
                                            group={g}
                                            days={days}
                                            canEdit={canEdit}
                                            colCount={2 + totalDayCols}
                                            selectedEmployeeId={selectedEmployeeId}
                                            onSelectEmployee={setSelectedEmployeeId}
                                            weekTotals={weekTotals}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </PresenceContext.Provider>
        </AppLayout>
    );
}

// =====================================================================
// Group / row sub-components
// =====================================================================

function GroupRows({
    group,
    days,
    canEdit,
    colCount,
    selectedEmployeeId,
    onSelectEmployee,
    weekTotals,
}: {
    group: Group;
    days: DayMeta[];
    canEdit: boolean;
    colCount: number;
    selectedEmployeeId: number | null;
    onSelectEmployee: (id: number | null) => void;
    weekTotals: Record<string, Record<string, number>>;
}) {
    void colCount;
    return (
        <>
            <tr>
                <td
                    colSpan={2}
                    className="sticky left-0 z-11 border-b border-t border-r bg-blue-100 px-3 py-1 text-sm font-bold uppercase tracking-wide text-blue-900 dark:bg-blue-950/80 dark:text-blue-100"
                >
                    {group.campaign}
                </td>
                {days.map((d) => (
                    <Fragment key={d.date}>
                        <td className="border-b border-t bg-blue-50 dark:bg-blue-950/40" />
                        {d.is_saturday && (
                            <td className="border-b border-t bg-blue-50 dark:bg-blue-950/40" />
                        )}
                    </Fragment>
                ))}
            </tr>
            {group.employees.map((emp, idx) => (
                <EmployeeRow
                    key={emp.id}
                    employee={emp}
                    days={days}
                    canEdit={canEdit}
                    zebra={idx % 2 === 1}
                    isRowSelected={selectedEmployeeId === emp.id}
                    onSelectEmployee={onSelectEmployee}
                    employeeWeekTotals={weekTotals[emp.id] ?? weekTotals[String(emp.id)] ?? {}}
                />
            ))}
        </>
    );
}

const EmployeeRow = memo(function EmployeeRow({
    employee,
    days,
    canEdit,
    zebra,
    isRowSelected,
    onSelectEmployee,
    employeeWeekTotals,
}: {
    employee: Employee;
    days: DayMeta[];
    canEdit: boolean;
    zebra: boolean;
    isRowSelected: boolean;
    onSelectEmployee: (id: number | null) => void;
    employeeWeekTotals: Record<string, number>;
}) {
    const { can } = usePermission();
    const canManagePoints = can("attendance_points.manage");
    const [isRecalculating, setIsRecalculating] = useState(false);

    // Solid backgrounds so sticky left columns don't go transparent over scrolling content.
    const rowBg = zebra
        ? "bg-slate-50 dark:bg-slate-900"
        : "bg-white dark:bg-slate-950";

    const pointsColorCls =
        employee.points >= 1
            ? "text-red-500 font-semibold"
            : employee.points > 0
                ? "text-amber-500"
                : "";

    const handleRecalculateGbro = () => {
        if (!canManagePoints || isRecalculating) return;
        setIsRecalculating(true);
        router.post(
            recalculateGbroRoute({ user: employee.id }).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setIsRecalculating(false),
            }
        );
    };

    return (
        <tr>
            <td
                className={`sticky left-0 z-11 border-b border-r px-2 py-1 font-medium ${isRowSelected ? "bg-blue-100 dark:bg-blue-900/60" : rowBg
                    }`}
            >
                <span>{employee.name}</span>
            </td>
            <td
                className={`sticky left-46 z-11 border-b border-r p-0 text-center font-mono ${isRowSelected ? "bg-blue-100 dark:bg-blue-900/60" : rowBg
                    }`}
            >
                {canManagePoints ? (
                    <Tooltip delayDuration={400}>
                        <TooltipTrigger asChild>
                            <button
                                type="button"
                                onClick={handleRecalculateGbro}
                                disabled={isRecalculating}
                                className="group flex h-full w-full items-center justify-center gap-1 px-2 py-1 transition-colors hover:bg-blue-50 dark:hover:bg-blue-950/40 disabled:cursor-progress disabled:opacity-70"
                                aria-label={`Recalculate GBRO for ${employee.name}`}
                            >
                                {isRecalculating ? (
                                    <Loader2 className="h-3 w-3 animate-spin text-blue-500" />
                                ) : (
                                    <RefreshCw className="h-2.5 w-2.5 opacity-0 text-muted-foreground transition-opacity group-hover:opacity-100" />
                                )}
                                <span className={pointsColorCls}>
                                    {employee.points.toFixed(2)}
                                </span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent side="right" className="text-xs">
                            <div className="font-semibold">Recalculate GBRO</div>
                            <div className="opacity-80">Click if pts don&apos;t match the GBRO ledger.</div>
                        </TooltipContent>
                    </Tooltip>
                ) : (
                    <span className={`block px-2 py-1 ${pointsColorCls}`}>
                        {employee.points.toFixed(2)}
                    </span>
                )}
            </td>
            {days.map((d) => {
                const cell = (employee.cells as Record<string, Cell>)[d.date];
                return (
                    <Fragment key={d.date}>
                        <CellView
                            date={d}
                            employeeId={employee.id}
                            employeeName={employee.name}
                            employeeRole={employee.role}
                            avatarUrl={employee.avatar_url}
                            schedule={employee.schedule}
                            cell={cell}
                            canEdit={canEdit}
                            isRowSelected={isRowSelected}
                            onSelectEmployee={onSelectEmployee}
                        />
                        {d.is_saturday && (
                            <WeekHoursCell
                                employeeId={employee.id}
                                saturday={d.date}
                                weekTotal={employeeWeekTotals[d.date] ?? null}
                                canEdit={canEdit}
                                isRowSelected={isRowSelected}
                                rowBg={rowBg}
                            />
                        )}
                    </Fragment>
                );
            })}
        </tr>
    );
});

// =====================================================================
// Week Hours Cell (dedicated column rendered after each Saturday)
// =====================================================================

const WeekHoursCell = memo(function WeekHoursCell({
    employeeId,
    saturday,
    weekTotal,
    canEdit,
    isRowSelected,
    rowBg,
}: {
    employeeId: number;
    saturday: string;
    weekTotal: number | null;
    canEdit: boolean;
    isRowSelected: boolean;
    rowBg: string;
}) {
    const [loading, setLoading] = useState(false);

    const submit = async (url: string, errLabel: string) => {
        if (loading) return;
        setLoading(true);
        try {
            // Use fetch (not router.post) so concurrent clicks on different
            // employees don't cancel each other. Inertia's router cancels all
            // in-flight async visits when a new visit targets a different URL
            // (the POST endpoint differs from the current spreadsheet page URL),
            // which would otherwise abort the first click's onFinish and clear
            // its loading spinner the moment a second cell is clicked.
            const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
            const socketId = window.Echo?.socketId();
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                    ...(socketId ? { 'X-Socket-Id': socketId } : {}),
                },
                body: JSON.stringify({ user_id: employeeId, saturday }),
            });

            if (!res.ok) {
                let msg: string = errLabel;
                try {
                    const body = await res.json();
                    msg = String(body?.message ?? Object.values(body?.errors ?? {})[0] ?? errLabel);
                } catch { /* keep default */ }
                toast.error(msg);
                return;
            }

            // Refresh only the weekTotals prop. router.reload uses the current
            // page URL, so it doesn't interact with other in-flight calc clicks.
            router.reload({
                only: ['weekTotals', 'flash'],
                async: true,
            });
        } catch {
            toast.error(errLabel);
        } finally {
            setLoading(false);
        }
    };

    return (
        <td
            className={`w-16 min-w-16 max-w-16 h-9 border-b border-r px-1 py-1 text-center align-middle ${isRowSelected ? "bg-blue-100 dark:bg-blue-900/60" : rowBg}`}
        >
            <div className="flex flex-col items-center gap-0.5">
                {weekTotal !== null && (
                    <span className="inline-flex items-center rounded bg-emerald-600 px-1 py-0 text-[10px] font-semibold leading-tight text-white tabular-nums">
                        {weekTotal.toFixed(2)}h
                    </span>
                )}
                {canEdit && (
                    <div className="flex items-center gap-0.5">
                        <button
                            type="button"
                            onClick={() => submit(spreadsheetCalculateWeek().url, "Failed to calculate week hours.")}
                            disabled={loading}
                            className="inline-flex items-center gap-0.5 rounded border border-emerald-300 bg-emerald-50 px-1 py-0 text-[9px] font-medium text-emerald-700 transition-colors hover:bg-emerald-100 disabled:cursor-progress disabled:opacity-60 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                            aria-label={`Calculate week hours ending ${saturday}`}
                        >
                            {loading ? (
                                <Loader2 className="h-2.5 w-2.5 animate-spin" />
                            ) : (
                                <Clock className="h-2.5 w-2.5" />
                            )}
                            {weekTotal !== null ? "Recalc" : "Calc"}
                        </button>
                        {weekTotal !== null && (
                            <button
                                type="button"
                                onClick={() => submit(spreadsheetRemoveWeek().url, "Failed to remove week hours.")}
                                disabled={loading}
                                className="inline-flex items-center rounded border border-red-300 bg-red-50 px-0.5 py-0 text-[9px] font-medium text-red-700 transition-colors hover:bg-red-100 disabled:cursor-progress disabled:opacity-60 dark:border-red-700 dark:bg-red-950/40 dark:text-red-300"
                                aria-label={`Remove week hours calculation ending ${saturday}`}
                                title="Remove calculation"
                            >
                                <X className="h-2.5 w-2.5" />
                            </button>
                        )}
                    </div>
                )}
            </div>
        </td>
    );
});

// =====================================================================
// Cell + inline editor popover
// =====================================================================

const STATUS_OPTIONS: Array<{ value: string; label: string }> = [
    { value: "on_time", label: "On Time" },
    { value: "tardy", label: "Tardy" },
    { value: "undertime", label: "Undertime" },
    { value: "undertime_more_than_hour", label: "Undertime > 1h" },
    { value: "half_day_absence", label: "Half Day Absence" },
    { value: "ncns", label: "NCNS (Absent)" },
    { value: "advised_absence", label: "Advised Absence" },
    { value: "non_work_day", label: "Non-Work Day" },
    { value: "present_no_bio", label: "Present (No Bio)" },
    { value: "failed_bio_in", label: "Failed Bio In" },
    { value: "failed_bio_out", label: "Failed Bio Out" },
    { value: "needs_manual_review", label: "Needs Review" },
];

// =====================================================================
// NMR Warnings Dialog
// =====================================================================

function NmrWarningsDialog({
    open,
    onClose,
    cell,
    employeeName,
    date,
}: {
    open: boolean;
    onClose: () => void;
    cell: Cell;
    employeeName: string;
    date: string;
}) {
    const warningMessages = cell.warnings.map((w) =>
        typeof w === "string" ? w : w.message
    );

    return (
        <Dialog open={open} onOpenChange={(val) => { if (!val) onClose(); }}>
            <DialogContent className="max-w-md gap-0 p-0">
                <DialogHeader className="px-5 pt-5 pb-3">
                    <DialogTitle className="flex items-center gap-2 text-base">
                        <AlertCircle className="h-4 w-4 text-amber-500" />
                        Suspicious Pattern Detected
                    </DialogTitle>
                    <DialogDescription className="text-xs">
                        {employeeName} &mdash; {new Date(date).toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric", year: "numeric" })}
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[55vh] overflow-y-auto px-5 pb-4 space-y-3">
                    {/* Attendance summary */}
                    <div className="rounded bg-muted px-3 py-2 text-[11px] grid grid-cols-2 gap-x-4 gap-y-0.5">
                        <div className="font-medium text-foreground">Scheduled Shift</div>
                        <div className="font-medium text-foreground">Recorded Times</div>
                        <div className="text-muted-foreground">{fmtShiftType(cell.shift_type)}</div>
                        <div className="text-muted-foreground">
                            In: {cell.actual_time_in ? fmtTime(cell.actual_time_in) : "N/A"}
                        </div>
                        {cell.scheduled_time_in && (
                            <div className="text-muted-foreground">{cell.scheduled_time_in} – {cell.scheduled_time_out}</div>
                        )}
                        <div className="text-muted-foreground">
                            Out: {cell.actual_time_out ? fmtTime(cell.actual_time_out) : "N/A"}
                        </div>
                    </div>

                    {/* Warnings */}
                    {warningMessages.length > 0 && (
                        <div className="space-y-1.5">
                            <p className="text-[11px] font-semibold text-foreground">Detected Issues:</p>
                            {warningMessages.map((msg, idx) => (
                                <div key={idx} className="flex items-start gap-1.5 rounded border border-amber-200 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/40 px-2.5 py-2">
                                    <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600" />
                                    <span className="text-[11px] text-amber-900 dark:text-amber-100 leading-snug">{msg}</span>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Recommendation */}
                    <div className="flex items-start gap-1.5 rounded border border-blue-200 bg-blue-50 dark:border-blue-700 dark:bg-blue-950/40 px-2.5 py-2">
                        <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-blue-500" />
                        <p className="text-[11px] text-blue-900 dark:text-blue-100 leading-snug">
                            <span className="font-semibold">Recommended Action: </span>
                            Review biometric records and verify with employee or supervisor.
                        </p>
                    </div>
                </div>

                <DialogFooter className="px-5 py-3 border-t">
                    <Button variant="outline" size="sm" onClick={onClose}>
                        Close
                    </Button>
                    <Button size="sm" asChild>
                        <a
                            href={`${attendanceReview().url}?verify=${cell.attendance_id}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            onClick={onClose}
                        >
                            <Edit className="mr-1.5 h-3.5 w-3.5" />
                            Verify Record
                        </a>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function fmtTime(t: string | null): string {
    if (!t) return "—";
    const [h, m] = t.substring(0, 5).split(":").map(Number);
    const ampm = h >= 12 ? "PM" : "AM";
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m).padStart(2, "0")} ${ampm}`;
}

function fmtShiftType(s: string | null): string {
    return s ? s.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase()) : "—";
}

// Tiny avatar overlay shown on cells that other users are currently editing.
function LiveEditorBadge({ editors, colorFor }: { editors: PresenceUser[]; colorFor: (id: number) => string }) {
    if (editors.length === 0) return null;
    const first = editors[0];
    const color = colorFor(first.id);
    const initials = first.name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
    const title = editors.map((e) => e.name).join(', ') + ' editing';
    return (
        <span
            className="absolute top-0.5 right-0.5 z-10 flex items-center justify-center"
            title={title}
        >
            <Avatar
                className="h-3.5 w-3.5 border border-background shadow"
                style={{ boxShadow: `0 0 0 1px ${color}` }}
            >
                {first.avatar_url && <AvatarImage src={first.avatar_url} alt={first.name} />}
                <AvatarFallback
                    className="text-[7px] leading-none text-white"
                    style={{ backgroundColor: color }}
                >
                    {initials}
                </AvatarFallback>
            </Avatar>
            {editors.length > 1 && (
                <span
                    className="absolute top-0 -right-1 rounded-full px-1 text-[7px] font-semibold leading-none text-white"
                    style={{ backgroundColor: color }}
                >
                    +{editors.length - 1}
                </span>
            )}
        </span>
    );
}


const CellView = memo(function CellView({
    date,
    employeeId,
    employeeName,
    employeeRole,
    avatarUrl,
    schedule,
    cell,
    canEdit,
    isRowSelected,
    onSelectEmployee,
}: {
    date: DayMeta;
    employeeId: number;
    employeeName: string;
    employeeRole: string;
    avatarUrl: string | null;
    schedule: EmployeeSchedule | null;
    cell: Cell | undefined;
    canEdit: boolean;
    isRowSelected: boolean;
    onSelectEmployee: (id: number | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const [nmrOpen, setNmrOpen] = useState(false);
    const isNmr = cell?.status === "needs_manual_review";

    // Live presence: who else (besides me) is currently editing this cell.
    const { selfId, editingMap, whisperFocus, colorFor } = useContext(PresenceContext);
    const key = cellKey(employeeId, date.date);
    const otherEditors = useMemo(
        () => (editingMap[key] ?? []).filter((u) => u.id !== selfId),
        [editingMap, key, selfId]
    );
    const primaryEditorColor = otherEditors.length > 0 ? colorFor(otherEditors[0].id) : null;
    const editingStyle: React.CSSProperties | undefined = primaryEditorColor
        ? { boxShadow: `inset 0 0 0 2px ${primaryEditorColor}` }
        : undefined;

    // Broadcast focus/blur so other tabs can highlight this cell while I edit.
    useEffect(() => {
        if (!open) return;
        whisperFocus(key);
        return () => whisperFocus(null);
    }, [open, key, whisperFocus]);

    const tdClass = `w-11 min-w-11 max-w-11 h-9 overflow-hidden border-b border-r px-1 py-1 text-center text-[11px] tabular-nums align-middle ${cellClass(
        cell,
        date.is_weekend
    )} ${unverifiedRing(cell)} ${date.is_overflow ? "opacity-60" : ""}`;

    const label = cellLabel(cell);

    const tooltipContent = useMemo(() => (
        <div className="space-y-1 text-xs">
            <div className="font-semibold">{employeeName}</div>
            <div className="opacity-70">{date.date}</div>
            {cell ? (
                <>
                    <div className="flex items-center gap-1.5 pt-0.5">
                        <span className="opacity-70">Status:</span>
                        <span className="font-medium capitalize">{cell.status.replace(/_/g, " ")}</span>
                    </div>
                    {cell.secondary_status && (
                        <div className="flex items-center gap-1.5">
                            <span className="opacity-70">Secondary:</span>
                            <span className="font-medium capitalize">{cell.secondary_status.replace(/_/g, " ")}</span>
                        </div>
                    )}
                    {cell.hours !== null && (
                        <div className="flex items-center gap-1.5">
                            <span className="opacity-70">Hours:</span>
                            <span className="font-medium">
                                {cell.overtime_approved
                                    ? cell.hours.toFixed(2)
                                    : Math.round(Math.min(cell.hours, 8))}
                            </span>
                            {!cell.overtime_approved && cell.hours > 8 && (
                                <span className="opacity-50 text-[10px]">({cell.hours.toFixed(2)} actual)</span>
                            )}
                            {cell.overtime_minutes > 0 && (
                                <span className={`text-[10px] font-medium ${cell.overtime_approved ? "text-emerald-300" : "text-yellow-300"}`}>
                                    OT {cell.overtime_approved ? "approved" : "pending"}
                                </span>
                            )}
                        </div>
                    )}
                    {(cell.actual_time_in || cell.actual_time_out) && (
                        <div className="mt-1 border-t border-white/20 pt-1 space-y-0.5">
                            {cell.actual_time_in && (
                                <div className="flex items-center gap-1.5">
                                    <span className="opacity-70">Bio In:</span>
                                    <span className="font-mono font-medium">{fmtTime(cell.actual_time_in)}</span>
                                </div>
                            )}
                            {cell.actual_time_out && (
                                <div className="flex items-center gap-1.5">
                                    <span className="opacity-70">Bio Out:</span>
                                    <span className="font-mono font-medium">{fmtTime(cell.actual_time_out)}</span>
                                </div>
                            )}
                        </div>
                    )}
                    {cell.unverified_bio && (
                        <div className="flex items-center gap-1 mt-1 text-yellow-300 font-medium">
                            <span>⚠</span><span>Unverified biometric</span>
                        </div>
                    )}
                    {!cell.unverified_bio && !cell.verified && (
                        <div className="opacity-60 italic">Unverified</div>
                    )}
                    {(cell.notes || cell.verification_notes) && (
                        <div className="mt-1 border-t border-white/20 pt-1 space-y-0.5">
                            {cell.notes && (
                                <div>
                                    <span className="opacity-70">Note: </span>
                                    <span className="italic">{cell.notes.length > 60 ? `${cell.notes.substring(0, 60)}…` : cell.notes}</span>
                                </div>
                            )}
                            {cell.verification_notes && (
                                <div>
                                    <span className="opacity-70">Admin note: </span>
                                    <span className="italic">{cell.verification_notes.length > 60 ? `${cell.verification_notes.substring(0, 60)}…` : cell.verification_notes}</span>
                                </div>
                            )}
                        </div>
                    )}
                </>
            ) : (
                <div className="opacity-60 italic pt-0.5">No record — click to create</div>
            )}
        </div>
    ), [cell, employeeName, date.date]);

    if (!canEdit) {
        return (
            <>
                {cell && isNmr && (
                    <NmrWarningsDialog
                        open={nmrOpen}
                        onClose={() => setNmrOpen(false)}
                        cell={cell}
                        employeeName={employeeName}
                        date={date.date}
                    />
                )}
                <Tooltip delayDuration={500}>
                    <TooltipTrigger asChild>
                        <td
                            className={`${tdClass} relative${isNmr && cell ? " cursor-pointer" : ""}`}
                            style={editingStyle}
                            onClick={() => { if (isNmr && cell) setNmrOpen(true); }}
                        >
                            {isRowSelected && (
                                <span className="absolute inset-0 bg-blue-500/10 pointer-events-none" />
                            )}
                            {label}
                            <LiveEditorBadge editors={otherEditors} colorFor={colorFor} />
                        </td>
                    </TooltipTrigger>
                    <TooltipContent side="top" className="max-w-55">
                        {tooltipContent}
                    </TooltipContent>
                </Tooltip>
            </>
        );
    }

    return (
        <>
            <Tooltip delayDuration={1000}>
                <TooltipTrigger asChild>
                    <td
                        className={`${tdClass} cursor-pointer relative`}
                        style={editingStyle}
                        onClick={() => { setOpen(true); onSelectEmployee(employeeId); }}
                        role="button"
                    >
                        {isRowSelected && !open && (
                            <span className="absolute inset-0 bg-blue-500/10 pointer-events-none" />
                        )}
                        {open && (
                            <span className="absolute inset-0 bg-blue-500/25 ring-2 ring-blue-500 ring-inset pointer-events-none" />
                        )}
                        {label}
                        <LiveEditorBadge editors={otherEditors} colorFor={colorFor} />
                    </td>
                </TooltipTrigger>
                {!open && (
                    <TooltipContent side="top" className="max-w-55">
                        {tooltipContent}
                    </TooltipContent>
                )}
            </Tooltip>
            <Dialog open={open} onOpenChange={(val) => { setOpen(val); if (!val) onSelectEmployee(null); }}>
                <DialogContent className="w-80 max-w-[92vw] p-0 gap-0 overflow-hidden [&>button.absolute]:hidden" aria-describedby={undefined}>
                    <DialogTitle className="sr-only">{employeeName} — {date.date}</DialogTitle>
                    <div className="overflow-y-auto p-4" style={{ maxHeight: 'calc(90dvh - 48px)' }}>
                        <CellEditor
                            cell={cell}
                            employeeId={employeeId}
                            employeeName={employeeName}
                            employeeRole={employeeRole}
                            avatarUrl={avatarUrl}
                            schedule={schedule}
                            date={date.date}
                            onDone={() => setOpen(false)}
                        />
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
});

function CellEditor({
    cell,
    employeeId,
    employeeName,
    employeeRole,
    avatarUrl,
    schedule,
    date,
    onDone,
}: {
    cell: Cell | undefined;
    employeeId: number;
    employeeName: string;
    employeeRole: string;
    avatarUrl: string | null;
    schedule: EmployeeSchedule | null;
    date: string;
    onDone: () => void;
}) {
    const isCreate = !cell;

    // failed_bio_* is a flag-only status: the user may still enter both time-in
    // and time-out so the total work hours get computed. Do not blank the
    // fields or suppress hours prefill based on the status.
    const effectiveInitialHours =
        cell?.hours !== null && cell?.hours !== undefined
            ? cell.overtime_approved
                ? cell.hours
                : Math.min(cell.hours, 8)
            : null;
    const initialHours = effectiveInitialHours !== null ? effectiveInitialHours.toFixed(2) : "";
    const form = useForm({
        attendance_id: cell?.attendance_id ?? 0,
        user_id: employeeId,
        shift_date: date,
        status: cell?.status ?? "non_work_day",
        hours: initialHours,
        // Create mode: do NOT prefill from schedule so the user can deliberately
        // create partial / failed-bio records without clearing fields first.
        actual_time_in: cell?.actual_time_in ?? "",
        actual_time_out: cell?.actual_time_out ?? "",
        verify: false,
        overtime_approved: cell?.overtime_approved ?? false,
        is_critical_day: cell?.is_critical_day ?? false,
        undertime_approval_reason: (cell?.undertime_approval_reason ?? null) as 'generate_points' | 'skip_points' | 'lunch_used' | null,
        undertime_approval_action: null as 'approve' | 'reject' | 'request' | null,
        is_set_home: cell?.is_set_home ?? false,
        notes: cell?.notes ?? "",
    });
    const isLeave = cell?.kind === "leave";
    const submittedRef = useRef(false);

    const [isPartialApproving, setIsPartialApproving] = useState(false);
    const [nmrCardOpen, setNmrCardOpen] = useState(false);
    const [violationsCardOpen, setViolationsCardOpen] = useState(false);

    const { can } = usePermission();
    const canApproveUndertime = can('attendance.approve_undertime');
    const canRequestUndertimeApproval = can('attendance.request_undertime_approval');

    const fmtMins = (m: number) => {
        const h = Math.floor(m / 60);
        const min = m % 60;
        return h > 0 ? `${h}h ${min}m` : `${min}m`;
    };

    const violations: Array<{ label: string; value: string; cls: string }> = [];
    if (cell) {
        if (cell.tardy_minutes > 0)
            violations.push({ label: "Tardy", value: fmtMins(cell.tardy_minutes), cls: "text-amber-600 dark:text-amber-400" });
        if (cell.undertime_minutes > 0)
            violations.push({ label: "Undertime", value: fmtMins(cell.undertime_minutes), cls: "text-orange-600 dark:text-orange-400" });
        if (cell.overtime_minutes > 0)
            violations.push({
                label: "Overtime",
                value: `${fmtMins(cell.overtime_minutes)}${cell.overtime_approved ? " ✓" : " (pending)"}`,
                cls: cell.overtime_approved ? "text-emerald-600 dark:text-emerald-400" : "text-blue-600 dark:text-blue-400",
            });
        if (cell.status === "failed_bio_in")
            violations.push({ label: "Violation", value: "No time-in biometric", cls: "text-purple-600 dark:text-purple-400" });
        if (cell.status === "failed_bio_out")
            violations.push({ label: "Violation", value: "No time-out biometric", cls: "text-purple-600 dark:text-purple-400" });
        if (cell.status === "ncns")
            violations.push({ label: "Violation", value: "No call / No show", cls: "text-red-600 dark:text-red-400" });
        if (cell.status === "half_day_absence")
            violations.push({ label: "Violation", value: "Half day absence", cls: "text-red-600 dark:text-red-400" });
        if (cell.status === "advised_absence")
            violations.push({ label: "Violation", value: "Advised absence", cls: "text-red-600 dark:text-red-400" });
    }

    // Statuses that require a specific actual time in/out
    const TIME_REQUIRED_STATUSES = new Set([
        "on_time",
        "tardy",
        "undertime",
        "undertime_more_than_hour",
        "half_day_absence",
        "failed_bio_in",
        "failed_bio_out",
        "present_no_bio",
    ]);
    // Statuses where time in/out fields make no sense (employee did not work).
    const NO_TIME_STATUSES = new Set([
        "ncns",
        "advised_absence",
    ]);
    const showTimeFields =
        !NO_TIME_STATUSES.has(form.data.status)
        && (isCreate || TIME_REQUIRED_STATUSES.has(form.data.status) || cell?.has_bio === true);

    // Preview/predicted status from schedule + times (works in both create and edit mode)
    const { halfDayThreshold } = usePage<PageProps>().props;
    const previewStatus = useMemo(() => {
        if (!schedule?.scheduled_time_in || !schedule?.scheduled_time_out) return null;
        const tin = form.data.actual_time_in;
        const tout = form.data.actual_time_out;

        // Create mode: don't push the user toward NCNS/failed_bio_* before they've
        // typed anything. Show the preview only once at least one time is entered.
        if (isCreate && !tin && !tout) return null;

        // No time in: surface NCNS / failed_bio_in violations so they still show in the card
        if (!tin) {
            if (!tout) {
                return { status: "ncns", detail: "No time in or out", overtimeMins: 0, undertimeMins: 0, violations: ["ncns"] };
            }
            return { status: "failed_bio_in", detail: "Missing time in", overtimeMins: 0, undertimeMins: 0, violations: ["failed_bio_in"] };
        }

        // Parse to minutes-since-midnight
        const toMins = (hhmm: string) => {
            const [h, m] = hhmm.split(":").map(Number);
            return h * 60 + m;
        };
        const schedIn = toMins(schedule.scheduled_time_in.slice(0, 5));
        const schedOut = toMins(schedule.scheduled_time_out.slice(0, 5));
        const actualIn = toMins(tin);
        const actualOut = tout ? toMins(tout) : null;

        // Night shift: scheduled out is next day (e.g. 23:00 → 08:00)
        const isNightShift = schedOut <= schedIn;
        const adjustedSchedOut = isNightShift ? schedOut + 1440 : schedOut;

        // For night shifts, times in the early morning (< noon) belong to "next day"
        // e.g. 00:00 → 1440, 01:00 → 1500 — so tardy vs 23:00 schedIn calculates correctly
        const adjustedActualIn = isNightShift && actualIn < 720 ? actualIn + 1440 : actualIn;
        const adjustedActualOut = actualOut !== null
            ? (isNightShift && actualOut < 720 ? actualOut + 1440 : actualOut)
            : null;

        // Tardy check
        const tardyMins = Math.max(0, adjustedActualIn - schedIn);
        // Undertime check (only when time-out provided)
        const undertimeMins = adjustedActualOut !== null
            ? Math.max(0, adjustedSchedOut - adjustedActualOut)
            : 0;
        // Overtime check
        const overtimeMins = adjustedActualOut !== null
            ? Math.max(0, adjustedActualOut - adjustedSchedOut)
            : 0;

        // Tardy threshold semantics (match AttendanceProcessor::determineTimeInStatus):
        //  tardy <= grace                  => on_time
        //  grace < tardy <= half_day       => tardy
        //  tardy > half_day                => half_day_absence
        const gracePeriod = schedule.grace_period_minutes ?? 0;
        const halfDay = halfDayThreshold ?? 15;
        const tardyStatus = tardyMins <= gracePeriod
            ? "on_time"
            : tardyMins > halfDay
                ? "half_day_absence"
                : "tardy";

        let status = "on_time";
        let detail = "";
        if (!tout) {
            // Missing time-out: surface failed_bio_out (mirrors import behavior).
            // Tardy still detected so the violations list shows both.
            status = "failed_bio_out";
            detail = tardyMins > gracePeriod
                ? `Missing time out, +${tardyMins}m late`
                : "Missing time out";
        } else if (tardyMins > gracePeriod && undertimeMins > 0) {
            status = tardyStatus;
            detail = `+${tardyMins}m tardy, ${undertimeMins}m undertime`;
        } else if (tardyMins > gracePeriod) {
            status = tardyStatus;
            detail = `+${tardyMins}m late`;
        } else if (undertimeMins > 60) {
            status = "undertime_more_than_hour";
            detail = `${undertimeMins}m undertime`;
        } else if (undertimeMins > 0) {
            status = "undertime";
            detail = `${undertimeMins}m undertime`;
        } else if (overtimeMins > 30) {
            status = "on_time";
            detail = `+${overtimeMins}m overtime`;
        } else {
            status = "on_time";
        }

        // Collect violations that generate points (excludes failed_bio_* — flag only, no points)
        const violations: string[] = [];
        if (tardyMins > halfDay) violations.push("half_day_absence");
        else if (tardyMins > gracePeriod) violations.push("tardy");
        if (undertimeMins > 60) violations.push("undertime_more_than_hour");
        else if (undertimeMins > 0) violations.push("undertime");

        return { status, detail, overtimeMins, undertimeMins, violations };
    }, [schedule, form.data.actual_time_in, form.data.actual_time_out, isCreate, halfDayThreshold]);

    const hasViolation = violations.length > 0 || (previewStatus?.violations.length ?? 0) > 0;

    // In edit mode: auto-sync form status whenever time fields change.
    // Skip the first render so we don't override the existing saved status on open.
    const isFirstStatusSync = useRef(true);
    useEffect(() => {
        if (isFirstStatusSync.current) {
            isFirstStatusSync.current = false;
            return;
        }
        if (!previewStatus) return;
        form.setData('status', previewStatus.status);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.actual_time_in, form.data.actual_time_out]);

    const STATUS_PREVIEW_LABELS: Record<string, { label: string; cls: string }> = {
        on_time: { label: "On Time", cls: "text-emerald-600 dark:text-emerald-400" },
        tardy: { label: "Tardy", cls: "text-amber-600 dark:text-amber-400" },
        undertime: { label: "Undertime", cls: "text-orange-600 dark:text-orange-400" },
        undertime_more_than_hour: { label: "Undertime >1hr", cls: "text-orange-600 dark:text-orange-500" },
        half_day_absence: { label: "Half Day Absence", cls: "text-red-600 dark:text-red-400" },
        failed_bio_in: { label: "Failed Bio In", cls: "text-purple-600 dark:text-purple-400" },
        failed_bio_out: { label: "Failed Bio Out", cls: "text-purple-600 dark:text-purple-400" },
        present_no_bio: { label: "Present (No Bio)", cls: "text-slate-600 dark:text-slate-400" },
        ncns: { label: "NCNS (Absent)", cls: "text-red-600 dark:text-red-500" },
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (submittedRef.current) return;

        submittedRef.current = true;
        const url = isCreate
            ? spreadsheetCreateCell().url
            : spreadsheetUpdateCell().url;
        if (isCreate) {
            // Backend auto-determines status — only send time/hours fields
            form.transform((data) => ({
                user_id: data.user_id,
                shift_date: data.shift_date,
                actual_time_in: data.actual_time_in,
                actual_time_out: data.actual_time_out,
                hours: data.hours,
                overtime_approved: data.overtime_approved,
                is_critical_day: data.is_critical_day,
                undertime_approval_reason: data.undertime_approval_reason,
                is_set_home: data.is_set_home,
                notes: data.notes,
            }));
        } else {
            // Always mark as verified when saving from the spreadsheet
            form.transform((data) => ({
                ...data,
                verify: true,
            }));
        }
        form.post(url, {
            preserveScroll: true,
            onFinish: () => {
                submittedRef.current = false;
            },
            onSuccess: () => onDone(),
        });
    };



    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <div className="space-y-0.5 min-w-0">
                    <div className="text-sm font-semibold truncate">{employeeName}</div>
                    <div className="text-xs text-muted-foreground">
                        {new Date(date + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                    </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                    <Avatar className="h-10 w-10">
                        <AvatarImage src={avatarUrl ?? undefined} alt={employeeName} />
                        <AvatarFallback className="text-xs">
                            {employeeName.split(',').map((p) => p.trim()[0]).reverse().join('')}
                        </AvatarFallback>
                    </Avatar>
                    <button
                        type="button"
                        onClick={onDone}
                        className="rounded-sm opacity-60 hover:opacity-100 transition-opacity"
                        aria-label="Close"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>
            </div>

            {/* NMR / Suspicious Pattern card */}
            {cell && (cell.warnings?.length ?? 0) > 0 && (
                <div className="rounded border border-amber-300 bg-amber-50 dark:border-amber-600 dark:bg-amber-950/40">
                    <button
                        type="button"
                        onClick={() => setNmrCardOpen((v) => !v)}
                        className="flex w-full items-center gap-1.5 px-2.5 py-2 text-left"
                    >
                        <AlertCircle className="h-3.5 w-3.5 shrink-0 text-amber-500" />
                        <span className="flex-1 text-[11px] font-semibold text-amber-900 dark:text-amber-100">Suspicious Pattern Detected</span>
                        <span className="text-[10px] text-amber-600 dark:text-amber-400 mr-0.5">{cell.warnings.length}</span>
                        <ChevronDown className={`h-3 w-3 text-amber-500 transition-transform ${nmrCardOpen ? 'rotate-180' : ''}`} />
                    </button>
                    {nmrCardOpen && (
                        <div className="space-y-1 px-2.5 pb-2.5 border-t border-amber-200 dark:border-amber-700 pt-2">
                            {cell.warnings.map((w, i) => (
                                <p key={i} className="text-[10px] text-amber-800 dark:text-amber-200 leading-snug pl-5">
                                    • {typeof w === 'string' ? w : w.message}
                                </p>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* Schedule info */}
            <div className="rounded border bg-muted/40 p-2 text-[11px] text-foreground">
                <div className="mb-1 font-semibold uppercase text-[10px] text-muted-foreground tracking-wide">
                    Schedule
                </div>
                {schedule ? (
                    <div className="grid grid-cols-2 gap-x-2 gap-y-0.5">
                        <span className="text-muted-foreground">Shift:</span>
                        <span className="font-mono">{fmtShiftType(schedule.shift_type)}</span>
                        <span className="text-muted-foreground">In:</span>
                        <span className="font-mono">{fmtTime(schedule.scheduled_time_in)}</span>
                        <span className="text-muted-foreground">Out:</span>
                        <span className="font-mono">{fmtTime(schedule.scheduled_time_out)}</span>
                        {schedule.campaign && (
                            <>
                                <span className="text-muted-foreground">Campaign:</span>
                                <span>{schedule.campaign} <span className="text-muted-foreground">({employeeRole})</span></span>
                            </>
                        )}
                    </div>
                ) : (
                    <div className="text-muted-foreground">No active schedule.</div>
                )}
            </div>

            {/* Biometric info (only when there's bio data) */}
            {cell?.has_bio && (
                <div
                    className={`rounded border p-2 text-[11px] ${cell.unverified_bio
                        ? "border-yellow-400 bg-yellow-50 text-yellow-900 dark:border-yellow-600 dark:bg-yellow-950/40 dark:text-yellow-100"
                        : "border-emerald-400 bg-emerald-50 text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-100"
                        }`}
                >
                    <div className="mb-1 font-semibold uppercase text-[10px] tracking-wide">
                        Biometric {cell.unverified_bio ? "(Unverified)" : "(Verified)"}
                    </div>
                    <div className="grid grid-cols-2 gap-x-2 gap-y-0.5">
                        <span className="opacity-70">Bio In:</span>
                        <span className="font-mono">{fmtTime(cell.actual_time_in)}</span>
                        <span className="opacity-70">Bio Out:</span>
                        <span className="font-mono">{fmtTime(cell.actual_time_out)}</span>
                    </div>
                </div>
            )}

            {isLeave && (
                <div className="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                    This cell is linked to a leave request ({cell?.code}). Edit the
                    leave request directly to change leave details.
                </div>
            )}

            {/* Partial-day SL informational banner */}
            {!isLeave && cell?.is_partial_day_sl && (
                <div className="rounded border border-cyan-300 bg-cyan-50 p-2 text-xs text-cyan-900 dark:border-cyan-700 dark:bg-cyan-950/40 dark:text-cyan-100">
                    <div className="font-semibold">Partial-day Absence (SL with Undertime)</div>
                    <p className="mt-0.5 text-[11px] opacity-90">
                        This day is covered by an approved Sick Leave. Worked hours are counted
                        normally, and any tardy/undertime point generated here is automatically
                        excused via the medical certificate — no approval action needed.
                    </p>
                </div>
            )}

            {/* Partial approval — for records with time-in but no time-out, not yet fully verified */}
            {!isCreate && cell && !cell.actual_time_out && cell.actual_time_in && !cell.verified && (
                <div className="rounded border border-orange-400 bg-orange-50 dark:bg-orange-950/30 p-2 text-[11px] space-y-2">
                    <div className="flex items-center gap-1.5 font-semibold text-orange-700 dark:text-orange-300">
                        <Clock className="h-3 w-3" />
                        <span>Time-out missing</span>
                        <span className="text-orange-500/70 dark:text-orange-200/50 font-normal ml-auto">Points from time-in status</span>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => {
                            setIsPartialApproving(true);
                            router.post(attendancePartialApprove(cell.attendance_id).url, {}, {
                                preserveScroll: true,
                                onFinish: () => { setIsPartialApproving(false); onDone(); },
                            });
                        }}
                        disabled={isPartialApproving}
                        className="h-7 text-xs bg-orange-600 hover:bg-orange-700 w-full"
                    >
                        <CheckCircle className="h-3 w-3 mr-1.5" />
                        {isPartialApproving ? 'Saving...' : 'Partially Approve'}
                    </Button>
                </div>
            )}

            {/* Partial approval — create mode: time-in or time-out missing (XOR) */}
            {isCreate && (
                (!!form.data.actual_time_in !== !!form.data.actual_time_out) && (
                    <div className="rounded border border-orange-400 bg-orange-50 dark:bg-orange-950/30 p-2 text-[11px] space-y-2">
                        <div className="flex items-center gap-1.5 font-semibold text-orange-700 dark:text-orange-300">
                            <Clock className="h-3 w-3" />
                            <span>{form.data.actual_time_out ? 'Time-in missing' : 'Time-out missing'}</span>
                            <span className="text-orange-500/70 dark:text-orange-200/50 font-normal ml-auto">
                                Points from {form.data.actual_time_out ? 'time-out' : 'time-in'} status
                            </span>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            onClick={(e) => submit(e as unknown as React.FormEvent)}
                            disabled={form.processing}
                            className="h-7 text-xs bg-orange-600 hover:bg-orange-700 w-full"
                        >
                            <CheckCircle className="h-3 w-3 mr-1.5" />
                            {form.processing ? 'Saving...' : 'Partially Approve'}
                        </Button>
                    </div>
                )
            )}

            {/* Partially verified indicator — time-in only, waiting for time-out */}
            {!isCreate && cell?.is_partially_verified && !cell.actual_time_out && (
                <div className="rounded border border-orange-400 bg-orange-50 dark:bg-orange-950/20 p-2 text-[11px] space-y-1">
                    <div className="flex items-center gap-1.5 font-semibold text-orange-700 dark:text-orange-300">
                        <Clock className="h-3 w-3 animate-pulse" />
                        <span>Partially verified — awaiting time-out</span>
                    </div>
                </div>
            )}

            {violations.length > 0 && (
                <div className="rounded border border-red-200 bg-red-50 dark:border-red-400/40 dark:bg-red-950/20 p-2 text-[11px]">
                    <div className="mb-1 font-semibold uppercase text-[10px] text-muted-foreground tracking-wide">
                        Violations / Notes
                    </div>
                    <div className="grid grid-cols-2 gap-x-2 gap-y-0.5">
                        {violations.map((v) => (
                            <>
                                <span className="text-muted-foreground">{v.label}:</span>
                                <span className={`font-mono font-semibold ${v.cls}`}>{v.value}</span>
                            </>
                        ))}
                    </div>
                </div>
            )}

            {/* Critical Working Day — only shown when violations are detected */}
            {hasViolation && (
                <div className="rounded border border-purple-200 bg-purple-50 dark:border-purple-400/40 dark:bg-purple-950/20 p-2 text-[11px]">
                    <div className="flex items-start gap-2">
                        <Checkbox
                            id="cell-critical-day"
                            checked={form.data.is_critical_day}
                            onCheckedChange={(checked) => form.setData("is_critical_day", checked === true)}
                            className="mt-0.5"
                        />
                        <div className="space-y-0.5">
                            <label htmlFor="cell-critical-day" className="cursor-pointer font-medium leading-tight text-purple-900 dark:text-purple-200">
                                Critical Working Day
                            </label>
                            <p className="text-[10px] text-purple-600/70 dark:text-purple-400/70">
                                {form.data.is_critical_day
                                    ? "×2 point multiplier active — all violations doubled"
                                    : "Enable to apply ×2 point multiplier to all violations"}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Overtime approval — edit mode: show when OT exists or entered hours exceed 8 */}
            {!isCreate && (() => {
                const enteredHours = parseFloat(form.data.hours || "0");
                const hoursOtMins = enteredHours > 8 ? Math.round((enteredHours - 8) * 60) : 0;
                const otMins = Math.max(cell?.overtime_minutes ?? 0, hoursOtMins);
                if (otMins <= 0) return null;
                return (
                    <div className="rounded border border-blue-200 bg-blue-50 dark:border-blue-400/40 dark:bg-blue-950/20 p-2 text-[11px]">
                        <div className="mb-1 font-semibold uppercase text-[10px] text-muted-foreground tracking-wide">
                            Overtime Approval
                        </div>
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id="cell-ot-approved"
                                checked={form.data.overtime_approved}
                                onCheckedChange={(checked) => form.setData("overtime_approved", checked === true)}
                                className="mt-0.5"
                            />
                            <div className="space-y-0.5">
                                <label htmlFor="cell-ot-approved" className="cursor-pointer font-medium leading-tight">
                                    Approve {fmtMins(otMins)} overtime
                                </label>
                                <p className="text-[10px] text-muted-foreground">
                                    {form.data.overtime_approved
                                        ? <span className="text-emerald-600 dark:text-emerald-400">OT hours will be included in total hours worked.</span>
                                        : <span className="text-blue-600/80 dark:text-blue-400/80">OT hours are not counted until approved.</span>
                                    }
                                </p>
                            </div>
                        </div>
                    </div>
                );
            })()}

            {/* Overtime approval — create mode: show when preview detects OT or entered hours exceed 8 */}
            {isCreate && (() => {
                const enteredHours = parseFloat(form.data.hours || "0");
                const hoursOtMins = enteredHours > 8 ? Math.round((enteredHours - 8) * 60) : 0;
                const otMins = Math.max(previewStatus?.overtimeMins ?? 0, hoursOtMins);
                if (otMins <= 0) return null;
                return (
                    <div className="rounded border border-blue-200 bg-blue-50 dark:border-blue-400/40 dark:bg-blue-950/20 p-2 text-[11px]">
                        <div className="mb-1 font-semibold uppercase text-[10px] text-muted-foreground tracking-wide">
                            Overtime Approval
                        </div>
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id="cell-ot-approved-create"
                                checked={form.data.overtime_approved}
                                onCheckedChange={(checked) => form.setData("overtime_approved", checked === true)}
                                className="mt-0.5"
                            />
                            <div className="space-y-0.5">
                                <label htmlFor="cell-ot-approved-create" className="cursor-pointer font-medium leading-tight">
                                    Approve {fmtMins(otMins)} overtime
                                </label>
                                <p className="text-[10px] text-muted-foreground">
                                    {form.data.overtime_approved
                                        ? <span className="text-emerald-600 dark:text-emerald-400">OT hours will be included in total hours worked.</span>
                                        : <span className="text-blue-600/80 dark:text-blue-400/80">OT hours are not counted until approved.</span>
                                    }
                                </p>
                            </div>
                        </div>
                    </div>
                );
            })()}

            {/* Undertime approval — edit mode: show when undertime > 30 min */}
            {!isCreate && !cell?.is_partial_day_sl && (cell?.undertime_minutes ?? 0) > 30 && (() => {
                const approvalStatus = cell!.undertime_approval_status;
                const approvalReason = cell!.undertime_approval_reason;
                const action = form.data.undertime_approval_action;
                const reason = form.data.undertime_approval_reason;

                const ActionButtons = ({ requestMode = false }: { requestMode?: boolean }) => (
                    <div className="grid grid-cols-2 gap-1.5">
                        <button
                            type="button"
                            onClick={() => { form.setData('undertime_approval_reason', 'generate_points'); form.setData('undertime_approval_action', requestMode ? 'request' : 'reject'); }}
                            className={`flex items-center justify-center gap-1 rounded border px-2 py-1 text-[11px] font-medium transition-colors ${reason === 'generate_points'
                                ? 'border-foreground bg-foreground text-background'
                                : 'border-border bg-background hover:bg-muted'
                                }`}
                        >
                            <Check className="h-3 w-3" />{requestMode ? 'Suggest: Points' : 'Generate Points'}
                        </button>
                        <button
                            type="button"
                            onClick={() => { form.setData('undertime_approval_reason', 'lunch_used'); form.setData('undertime_approval_action', requestMode ? 'request' : 'approve'); }}
                            className={`flex items-center justify-center gap-1 rounded border px-2 py-1 text-[11px] font-medium transition-colors ${reason === 'lunch_used'
                                ? 'border-foreground bg-foreground text-background'
                                : 'border-border bg-background hover:bg-muted'
                                }`}
                        >
                            <Clock className="h-3 w-3" />{requestMode ? 'Suggest: Lunch' : 'Lunch Used'}
                        </button>
                    </div>
                );

                const requestMode = !canApproveUndertime && canRequestUndertimeApproval;
                const hint = action ? (
                    <p className="text-[10px] text-amber-700 dark:text-amber-300">
                        {reason === 'lunch_used' && (requestMode ? '• Suggesting lunch used' : '✓ Will approve — +1 hr credited')}
                        {reason === 'generate_points' && (requestMode ? '• Suggesting generate points' : '• Will generate points')}
                    </p>
                ) : null;

                return (
                    <div className="rounded border border-amber-200 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/40 p-2.5 space-y-2">
                        {/* Header */}
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-1.5">
                                <Clock className="h-3.5 w-3.5 text-amber-600" />
                                <span className="text-[11px] font-semibold text-amber-900 dark:text-amber-100">
                                    Undertime: {fmtMins(cell!.undertime_minutes)}
                                </span>
                            </div>
                            {(canApproveUndertime || canRequestUndertimeApproval) && (
                                <div className="flex items-center gap-1.5">
                                    <span className="text-[10px] text-amber-700 dark:text-amber-300">Set Home</span>
                                    <Switch
                                        id="edit-set-home"
                                        checked={form.data.is_set_home}
                                        onCheckedChange={(checked) => {
                                            form.setData('is_set_home', checked);
                                            if (checked) form.setData('undertime_approval_reason', null);
                                        }}
                                    />
                                </div>
                            )}
                        </div>

                        {form.data.is_set_home ? (
                            <p className="text-[11px] text-green-700 dark:text-green-400">✓ Sent home early — no undertime points</p>
                        ) : approvalStatus === 'approved' ? (
                            canApproveUndertime ? (
                                <div className="space-y-1.5">
                                    <p className="text-[10px] text-green-700 dark:text-green-400">
                                        ✓ Approved: {approvalReason === 'lunch_used' ? 'Lunch used' : 'Points generated'} — select below to change
                                    </p>
                                    <ActionButtons />{hint}
                                </div>
                            ) : (
                                <p className="text-[11px] text-green-700 dark:text-green-400">✓ {approvalReason === 'lunch_used' ? 'Lunch used — +1 hr credited' : approvalReason === 'skip_points' ? 'Set home — no points' : 'Points generated'}</p>
                            )
                        ) : approvalStatus === 'rejected' ? (
                            canApproveUndertime ? (
                                <div className="space-y-1.5">
                                    <p className="text-[10px] text-red-600 dark:text-red-400">✗ Rejected — select action to update</p>
                                    <ActionButtons />{hint}
                                </div>
                            ) : (
                                <p className="text-[11px] text-red-600 dark:text-red-400">✗ Rejected — points will be generated</p>
                            )
                        ) : approvalStatus === 'pending' ? (
                            canApproveUndertime ? (
                                <div className="space-y-1.5">
                                    {approvalReason && <p className="text-[10px] text-blue-600 dark:text-blue-400">⭐ Suggested: {approvalReason === 'lunch_used' ? 'Lunch used' : 'Generate points'}</p>}
                                    <ActionButtons />{hint}
                                </div>
                            ) : (
                                <div className="flex items-center gap-1.5">
                                    <Clock className="h-3 w-3 text-yellow-600 animate-pulse" />
                                    <p className="text-[11px] text-yellow-700 dark:text-yellow-400">Pending Admin/HR approval</p>
                                </div>
                            )
                        ) : canApproveUndertime ? (
                            <div className="space-y-1.5"><ActionButtons />{hint}</div>
                        ) : canRequestUndertimeApproval ? (
                            <div className="space-y-1.5"><ActionButtons requestMode />{hint}</div>
                        ) : (
                            <p className="text-[11px] text-amber-700 dark:text-amber-300">Points will be generated</p>
                        )}
                    </div>
                );
            })()}

            {isCreate && (previewStatus?.undertimeMins ?? 0) > 30 && (
                <div className="space-y-2 p-3 bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 rounded-lg">
                    {/* Header row: label + Set Home toggle */}
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-1.5">
                            <Clock className="h-3.5 w-3.5 text-amber-600" />
                            <span className="text-xs font-medium text-amber-900 dark:text-amber-100">
                                Undertime: {fmtMins(previewStatus!.undertimeMins)}
                            </span>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <Label htmlFor="create-set-home" className="text-[11px] text-amber-700 dark:text-amber-300 cursor-pointer">
                                Set Home
                            </Label>
                            <Switch
                                id="create-set-home"
                                checked={form.data.is_set_home}
                                onCheckedChange={(checked) => {
                                    form.setData('is_set_home', checked);
                                    if (checked) form.setData('undertime_approval_reason', null);
                                }}
                            />
                        </div>
                    </div>

                    {form.data.is_set_home ? (
                        <p className="text-xs text-green-700 dark:text-green-400">
                            ✓ Employee sent home early - no undertime points
                        </p>
                    ) : (
                        <div className="space-y-2">
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={form.data.undertime_approval_reason === 'generate_points' ? 'default' : 'outline'}
                                    onClick={() => form.setData('undertime_approval_reason', 'generate_points')}
                                    className="h-7 text-xs"
                                >
                                    <Check className="h-3 w-3 mr-1" />
                                    Generate Points
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={form.data.undertime_approval_reason === 'lunch_used' ? 'default' : 'outline'}
                                    onClick={() => form.setData('undertime_approval_reason', 'lunch_used')}
                                    className="h-7 text-xs"
                                >
                                    <Clock className="h-3 w-3 mr-1" />
                                    Lunch Used
                                </Button>
                                {form.data.undertime_approval_reason !== null && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => form.setData('undertime_approval_reason', null)}
                                        className="h-7 text-xs text-muted-foreground"
                                    >
                                        Clear
                                    </Button>
                                )}
                            </div>
                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                {form.data.undertime_approval_reason === 'lunch_used' && '✓ No points generated — employee worked through lunch (+1hr credited to total hours)'}
                                {form.data.undertime_approval_reason === 'generate_points' && '• Points will be generated'}
                                {form.data.undertime_approval_reason === null && 'No selection — points will be generated by default'}
                            </p>
                        </div>
                    )}
                </div>
            )}

            {/* Detected Violations */}
            {previewStatus && previewStatus.violations.length > 0 && previewStatus.status !== "on_time" && (
                <div className="rounded-lg border bg-red-50 border-red-200 dark:bg-red-950/20 dark:border-red-800">
                    <button
                        type="button"
                        onClick={() => setViolationsCardOpen((v) => !v)}
                        className="flex w-full items-center gap-2 px-2.5 py-2 text-left"
                    >
                        <AlertCircle className="h-3.5 w-3.5 text-red-600" />
                        <span className="flex-1 text-xs font-medium text-red-800 dark:text-red-400">Detected Violations</span>
                        <span className="text-[10px] text-red-500 mr-0.5">{previewStatus.violations.length}</span>
                        <ChevronDown className={`h-3 w-3 text-red-500 transition-transform ${violationsCardOpen ? 'rotate-180' : ''}`} />
                    </button>
                    {violationsCardOpen && (
                        <div className="px-2.5 pb-2.5 border-t border-red-200 dark:border-red-800 pt-2 space-y-1">
                            {previewStatus.violations.map((violation, index) => (
                                <div key={violation} className="flex justify-between items-center text-[11px]">
                                    <span className={index === 0 ? "font-medium text-red-700 dark:text-red-400" : "text-red-600 dark:text-red-500"}>
                                        {index === 0 ? "▶ " : "  "}{violation.replace(/_/g, " ").replace(/\b\w/g, (l) => l.toUpperCase())}
                                        {index === 0 && " (Primary)"}
                                        {index === 1 && " (Secondary)"}
                                    </span>
                                    <Badge variant="outline" className="text-red-600 border-red-400 text-[10px] h-4 px-1.5">
                                        {getPointValue(violation).toFixed(2)} pts
                                    </Badge>
                                </div>
                            ))}
                            <p className="text-[10px] text-red-600 dark:text-red-500 mt-1.5 pt-1.5 border-t border-red-200 dark:border-red-800">
                                Higher point violation is selected as primary status. Points will be generated for both violations.
                            </p>
                        </div>
                    )}
                </div>
            )}

            {isCreate && (
                <div className="rounded border border-blue-200 bg-blue-50 dark:border-blue-400/40 dark:bg-blue-950/20 p-2 text-[11px] text-blue-700 dark:text-blue-300">
                    {previewStatus ? (
                        <div className="flex items-center justify-between gap-2">
                            <span>Expected status:</span>
                            <span>
                                <span className={`font-semibold ${STATUS_PREVIEW_LABELS[previewStatus.status]?.cls ?? "text-blue-700 dark:text-blue-300"}`}>
                                    {STATUS_PREVIEW_LABELS[previewStatus.status]?.label ?? previewStatus.status}
                                </span>
                                {previewStatus.detail && (
                                    <span className="ml-1.5 text-blue-600/70 dark:text-blue-400/70">({previewStatus.detail})</span>
                                )}
                            </span>
                        </div>
                    ) : (
                        <span>Status will be auto-determined from the time in/out and schedule, or pick one manually below.</span>
                    )}
                </div>
            )}

            <div className="space-y-1">
                <Label htmlFor="cell-status" className="text-xs">
                    Status
                </Label>
                <Select
                    value={form.data.status}
                    onValueChange={(v) => form.setData("status", v)}
                    disabled={isLeave}
                >
                    <SelectTrigger id="cell-status">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {STATUS_OPTIONS.map((s) => (
                            <SelectItem key={s.value} value={s.value}>
                                {s.label}
                            </SelectItem>
                        ))}
                        {isLeave && (
                            <SelectItem value="on_leave">On Leave</SelectItem>
                        )}
                    </SelectContent>
                </Select>
                {form.errors.status && (
                    <div className="text-xs text-destructive">
                        {form.errors.status}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-2 gap-2" hidden={!showTimeFields}>
                <div className="space-y-1">
                    <Label htmlFor="cell-time-in" className="text-xs">
                        Time In
                    </Label>
                    <Input
                        id="cell-time-in"
                        type="time"
                        value={form.data.actual_time_in}
                        onChange={(e) => {
                            form.setData("actual_time_in", e.target.value);
                            if (e.target.value) form.setData("hours", "");
                        }}
                    />
                    {form.errors.actual_time_in && (
                        <div className="text-xs text-destructive">
                            {form.errors.actual_time_in}
                        </div>
                    )}
                </div>
                <div className="space-y-1">
                    <Label htmlFor="cell-time-out" className="text-xs">
                        Time Out
                    </Label>
                    <Input
                        id="cell-time-out"
                        type="time"
                        value={form.data.actual_time_out}
                        onChange={(e) => {
                            form.setData("actual_time_out", e.target.value);
                            if (e.target.value) form.setData("hours", "");
                        }}
                    />
                    {form.errors.actual_time_out && (
                        <div className="text-xs text-destructive">
                            {form.errors.actual_time_out}
                        </div>
                    )}
                </div>
            </div>

            <div className="space-y-1" hidden={!showTimeFields}>
                <Label htmlFor="cell-hours" className="text-xs">
                    Hours worked
                </Label>
                <Input
                    id="cell-hours"
                    type="number"
                    step="0.01"
                    min="0"
                    max="24"
                    value={form.data.hours}
                    onChange={(e) => {
                        const val = e.target.value;
                        form.setData("hours", val);
                        if (val) {
                            const parsed = parseFloat(val);
                            form.setData("status", "on_time");
                            form.setData("overtime_approved", parsed > 8);
                            if (schedule?.scheduled_time_in) {
                                // Auto-fill time_in from schedule
                                const timeIn = schedule.scheduled_time_in.slice(0, 5);
                                form.setData("actual_time_in", timeIn);
                                // time_out = time_in + net hours + 1hr lunch
                                const [h, m] = timeIn.split(":").map(Number);
                                const outTotalMins = h * 60 + m + Math.round((parsed + 1) * 60);
                                const outH = Math.floor(outTotalMins / 60) % 24;
                                const outM = outTotalMins % 60;
                                form.setData("actual_time_out", `${String(outH).padStart(2, "0")}:${String(outM).padStart(2, "0")}`);
                            } else {
                                form.setData("actual_time_in", "");
                                form.setData("actual_time_out", "");
                            }
                        }
                    }}
                    placeholder="e.g. 8.00"
                />
                {form.errors.hours && (
                    <div className="text-xs text-destructive">
                        {form.errors.hours}
                    </div>
                )}
            </div>

            {/* Notes — reason why this record exists (tardy, missed bio, etc.) */}
            <div className="space-y-1">
                <Label htmlFor="cell-notes" className="text-xs">
                    Notes <span className="text-muted-foreground font-normal">(reason / explanation)</span>
                </Label>
                <Textarea
                    id="cell-notes"
                    rows={2}
                    placeholder="e.g. Heavy traffic, power outage, forgot to tap out…"
                    value={form.data.notes}
                    onChange={(e) => form.setData("notes", e.target.value)}
                    className="text-xs resize-none"
                />
                {form.errors.notes && (
                    <div className="text-xs text-destructive">{form.errors.notes}</div>
                )}
            </div>

            {!isCreate && cell?.unverified_bio && (
                <div className="text-[10px] text-emerald-500">
                    ✓ Saving will automatically mark this record as verified.
                </div>
            )}

            <div className="flex justify-end gap-2 pt-1">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onDone}
                    disabled={form.processing}
                >
                    Cancel
                </Button>
                <Button type="submit" size="sm" disabled={form.processing}>
                    {form.processing ? "Saving..." : isCreate ? "Create" : "Save"}
                </Button>
            </div>
            <div className="border-t pt-2 text-[11px] text-muted-foreground">
                Need a different change? Open the{" "}
                <a
                    href={
                        !isCreate && cell
                            ? `${attendanceReview().url}?verify=${cell.attendance_id}`
                            : attendanceReview().url
                    }
                    target="_blank"
                    rel="noopener noreferrer"
                    className="underline"
                    onClick={onDone}
                >
                    full editor
                </a>
                .
            </div>
        </form>
    );
}

// =====================================================================
// Active editors panel (live presence list shown in the page header)
// =====================================================================

function ActiveEditorsPanel({ users, selfId, colorFor }: { users: PresenceUser[]; selfId: number | null; colorFor: (id: number) => string }) {
    if (users.length === 0) {
        return (
            <div className="flex items-center gap-2 rounded-md border bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground">
                <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full rounded-full bg-slate-400 opacity-60" />
                </span>
                Connecting…
            </div>
        );
    }

    const sorted = [...users].sort((a, b) => {
        if (a.id === selfId) return -1;
        if (b.id === selfId) return 1;
        return a.name.localeCompare(b.name);
    });

    return (
        <TooltipProvider delayDuration={150}>
            <div className="flex items-center gap-2 rounded-md border bg-card px-3 py-1.5 shadow-sm">
                <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                </span>
                <span className="text-xs font-medium text-foreground">
                    {users.length} active
                </span>
                <div className="flex -space-x-2">
                    {sorted.slice(0, 6).map((u) => {
                        const color = colorFor(u.id);
                        return (
                            <Tooltip key={u.id}>
                                <TooltipTrigger asChild>
                                    <span>
                                        <Avatar
                                            className="h-6 w-6 border-2 border-background"
                                            style={{ boxShadow: `0 0 0 2px ${color}` }}
                                        >
                                            {u.avatar_url && <AvatarImage src={u.avatar_url} alt={u.name} />}
                                            <AvatarFallback
                                                className="text-[10px] text-white"
                                                style={{ backgroundColor: color }}
                                            >
                                                {u.name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase()}
                                            </AvatarFallback>
                                        </Avatar>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent side="bottom">
                                    <span className="flex items-center gap-1.5">
                                        <span
                                            className="inline-block h-2 w-2 rounded-full"
                                            style={{ backgroundColor: color }}
                                        />
                                        {u.name}{u.id === selfId ? ' (you)' : ''}
                                    </span>
                                </TooltipContent>
                            </Tooltip>
                        );
                    })}
                    {sorted.length > 6 && (
                        <span className="flex h-6 min-w-6 items-center justify-center rounded-full border-2 border-background bg-muted px-1 text-[10px] font-medium">
                            +{sorted.length - 6}
                        </span>
                    )}
                </div>
            </div>
        </TooltipProvider>
    );
}

// =====================================================================
// Legend
// =====================================================================

function Legend() {
    const items: Array<{ label: string; desc: string; cls: string }> = [
        { label: "Hours", desc: "Verified attendance with hours", cls: "bg-pink-50 text-pink-900 border" },
        { label: "Tardy", desc: "Late arrival", cls: "bg-amber-100 text-amber-900 border" },
        { label: "ABS", desc: "Absent / Half-day / NCNS / Advised", cls: "bg-red-500 text-white" },
        { label: "OFF", desc: "Non-work day / Day off", cls: "bg-slate-400 text-white" },
        { label: "BIO", desc: "Unverified biometric — needs review", cls: "bg-purple-400 text-white" },
        { label: "PART", desc: "Partially verified — time-out pending", cls: "bg-orange-400 text-white font-semibold" },
        { label: "VL", desc: "Vacation Leave", cls: "bg-green-500 text-white" },
        { label: "SL", desc: "Sick Leave", cls: "bg-cyan-400 text-white" },
        { label: "P-SL", desc: "Partial-day Absence (SL with Undertime) — worked hours counted", cls: "bg-cyan-200 text-cyan-900 font-semibold" },
        { label: "ML", desc: "Maternity / Paternity Leave", cls: "bg-fuchsia-500 text-white" },
        { label: "LOA", desc: "Leave of Absence", cls: "bg-rose-400 text-white" },
        { label: "UPTO", desc: "Unpaid Time Off", cls: "bg-sky-500 text-white" },
        { label: "BL", desc: "Birthday Leave", cls: "bg-indigo-500 text-white" },
        { label: "SPL", desc: "Special Leave", cls: "bg-teal-500 text-white" },
        { label: "NMR", desc: "Needs Manual Review", cls: "bg-amber-400 text-white font-semibold" },
    ];
    return (
        <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
            <span className="text-muted-foreground mr-1">Legend:</span>
            {items.map((it) => (
                <span
                    key={it.label}
                    title={it.desc}
                    className={`inline-flex items-center rounded px-1.5 py-0.5 font-mono cursor-default ${it.cls}`}
                >
                    {it.label}
                </span>
            ))}
        </div>
    );
}
