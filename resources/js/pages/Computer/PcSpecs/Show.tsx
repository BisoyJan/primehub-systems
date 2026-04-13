
import React from 'react';
import { usePage, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { PageProps } from '@inertiajs/core';

interface ProcessorSpec {
    id: number;
    manufacturer: string;
    model: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
}

interface PcSpec {
    id: number;
    pc_number?: string | null;
    manufacturer: string;
    model: string;
    chipset: string;
    memory_type: string;
    ram_gb: number;
    disk_gb: number;
    available_ports: string | null;
    bios_release_date: string | null;
    station?: { id: number; name: string };
    processor_specs?: ProcessorSpec[];
}

interface Props extends PageProps {
    pcspec: PcSpec;
}

export default function Show() {
    const { pcspec } = usePage<Props>().props;

    return (
        <div className="max-w-md w-full mx-auto p-4 sm:p-6 bg-white dark:bg-gray-900 rounded-2xl shadow-lg border border-gray-100 dark:border-gray-800 mt-4">
            <div className="flex flex-col sm:flex-row items-center sm:items-start gap-4 mb-4">
                <div className="flex-1 text-center sm:text-left">
                    <h1 className="text-2xl sm:text-3xl font-extrabold mb-1 text-gray-900 dark:text-white tracking-tight">
                        {pcspec.pc_number || `PC #${pcspec.id}`}
                    </h1>
                    <span className="inline-block px-2 py-1 text-xs font-semibold rounded bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 mb-2">
                        PC Details
                    </span>
                </div>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6 text-gray-700 dark:text-gray-300">
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">Manufacturer</span>
                    <span className="font-medium">{pcspec.manufacturer}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">Model</span>
                    <span className="font-medium">{pcspec.model}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">Chipset</span>
                    <span className="font-medium">{pcspec.chipset}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">Memory Type</span>
                    <span className="font-medium">{pcspec.memory_type}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">RAM (GB)</span>
                    <span className="font-medium">{pcspec.ram_gb}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">Disk (GB)</span>
                    <span className="font-medium">{pcspec.disk_gb}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">Available Ports</span>
                    <span className="font-medium">{pcspec.available_ports || 'N/A'}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">Bios Release Date</span>
                    <span className="font-medium">{pcspec.bios_release_date || 'N/A'}</span>
                </div>
                {pcspec.station && (
                    <div className="flex flex-col gap-1">
                        <span className="text-xs text-gray-500 dark:text-gray-400">Station</span>
                        <span className="font-medium">{pcspec.station.name}</span>
                    </div>
                )}
            </div>
            {pcspec.processor_specs && pcspec.processor_specs.length > 0 && (
                <div className="mb-6">
                    <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-3">Processor(s)</h2>
                    <div className="space-y-3">
                        {pcspec.processor_specs.map((proc) => (
                            <div key={proc.id} className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-gray-700 dark:text-gray-300 border rounded-lg p-3">
                                <div className="flex flex-col gap-1">
                                    <span className="text-xs text-gray-500 dark:text-gray-400">Manufacturer</span>
                                    <span className="font-medium">{proc.manufacturer}</span>
                                </div>
                                <div className="flex flex-col gap-1">
                                    <span className="text-xs text-gray-500 dark:text-gray-400">Model</span>
                                    <span className="font-medium">{proc.model}</span>
                                </div>
                                <div className="flex flex-col gap-1">
                                    <span className="text-xs text-gray-500 dark:text-gray-400">Cores / Threads</span>
                                    <span className="font-medium">{proc.core_count} / {proc.thread_count}</span>
                                </div>
                                <div className="flex flex-col gap-1">
                                    <span className="text-xs text-gray-500 dark:text-gray-400">Clock (Base / Boost)</span>
                                    <span className="font-medium">{proc.base_clock_ghz} / {proc.boost_clock_ghz} GHz</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            <div className="flex flex-col sm:flex-row gap-3 mt-4">
                <Link href={`/pcspecs/${pcspec.id}/edit`} className="w-full sm:w-auto">
                    <Button variant="default" className="w-full sm:w-auto text-base py-2 px-4 rounded-lg shadow-md">
                        Edit
                    </Button>
                </Link>
                <Link href={`/pc-transfers/transfer?pc=${pcspec.id}`} className="w-full sm:w-auto">
                    <Button variant="outline" className="w-full sm:w-auto text-base py-2 px-4 rounded-lg shadow-md">
                        Transfer to Station
                    </Button>
                </Link>
            </div>
        </div>
    );
}
