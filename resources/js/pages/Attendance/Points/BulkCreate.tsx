import React, { useState, useCallback, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Separator } from '@/components/ui/separator';
import { RefreshCcw } from 'lucide-react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { toast } from 'sonner';
import {
    Plus,
    Trash2,
    Users,
    ChevronDown,
    ChevronUp,
    RefreshCw,
    ArrowLeft,
    Check,
    ChevronsUpDown,
    AlertCircle,
    Info,
    Pencil,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    index as attendancePointsIndex,
    bulkStore as attendancePointsBulkStore,
} from '@/routes/attendance-points';

// ─── Types ───────────────────────────────────────────────────────────────────

interface User {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
    middle_name?: string;
}

interface PageProps {
    users: User[];
    campaigns: unknown[];
}

type PointType = 'whole_day_absence' | 'half_day_absence' | 'undertime' | 'undertime_more_than_hour' | 'tardy';

interface EntryRow {
    id: string; // local uuid for key
    user_id: string;
    shift_date: string;
    point_type: PointType | '';
    is_advised: boolean;
    is_critical_day: boolean;
    violation_details: string;
    notes: string;
    tardy_minutes: string;
    undertime_minutes: string;
}

// ─── Constants ───────────────────────────────────────────────────────────────

const POINT_TYPE_OPTIONS: { value: PointType; label: string; points: string }[] = [
    { value: 'whole_day_absence', label: 'Whole Day Absence', points: '1.00' },
    { value: 'half_day_absence', label: 'Half-Day Absence', points: '0.50' },
    { value: 'tardy', label: 'Tardy', points: '0.25' },
    { value: 'undertime', label: 'Undertime (≤60 min)', points: '0.25' },
    { value: 'undertime_more_than_hour', label: 'Undertime (61+ min)', points: '0.50' },
];

const POINT_VALUES: Record<PointType, number> = {
    whole_day_absence: 1.0,
    half_day_absence: 0.5,
    tardy: 0.25,
    undertime: 0.25,
    undertime_more_than_hour: 0.5,
};

const formatUserName = (user: User) => {
    const mid = user.middle_name ? ` ${user.middle_name}.` : '';
    return `${user.last_name}, ${user.first_name}${mid}`;
};

const uid = () => Math.random().toString(36).slice(2, 10);

const blankRow = (shared?: { shift_date: string; point_type: PointType | ''; is_advised: boolean; is_critical_day: boolean; tardy_minutes: string }): EntryRow => ({
    id: uid(),
    user_id: '',
    shift_date: shared?.shift_date ?? '',
    point_type: shared?.point_type ?? '',
    is_advised: shared?.is_advised ?? false,
    is_critical_day: shared?.is_critical_day ?? false,
    violation_details: '',
    notes: '',
    tardy_minutes: shared?.tardy_minutes ?? '',
    undertime_minutes: '',
});

// ─── Sub-components ──────────────────────────────────────────────────────────

function UserPicker({
    users,
    value,
    onChange,
    disabled,
    error,
}: {
    users: User[];
    value: string;
    onChange: (v: string) => void;
    disabled?: boolean;
    error?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    const filtered = useMemo(() => {
        if (!query.trim()) return users;
        const q = query.toLowerCase();
        return users.filter(
            (u) => formatUserName(u).toLowerCase().includes(q) || u.name.toLowerCase().includes(q),
        );
    }, [users, query]);

    const selected = users.find((u) => String(u.id) === value);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    className={cn(
                        'w-full justify-between font-normal text-left',
                        !selected && 'text-muted-foreground',
                        error && 'border-red-500',
                    )}
                    disabled={disabled}
                >
                    <span className="truncate">{selected ? formatUserName(selected) : 'Select employee…'}</span>
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-64 p-0" align="start">
                <Command shouldFilter={false}>
                    <CommandInput
                        placeholder="Search employee…"
                        value={query}
                        onValueChange={setQuery}
                    />
                    <CommandList>
                        <CommandEmpty>No employee found.</CommandEmpty>
                        <CommandGroup>
                            {filtered.map((u) => (
                                <CommandItem
                                    key={u.id}
                                    value={String(u.id)}
                                    onSelect={(v) => {
                                        onChange(v);
                                        setOpen(false);
                                        setQuery('');
                                    }}
                                >
                                    <Check
                                        className={cn(
                                            'mr-2 h-4 w-4',
                                            value === String(u.id) ? 'opacity-100' : 'opacity-0',
                                        )}
                                    />
                                    {formatUserName(u)}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function BulkCreatePage({ users }: PageProps) {
    useFlashMessage();

    const { title, breadcrumbs } = usePageMeta({
        title: 'Bulk Add Attendance Points',
        breadcrumbs: [
            { title: 'Attendance Points', href: attendancePointsIndex().url },
            { title: 'Bulk Add' },
        ],
    });

    // ── Shared defaults ───────────────────────────────────────────────────────
    const [sharedDate, setSharedDate] = useState('');
    const [sharedType, setSharedType] = useState<PointType | ''>('');
    const [sharedIsAdvised, setSharedIsAdvised] = useState(false);
    const [sharedIsCriticalDay, setSharedIsCriticalDay] = useState(false);
    const [sharedTardyMinutes, setSharedTardyMinutes] = useState('');

    // ── Rows ──────────────────────────────────────────────────────────────────
    const [entries, setEntries] = useState<EntryRow[]>([]);
    const [expandedRows, setExpandedRows] = useState<Set<string>>(new Set());

    // ── Employee search ───────────────────────────────────────────────────────
    const [userSearch, setUserSearch] = useState('');
    const [userPickerOpen, setUserPickerOpen] = useState(false);

    // ── Submit state ──────────────────────────────────────────────────────────
    const [submitting, setSubmitting] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    // ── Validation errors ─────────────────────────────────────────────────────
    const [rowErrors, setRowErrors] = useState<Record<string, string[]>>({});

    // ─── Computed ──────────────────────────────────────────────────────────────

    const filteredUsers = useMemo(() => {
        const q = userSearch.toLowerCase();
        return q ? users.filter((u) => formatUserName(u).toLowerCase().includes(q) || u.name.toLowerCase().includes(q)) : users;
    }, [users, userSearch]);

    const totalPoints = useMemo(
        () => entries.reduce((sum, e) => sum + (e.point_type ? POINT_VALUES[e.point_type] ?? 0 : 0), 0),
        [entries],
    );

    const userIdsInEntries = useMemo(() => new Set(entries.map((e) => e.user_id).filter(Boolean)), [entries]);

    // ─── Row helpers ───────────────────────────────────────────────────────────

    const addRow = useCallback(() => {
        setEntries((prev) => [...prev, blankRow({ shift_date: sharedDate, point_type: sharedType, is_advised: sharedIsAdvised, is_critical_day: sharedIsCriticalDay, tardy_minutes: sharedTardyMinutes })]);
    }, [sharedDate, sharedType, sharedIsAdvised, sharedIsCriticalDay, sharedTardyMinutes]);

    const addUserRow = useCallback(
        (userId: string) => {
            if (userIdsInEntries.has(userId)) {
                toast.warning('This employee is already in the list.');
                return;
            }
            const row = blankRow({ shift_date: sharedDate, point_type: sharedType, is_advised: sharedIsAdvised, is_critical_day: sharedIsCriticalDay, tardy_minutes: sharedTardyMinutes });
            row.user_id = userId;
            setEntries((prev) => [...prev, row]);
            setUserPickerOpen(false);
            setUserSearch('');
        },
        [sharedDate, sharedType, sharedIsAdvised, sharedIsCriticalDay, sharedTardyMinutes, userIdsInEntries],
    );

    // Apply shared values to ALL existing rows
    const applySharedToAll = useCallback(
        (field: 'shift_date' | 'point_type' | 'is_advised' | 'is_critical_day' | 'tardy_minutes', value: string | boolean) => {
            setEntries((prev) => prev.map((r) => ({ ...r, [field]: value })));
        },
        [],
    );

    const handleSharedDateChange = (v: string) => {
        setSharedDate(v);
    };

    const handleSharedTypeChange = (v: PointType | '') => {
        setSharedType(v);
    };

    const handleSharedAdvisedChange = (v: boolean) => {
        setSharedIsAdvised(v);
    };



    const removeRow = useCallback((id: string) => {
        setEntries((prev) => prev.filter((r) => r.id !== id));
        setExpandedRows((prev) => {
            const next = new Set(prev);
            next.delete(id);
            return next;
        });
        setRowErrors((prev) => {
            const next = { ...prev };
            delete next[id];
            return next;
        });
    }, []);

    const updateRow = useCallback(<K extends keyof EntryRow>(id: string, key: K, value: EntryRow[K]) => {
        setEntries((prev) => prev.map((r) => (r.id === id ? { ...r, [key]: value } : r)));
    }, []);

    const toggleExpand = useCallback((id: string) => {
        setExpandedRows((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }, []);

    // ─── Validation ────────────────────────────────────────────────────────────

    const validate = () => {
        const errors: Record<string, string[]> = {};

        if (entries.length === 0) {
            toast.error('Add at least one employee entry.');
            return false;
        }

        for (const row of entries) {
            const errs: string[] = [];
            if (!row.user_id) errs.push('Employee is required.');
            if (!row.shift_date) errs.push('Violation date is required.');
            if (!row.point_type) errs.push('Violation type is required.');
            if (row.point_type === 'undertime' && row.undertime_minutes && parseInt(row.undertime_minutes) > 60)
                errs.push('Undertime (≤60 min) must be 60 minutes or less.');
            if (row.point_type === 'undertime_more_than_hour' && row.undertime_minutes && parseInt(row.undertime_minutes) < 61)
                errs.push('Undertime (61+ min) must be at least 61 minutes.');
            if (errs.length) errors[row.id] = errs;
        }

        // Check duplicates within current entries
        const seen = new Set<string>();
        for (const row of entries) {
            const key = `${row.user_id}-${row.shift_date}`;
            if (seen.has(key)) {
                if (!errors[row.id]) errors[row.id] = [];
                errors[row.id].push('Duplicate: same employee and date already in this list.');
            }
            if (row.user_id && row.shift_date) seen.add(key);
        }

        setRowErrors(errors);
        if (Object.keys(errors).length > 0) {
            toast.error('Please fix the highlighted errors before submitting.');
            return false;
        }
        return true;
    };

    // ─── Submit ────────────────────────────────────────────────────────────────

    const handleSubmit = () => {
        if (!validate()) return;
        setConfirmOpen(true);
    };

    const handleConfirmedSubmit = () => {
        setConfirmOpen(false);
        setSubmitting(true);

        const payload = {
            entries: entries.map((row) => ({
                user_id: parseInt(row.user_id),
                shift_date: row.shift_date,
                point_type: row.point_type,
                is_advised: row.is_advised,
                is_critical_day: row.is_critical_day,
                violation_details: row.violation_details || null,
                notes: row.notes || null,
                tardy_minutes: row.tardy_minutes ? parseInt(row.tardy_minutes) : null,
                undertime_minutes: row.undertime_minutes ? parseInt(row.undertime_minutes) : null,
            })),
        };

        router.post(attendancePointsBulkStore().url, payload, {
            onSuccess: () => {
                // Flash message handled by backend + useFlashMessage hook
            },
            onError: (errors) => {
                const msg = Object.values(errors).flat().join(', ') || 'Failed to submit bulk entries.';
                toast.error(msg);
            },
            onFinish: () => setSubmitting(false),
        });
    };

    // ─── Render ────────────────────────────────────────────────────────────────

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title={title}
                    description="Add violation points for multiple employees at once."
                />

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    {/* ── LEFT: Shared Defaults + Employee Picker + Summary ─────── */}
                    <div className="lg:col-span-1 space-y-4">
                        {/* Shared defaults card */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Info className="h-4 w-4 text-blue-500" />
                                    Violation Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label className="text-sm">Violation Date</Label>
                                    <DatePicker
                                        value={sharedDate}
                                        onChange={handleSharedDateChange}
                                        placeholder="Select date"
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <Label className="text-sm">Violation Type</Label>
                                    <Select
                                        value={sharedType}
                                        onValueChange={(v) => handleSharedTypeChange(v as PointType)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {POINT_TYPE_OPTIONS.map((o) => (
                                                <SelectItem key={o.value} value={o.value}>
                                                    {o.label}
                                                    <span className="ml-1 text-muted-foreground">({o.points} pt)</span>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {sharedType === 'tardy' && (
                                    <div className="space-y-1.5">
                                        <Label className="text-sm">Tardy Minutes</Label>
                                        <Input
                                            type="number"
                                            min="0"
                                            placeholder="Minutes late"
                                            value={sharedTardyMinutes}
                                            onChange={(e) => setSharedTardyMinutes(e.target.value)}
                                        />
                                    </div>
                                )}

                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="shared-advised"
                                        checked={sharedIsAdvised}
                                        onCheckedChange={(v) => handleSharedAdvisedChange(Boolean(v))}
                                    />
                                    <Label htmlFor="shared-advised" className="text-sm cursor-pointer">
                                        Advised absence
                                    </Label>
                                </div>

                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="shared-critical"
                                        checked={sharedIsCriticalDay}
                                        onCheckedChange={(v) => setSharedIsCriticalDay(Boolean(v))}
                                    />
                                    <Label htmlFor="shared-critical" className="text-sm cursor-pointer">
                                        Critical Working Day (×2 points)
                                    </Label>
                                </div>

                                {entries.length > 0 && (
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        className="w-full gap-2 mt-1"
                                        onClick={() => {
                                            applySharedToAll('shift_date', sharedDate);
                                            applySharedToAll('point_type', sharedType);
                                            applySharedToAll('is_advised', sharedIsAdvised);
                                            applySharedToAll('is_critical_day', sharedIsCriticalDay);
                                            if (sharedType === 'tardy') applySharedToAll('tardy_minutes', sharedTardyMinutes);
                                        }}
                                    >
                                        <RefreshCcw className="h-3.5 w-3.5" />
                                        Re-apply to all rows
                                    </Button>
                                )}
                            </CardContent>
                        </Card>

                        {/* Employee Picker */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Users className="h-4 w-4 text-primary" />
                                    Add Employees
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <Popover open={userPickerOpen} onOpenChange={setUserPickerOpen}>
                                    <PopoverTrigger asChild>
                                        <Button variant="outline" className="w-full justify-between font-normal">
                                            <span className="text-muted-foreground">Search employee…</span>
                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-72 p-0" align="start">
                                        <Command shouldFilter={false}>
                                            <CommandInput
                                                placeholder="Search…"
                                                value={userSearch}
                                                onValueChange={setUserSearch}
                                            />
                                            <CommandList>
                                                <CommandEmpty>No employee found.</CommandEmpty>
                                                <CommandGroup>
                                                    {filteredUsers.map((u) => {
                                                        const alreadyAdded = userIdsInEntries.has(String(u.id));
                                                        return (
                                                            <CommandItem
                                                                key={u.id}
                                                                value={String(u.id)}
                                                                onSelect={(v) => addUserRow(v)}
                                                                disabled={alreadyAdded}
                                                                className={cn(alreadyAdded && 'opacity-50')}
                                                            >
                                                                <Check
                                                                    className={cn(
                                                                        'mr-2 h-4 w-4',
                                                                        alreadyAdded ? 'opacity-100' : 'opacity-0',
                                                                    )}
                                                                />
                                                                {formatUserName(u)}
                                                            </CommandItem>
                                                        );
                                                    })}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>

                                <Button
                                    variant="outline"
                                    className="w-full gap-2"
                                    onClick={addRow}
                                >
                                    <Plus className="h-4 w-4" />
                                    Add blank row
                                </Button>
                            </CardContent>
                        </Card>

                        {/* Summary */}
                        {entries.length > 0 && (
                            <Card className="border-primary/30 bg-primary/5">
                                <CardContent className="pt-4 space-y-2">
                                    <div className="flex justify-between text-sm">
                                        <span className="text-muted-foreground">Employees:</span>
                                        <span className="font-semibold">{entries.length}</span>
                                    </div>
                                    <div className="flex justify-between text-sm">
                                        <span className="text-muted-foreground">Total points:</span>
                                        <span className="font-semibold text-red-600 dark:text-red-400">
                                            {totalPoints.toFixed(2)} pts
                                        </span>
                                    </div>
                                    <Separator />
                                    <div className="flex gap-2 pt-1">
                                        <Button
                                            variant="outline"
                                            className="flex-1"
                                            onClick={() => router.visit(attendancePointsIndex().url)}
                                            disabled={submitting}
                                        >
                                            <ArrowLeft className="h-4 w-4 mr-1" />
                                            Cancel
                                        </Button>
                                        <Button
                                            className="flex-1 gap-2"
                                            onClick={handleSubmit}
                                            disabled={submitting || entries.length === 0}
                                        >
                                            {submitting ? (
                                                <RefreshCw className="h-4 w-4 animate-spin" />
                                            ) : (
                                                <Check className="h-4 w-4" />
                                            )}
                                            Submit {entries.length}
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* ── RIGHT: Entry Rows ─────────────────────────────────────── */}
                    <div className="lg:col-span-2 space-y-3">
                        {entries.length === 0 ? (
                            <div className="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-muted-foreground/25 p-12 text-center gap-3">
                                <Users className="h-10 w-10 text-muted-foreground/40" />
                                <p className="text-sm text-muted-foreground">
                                    No employees added yet. Use the picker on the left to add employees.
                                </p>
                                <Button variant="outline" className="gap-2 mt-2" onClick={addRow}>
                                    <Plus className="h-4 w-4" />
                                    Add blank row
                                </Button>
                            </div>
                        ) : (
                            <>
                                {/* Desktop table view */}
                                <div className="hidden md:block rounded-lg border overflow-hidden">
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="bg-muted/40">
                                                <TableHead className="w-10 text-center text-xs">#</TableHead>
                                                <TableHead className="w-52">Employee</TableHead>
                                                <TableHead className="w-44">Date</TableHead>
                                                <TableHead>Type</TableHead>
                                                <TableHead className="w-20 text-center">Advised</TableHead>
                                                <TableHead className="w-20 text-center">Critical</TableHead>
                                                <TableHead className="w-20 text-center">Actions</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {entries.map((row, index) => {
                                                const hasError = !!rowErrors[row.id];
                                                const isExpanded = expandedRows.has(row.id);

                                                return (
                                                    <React.Fragment key={row.id}>
                                                        <TableRow className={cn(
                                                            'transition-colors',
                                                            hasError && 'bg-red-50 dark:bg-red-950/20',
                                                            isExpanded && !hasError && 'bg-muted/20',
                                                        )}>
                                                            <TableCell className="text-center text-xs text-muted-foreground font-medium tabular-nums">
                                                                {index + 1}
                                                            </TableCell>
                                                            <TableCell>
                                                                <UserPicker
                                                                    users={users}
                                                                    value={row.user_id}
                                                                    onChange={(v) => updateRow(row.id, 'user_id', v)}
                                                                    error={hasError && !row.user_id}
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <DatePicker
                                                                    value={row.shift_date}
                                                                    onChange={(v) => updateRow(row.id, 'shift_date', v)}
                                                                    placeholder="Date"
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <Select
                                                                    value={row.point_type}
                                                                    onValueChange={(v) => updateRow(row.id, 'point_type', v as PointType)}
                                                                >
                                                                    <SelectTrigger className="w-full">
                                                                        <SelectValue placeholder="Select type" />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        {POINT_TYPE_OPTIONS.map((o) => (
                                                                            <SelectItem key={o.value} value={o.value}>
                                                                                {o.label}
                                                                                <span className="ml-1 text-muted-foreground text-xs">({o.points})</span>
                                                                            </SelectItem>
                                                                        ))}
                                                                    </SelectContent>
                                                                </Select>
                                                            </TableCell>
                                                            <TableCell className="text-center">
                                                                <Checkbox
                                                                    checked={row.is_advised}
                                                                    onCheckedChange={(v) => updateRow(row.id, 'is_advised', Boolean(v))}
                                                                    aria-label="Advised"
                                                                />
                                                            </TableCell>
                                                            <TableCell className="text-center">
                                                                <Checkbox
                                                                    checked={row.is_critical_day}
                                                                    onCheckedChange={(v) => updateRow(row.id, 'is_critical_day', Boolean(v))}
                                                                    aria-label="Critical Working Day"
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <div className="flex items-center justify-center gap-0.5">
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className={cn(
                                                                            'h-7 w-7',
                                                                            isExpanded && 'bg-muted text-foreground',
                                                                        )}
                                                                        onClick={() => toggleExpand(row.id)}
                                                                        title={isExpanded ? 'Collapse details' : 'Edit details'}
                                                                    >
                                                                        {isExpanded ? (
                                                                            <ChevronUp className="h-3.5 w-3.5" />
                                                                        ) : (
                                                                            <Pencil className="h-3.5 w-3.5" />
                                                                        )}
                                                                    </Button>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="h-7 w-7 text-destructive hover:text-destructive hover:bg-destructive/10"
                                                                        onClick={() => removeRow(row.id)}
                                                                        title="Remove row"
                                                                    >
                                                                        <Trash2 className="h-3.5 w-3.5" />
                                                                    </Button>
                                                                </div>
                                                            </TableCell>
                                                        </TableRow>

                                                        {/* Errors */}
                                                        {hasError && (
                                                            <TableRow className="bg-red-50 dark:bg-red-950/20 hover:bg-red-50 dark:hover:bg-red-950/20">
                                                                <TableCell colSpan={6} className="py-1 px-4">
                                                                    <div className="flex items-start gap-1.5">
                                                                        <AlertCircle className="h-3.5 w-3.5 text-red-500 mt-0.5 shrink-0" />
                                                                        <p className="text-xs text-red-600 dark:text-red-400">
                                                                            {rowErrors[row.id].join(' ')}
                                                                        </p>
                                                                    </div>
                                                                </TableCell>
                                                            </TableRow>
                                                        )}

                                                        {/* Expanded detail row */}
                                                        {isExpanded && (
                                                            <TableRow className="bg-muted/20 border-t-0">
                                                                <TableCell colSpan={6} className="py-2 px-4 pb-3">
                                                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 pl-1 border-l-2 border-primary/30 ml-1">
                                                                        {/* Tardy minutes */}
                                                                        {row.point_type === 'tardy' && (
                                                                            <div className="flex items-center gap-1.5 shrink-0">
                                                                                <Label className="text-xs whitespace-nowrap">Tardy min</Label>
                                                                                <Input
                                                                                    type="number"
                                                                                    min="0"
                                                                                    placeholder="0"
                                                                                    value={row.tardy_minutes}
                                                                                    onChange={(e) =>
                                                                                        updateRow(row.id, 'tardy_minutes', e.target.value)
                                                                                    }
                                                                                    className="h-7 w-20 text-xs"
                                                                                />
                                                                            </div>
                                                                        )}

                                                                        {/* Undertime minutes */}
                                                                        {(row.point_type === 'undertime' ||
                                                                            row.point_type === 'undertime_more_than_hour') && (
                                                                                <div className="flex items-center gap-1.5 shrink-0">
                                                                                    <Label className="text-xs whitespace-nowrap">
                                                                                        Undertime min
                                                                                        <span className="ml-1 text-muted-foreground">
                                                                                            {row.point_type === 'undertime' ? '(1–60)' : '(61+)'}
                                                                                        </span>
                                                                                    </Label>
                                                                                    <Input
                                                                                        type="number"
                                                                                        min={row.point_type === 'undertime_more_than_hour' ? 61 : 1}
                                                                                        max={row.point_type === 'undertime' ? 60 : undefined}
                                                                                        placeholder="0"
                                                                                        value={row.undertime_minutes}
                                                                                        onChange={(e) =>
                                                                                            updateRow(row.id, 'undertime_minutes', e.target.value)
                                                                                        }
                                                                                        className="h-7 w-20 text-xs"
                                                                                    />
                                                                                </div>
                                                                            )}

                                                                        {/* Violation details */}
                                                                        <div className="flex items-center gap-1.5 min-w-40 flex-1">
                                                                            <Label className="text-xs whitespace-nowrap shrink-0 text-muted-foreground">Details</Label>
                                                                            <Input
                                                                                placeholder="Auto-generated if blank"
                                                                                value={row.violation_details}
                                                                                onChange={(e) =>
                                                                                    updateRow(row.id, 'violation_details', e.target.value)
                                                                                }
                                                                                className="h-7 text-xs min-w-0"
                                                                            />
                                                                        </div>

                                                                        {/* Notes */}
                                                                        <div className="flex items-center gap-1.5 min-w-32 flex-1">
                                                                            <Label className="text-xs whitespace-nowrap shrink-0 text-muted-foreground">Notes</Label>
                                                                            <Input
                                                                                placeholder="Optional"
                                                                                value={row.notes}
                                                                                onChange={(e) =>
                                                                                    updateRow(row.id, 'notes', e.target.value)
                                                                                }
                                                                                className="h-7 text-xs min-w-0"
                                                                            />
                                                                        </div>
                                                                    </div>
                                                                </TableCell>
                                                            </TableRow>
                                                        )}
                                                    </React.Fragment>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </div>

                                {/* Mobile card view */}
                                <div className="md:hidden space-y-3">
                                    {entries.map((row, index) => {
                                        const hasError = !!rowErrors[row.id];
                                        const isExpanded = expandedRows.has(row.id);
                                        return (
                                            <Card key={row.id} className={cn('shadow-sm', hasError && 'border-red-500')}>
                                                <CardHeader className="py-3 px-4">
                                                    <div className="flex items-center justify-between">
                                                        <span className="text-sm font-medium text-muted-foreground">
                                                            Entry #{index + 1}
                                                        </span>
                                                        <div className="flex items-center gap-1">
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-7 w-7"
                                                                onClick={() => toggleExpand(row.id)}
                                                            >
                                                                {isExpanded ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                                                            </Button>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-7 w-7 text-destructive hover:text-destructive"
                                                                onClick={() => removeRow(row.id)}
                                                            >
                                                                <Trash2 className="h-4 w-4" />
                                                            </Button>
                                                        </div>
                                                    </div>
                                                </CardHeader>
                                                <CardContent className="pb-3 px-4 space-y-3">
                                                    <UserPicker
                                                        users={users}
                                                        value={row.user_id}
                                                        onChange={(v) => updateRow(row.id, 'user_id', v)}
                                                        error={hasError && !row.user_id}
                                                    />

                                                    <DatePicker
                                                        value={row.shift_date}
                                                        onChange={(v) => updateRow(row.id, 'shift_date', v)}
                                                        placeholder="Violation date"
                                                    />

                                                    <Select
                                                        value={row.point_type}
                                                        onValueChange={(v) => updateRow(row.id, 'point_type', v as PointType)}
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select type" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {POINT_TYPE_OPTIONS.map((o) => (
                                                                <SelectItem key={o.value} value={o.value}>
                                                                    {o.label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>

                                                    <div className="flex items-center gap-2">
                                                        <Checkbox
                                                            id={`mob-advised-${row.id}`}
                                                            checked={row.is_advised}
                                                            onCheckedChange={(v) => updateRow(row.id, 'is_advised', Boolean(v))}
                                                        />
                                                        <Label htmlFor={`mob-advised-${row.id}`} className="text-sm cursor-pointer">
                                                            Advised absence
                                                        </Label>
                                                        {row.is_advised && (
                                                            <span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900 dark:text-blue-200">
                                                                Advised
                                                            </span>
                                                        )}
                                                    </div>

                                                    <div className="flex items-center gap-2">
                                                        <Checkbox
                                                            id={`mob-critical-${row.id}`}
                                                            checked={row.is_critical_day}
                                                            onCheckedChange={(v) => updateRow(row.id, 'is_critical_day', Boolean(v))}
                                                        />
                                                        <Label htmlFor={`mob-critical-${row.id}`} className="text-sm cursor-pointer">
                                                            Critical Working Day (×2)
                                                        </Label>
                                                    </div>

                                                    {hasError && (
                                                        <div className="flex items-start gap-1.5 bg-red-50 dark:bg-red-950/20 p-2 rounded">
                                                            <AlertCircle className="h-3.5 w-3.5 text-red-500 mt-0.5 shrink-0" />
                                                            <p className="text-xs text-red-600 dark:text-red-400">
                                                                {rowErrors[row.id].join(' ')}
                                                            </p>
                                                        </div>
                                                    )}

                                                    {isExpanded && (
                                                        <div className="space-y-2 pt-1 border-t">
                                                            {row.point_type === 'tardy' && (
                                                                <div className="space-y-1">
                                                                    <Label className="text-xs">Tardy minutes</Label>
                                                                    <Input
                                                                        type="number"
                                                                        min="0"
                                                                        placeholder="Minutes late"
                                                                        value={row.tardy_minutes}
                                                                        onChange={(e) => updateRow(row.id, 'tardy_minutes', e.target.value)}
                                                                    />
                                                                </div>
                                                            )}
                                                            {(row.point_type === 'undertime' || row.point_type === 'undertime_more_than_hour') && (
                                                                <div className="space-y-1">
                                                                    <Label className="text-xs">Undertime minutes</Label>
                                                                    <Input
                                                                        type="number"
                                                                        min={row.point_type === 'undertime_more_than_hour' ? 61 : 1}
                                                                        max={row.point_type === 'undertime' ? 60 : undefined}
                                                                        placeholder="Minutes"
                                                                        value={row.undertime_minutes}
                                                                        onChange={(e) => updateRow(row.id, 'undertime_minutes', e.target.value)}
                                                                    />
                                                                </div>
                                                            )}
                                                            <div className="space-y-1">
                                                                <Label className="text-xs">Violation details</Label>
                                                                <Textarea
                                                                    placeholder="Auto-generated if blank"
                                                                    value={row.violation_details}
                                                                    onChange={(e) => updateRow(row.id, 'violation_details', e.target.value)}
                                                                    rows={2}
                                                                    className="resize-none text-xs"
                                                                />
                                                            </div>
                                                            <div className="space-y-1">
                                                                <Label className="text-xs">Notes</Label>
                                                                <Textarea
                                                                    placeholder="Optional"
                                                                    value={row.notes}
                                                                    onChange={(e) => updateRow(row.id, 'notes', e.target.value)}
                                                                    rows={2}
                                                                    className="resize-none text-xs"
                                                                />
                                                            </div>
                                                        </div>
                                                    )}
                                                </CardContent>
                                            </Card>
                                        );
                                    })}
                                </div>

                                {/* Bottom submit bar */}
                                <div className="flex items-center justify-between p-4 rounded-lg border bg-card">
                                    <div className="text-sm text-muted-foreground">
                                        <span className="font-semibold text-foreground">{entries.length}</span> entr{entries.length === 1 ? 'y' : 'ies'} ·{' '}
                                        <span className="font-semibold text-red-600 dark:text-red-400">
                                            {totalPoints.toFixed(2)} pts total
                                        </span>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            onClick={() => router.visit(attendancePointsIndex().url)}
                                            disabled={submitting}
                                        >
                                            <ArrowLeft className="h-4 w-4 mr-1" />
                                            Cancel
                                        </Button>
                                        <Button
                                            onClick={handleSubmit}
                                            disabled={submitting || entries.length === 0}
                                            className="gap-2"
                                        >
                                            {submitting ? (
                                                <RefreshCw className="h-4 w-4 animate-spin" />
                                            ) : (
                                                <Check className="h-4 w-4" />
                                            )}
                                            Submit {entries.length} point{entries.length !== 1 ? 's' : ''}
                                        </Button>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>

            {/* Confirm Dialog */}
            <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Confirm Bulk Submission</AlertDialogTitle>
                        <AlertDialogDescription asChild>
                            <div className="space-y-3">
                                <p>
                                    You are about to create <strong>{entries.length}</strong> attendance point
                                    {entries.length !== 1 ? 's' : ''} totalling{' '}
                                    <strong className="text-red-600 dark:text-red-400">{totalPoints.toFixed(2)} pts</strong>.
                                </p>
                                <p className="text-sm">
                                    Each affected employee will be notified. Existing active points for the same employee
                                    and date will be replaced.
                                </p>
                                <div className="max-h-48 overflow-y-auto rounded border bg-muted/50 p-2 space-y-1 text-xs">
                                    {entries.map((row) => {
                                        const user = users.find((u) => String(u.id) === row.user_id);
                                        const label = POINT_TYPE_OPTIONS.find((o) => o.value === row.point_type)?.label ?? row.point_type;
                                        return (
                                            <div key={row.id} className="flex justify-between gap-2">
                                                <span className="font-medium truncate">
                                                    {user ? formatUserName(user) : 'Unknown'}
                                                </span>
                                                <span className="text-muted-foreground shrink-0">
                                                    {row.shift_date} · {label}{row.is_advised ? ' · Advised' : ''}{row.is_critical_day ? ' · ×2 Critical' : ''}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Go Back</AlertDialogCancel>
                        <AlertDialogAction onClick={handleConfirmedSubmit}>
                            Confirm & Submit
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
