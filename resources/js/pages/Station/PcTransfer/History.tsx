import { Head, Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { ArrowLeft, Clock, RefreshCw } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import {
    history as pcTransfersHistoryRoute,
    index as pcTransfersIndexRoute,
} from '@/routes/pc-transfers';
import { index as stationsIndexRoute } from '@/routes/stations';

type Transfer = {
    id: number;
    from_station: string | null;
    to_station: string | null;
    pc_spec: string | null;
    user: string | null;
    transfer_type: string;
    notes: string | null;
    created_at: string;
};

type TransfersPayload = {
    data: Transfer[];
    links: PaginationLink[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

type PageProps = {
    transfers: TransfersPayload;
};

export default function History({ transfers }: PageProps) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'PC Transfer History',
        breadcrumbs: [
            { title: 'Stations', href: stationsIndexRoute().url },
            { title: 'PC Transfer', href: pcTransfersIndexRoute().url },
            { title: 'History', href: pcTransfersHistoryRoute().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());

    const summary = useMemo(() => {
        const showingStart = transfers.data.length > 0
            ? transfers.meta.per_page * (transfers.meta.current_page - 1) + 1
            : 0;
        const showingEnd = transfers.data.length > 0
            ? showingStart + transfers.data.length - 1
            : 0;
        return transfers.data.length > 0
            ? `Showing ${showingStart}-${showingEnd} of ${transfers.meta.total} transfers`
            : 'No transfers to display';
    }, [transfers]);

    const handlePaginate = (pageNumber: number) => {
        router.get(pcTransfersHistoryRoute().url, { page: pageNumber }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['transfers'],
        });
    };

    const handleRefresh = () => {
        router.get(pcTransfersHistoryRoute().url, {}, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['transfers'],
            onSuccess: () => setLastRefresh(new Date()),
        });
    };

    function getTransferTypeVariant(type: string): 'default' | 'secondary' | 'destructive' | 'outline' {
        switch (type) {
            case 'assign':
                return 'default';
            case 'swap':
                return 'secondary';
            case 'remove':
                return 'destructive';
            default:
                return 'outline';
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="PC Transfer History"
                    description="Complete log of assignments, swaps, and removals"
                >
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" onClick={handleRefresh}>
                            <RefreshCw className="h-4 w-4 mr-2" />
                            Refresh
                        </Button>
                        <Link href={pcTransfersIndexRoute().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to Transfers
                            </Button>
                        </Link>
                    </div>
                </PageHeader>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Clock className="h-5 w-5" />
                            Transfer History
                        </CardTitle>
                        <CardDescription>
                            Complete log of all PC transfers between stations
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date & Time</TableHead>
                                        <TableHead>PC</TableHead>
                                        <TableHead>From Station</TableHead>
                                        <TableHead>To Station</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>User</TableHead>
                                        <TableHead>Notes</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transfers.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-center py-8 text-muted-foreground">
                                                No transfer history found
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        transfers.data.map((transfer) => (
                                            <TableRow key={transfer.id}>
                                                <TableCell className="font-medium">
                                                    {transfer.created_at}
                                                </TableCell>
                                                <TableCell>
                                                    <span className="text-blue-600 font-medium">
                                                        {transfer.pc_spec || '-'}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {transfer.from_station ? (
                                                        <span className="text-orange-600">
                                                            {transfer.from_station}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {transfer.to_station ? (
                                                        <span className="text-green-600">
                                                            {transfer.to_station}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={getTransferTypeVariant(transfer.transfer_type)}>
                                                        {transfer.transfer_type}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>{transfer.user || '-'}</TableCell>
                                                <TableCell>
                                                    {transfer.notes ? (
                                                        <span className="text-sm text-muted-foreground">
                                                            {transfer.notes}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="mt-4 flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                            <span>{summary}</span>
                            <span className="text-xs">Last updated: {lastRefresh.toLocaleTimeString()}</span>
                        </div>
                    </CardContent>
                </Card>

                {transfers.links && transfers.links.length > 0 && (
                    <div className="flex justify-center mt-4">
                        <PaginationNav
                            links={transfers.links}
                            onPageChange={handlePaginate}
                            only={['transfers']}
                        />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
