import { Head, usePage, Link } from '@inertiajs/react';
import { ArrowLeft, Clock } from 'lucide-react';

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

import { dashboard } from '@/routes';

const breadcrumbs = [{ title: 'PC Transfer History', href: dashboard().url }];

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

export default function History() {
    const page = usePage<PageProps>();
    const { transfers } = page.props;

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
            <Head title="PC Transfer History" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-2xl font-bold">PC Transfer History</h2>
                    <Link href="/pc-transfers">
                        <Button variant="outline">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back to Transfers
                        </Button>
                    </Link>
                </div>

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

                        {/* Stats */}
                        <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                            <div>
                                Showing {transfers.data.length} of {transfers.meta.total} transfers
                            </div>
                            <div>
                                Page {transfers.meta.current_page} of {transfers.meta.last_page}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {transfers.links && transfers.links.length > 0 && (
                    <div className="flex justify-center mt-4">
                        <PaginationNav
                            links={transfers.links}
                            onPageChange={(page) => {
                                window.location.href = `/pc-transfers/history?page=${page}`;
                            }}
                        />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
