import { Head, router, useForm, usePage } from "@inertiajs/react";
import { useMemo, useEffect, useRef, useState, memo } from "react";
import AppLayout from "@/layouts/app-layout";
import { type SharedData } from "@/types";
import { useFlashMessage, usePageMeta, usePermission } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
import { Check, CheckCircle, ChevronLeft, ChevronRight, ChevronsUpDown, Clock, Search } from "lucide-react";
import {
    hub as attendanceHub,
    review as attendanceReview,
    spreadsheet as attendanceSpreadsheet,
    partialApprove as attendancePartialApprove,
} from "@/routes/attendance";

import { Checkbox } from "@/components/ui/checkbox";
import { Switch } from "@/components/ui/switch";

// =====================================================================
// Types
// =====================================================================

type CellKind = "empty" | "hours" | "leave" | "absent" | "off" | "bio";

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
    is_partially_verified: boolean;
    undertime_approval_status: 'pending' | 'approved' | 'rejected' | null;
    undertime_approval_reason: 'generate_points' | 'skip_points' | 'lunch_used' | null;
}

interface EmployeeSchedule {
    shift_type: string | null;
    scheduled_time_in: string | null;
    scheduled_time_out: string | null;
    work_days: string[] | null;
    campaign: string | null;
}

interface Employee {
    id: number;
    name: string;
    role: string;
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
}

interface Campaign {
    id: number;
    name: string;
}

interface PageProps extends SharedData {
    groups: Group[];
    days: DayMeta[];
    month: number;
    year: number;
    campaigns: Campaign[];
    teamLeadCampaignIds?: number[];
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
        // Tardy/undertime statuses: show exact hours so the shortfall is visible
        if (SHOW_EXACT_HOURS_STATUSES.has(cell.status) || cell.tardy_minutes > 0 || cell.undertime_minutes > 0) {
            const exact = cell.hours.toFixed(2);
            return exact.endsWith(".00") ? String(Math.round(cell.hours)) : exact;
        }
        // Approved overtime (≥1 hr, or any approved OT): show exact hours
        if (cell.overtime_approved || cell.overtime_minutes >= 60) {
            const exact = cell.hours.toFixed(2);
            return exact.endsWith(".00") ? String(Math.round(cell.hours)) : exact;
        }
        return String(Math.round(cell.hours));
    }
    return "";
};

const unverifiedRing = (cell: Cell | undefined): string =>
    cell?.unverified_bio
        ? "ring-2 ring-inset ring-yellow-400 dark:ring-yellow-500"
        : "";

// =====================================================================
// Page
// =====================================================================

const MONTH_NAMES = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
];

export default function AttendanceSpreadsheet() {
    const {
        groups,
        days,
        month,
        year,
        campaigns,
        filters,
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
        router.get(attendanceSpreadsheet().url, params, {
            preserveState: true,
            preserveScroll: true,
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full min-w-0 max-w-full flex-1 flex-col gap-3 overflow-hidden rounded-xl p-3">
                <PageHeader
                    title="Attendance Spreadsheet"
                    description={`Per-employee × per-day grid for ${MONTH_NAMES[month - 1]} ${year} — ${totalEmployees} employees`}
                />

                {/* Toolbar */}
                <form onSubmit={handleApplyFilters} className="flex flex-col gap-3">
                    {/* Row 1: month nav + filters */}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-[auto_1fr_minmax(0,220px)_auto]">
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
                                    className="w-full justify-between font-normal"
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
                            <SelectTrigger id="ss-campaign" className="w-full">
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
                <div className="min-h-0 flex-1 overflow-auto rounded-lg border bg-card">
                    <table className="w-max min-w-full border-collapse text-xs">
                        <thead className="sticky top-0 z-2 bg-slate-100 dark:bg-slate-800">
                            <tr>
                                <th
                                    className="sticky left-0 z-3 w-55 min-w-55 border-b border-r bg-slate-200 px-2 py-1 text-left font-semibold dark:bg-slate-700"
                                >
                                    Name
                                </th>
                                <th className="sticky left-55 z-3 w-15 min-w-15 border-b border-r bg-slate-200 px-2 py-1 text-center font-semibold dark:bg-slate-700">
                                    Pts
                                </th>
                                {days.map((d) => (
                                    <th
                                        key={d.date}
                                        className={`min-w-11 border-b border-r px-1 py-1 text-center font-semibold ${d.is_weekend ? "bg-slate-300 dark:bg-slate-700" : ""}`}
                                    >
                                        <div className="leading-tight">{d.day}</div>
                                        <div className="text-[10px] font-normal text-muted-foreground leading-tight">
                                            {d.weekday}
                                        </div>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {groups.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={2 + days.length}
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
                                    colCount={2 + days.length}
                                    selectedEmployeeId={selectedEmployeeId}
                                    onSelectEmployee={setSelectedEmployeeId}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
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
}: {
    group: Group;
    days: DayMeta[];
    canEdit: boolean;
    colCount: number;
    selectedEmployeeId: number | null;
    onSelectEmployee: (id: number | null) => void;
}) {
    void colCount;
    return (
        <>
            <tr>
                <td
                    colSpan={2}
                    className="sticky left-0 z-1 border-b border-t border-r bg-blue-100 px-3 py-1 text-sm font-bold uppercase tracking-wide text-blue-900 dark:bg-blue-950/80 dark:text-blue-100"
                >
                    {group.campaign}
                </td>
                {days.map((d) => (
                    <td
                        key={d.date}
                        className="border-b border-t bg-blue-50 dark:bg-blue-950/40"
                    />
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
}: {
    employee: Employee;
    days: DayMeta[];
    canEdit: boolean;
    zebra: boolean;
    isRowSelected: boolean;
    onSelectEmployee: (id: number | null) => void;
}) {
    // Solid backgrounds so sticky left columns don't go transparent over scrolling content.
    const rowBg = zebra
        ? "bg-slate-50 dark:bg-slate-900"
        : "bg-white dark:bg-slate-950";
    return (
        <tr>
            <td
                className={`sticky left-0 z-1 border-b border-r px-2 py-1 font-medium ${
                    isRowSelected ? "bg-blue-100 dark:bg-blue-900/60" : rowBg
                }`}
            >
                {employee.name}
            </td>
            <td
                className={`sticky left-55 z-1 border-b border-r px-2 py-1 text-center font-mono ${
                    isRowSelected ? "bg-blue-100 dark:bg-blue-900/60" : rowBg
                }`}
            >
                {employee.points.toFixed(2)}
            </td>
            {days.map((d) => {
                const cell = (employee.cells as Record<string, Cell>)[d.date];
                return (
                    <CellView
                        key={d.date}
                        date={d}
                        employeeId={employee.id}
                        employeeName={employee.name}
                        schedule={employee.schedule}
                        cell={cell}
                        canEdit={canEdit}
                        isRowSelected={isRowSelected}
                        onSelectEmployee={onSelectEmployee}
                    />
                );
            })}
        </tr>
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
];

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

const CellView = memo(function CellView({
    date,
    employeeId,
    employeeName,
    schedule,
    cell,
    canEdit,
    isRowSelected,
    onSelectEmployee,
}: {
    date: DayMeta;
    employeeId: number;
    employeeName: string;
    schedule: EmployeeSchedule | null;
    cell: Cell | undefined;
    canEdit: boolean;
    isRowSelected: boolean;
    onSelectEmployee: (id: number | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const tdClass = `min-w-11 border-b border-r px-1 py-1 text-center text-[11px] tabular-nums ${cellClass(
        cell,
        date.is_weekend
    )} ${unverifiedRing(cell)}`;

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
                                {(cell.overtime_approved || cell.overtime_minutes >= 60)
                                    ? cell.hours.toFixed(2)
                                    : Math.round(cell.hours)}
                            </span>
                            {!cell.overtime_approved && cell.overtime_minutes < 60 && (
                                <span className="opacity-50 text-[10px]">({cell.hours.toFixed(2)} actual)</span>
                            )}
                            {cell.overtime_minutes > 0 && cell.overtime_minutes < 60 && (
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
                </>
            ) : (
                <div className="opacity-60 italic pt-0.5">No record — click to create</div>
            )}
        </div>
    ), [cell, employeeName, date.date]);

    if (!canEdit) {
        return (
            <Tooltip delayDuration={500}>
                <TooltipTrigger asChild>
                    <td className={`${tdClass} relative`}>
                        {isRowSelected && (
                            <span className="absolute inset-0 bg-blue-500/10 pointer-events-none" />
                        )}
                        {label}
                    </td>
                </TooltipTrigger>
                <TooltipContent side="top" className="max-w-55">
                    {tooltipContent}
                </TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Popover open={open} onOpenChange={(val) => { setOpen(val); if (!val) onSelectEmployee(null); }}>
            <Tooltip delayDuration={1000}>
                <TooltipTrigger asChild>
                    <PopoverTrigger asChild>
                        <td
                            className={`${tdClass} cursor-pointer relative`}
                            onClick={() => { setOpen(true); onSelectEmployee(employeeId); }}
                            role="button"
                        >
                            {isRowSelected && !open && (
                                <span className="absolute inset-0 bg-blue-500/10 pointer-events-none" />
                            )}
                            {open && (
                                <span className="absolute inset-0 bg-blue-500/25 pointer-events-none" />
                            )}
                            {label}
                        </td>
                    </PopoverTrigger>
                </TooltipTrigger>
                {!open && (
                    <TooltipContent side="top" className="max-w-55">
                        {tooltipContent}
                    </TooltipContent>
                )}
            </Tooltip>
            <PopoverContent
                className="w-80"
                align="start"
                side="bottom"
                collisionPadding={{ top: 120, bottom: 16, left: 16, right: 16 }}
            >
                <CellEditor
                    cell={cell}
                    employeeId={employeeId}
                    employeeName={employeeName}
                    schedule={schedule}
                    date={date.date}
                    onDone={() => setOpen(false)}
                />
            </PopoverContent>
        </Popover>
    );
});

function CellEditor({
    cell,
    employeeId,
    employeeName,
    schedule,
    date,
    onDone,
}: {
    cell: Cell | undefined;
    employeeId: number;
    employeeName: string;
    schedule: EmployeeSchedule | null;
    date: string;
    onDone: () => void;
}) {
    const isCreate = !cell;

    // For create mode, pre-fill times from schedule (take HH:MM part only)
    const schedTimeIn = schedule?.scheduled_time_in
        ? schedule.scheduled_time_in.slice(0, 5)
        : "";
    const schedTimeOut = schedule?.scheduled_time_out
        ? schedule.scheduled_time_out.slice(0, 5)
        : "";

    const initialHours = cell?.hours !== null && cell?.hours !== undefined ? cell.hours.toFixed(2) : "";
    const form = useForm({
        attendance_id: cell?.attendance_id ?? 0,
        user_id: employeeId,
        shift_date: date,
        status: cell?.status ?? "non_work_day",
        hours: initialHours,
        actual_time_in: cell?.actual_time_in ?? (isCreate ? schedTimeIn : ""),
        actual_time_out: cell?.actual_time_out ?? (isCreate ? schedTimeOut : ""),
        verify: false,
        overtime_approved: cell?.overtime_approved ?? false,
        undertime_approval_reason: (cell?.undertime_approval_reason ?? null) as 'generate_points' | 'skip_points' | 'lunch_used' | null,
        undertime_approval_action: null as 'approve' | 'reject' | 'request' | null,
        is_set_home: cell?.is_set_home ?? false,
    });
    const isLeave = cell?.kind === "leave";
    const submittedRef = useRef(false);

    const [isPartialApproving, setIsPartialApproving] = useState(false);

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
            violations.push({ label: "Tardy", value: fmtMins(cell.tardy_minutes), cls: "text-amber-400" });
        if (cell.undertime_minutes > 0)
            violations.push({ label: "Undertime", value: fmtMins(cell.undertime_minutes), cls: "text-orange-400" });
        if (cell.overtime_minutes > 30)
            violations.push({
                label: "Overtime",
                value: `${fmtMins(cell.overtime_minutes)}${cell.overtime_approved ? " ✓" : " (pending)"}`,
                cls: cell.overtime_approved ? "text-emerald-400" : "text-blue-400",
            });
        if (cell.status === "failed_bio_in")
            violations.push({ label: "Violation", value: "No time-in biometric", cls: "text-purple-400" });
        if (cell.status === "failed_bio_out")
            violations.push({ label: "Violation", value: "No time-out biometric", cls: "text-purple-400" });
        if (cell.status === "ncns")
            violations.push({ label: "Violation", value: "No call / No show", cls: "text-red-400" });
        if (cell.status === "half_day_absence")
            violations.push({ label: "Violation", value: "Half day absence", cls: "text-red-400" });
        if (cell.status === "advised_absence")
            violations.push({ label: "Violation", value: "Advised absence", cls: "text-red-400" });
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
    const showTimeFields =
        isCreate || TIME_REQUIRED_STATUSES.has(form.data.status) || cell?.has_bio === true;

    // Preview/predicted status from schedule + times (works in both create and edit mode)
    const previewStatus = useMemo(() => {
        if (!schedule?.scheduled_time_in || !schedule?.scheduled_time_out) return null;
        const tin = form.data.actual_time_in;
        const tout = form.data.actual_time_out;
        if (!tin) return null;

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

        // Determine tardy status — >15 min late = half_day_absence
        const HALF_DAY_TARDY_THRESHOLD = 15;
        const tardyStatus = tardyMins === 0
            ? "on_time"
            : tardyMins > HALF_DAY_TARDY_THRESHOLD
                ? "half_day_absence"
                : "tardy";

        let status = "on_time";
        let detail = "";
        if (!tout) {
            status = tardyStatus !== "on_time" ? tardyStatus : "on_time";
            detail = tardyMins > 0 ? `+${tardyMins}m late` : "";
        } else if (tardyMins > 0 && undertimeMins > 0) {
            status = tardyStatus;
            detail = `+${tardyMins}m tardy${undertimeMins > 0 ? `, ${undertimeMins}m undertime` : ""}`;
        } else if (tardyMins > 0) {
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

        return { status, detail, overtimeMins, undertimeMins };
    }, [schedule, form.data.actual_time_in, form.data.actual_time_out]);

    // In edit mode: auto-sync form status when predicted status changes due to time edits.
    // Skip the first render so we don't override the existing saved status immediately.
    const isFirstStatusSync = useRef(true);
    useEffect(() => {
        if (isFirstStatusSync.current) {
            isFirstStatusSync.current = false;
            return;
        }
        if (isCreate || !previewStatus) return;
        form.setData('status', previewStatus.status);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [previewStatus?.status]);

    const STATUS_PREVIEW_LABELS: Record<string, { label: string; cls: string }> = {
        on_time: { label: "On Time", cls: "text-emerald-400" },
        tardy: { label: "Tardy", cls: "text-amber-400" },
        undertime: { label: "Undertime", cls: "text-orange-400" },
        undertime_more_than_hour: { label: "Undertime >1hr", cls: "text-orange-500" },
        half_day_absence: { label: "Half Day Absence", cls: "text-red-400" },
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (submittedRef.current) return;
        submittedRef.current = true;
        const url = isCreate
            ? "/attendance/spreadsheet/cell/create"
            : "/attendance/spreadsheet/cell";
        if (isCreate) {
            // Backend auto-determines status — only send time/hours fields
            form.transform((data) => ({
                user_id: data.user_id,
                shift_date: data.shift_date,
                actual_time_in: data.actual_time_in,
                actual_time_out: data.actual_time_out,
                hours: data.hours,
                overtime_approved: data.overtime_approved,
                undertime_approval_reason: data.undertime_approval_reason,
                is_set_home: data.is_set_home,
            }));
        } else {
            form.transform((data) => data);
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
            <div className="space-y-0.5">
                <div className="text-sm font-semibold">{employeeName}</div>
                <div className="text-xs text-muted-foreground">{date}</div>
            </div>

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
                                <span>{schedule.campaign}</span>
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

            {/* Partial approval — for records with time-in but no time-out, not yet fully verified */}
            {!isCreate && cell && !cell.actual_time_out && cell.actual_time_in && !cell.verified && (
                <div className="rounded border border-orange-400/60 bg-orange-950/20 p-2 text-[11px] space-y-2">
                    <div className="flex items-center gap-1.5 font-semibold text-orange-300">
                        <Clock className="h-3 w-3" />
                        <span>Partial Attendance — time-out missing</span>
                    </div>
                    <p className="text-[10px] text-orange-200/70">
                        Employee has checked in but time-out is not yet recorded. You can partially approve this record now — points will be generated based on time-in status. The record can be fully verified once time-out is available.
                    </p>
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

            {/* Partially verified indicator — time-in only, waiting for time-out */}
            {!isCreate && cell?.is_partially_verified && !cell.actual_time_out && (
                <div className="rounded border border-orange-400/40 bg-orange-950/10 p-2 text-[11px] space-y-1">
                    <div className="flex items-center gap-1.5 font-semibold text-orange-300">
                        <Clock className="h-3 w-3 animate-pulse" />
                        <span>Partially Verified — awaiting time-out</span>
                    </div>
                    <p className="text-[10px] text-orange-200/60">
                        Record was partially approved. Once time-out biometric is recorded, the record will be completed on next verification.
                    </p>
                </div>
            )}

            {violations.length > 0 && (
                <div className="rounded border border-red-400/40 bg-red-950/20 p-2 text-[11px]">
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

            {/* Overtime approval — edit mode: show when OT > 30 min */}
            {!isCreate && (cell?.overtime_minutes ?? 0) > 30 && (
                <div className="rounded border border-blue-400/40 bg-blue-950/20 p-2 text-[11px]">
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
                                Approve {fmtMins(cell!.overtime_minutes)} overtime
                            </label>
                            <p className="text-[10px] text-muted-foreground">
                                {form.data.overtime_approved
                                    ? <span className="text-emerald-400">OT hours will be included in total hours worked.</span>
                                    : <span className="text-blue-400/80">OT hours are not counted until approved.</span>
                                }
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Overtime approval — create mode: show when preview detects OT > 30 min */}
            {isCreate && (previewStatus?.overtimeMins ?? 0) > 30 && (
                <div className="rounded border border-blue-400/40 bg-blue-950/20 p-2 text-[11px]">
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
                                Approve {fmtMins(previewStatus!.overtimeMins)} overtime
                            </label>
                            <p className="text-[10px] text-muted-foreground">
                                {form.data.overtime_approved
                                    ? <span className="text-emerald-400">OT hours will be included in total hours worked.</span>
                                    : <span className="text-blue-400/80">OT hours are not counted until approved.</span>
                                }
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Undertime approval — edit mode: show when undertime > 30 min */}
            {!isCreate && (cell?.undertime_minutes ?? 0) > 30 && (
                <div className="space-y-3 p-4 bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 rounded-lg">
                    {/* Header row: label + Set Home toggle */}
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Clock className="h-4 w-4 text-amber-600" />
                            <span className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                Undertime: {fmtMins(cell!.undertime_minutes)}
                            </span>
                        </div>
                        {(canApproveUndertime || canRequestUndertimeApproval) && (
                            <div className="flex items-center gap-2">
                                <Label htmlFor="edit-set-home" className="text-xs text-amber-700 dark:text-amber-300 cursor-pointer">
                                    Set Home
                                </Label>
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
                        <p className="text-xs text-green-700 dark:text-green-400">
                            ✓ Employee sent home early - no undertime points
                        </p>
                    ) : cell!.undertime_approval_status === 'approved' ? (
                        canApproveUndertime ? (
                            <div className="space-y-2">
                                <p className="text-xs text-green-700 dark:text-green-400 font-medium">
                                    ✓ Currently approved: {cell!.undertime_approval_reason === 'skip_points' ? 'No points (Set Home)' : cell!.undertime_approval_reason === 'lunch_used' ? 'Worked through lunch (+1hr credited)' : 'Points generated'}
                                    {' — select new action below to update on Save'}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" size="sm" variant={form.data.undertime_approval_reason === 'generate_points' ? 'default' : 'outline'} onClick={() => { form.setData('undertime_approval_reason', 'generate_points'); form.setData('undertime_approval_action', 'reject'); }} className="h-7 text-xs"><Check className="h-3 w-3 mr-1" />Generate Points</Button>
                                    <Button type="button" size="sm" variant={form.data.undertime_approval_reason === 'lunch_used' ? 'default' : 'outline'} onClick={() => { form.setData('undertime_approval_reason', 'lunch_used'); form.setData('undertime_approval_action', 'approve'); }} className="h-7 text-xs"><Clock className="h-3 w-3 mr-1" />Lunch Used</Button>
                                </div>
                                {form.data.undertime_approval_action && (
                                    <p className="text-xs text-amber-700 dark:text-amber-300">
                                        {form.data.undertime_approval_reason === 'lunch_used' && '✓ Will approve — worked through lunch, +1hr credited to total hours'}
                                        {form.data.undertime_approval_reason === 'generate_points' && '• Will reject — points generated'}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <p className="text-xs text-green-700 dark:text-green-400">
                                ✓ Approved: {cell!.undertime_approval_reason === 'skip_points' ? 'No points (Set Home)' : cell!.undertime_approval_reason === 'lunch_used' ? 'Worked through lunch (+1hr credited)' : 'Points generated'}
                            </p>
                        )
                    ) : cell!.undertime_approval_status === 'rejected' ? (
                        canApproveUndertime ? (
                            <div className="space-y-2">
                                <p className="text-xs text-red-600 dark:text-red-400 font-medium">✗ Currently rejected — select action below to update on Save</p>
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" size="sm" variant={form.data.undertime_approval_reason === 'generate_points' ? 'default' : 'outline'} onClick={() => { form.setData('undertime_approval_reason', 'generate_points'); form.setData('undertime_approval_action', 'reject'); }} className="h-7 text-xs"><Check className="h-3 w-3 mr-1" />Generate Points</Button>
                                    <Button type="button" size="sm" variant={form.data.undertime_approval_reason === 'lunch_used' ? 'default' : 'outline'} onClick={() => { form.setData('undertime_approval_reason', 'lunch_used'); form.setData('undertime_approval_action', 'approve'); }} className="h-7 text-xs"><Clock className="h-3 w-3 mr-1" />Lunch Used</Button>
                                </div>
                                {form.data.undertime_approval_action && (
                                    <p className="text-xs text-amber-700 dark:text-amber-300">
                                        {form.data.undertime_approval_reason === 'lunch_used' && '✓ Will approve — worked through lunch, +1hr credited to total hours'}
                                        {form.data.undertime_approval_reason === 'generate_points' && '• Will reject — points generated'}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <p className="text-xs text-red-600 dark:text-red-400">✗ Rejected - points will be generated</p>
                        )
                    ) : cell!.undertime_approval_status === 'pending' ? (
                        canApproveUndertime ? (
                            <div className="space-y-2">
                                {cell!.undertime_approval_reason && (
                                    <p className="text-xs text-blue-700 dark:text-blue-400 font-medium">
                                        ⭐ Team Lead suggested: {cell!.undertime_approval_reason === 'skip_points' ? 'No points (Set Home)' : cell!.undertime_approval_reason === 'lunch_used' ? 'Worked through lunch (+1hr credited)' : 'Generate points'}
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" size="sm" variant={form.data.undertime_approval_reason === 'generate_points' ? 'default' : 'outline'} onClick={() => { form.setData('undertime_approval_reason', 'generate_points'); form.setData('undertime_approval_action', 'reject'); }} className="h-7 text-xs"><Check className="h-3 w-3 mr-1" />Generate Points</Button>
                                    <Button type="button" size="sm" variant={form.data.undertime_approval_reason === 'lunch_used' ? 'default' : 'outline'} onClick={() => { form.setData('undertime_approval_reason', 'lunch_used'); form.setData('undertime_approval_action', 'approve'); }} className="h-7 text-xs"><Clock className="h-3 w-3 mr-1" />Lunch Used</Button>
                                </div>
                                {form.data.undertime_approval_action && (
                                    <p className="text-xs text-amber-700 dark:text-amber-300">
                                        {form.data.undertime_approval_reason === 'lunch_used' && '✓ Will approve — worked through lunch, +1hr credited to total hours'}
                                        {form.data.undertime_approval_reason === 'generate_points' && '• Will reject — points generated'}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <div className="flex items-center gap-2">
                                <Clock className="h-3 w-3 text-yellow-600 animate-pulse" />
                                <p className="text-xs text-yellow-700 dark:text-yellow-400">Pending approval from Admin/HR</p>
                            </div>
                        )
                    ) : canApproveUndertime ? (
                        <div className="space-y-2">
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" size="sm" variant={form.data.undertime_approval_reason === 'generate_points' ? 'default' : 'outline'} onClick={() => { form.setData('undertime_approval_reason', 'generate_points'); form.setData('undertime_approval_action', 'reject'); }} className="h-7 text-xs"><Check className="h-3 w-3 mr-1" />Generate Points</Button>
                                <Button type="button" size="sm" variant={form.data.undertime_approval_reason === 'lunch_used' ? 'default' : 'outline'} onClick={() => { form.setData('undertime_approval_reason', 'lunch_used'); form.setData('undertime_approval_action', 'approve'); }} className="h-7 text-xs"><Clock className="h-3 w-3 mr-1" />Lunch Used</Button>
                            </div>
                            {form.data.undertime_approval_action && (
                                <p className="text-xs text-amber-700 dark:text-amber-300">
                                    {form.data.undertime_approval_reason === 'lunch_used' && '✓ Will approve — worked through lunch, +1hr credited to total hours'}
                                    {form.data.undertime_approval_reason === 'generate_points' && '• Will reject — points generated'}
                                </p>
                            )}
                        </div>
                    ) : canRequestUndertimeApproval ? (
                        <div className="space-y-2">
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" size="sm" variant={form.data.undertime_approval_reason === 'generate_points' ? 'default' : 'outline'} onClick={() => { form.setData('undertime_approval_reason', 'generate_points'); form.setData('undertime_approval_action', 'request'); }} className="h-7 text-xs"><Check className="h-3 w-3 mr-1" />Generate Points</Button>
                                <Button type="button" size="sm" variant={form.data.undertime_approval_reason === 'lunch_used' ? 'default' : 'outline'} onClick={() => { form.setData('undertime_approval_reason', 'lunch_used'); form.setData('undertime_approval_action', 'request'); }} className="h-7 text-xs"><Clock className="h-3 w-3 mr-1" />Lunch Used</Button>
                            </div>
                            {form.data.undertime_approval_action && (
                                <p className="text-xs text-amber-700 dark:text-amber-300">
                                    {form.data.undertime_approval_reason === 'lunch_used' && '• Suggesting: Worked through lunch (+1hr credited)'}
                                    {form.data.undertime_approval_reason === 'generate_points' && '• Suggesting: Generate points'}
                                </p>
                            )}
                        </div>
                    ) : (
                        <p className="text-xs text-amber-700 dark:text-amber-300">• Undertime points will be generated</p>
                    )}
                </div>
            )}

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

            {isCreate ? (
                <div className="rounded border border-blue-400/40 bg-blue-950/20 p-2 text-[11px] text-blue-300">
                    {previewStatus ? (
                        <div className="flex items-center justify-between gap-2">
                            <span>Expected status:</span>
                            <span>
                                <span className={`font-semibold ${STATUS_PREVIEW_LABELS[previewStatus.status]?.cls ?? "text-blue-300"}`}>
                                    {STATUS_PREVIEW_LABELS[previewStatus.status]?.label ?? previewStatus.status}
                                </span>
                                {previewStatus.detail && (
                                    <span className="ml-1.5 text-blue-400/70">({previewStatus.detail})</span>
                                )}
                            </span>
                        </div>
                    ) : (
                        <span>Status will be auto-determined from the time in/out and schedule.</span>
                    )}
                </div>
            ) : (
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
            )}

            <div className="grid grid-cols-2 gap-2" hidden={!showTimeFields}>
                <div className="space-y-1">
                    <Label htmlFor="cell-time-in" className="text-xs">
                        Time In
                    </Label>
                    <Input
                        id="cell-time-in"
                        type="time"
                        value={form.data.actual_time_in}
                        onChange={(e) => form.setData("actual_time_in", e.target.value)}
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
                        onChange={(e) => form.setData("actual_time_out", e.target.value)}
                    />
                    {form.errors.actual_time_out && (
                        <div className="text-xs text-destructive">
                            {form.errors.actual_time_out}
                        </div>
                    )}
                </div>
            </div>

            <div className="space-y-1">
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
                    onChange={(e) => form.setData("hours", e.target.value)}
                    placeholder="e.g. 8.00"
                />
                {form.errors.hours && (
                    <div className="text-xs text-destructive">
                        {form.errors.hours}
                    </div>
                )}
            </div>

            {!isCreate && cell?.unverified_bio && (
                <label className="flex items-center gap-2 text-xs">
                    <input
                        type="checkbox"
                        checked={form.data.verify}
                        onChange={(e) => form.setData("verify", e.target.checked)}
                        className="h-3.5 w-3.5"
                    />
                    Mark as verified
                </label>
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
        { label: "ML", desc: "Maternity / Paternity Leave", cls: "bg-fuchsia-500 text-white" },
        { label: "LOA", desc: "Leave of Absence", cls: "bg-rose-400 text-white" },
        { label: "UPTO", desc: "Unpaid Time Off", cls: "bg-sky-500 text-white" },
        { label: "BL", desc: "Birthday Leave", cls: "bg-indigo-500 text-white" },
        { label: "SPL", desc: "Special Leave", cls: "bg-teal-500 text-white" },
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
