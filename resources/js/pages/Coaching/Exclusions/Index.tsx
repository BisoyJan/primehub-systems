import { useState, useMemo } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import PaginationNav, { type PaginationLink } from '@/components/pagination-nav';
import { Check, ChevronsUpDown, Filter, History, ShieldOff, ShieldCheck } from 'lucide-react';

import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';

interface ExclusionRow {
    id: number;
    name: string;
    email: string;
    role: string;
    campaign: string | null;
    is_excluded: boolean;
    exclusion: null | {
        id: number;
        reason: string;
        notes: string | null;
        excluded_at: string | null;
        expires_at: string | null;
        excluded_by: string | null;
    };
}

interface Paginated {
    data: ExclusionRow[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Filters {
    search: string;
    role: string | null;
    campaign_id: string | null;
    status: string;
    per_page: number;
}

interface UserOption {
    id: number;
    name: string;
    email: string;
    role: string;
    campaign: string | null;
}

interface Props extends InertiaPageProps {
    users: Paginated;
    allUsers: UserOption[];
    filters: Filters;
    campaigns: { id: number; name: string }[];
    reasons: string[];
}

type PeriodPreset = { label: string; getRange: () => { start: string; end: string } };

const PERIOD_PRESETS: PeriodPreset[] = [
    {
        label: 'This month',
        getRange: () => {
            const today = new Date().toISOString().split('T')[0];
            const d = new Date();
            const end = new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().split('T')[0];
            return { start: today, end };
        },
    },
    {
        label: 'Next month',
        getRange: () => {
            const d = new Date();
            const start = new Date(d.getFullYear(), d.getMonth() + 1, 1).toISOString().split('T')[0];
            const end = new Date(d.getFullYear(), d.getMonth() + 2, 0).toISOString().split('T')[0];
            return { start, end };
        },
    },
    {
        label: '30 days',
        getRange: () => {
            const today = new Date();
            const start = today.toISOString().split('T')[0];
            const e = new Date(today);
            e.setDate(e.getDate() + 30);
            return { start, end: e.toISOString().split('T')[0] };
        },
    },
    {
        label: '60 days',
        getRange: () => {
            const today = new Date();
            const start = today.toISOString().split('T')[0];
            const e = new Date(today);
            e.setDate(e.getDate() + 60);
            return { start, end: e.toISOString().split('T')[0] };
        },
    },
    {
        label: 'Forever',
        getRange: () => ({ start: new Date().toISOString().split('T')[0], end: '' }),
    },
];

export default function CoachingExclusionsIndex() {
    const { users, allUsers, filters, campaigns, reasons } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Coaching Exclusions',
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Coaching', href: '/coaching/dashboard' },
            { title: 'Exclusions' },
        ],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [search, setSearch] = useState(filters.search ?? '');
    const [userSearchOpen, setUserSearchOpen] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState('');
    const [role, setRole] = useState(filters.role ?? 'all');
    const [campaignId, setCampaignId] = useState(filters.campaign_id ?? 'all');
    const [status, setStatus] = useState(filters.status ?? 'all');
    const [selected, setSelected] = useState<number[]>([]);

    const [excludeOpen, setExcludeOpen] = useState(false);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [restoreUser, setRestoreUser] = useState<ExclusionRow | null>(null);

    const includableSelected = useMemo(
        () => users.data.filter((u) => selected.includes(u.id) && !u.is_excluded),
        [selected, users.data],
    );

    const filteredUsers = useMemo(() => {
        if (!userSearchQuery) return allUsers.slice(0, 50);
        const q = userSearchQuery.toLowerCase();
        return allUsers
            .filter(
                (u) =>
                    u.name.toLowerCase().includes(q) ||
                    u.email.toLowerCase().includes(q) ||
                    (u.campaign?.toLowerCase().includes(q) ?? false),
            )
            .slice(0, 50);
    }, [allUsers, userSearchQuery]);

    const applyFilters = (overrides: Partial<Filters> = {}) => {
        router.get(
            '/coaching/exclusions',
            {
                search: overrides.search ?? search,
                role: (overrides.role ?? role) === 'all' ? null : overrides.role ?? role,
                campaign_id:
                    (overrides.campaign_id ?? campaignId) === 'all'
                        ? null
                        : overrides.campaign_id ?? campaignId,
                status: overrides.status ?? status,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const toggleSelect = (id: number) => {
        setSelected((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    };

    const toggleSelectAll = () => {
        const includable = users.data.filter((u) => !u.is_excluded).map((u) => u.id);
        const allSelected = includable.every((id) => selected.includes(id));
        setSelected(allSelected ? [] : includable);
    };

    // Single exclusion form
    const excludeForm = useForm<{
        user_id: number | null;
        reason: string;
        notes: string;
        excluded_at: string;
        expires_at: string;
    }>({
        user_id: null,
        reason: reasons[0] ?? '',
        notes: '',
        excluded_at: '',
        expires_at: '',
    });

    const submitExclude = () => {
        excludeForm.post('/coaching/exclusions', {
            preserveScroll: true,
            onSuccess: () => {
                setExcludeOpen(false);
                excludeForm.reset();
                setSelected([]);
            },
        });
    };

    // Bulk
    const bulkForm = useForm<{
        user_ids: number[];
        reason: string;
        notes: string;
        excluded_at: string;
        expires_at: string;
    }>({
        user_ids: [],
        reason: reasons[0] ?? '',
        notes: '',
        excluded_at: '',
        expires_at: '',
    });

    const openBulk = () => {
        bulkForm.setData('user_ids', includableSelected.map((u) => u.id));
        setBulkOpen(true);
    };

    const submitBulk = () => {
        bulkForm.post('/coaching/exclusions/bulk', {
            preserveScroll: true,
            onSuccess: () => {
                setBulkOpen(false);
                bulkForm.reset();
                setSelected([]);
            },
        });
    };

    // Restore (revoke)
    const restoreForm = useForm<{ revoke_notes: string }>({ revoke_notes: '' });

    const submitRestore = () => {
        if (!restoreUser) return;
        restoreForm.delete(`/coaching/exclusions/users/${restoreUser.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setRestoreUser(null);
                restoreForm.reset();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isLoading} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <PageHeader
                    title={title}
                    description="Manage which agents/team leads are temporarily excluded from coaching cadence calculations and dashboards."
                />

                {/* Filters */}
                <div className="bg-card rounded-lg border p-4 space-y-3">
                    <div className="flex flex-col sm:flex-row gap-2">
                        <div className="flex-1">
                            <Popover open={userSearchOpen} onOpenChange={setUserSearchOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={userSearchOpen}
                                        className="w-full justify-between font-normal"
                                    >
                                        <span className="truncate text-muted-foreground">
                                            {search || 'Search by name or email...'}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-[320px] p-0" align="start">
                                    <Command shouldFilter={false}>
                                        <CommandInput
                                            placeholder="Search name or email..."
                                            value={userSearchQuery}
                                            onValueChange={setUserSearchQuery}
                                        />
                                        <CommandList>
                                            <CommandEmpty>No user found.</CommandEmpty>
                                            <CommandGroup>
                                                <CommandItem
                                                    value="all"
                                                    onSelect={() => {
                                                        setSearch('');
                                                        setUserSearchOpen(false);
                                                        setUserSearchQuery('');
                                                        applyFilters({ search: '' });
                                                    }}
                                                    className="cursor-pointer"
                                                >
                                                    <Check
                                                        className={`mr-2 h-4 w-4 ${!search ? 'opacity-100' : 'opacity-0'}`}
                                                    />
                                                    All Users
                                                </CommandItem>
                                                {filteredUsers.map((u) => (
                                                    <CommandItem
                                                        key={u.id}
                                                        value={String(u.id)}
                                                        onSelect={() => {
                                                            setSearch(u.name);
                                                            setUserSearchOpen(false);
                                                            setUserSearchQuery('');
                                                            applyFilters({ search: u.name });
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${search === u.name ? 'opacity-100' : 'opacity-0'}`}
                                                        />
                                                        <div className="flex flex-col">
                                                            <span>{u.name}</span>
                                                            <span className="text-xs text-muted-foreground">
                                                                {u.role}{u.campaign ? ` · ${u.campaign}` : ''}
                                                            </span>
                                                        </div>
                                                    </CommandItem>
                                                ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                        </div>
                        <Select value={role} onValueChange={(v) => { setRole(v); applyFilters({ role: v }); }}>
                            <SelectTrigger className="sm:w-40"><SelectValue placeholder="Role" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Roles</SelectItem>
                                <SelectItem value="Agent">Agent</SelectItem>
                                <SelectItem value="Team Lead">Team Lead</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            value={campaignId}
                            onValueChange={(v) => { setCampaignId(v); applyFilters({ campaign_id: v }); }}
                        >
                            <SelectTrigger className="sm:w-48"><SelectValue placeholder="Campaign" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Campaigns</SelectItem>
                                {campaigns.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={status} onValueChange={(v) => { setStatus(v); applyFilters({ status: v }); }}>
                            <SelectTrigger className="sm:w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="excluded">Excluded only</SelectItem>
                                <SelectItem value="included">Included only</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setSearch('');
                                setUserSearchQuery('');
                                setRole('all');
                                setCampaignId('all');
                                setStatus('all');
                                applyFilters({ search: '', role: 'all', campaign_id: 'all', status: 'all' });
                            }}
                        >
                            <Filter className="mr-2 h-4 w-4" /> Reset
                        </Button>
                    </div>

                    {includableSelected.length > 0 && (
                        <div className="flex items-center justify-between rounded-md bg-muted/40 px-3 py-2">
                            <span className="text-sm">
                                {includableSelected.length} included user(s) selected
                            </span>
                            <Button size="sm" onClick={openBulk}>
                                <ShieldOff className="mr-2 h-4 w-4" /> Bulk Exclude
                            </Button>
                        </div>
                    )}
                </div>

                {/* Desktop table */}
                <div className="hidden md:block bg-card rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10">
                                    <Checkbox
                                        checked={
                                            users.data.length > 0 &&
                                            users.data
                                                .filter((u) => !u.is_excluded)
                                                .every((u) => selected.includes(u.id))
                                        }
                                        onCheckedChange={toggleSelectAll}
                                    />
                                </TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Campaign</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Reason</TableHead>
                                <TableHead>Excluded At</TableHead>
                                <TableHead>Expires</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.data.map((u) => (
                                <TableRow key={u.id}>
                                    <TableCell>
                                        {!u.is_excluded && (
                                            <Checkbox
                                                checked={selected.includes(u.id)}
                                                onCheckedChange={() => toggleSelect(u.id)}
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div className="font-medium">{u.name}</div>
                                        <div className="text-xs text-muted-foreground">{u.email}</div>
                                    </TableCell>
                                    <TableCell>{u.role}</TableCell>
                                    <TableCell>{u.campaign ?? <span className="text-muted-foreground">—</span>}</TableCell>
                                    <TableCell>
                                        {u.is_excluded ? (
                                            <Badge variant="destructive">Excluded</Badge>
                                        ) : (
                                            <Badge variant="secondary">Active</Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>{u.exclusion?.reason ?? '—'}</TableCell>
                                    <TableCell>
                                        {u.exclusion?.excluded_at
                                            ? new Date(u.exclusion.excluded_at).toLocaleDateString()
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        {u.exclusion?.expires_at
                                            ? new Date(u.exclusion.expires_at).toLocaleDateString()
                                            : <span className="text-muted-foreground">No expiry</span>}
                                    </TableCell>
                                    <TableCell className="text-right space-x-1">
                                        {u.is_excluded ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setRestoreUser(u)}
                                            >
                                                <ShieldCheck className="mr-1 h-4 w-4" /> Restore
                                            </Button>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    excludeForm.setData('user_id', u.id);
                                                    setExcludeOpen(true);
                                                }}
                                            >
                                                <ShieldOff className="mr-1 h-4 w-4" /> Exclude
                                            </Button>
                                        )}
                                        <Link
                                            href={`/coaching/exclusions/users/${u.id}/history`}
                                            className="inline-flex"
                                        >
                                            <Button size="sm" variant="ghost">
                                                <History className="h-4 w-4" />
                                            </Button>
                                        </Link>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {users.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="text-center text-muted-foreground py-8">
                                        No users match your filters.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                {/* Mobile cards */}
                <div className="md:hidden space-y-3">
                    {users.data.map((u) => (
                        <div key={u.id} className="bg-card border rounded-lg p-4 space-y-2">
                            <div className="flex justify-between">
                                <div>
                                    <div className="font-medium">{u.name}</div>
                                    <div className="text-xs text-muted-foreground">{u.email}</div>
                                </div>
                                {u.is_excluded ? (
                                    <Badge variant="destructive">Excluded</Badge>
                                ) : (
                                    <Badge variant="secondary">Active</Badge>
                                )}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                {u.role} • {u.campaign ?? 'No campaign'}
                            </div>
                            {u.exclusion && (
                                <div className="text-xs">
                                    <span className="font-medium">{u.exclusion.reason}</span>
                                    {u.exclusion.expires_at && (
                                        <span> · expires {new Date(u.exclusion.expires_at).toLocaleDateString()}</span>
                                    )}
                                </div>
                            )}
                            <div className="flex gap-2">
                                {u.is_excluded ? (
                                    <Button size="sm" variant="outline" className="flex-1" onClick={() => setRestoreUser(u)}>
                                        <ShieldCheck className="mr-1 h-4 w-4" /> Restore
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="flex-1"
                                        onClick={() => {
                                            excludeForm.setData('user_id', u.id);
                                            setExcludeOpen(true);
                                        }}
                                    >
                                        <ShieldOff className="mr-1 h-4 w-4" /> Exclude
                                    </Button>
                                )}
                                <Link href={`/coaching/exclusions/users/${u.id}/history`}>
                                    <Button size="sm" variant="ghost"><History className="h-4 w-4" /></Button>
                                </Link>
                            </div>
                        </div>
                    ))}
                </div>

                <PaginationNav links={users.links} />
            </div>

            {/* Exclude dialog */}
            <Dialog open={excludeOpen} onOpenChange={setExcludeOpen}>
                <DialogContent className="max-w-[90vw] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Exclude from Coaching</DialogTitle>
                        <DialogDescription>
                            This user will be hidden from coaching dashboards and dropdowns until restored.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div>
                            <Label>Reason</Label>
                            <Select
                                value={excludeForm.data.reason}
                                onValueChange={(v) => excludeForm.setData('reason', v)}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {reasons.map((r) => (
                                        <SelectItem key={r} value={r}>{r}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {excludeForm.errors.reason && (
                                <p className="text-xs text-destructive mt-1">{excludeForm.errors.reason}</p>
                            )}
                        </div>
                        <div>
                            <Label>Exclusion Period</Label>
                            <div className="grid grid-cols-2 gap-2 mt-1">
                                <div>
                                    <p className="text-xs text-muted-foreground mb-1">Start date</p>
                                    <Input
                                        type="date"
                                        value={excludeForm.data.excluded_at}
                                        onChange={(e) => excludeForm.setData('excluded_at', e.target.value)}
                                        placeholder="Today"
                                    />
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground mb-1">End date (optional)</p>
                                    <Input
                                        type="date"
                                        value={excludeForm.data.expires_at}
                                        onChange={(e) => excludeForm.setData('expires_at', e.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-1 mt-1.5">
                                {PERIOD_PRESETS.map(({ label, getRange }) => {
                                    const isForever = label === 'Forever';
                                    const active = isForever
                                        ? !!excludeForm.data.excluded_at && !excludeForm.data.expires_at
                                        : false;
                                    return (
                                        <button
                                            key={label}
                                            type="button"
                                            onClick={() => {
                                                const { start, end } = getRange();
                                                excludeForm.setData((prev) => ({ ...prev, excluded_at: start, expires_at: end }));
                                            }}
                                            className={`rounded border px-2 py-0.5 text-xs transition-colors ${isForever
                                                ? active
                                                    ? 'border-amber-500 bg-amber-100 text-amber-900'
                                                    : 'border-amber-400/50 text-amber-700 hover:bg-amber-50'
                                                : 'hover:bg-muted'
                                                }`}
                                        >
                                            {label}
                                        </button>
                                    );
                                })}
                                {(excludeForm.data.excluded_at || excludeForm.data.expires_at) && (
                                    <button
                                        type="button"
                                        onClick={() => excludeForm.setData((prev) => ({ ...prev, excluded_at: '', expires_at: '' }))}
                                        className="rounded border border-destructive/40 px-2 py-0.5 text-xs text-destructive hover:bg-destructive/10 transition-colors"
                                    >
                                        Clear
                                    </button>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Start defaults to today. Leave end blank or click <span className="font-medium">Forever</span> for indefinite exclusion.
                            </p>
                        </div>
                        <div>
                            <Label>Notes (optional)</Label>
                            <Textarea
                                rows={3}
                                value={excludeForm.data.notes}
                                onChange={(e) => excludeForm.setData('notes', e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setExcludeOpen(false)}>Cancel</Button>
                        <Button onClick={submitExclude} disabled={excludeForm.processing}>
                            Exclude
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Bulk dialog */}
            <Dialog open={bulkOpen} onOpenChange={setBulkOpen}>
                <DialogContent className="max-w-[90vw] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Bulk Exclude {bulkForm.data.user_ids.length} user(s)</DialogTitle>
                        <DialogDescription>All selected users will receive the same reason.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div>
                            <Label>Reason</Label>
                            <Select
                                value={bulkForm.data.reason}
                                onValueChange={(v) => bulkForm.setData('reason', v)}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {reasons.map((r) => (
                                        <SelectItem key={r} value={r}>{r}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Exclusion Period</Label>
                            <div className="grid grid-cols-2 gap-2 mt-1">
                                <div>
                                    <p className="text-xs text-muted-foreground mb-1">Start date</p>
                                    <Input
                                        type="date"
                                        value={bulkForm.data.excluded_at}
                                        onChange={(e) => bulkForm.setData('excluded_at', e.target.value)}
                                        placeholder="Today"
                                    />
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground mb-1">End date (optional)</p>
                                    <Input
                                        type="date"
                                        value={bulkForm.data.expires_at}
                                        onChange={(e) => bulkForm.setData('expires_at', e.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-1 mt-1.5">
                                {PERIOD_PRESETS.map(({ label, getRange }) => {
                                    const isForever = label === 'Forever';
                                    const active = isForever
                                        ? !!bulkForm.data.excluded_at && !bulkForm.data.expires_at
                                        : false;
                                    return (
                                        <button
                                            key={label}
                                            type="button"
                                            onClick={() => {
                                                const { start, end } = getRange();
                                                bulkForm.setData((prev) => ({ ...prev, excluded_at: start, expires_at: end }));
                                            }}
                                            className={`rounded border px-2 py-0.5 text-xs transition-colors ${isForever
                                                ? active
                                                    ? 'border-amber-500 bg-amber-100 text-amber-900'
                                                    : 'border-amber-400/50 text-amber-700 hover:bg-amber-50'
                                                : 'hover:bg-muted'
                                                }`}
                                        >
                                            {label}
                                        </button>
                                    );
                                })}
                                {(bulkForm.data.excluded_at || bulkForm.data.expires_at) && (
                                    <button
                                        type="button"
                                        onClick={() => bulkForm.setData((prev) => ({ ...prev, excluded_at: '', expires_at: '' }))}
                                        className="rounded border border-destructive/40 px-2 py-0.5 text-xs text-destructive hover:bg-destructive/10 transition-colors"
                                    >
                                        Clear
                                    </button>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Start defaults to today. Leave end blank or click <span className="font-medium">Forever</span> for indefinite exclusion.
                            </p>
                        </div>
                        <div>
                            <Label>Notes (optional)</Label>
                            <Textarea
                                rows={3}
                                value={bulkForm.data.notes}
                                onChange={(e) => bulkForm.setData('notes', e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setBulkOpen(false)}>Cancel</Button>
                        <Button onClick={submitBulk} disabled={bulkForm.processing}>
                            Exclude All
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Restore confirm */}
            <Dialog open={!!restoreUser} onOpenChange={(o) => !o && setRestoreUser(null)}>
                <DialogContent className="max-w-[90vw] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Restore to Coaching?</DialogTitle>
                        <DialogDescription>
                            {restoreUser
                                ? `${restoreUser.name} will reappear in coaching dashboards and dropdowns.`
                                : ''}
                        </DialogDescription>
                    </DialogHeader>
                    <div>
                        <Label>Notes (optional)</Label>
                        <Textarea
                            rows={3}
                            value={restoreForm.data.revoke_notes}
                            onChange={(e) => restoreForm.setData('revoke_notes', e.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRestoreUser(null)}>Cancel</Button>
                        <Button onClick={submitRestore} disabled={restoreForm.processing}>
                            Restore
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
