import { Head, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { format } from 'date-fns';
import { AlertTriangle, Search, Loader2 } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Checkbox } from '@/components/ui/checkbox';
import { type SharedData } from '@/types';

interface Anomaly {
    type: string;
    severity: 'high' | 'medium' | 'low';
    description: string;
    user: {
        id: number;
        name: string;
        employee_number: string;
    };
    records: Array<{
        id: number;
        scan_datetime: string;
        site: string;
    }>;
    details: Record<string, string | number | boolean>;
}

interface Stats {
    total_records: number;
    oldest_record: string | null;
    newest_record: string | null;
}

interface DetectionResults {
    total_anomalies: number;
    by_type: Record<string, number>;
    by_severity: Record<string, number>;
    anomalies: Anomaly[];
}

interface Stats {
    total_records: number;
    oldest_record: string | null;
    newest_record: string | null;
}

const anomalyTypes = [
    { value: 'simultaneous_sites', title: 'Simultaneous Sites', description: 'Scans at different sites within 30 minutes' },
    { value: 'impossible_gaps', title: 'Impossible Time Gaps', description: 'Time going backwards' },
    { value: 'duplicate_scans', title: 'Duplicate Scans', description: 'Multiple scans in the same minute' },
    { value: 'unusual_hours', title: 'Unusual Hours', description: 'Scans between 2-5 AM' },
    { value: 'excessive_scans', title: 'Excessive Scans', description: 'More than 6 scans per day' },
];

const severityColors = {
    high: 'bg-red-100 text-red-800 border-red-200',
    medium: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    low: 'bg-blue-100 text-blue-800 border-blue-200',
};

interface PageProps extends SharedData {
    stats: Stats;
    results?: DetectionResults;
}

export default function Anomalies({ stats, results }: PageProps) {
    const { auth } = usePage<PageProps>().props;
    const timeFormat = auth.user.time_format || '24';
    const { title, breadcrumbs } = usePageMeta({
        title: 'Anomaly Detection',
        breadcrumbs: [
            { title: 'Attendance', href: '/attendance' },
            { title: 'Anomaly Detection', href: '/biometric-anomalies' },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [selectedTypes, setSelectedTypes] = useState<string[]>(anomalyTypes.map(t => t.value));
    const [minSeverity, setMinSeverity] = useState<'low' | 'medium' | 'high'>('low');
    const [isLoading, setIsLoading] = useState(false);
    const [detectionResults, setDetectionResults] = useState<DetectionResults | null>(results || null);
    const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set());

    // Update detection results when results prop changes
    useEffect(() => {
        if (results) {
            setDetectionResults(results);
        }
    }, [results]);

    const handleDetect = () => {
        if (!startDate || !endDate) {
            alert('Please select both start and end dates');
            return;
        }

        setIsLoading(true);
        router.post(
            '/biometric-anomalies/detect',
            {
                start_date: startDate,
                end_date: endDate,
                anomaly_types: selectedTypes,
                min_severity: minSeverity,
            },
            {
                onFinish: () => {
                    setIsLoading(false);
                },
            }
        );
    };

    const toggleRow = (index: number) => {
        const newExpanded = new Set(expandedRows);
        if (newExpanded.has(index)) {
            newExpanded.delete(index);
        } else {
            newExpanded.add(index);
        }
        setExpandedRows(newExpanded);
    };

    const toggleType = (typeValue: string) => {
        setSelectedTypes(prev =>
            prev.includes(typeValue)
                ? prev.filter(t => t !== typeValue)
                : [...prev, typeValue]
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <PageHeader title={title} />
            <LoadingOverlay isLoading={isPageLoading} />

            <div className="py-6">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="space-y-6">
                        {/* Database Stats */}
                        <div className="grid gap-4 md:grid-cols-3">
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Total Records</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-bold">{stats.total_records.toLocaleString()}</div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Oldest Record</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-bold">
                                        {stats.oldest_record ? new Date(stats.oldest_record).toLocaleDateString() : 'N/A'}
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Newest Record</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-bold">
                                        {stats.newest_record ? new Date(stats.newest_record).toLocaleDateString() : 'N/A'}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Detection Form */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <AlertTriangle className="h-5 w-5" />
                                    Detect Anomalies
                                </CardTitle>
                                <CardDescription>
                                    Find unusual patterns in biometric scan records
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="start-date">Start Date</Label>
                                        <Input
                                            id="start-date"
                                            type="date"
                                            value={startDate}
                                            onChange={(e) => setStartDate(e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="end-date">End Date</Label>
                                        <Input
                                            id="end-date"
                                            type="date"
                                            value={endDate}
                                            onChange={(e) => setEndDate(e.target.value)}
                                            min={startDate}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-3">
                                    <Label>Anomaly Types</Label>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {anomalyTypes.map((type) => (
                                            <div key={type.value} className="flex items-start space-x-2">
                                                <Checkbox
                                                    id={type.value}
                                                    checked={selectedTypes.includes(type.value)}
                                                    onCheckedChange={() => toggleType(type.value)}
                                                />
                                                <div className="grid gap-1.5 leading-none">
                                                    <label
                                                        htmlFor={type.value}
                                                        className="text-sm font-medium leading-none cursor-pointer peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                                    >
                                                        {type.title}
                                                    </label>
                                                    <p className="text-sm text-muted-foreground">
                                                        {type.description}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="min-severity">Minimum Severity</Label>
                                    <select
                                        id="min-severity"
                                        value={minSeverity}
                                        onChange={(e) => setMinSeverity(e.target.value as 'low' | 'medium' | 'high')}
                                        className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <option value="low">Low and above</option>
                                        <option value="medium">Medium and above</option>
                                        <option value="high">High only</option>
                                    </select>
                                </div>

                                <Button
                                    onClick={handleDetect}
                                    disabled={isLoading || !startDate || !endDate || selectedTypes.length === 0}
                                >
                                    {isLoading ? (
                                        <>
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                            Detecting...
                                        </>
                                    ) : (
                                        <>
                                            <Search className="mr-2 h-4 w-4" />
                                            Detect Anomalies
                                        </>
                                    )}
                                </Button>
                            </CardContent>
                        </Card>

                        {/* Results Summary */}
                        {detectionResults && (
                            <>
                                <div className="grid gap-4 md:grid-cols-4">
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium">Total Anomalies</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-2xl font-bold">{detectionResults.total_anomalies}</div>
                                        </CardContent>
                                    </Card>
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium">High Severity</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-2xl font-bold text-red-600">
                                                {detectionResults.by_severity.high || 0}
                                            </div>
                                        </CardContent>
                                    </Card>
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium">Medium Severity</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-2xl font-bold text-yellow-600">
                                                {detectionResults.by_severity.medium || 0}
                                            </div>
                                        </CardContent>
                                    </Card>
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium">Low Severity</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="text-2xl font-bold text-blue-600">
                                                {detectionResults.by_severity.low || 0}
                                            </div>
                                        </CardContent>
                                    </Card>
                                </div>

                                {/* Anomalies Table */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Detected Anomalies</CardTitle>
                                        <CardDescription>
                                            Click on a row to see detailed information
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {detectionResults.anomalies.length === 0 ? (
                                            <p className="text-center text-muted-foreground py-8">
                                                No anomalies detected for the selected criteria
                                            </p>
                                        ) : (
                                            <div className="space-y-2">
                                                {detectionResults.anomalies.map((anomaly, index) => (
                                                    <Collapsible key={index}>
                                                        <CollapsibleTrigger
                                                            onClick={() => toggleRow(index)}
                                                            className="w-full"
                                                        >
                                                            <div className="flex items-center justify-between p-4 rounded-lg border hover:bg-gray-50 cursor-pointer">
                                                                <div className="flex items-center gap-4 flex-1">
                                                                    <Badge className={severityColors[anomaly.severity]}>
                                                                        {anomaly.severity.toUpperCase()}
                                                                    </Badge>
                                                                    <div className="text-left">
                                                                        <p className="font-medium">{anomaly.user.name}</p>
                                                                        <p className="text-sm text-muted-foreground">
                                                                            {anomaly.user.employee_number}
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                                <div className="text-right">
                                                                    <p className="text-sm font-medium">
                                                                        {anomaly.type.replace(/_/g, ' ').toUpperCase()}
                                                                    </p>
                                                                    <p className="text-sm text-muted-foreground">
                                                                        {anomaly.records.length} records
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </CollapsibleTrigger>
                                                        <CollapsibleContent>
                                                            <div className="mt-2 p-4 border rounded-lg bg-gray-50">
                                                                <p className="text-sm mb-3">{anomaly.description}</p>
                                                                <div className="space-y-2">
                                                                    <h4 className="text-sm font-semibold">Related Records:</h4>
                                                                    <Table>
                                                                        <TableHeader>
                                                                            <TableRow>
                                                                                <TableHead>Date/Time</TableHead>
                                                                                <TableHead>Site</TableHead>
                                                                            </TableRow>
                                                                        </TableHeader>
                                                                        <TableBody>
                                                                            {anomaly.records.map((record) => (
                                                                                <TableRow key={record.id}>
                                                                                    <TableCell>
                                                                                        {format(new Date(record.scan_datetime), timeFormat === '12' ? 'MMM d, yyyy h:mm:ss a' : 'MMM d, yyyy HH:mm:ss')}
                                                                                    </TableCell>
                                                                                    <TableCell>{record.site}</TableCell>
                                                                                </TableRow>
                                                                            ))}
                                                                        </TableBody>
                                                                    </Table>
                                                                </div>
                                                                {Object.keys(anomaly.details).length > 0 && (
                                                                    <div className="mt-3">
                                                                        <h4 className="text-sm font-semibold mb-2">Additional Details:</h4>
                                                                        <pre className="text-xs bg-white p-2 rounded border overflow-auto">
                                                                            {JSON.stringify(anomaly.details, null, 2)}
                                                                        </pre>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </CollapsibleContent>
                                                    </Collapsible>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
