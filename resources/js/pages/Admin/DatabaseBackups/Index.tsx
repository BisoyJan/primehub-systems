import React, { useState, useEffect } from "react";
import { Head, useForm, usePage, router } from "@inertiajs/react";
import type { PageProps as InertiaPageProps } from "@inertiajs/core";

import AppLayout from "@/layouts/app-layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Progress } from "@/components/ui/progress";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/components/ui/dialog";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import {
    Search,
    Filter,
    Plus,
    Download,
    Trash2,
    Database,
    RefreshCw,
    Loader2,
} from "lucide-react";

import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { DeleteConfirmDialog } from "@/components/DeleteConfirmDialog";
import { Can } from "@/components/authorization";
import { usePermission } from "@/hooks/useAuthorization";
import { LoadingOverlay } from "@/components/LoadingOverlay";

import { index, store, destroy, progress, download, cleanOld } from "@/routes/database-backups";

interface Creator {
    id: number;
    name: string;
}

interface DatabaseBackup {
    id: number;
    filename: string;
    disk: string;
    path: string;
    size: number;
    formatted_size: string;
    status: "pending" | "in_progress" | "completed" | "failed";
    error_message: string | null;
    created_by: number;
    creator: Creator | null;
    completed_at: string | null;
    created_at: string;
}

interface PaginatedBackups {
    data: DatabaseBackup[];
    links: PaginationLink[];
}

interface Props extends InertiaPageProps {
    backups: PaginatedBackups;
    search?: string;
    flash?: {
        message?: string;
        type?: string;
        backup_job_id?: string;
    };
}

const statusVariant = (status: string) => {
    switch (status) {
        case "completed":
            return "default";
        case "pending":
        case "in_progress":
            return "secondary";
        case "failed":
            return "destructive";
        default:
            return "outline";
    }
};

const statusLabel = (status: string) => {
    switch (status) {
        case "in_progress":
            return "In Progress";
        case "completed":
            return "Completed";
        case "pending":
            return "Pending";
        case "failed":
            return "Failed";
        default:
            return status;
    }
};

export default function DatabaseBackupsIndex() {
    const props = usePage<Props>().props;
    const { backups, search: initialSearch } = props;
    const form = useForm({});

    const { title, breadcrumbs } = usePageMeta({
        title: "Database Backups",
        breadcrumbs: [{ title: "Database Backups", href: index().url }],
    });

    useFlashMessage();
    const isLoading = usePageLoading();
    const { can } = usePermission();

    const [searchQuery, setSearchQuery] = useState(initialSearch || "");
    const [activeJobId, setActiveJobId] = useState<string | null>(null);
    const [jobProgress, setJobProgress] = useState({ percent: 0, status: "Waiting...", finished: false, error: false });
    const [cleanDays, setCleanDays] = useState("30");
    const [cleanDialogOpen, setCleanDialogOpen] = useState(false);
    const [cleaningOld, setCleaningOld] = useState(false);

    // Check for backup_job_id in flash
    useEffect(() => {
        const flash = (props as any).flash;
        if (flash?.backup_job_id) {
            setActiveJobId(flash.backup_job_id);
        }
    }, [props]);

    // Poll for backup progress
    useEffect(() => {
        if (!activeJobId) return;

        const interval = setInterval(async () => {
            try {
                const res = await fetch(progress(activeJobId).url);
                const data = await res.json();
                setJobProgress(data);

                if (data.finished) {
                    clearInterval(interval);
                    setActiveJobId(null);
                    router.reload({ only: ["backups"] });
                }
            } catch {
                // Silently ignore fetch errors during polling
            }
        }, 2000);

        return () => clearInterval(interval);
    }, [activeJobId]);

    const handleFilter = () => {
        router.get(index().url, { search: searchQuery }, { preserveState: true, preserveScroll: true });
    };

    const handleReset = () => {
        setSearchQuery("");
        router.get(index().url);
    };

    const handleCreateBackup = () => {
        form.post(store().url, { preserveScroll: true });
    };

    const handleDelete = (id: number) => {
        form.delete(destroy(id).url, { preserveScroll: true });
    };

    const handleCleanOld = () => {
        setCleaningOld(true);
        router.post(cleanOld().url, { days: parseInt(cleanDays) }, {
            preserveScroll: true,
            onSuccess: () => setCleanDialogOpen(false),
            onFinish: () => setCleaningOld(false),
        });
    };

    const formatDate = (dateStr: string | null) => {
        if (!dateStr) return "-";
        return new Date(dateStr).toLocaleString();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader
                    title="Database Backups"
                    description="Create, manage, and download database backups"
                />

                {/* Progress Banner */}
                {activeJobId && !jobProgress.finished && (
                    <div className="bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                        <div className="flex items-center gap-3 mb-2">
                            <Loader2 className="h-4 w-4 animate-spin text-blue-600" />
                            <span className="text-sm font-medium text-blue-800 dark:text-blue-200">
                                {jobProgress.status}
                            </span>
                        </div>
                        <Progress value={jobProgress.percent} className="h-2" />
                        <div className="text-xs text-blue-600 dark:text-blue-400 mt-1">
                            {jobProgress.percent}% complete
                        </div>
                    </div>
                )}

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search backups..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    onKeyDown={(e) => e.key === "Enter" && handleFilter()}
                                    className="pl-8"
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                            <Button onClick={handleFilter} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>
                            <Button variant="outline" onClick={handleReset} className="flex-1 sm:flex-none">
                                Reset
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => router.reload({ only: ["backups"] })}
                                title="Refresh"
                            >
                                <RefreshCw className="h-4 w-4" />
                            </Button>

                            <Can permission="database_backups.clean">
                                <Dialog open={cleanDialogOpen} onOpenChange={setCleanDialogOpen}>
                                    <DialogTrigger asChild>
                                        <Button variant="outline" className="flex-1 sm:flex-none">
                                            <Trash2 className="mr-2 h-4 w-4" />
                                            Clean Old
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="max-w-[90vw] sm:max-w-md">
                                        <DialogHeader>
                                            <DialogTitle>Clean Old Backups</DialogTitle>
                                            <DialogDescription>
                                                Delete backups older than the specified number of days.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div className="py-4">
                                            <label className="text-sm font-medium">
                                                Delete backups older than (days):
                                            </label>
                                            <Input
                                                type="number"
                                                min="1"
                                                max="365"
                                                value={cleanDays}
                                                onChange={(e) => setCleanDays(e.target.value)}
                                                className="mt-2"
                                            />
                                        </div>
                                        <DialogFooter>
                                            <Button variant="outline" onClick={() => setCleanDialogOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                onClick={handleCleanOld}
                                                disabled={cleaningOld}
                                            >
                                                {cleaningOld ? "Cleaning..." : "Clean Old Backups"}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </Can>

                            <Can permission="database_backups.create">
                                <Button
                                    onClick={handleCreateBackup}
                                    disabled={form.processing || !!activeJobId}
                                    className="flex-1 sm:flex-none"
                                >
                                    {form.processing || activeJobId ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Plus className="mr-2 h-4 w-4" />
                                    )}
                                    {form.processing ? "Starting..." : activeJobId ? "Backup Running..." : "Create Backup"}
                                </Button>
                            </Can>
                        </div>
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            Showing {backups.data.length} records
                        </div>
                    </div>
                </div>

                {/* Desktop Table View */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/50">
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>Filename</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Size</TableHead>
                                    <TableHead>Created By</TableHead>
                                    <TableHead>Created At</TableHead>
                                    <TableHead>Completed</TableHead>
                                    <TableHead className="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                {backups.data.map((backup) => (
                                    <TableRow key={backup.id}>
                                        <TableCell className="hidden lg:table-cell">{backup.id}</TableCell>
                                        <TableCell className="font-medium">
                                            <div className="flex items-center gap-2">
                                                <Database className="h-4 w-4 text-muted-foreground" />
                                                {backup.filename}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={statusVariant(backup.status)}>
                                                {statusLabel(backup.status)}
                                            </Badge>
                                            {backup.error_message && (
                                                <p className="text-xs text-red-500 mt-1 max-w-[200px] truncate" title={backup.error_message}>
                                                    {backup.error_message}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell>{backup.status === "completed" ? backup.formatted_size : "-"}</TableCell>
                                        <TableCell>{backup.creator?.name ?? "System"}</TableCell>
                                        <TableCell>{formatDate(backup.created_at)}</TableCell>
                                        <TableCell>{formatDate(backup.completed_at)}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-center gap-2">
                                                <Can permission="database_backups.download">
                                                    {backup.status === "completed" && (
                                                        <a href={download(backup.id).url}>
                                                            <Button variant="outline" size="sm">
                                                                <Download className="mr-1 h-3 w-3" />
                                                                Download
                                                            </Button>
                                                        </a>
                                                    )}
                                                </Can>

                                                <Can permission="database_backups.delete">
                                                    <DeleteConfirmDialog
                                                        onConfirm={() => handleDelete(backup.id)}
                                                        title="Delete Backup"
                                                        description={`Are you sure you want to delete backup "${backup.filename}"? This action cannot be undone.`}
                                                    />
                                                </Can>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {backups.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="h-24 text-center text-muted-foreground">
                                            No backups found. Create your first backup to get started.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {backups.data.map((backup) => (
                        <div key={backup.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                            <div className="flex justify-between items-start">
                                <div className="flex items-center gap-2">
                                    <Database className="h-4 w-4 text-muted-foreground" />
                                    <div className="text-sm font-semibold truncate max-w-[200px]">{backup.filename}</div>
                                </div>
                                <Badge variant={statusVariant(backup.status)}>
                                    {statusLabel(backup.status)}
                                </Badge>
                            </div>

                            {backup.error_message && (
                                <p className="text-xs text-red-500 truncate" title={backup.error_message}>
                                    {backup.error_message}
                                </p>
                            )}

                            <div className="grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <span className="text-muted-foreground">Size:</span>{" "}
                                    <span className="font-medium">
                                        {backup.status === "completed" ? backup.formatted_size : "-"}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">By:</span>{" "}
                                    <span className="font-medium">{backup.creator?.name ?? "System"}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Created:</span>{" "}
                                    <span className="font-medium">{formatDate(backup.created_at)}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Completed:</span>{" "}
                                    <span className="font-medium">{formatDate(backup.completed_at)}</span>
                                </div>
                            </div>

                            <div className="flex gap-2 pt-2 border-t">
                                <Can permission="database_backups.download">
                                    {backup.status === "completed" && (
                                        <a href={download(backup.id).url} className="flex-1">
                                            <Button variant="outline" size="sm" className="w-full">
                                                <Download className="mr-1 h-3 w-3" />
                                                Download
                                            </Button>
                                        </a>
                                    )}
                                </Can>
                                <Can permission="database_backups.delete">
                                    <div className="flex-1">
                                        <DeleteConfirmDialog
                                            onConfirm={() => handleDelete(backup.id)}
                                            title="Delete Backup"
                                            description={`Are you sure you want to delete "${backup.filename}"?`}
                                        />
                                    </div>
                                </Can>
                            </div>
                        </div>
                    ))}
                    {backups.data.length === 0 && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No backups found. Create your first backup to get started.
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {backups.links && (
                    <div className="flex justify-center">
                        <PaginationNav links={backups.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
