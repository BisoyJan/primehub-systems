import { Button } from '@/components/ui/button';
import type { PageProps } from '@inertiajs/core';
import { Link, usePage } from '@inertiajs/react';

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
        <div className="mx-auto mt-4 w-full max-w-md rounded-2xl border border-gray-100 bg-white p-4 shadow-lg sm:p-6 dark:border-gray-800 dark:bg-gray-900">
            <div className="mb-4 flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                <div className="flex-1 text-center sm:text-left">
                    <h1 className="mb-1 text-2xl font-extrabold tracking-tight text-gray-900 sm:text-3xl dark:text-white">
                        {pcspec.pc_number || `PC #${pcspec.id}`}
                    </h1>
                    <span className="mb-2 inline-block rounded bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                        PC Details
                    </span>
                </div>
            </div>
            <div className="mb-6 grid grid-cols-1 gap-4 text-gray-700 sm:grid-cols-2 dark:text-gray-300">
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        Manufacturer
                    </span>
                    <span className="font-medium">{pcspec.manufacturer}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        Chipset
                    </span>
                    <span className="font-medium">{pcspec.chipset}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        Memory Type
                    </span>
                    <span className="font-medium">{pcspec.memory_type}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        RAM (GB)
                    </span>
                    <span className="font-medium">{pcspec.ram_gb}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        Disk (GB)
                    </span>
                    <span className="font-medium">{pcspec.disk_gb}</span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        Available Ports
                    </span>
                    <span className="font-medium">
                        {pcspec.available_ports || 'N/A'}
                    </span>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        Bios Release Date
                    </span>
                    <span className="font-medium">
                        {pcspec.bios_release_date || 'N/A'}
                    </span>
                </div>
                {pcspec.station && (
                    <div className="flex flex-col gap-1">
                        <span className="text-xs text-gray-500 dark:text-gray-400">
                            Station
                        </span>
                        <span className="font-medium">
                            {pcspec.station.name}
                        </span>
                    </div>
                )}
            </div>
            {pcspec.processor_specs && pcspec.processor_specs.length > 0 && (
                <div className="mb-6">
                    <h2 className="mb-3 text-lg font-bold text-gray-900 dark:text-white">
                        Processor(s)
                    </h2>
                    <div className="space-y-3">
                        {pcspec.processor_specs.map((proc) => (
                            <div
                                key={proc.id}
                                className="grid grid-cols-1 gap-2 rounded-lg border p-3 text-gray-700 sm:grid-cols-2 dark:text-gray-300"
                            >
                                <div className="flex flex-col gap-1">
                                    <span className="text-xs text-gray-500 dark:text-gray-400">
                                        Manufacturer
                                    </span>
                                    <span className="font-medium">
                                        {proc.manufacturer}
                                    </span>
                                </div>
                                <div className="flex flex-col gap-1">
                                    <span className="text-xs text-gray-500 dark:text-gray-400">
                                        Model
                                    </span>
                                    <span className="font-medium">
                                        {proc.model}
                                    </span>
                                </div>
                                <div className="flex flex-col gap-1">
                                    <span className="text-xs text-gray-500 dark:text-gray-400">
                                        Cores / Threads
                                    </span>
                                    <span className="font-medium">
                                        {proc.core_count} / {proc.thread_count}
                                    </span>
                                </div>
                                <div className="flex flex-col gap-1">
                                    <span className="text-xs text-gray-500 dark:text-gray-400">
                                        Clock (Base / Boost)
                                    </span>
                                    <span className="font-medium">
                                        {proc.base_clock_ghz} /{' '}
                                        {proc.boost_clock_ghz} GHz
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            <div className="mt-4 flex flex-col gap-3 sm:flex-row">
                <Link
                    href={`/pcspecs/${pcspec.id}/edit`}
                    className="w-full sm:w-auto"
                >
                    <Button
                        variant="default"
                        className="w-full rounded-lg px-4 py-2 text-base shadow-md sm:w-auto"
                    >
                        Edit
                    </Button>
                </Link>
                <Link
                    href={`/pc-transfers/transfer?pc=${pcspec.id}`}
                    className="w-full sm:w-auto"
                >
                    <Button
                        variant="outline"
                        className="w-full rounded-lg px-4 py-2 text-base shadow-md sm:w-auto"
                    >
                        Transfer to Station
                    </Button>
                </Link>
            </div>
        </div>
    );
}
