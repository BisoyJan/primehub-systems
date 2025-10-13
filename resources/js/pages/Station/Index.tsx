import React, { useEffect, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import AppLayout from "@/layouts/app-layout";
import { Input } from "@/components/ui/input";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { toast } from "sonner";

const breadcrumbs = [{ title: "Stations", href: "/stations" }];

interface Station {
    id: number;
    site: string;
    station_number: string;
    campaign: string;
    status: string;
    pc_spec: string;
}
interface Flash { message?: string; type?: string; }
interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
interface StationsPayload {
    data: Station[];
    links: PaginationLink[];
    meta: Meta;
}

export default function StationIndex() {
    const { stations, flash } = usePage<{ stations: StationsPayload; flash?: Flash }>().props;
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState("");
    const [debouncedSearch, setDebouncedSearch] = useState("");

    useEffect(() => {
        if (flash?.message) {
            if (flash.type === "error") toast.error(flash.message);
            else toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        if (debouncedSearch) {
            setLoading(true);
            router.get("/stations", { search: debouncedSearch }, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setLoading(false),
            });
        }
    }, [debouncedSearch]);


    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                <div className="flex items-center gap-3 mb-4">
                    <h2 className="text-xl font-semibold">Station Management</h2>
                    <div className="ml-auto flex items-center gap-2">
                        <Input
                            type="search"
                            placeholder="Search site, station #, campaign..."
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            className="w-64"
                        />
                        <Button onClick={() => router.get('/stations/create')}>
                            Add Station
                        </Button>
                        <Button onClick={() => router.get('/sites')}>
                            Site Management
                        </Button>
                        <Button onClick={() => router.get('/campaigns')}>
                            Campaign Management
                        </Button>
                    </div>
                </div>
                <div className="shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto ">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Station #</TableHead>
                                    <TableHead>Campaign</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>PC Spec</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {stations.data.map((station) => (
                                    <TableRow key={station.id}>
                                        <TableCell>{station.id}</TableCell>
                                        <TableCell>{station.site}</TableCell>
                                        <TableCell>{station.station_number}</TableCell>
                                        <TableCell>{station.campaign}</TableCell>
                                        <TableCell>{station.status}</TableCell>
                                        <TableCell>{station.pc_spec}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Button variant="outline" size="sm" onClick={() => router.get(`/stations/${station.id}/edit`)} disabled={loading}>
                                                    Edit
                                                </Button>
                                                <Button variant="destructive" size="sm" onClick={() => {
                                                    if (confirm(`Delete station '${station.station_number}'?`)) {
                                                        setLoading(true);
                                                        router.delete(`/stations/${station.id}`, {
                                                            onFinish: () => setLoading(false),
                                                            onSuccess: () => toast.success("Station deleted"),
                                                            onError: () => toast.error("Delete failed"),
                                                        });
                                                    }
                                                }} className="ml-2" disabled={loading}>
                                                    Delete
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {stations.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-gray-500">
                                            No stations found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>
                <div className="flex justify-center mt-4">
                    {stations.links && stations.links.length > 0 && (
                        <PaginationNav links={stations.links} />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
