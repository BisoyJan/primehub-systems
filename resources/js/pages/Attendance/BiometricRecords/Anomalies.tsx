import { Head, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { format } from 'date-fns';
import { AlertTriangle, Search, Loader2, AlertCircle, CheckCircle2, ChevronDown, ChevronUp, Flag } from 'lucide-react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from '@/components/ui/select';
import { type SharedData } from '@/types';
import { Input } from '@headlessui/react';
import { index as attendanceIndex, review as attendanceReview } from '@/routes/attendance';
import { index as biometricAnomaliesIndex, detect as biometricAnomaliesDetect } from '@/routes/biometric-anomalies';

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
    flagged_count?: number;
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

const severityConfig = {
    high: {
        label: 'HIGH',
        className: 'bg-red-500 hover:bg-red-600 text-white',
        icon: AlertCircle,
        description: 'Critical - Requires immediate attention'
    },
    medium: {
        label: 'MEDIUM',
        className: 'bg-yellow-500 hover:bg-yellow-600 text-white',
        icon: AlertTriangle,
        description: 'Suspicious - Should be reviewed'
    },
    low: {
        label: 'LOW',
        className: 'bg-blue-500 hover:bg-blue-600 text-white',
        icon: AlertCircle,
        description: 'Unusual - May be legitimate'
    },
};

interface PageProps extends SharedData {
    stats: Stats;
    results?: DetectionResults;
}

export default function Anomalies({ stats, results }: PageProps) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Anomaly Detection',
        breadcrumbs: [
            { title: 'Attendance', href: attendanceIndex().url },
            { title: 'Anomaly Detection', href: biometricAnomaliesIndex().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [selectedTypes, setSelectedTypes] = useState<string[]>(anomalyTypes.map(t => t.value));
    const [minSeverity, setMinSeverity] = useState<'low' | 'medium' | 'high'>('low');
    const [autoFlag, setAutoFlag] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [detectionResults, setDetectionResults] = useState<DetectionResults | null>(results || null);
    const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set());
    const [severityFilter, setSeverityFilter] = useState<'all' | 'high' | 'medium' | 'low'>('all');

    // Update detection results when results prop changes
    useEffect(() => {
        if (results) {
            setDetectionResults(results);
            if (results.flagged_count && results.flagged_count > 0) {
                toast.success(`${results.flagged_count} attendance record${results.flagged_count === 1 ? '' : 's'} flagged for review`);
            }
        }
    }, [results]);

    const handleDetect = () => {
        if (!startDate || !endDate) {
            toast.error('Please select both start and end dates');
            return;
        }

        if (selectedTypes.length === 0) {
            toast.error('Please select at least one anomaly type');
            return;
        }

        setIsLoading(true);
        router.post(
            biometricAnomaliesDetect().url,
            {
                start_date: startDate,
                end_date: endDate,
                anomaly_types: selectedTypes,
                min_severity: minSeverity,
                auto_flag: autoFlag,
            },
            {
                preserveScroll: true,
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

    const clearFilters = () => {
        setStartDate('');
        setEndDate('');
        setSelectedTypes(anomalyTypes.map(t => t.value));
        setMinSeverity('low');
        setSeverityFilter('all');
        setDetectionResults(null);
    };

    const showClearFilters = startDate || endDate || selectedTypes.length !== anomalyTypes.length || minSeverity !== 'low' || severityFilter !== 'all';

    // Filter displayed anomalies by severity
    const filteredAnomalies = detectionResults?.anomalies.filter(anomaly => {
        if (severityFilter === 'all') return true;
        return anomaly.severity === severityFilter;
    }) || [];

    const getSeverityIcon = (severity: 'high' | 'medium' | 'low') => {
        const Icon = severityConfig[severity].icon;
        return <Icon className="h-4 w-4" />;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || isLoading} />

                <PageHeader
                    title="Biometric Anomaly Detection"
                    description="Detect and investigate unusual patterns in biometric scan records"
                />

                {/* Database Statistics */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Total Biometric Records</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats.total_records.toLocaleString()}</div>
                            <p className="text-xs text-muted-foreground mt-1">Available for analysis</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Date Range</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-sm font-medium">
                                {stats.oldest_record ? new Date(stats.oldest_record).toLocaleDateString() : 'N/A'}
                                {' → '}
                                {stats.newest_record ? new Date(stats.newest_record).toLocaleDateString() : 'N/A'}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">Historical data span</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Detection Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {detectionResults ? detectionResults.total_anomalies.toLocaleString() : '—'}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                {detectionResults ? 'Anomalies detected' : 'Run detection to begin'}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Detection Form */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Search className="h-5 w-5" />
                            Detection Parameters
                        </CardTitle>
                        <CardDescription>
                            Configure detection criteria and run analysis
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {/* Date Range */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div className="flex items-center gap-2 rounded-md border px-3 py-2">
                                <span className="text-muted-foreground text-xs whitespace-nowrap">From:</span>
                                <Input
                                    type="date"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                    className="w-full bg-transparent outline-none text-sm"
                                />
                            </div>

                            <div className="flex items-center gap-2 rounded-md border px-3 py-2">
                                <span className="text-muted-foreground text-xs whitespace-nowrap">To:</span>
                                <Input
                                    type="date"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    min={startDate}
                                    className="w-full bg-transparent outline-none text-sm"
                                />
                            </div>

                            <Select value={minSeverity} onValueChange={(value: 'low' | 'medium' | 'high') => setMinSeverity(value)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Minimum Severity" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="low">Low and above</SelectItem>
                                    <SelectItem value="medium">Medium and above</SelectItem>
                                    <SelectItem value="high">High only</SelectItem>
                                </SelectContent>
                            </Select>

                            {showClearFilters && (
                                <Button variant="outline" onClick={clearFilters} className="w-full">
                                    Clear All
                                </Button>
                            )}
                        </div>

                        {/* Anomaly Types */}
                        <div className="space-y-2">
                            <Label>Anomaly Types to Detect</Label>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {anomalyTypes.map((type) => (
                                    <div key={type.value} className="flex items-start space-x-2 p-3 rounded-md border">
                                        <Checkbox
                                            id={type.value}
                                            checked={selectedTypes.includes(type.value)}
                                            onCheckedChange={() => toggleType(type.value)}
                                        />
                                        <div className="grid gap-1 leading-none flex-1">
                                            <label
                                                htmlFor={type.value}
                                                className="text-sm font-medium cursor-pointer"
                                            >
                                                {type.title}
                                            </label>
                                            <p className="text-xs text-muted-foreground">
                                                {type.description}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Auto-flag Option */}
                        <div className="flex items-start space-x-2 p-3 rounded-md border border-orange-200 bg-orange-50/50">
                            <Checkbox
                                id="auto-flag"
                                checked={autoFlag}
                                onCheckedChange={(checked) => setAutoFlag(checked as boolean)}
                            />
                            <div className="grid gap-1 leading-none flex-1">
                                <label
                                    htmlFor="auto-flag"
                                    className="text-sm font-medium cursor-pointer flex items-center gap-2"
                                >
                                    <Flag className="h-4 w-4 text-orange-600" />
                                    Auto-flag High Severity Anomalies
                                </label>
                                <p className="text-xs text-muted-foreground">
                                    Automatically mark attendance records with high severity anomalies for admin review
                                </p>
                            </div>
                        </div>

                        {/* Action Buttons */}
                        <div className="flex flex-col sm:flex-row gap-3">
                            <Button
                                onClick={handleDetect}
                                disabled={isLoading || !startDate || !endDate || selectedTypes.length === 0}
                                className="w-full sm:w-auto"
                            >
                                {isLoading ? (
                                    <>
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        Detecting...
                                    </>
                                ) : (
                                    <>
                                        <Search className="mr-2 h-4 w-4" />
                                        Run Detection
                                    </>
                                )}
                            </Button>
                            {detectionResults && (
                                <Button
                                    variant="outline"
                                    onClick={() => router.get(attendanceReview().url)}
                                    className="w-full sm:w-auto"
                                >
                                    <AlertCircle className="mr-2 h-4 w-4" />
                                    Review Flagged Attendance
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Results Summary */}
                {detectionResults && (
                    <>
                        <div className="grid gap-4 md:grid-cols-4">
                            <Card className="cursor-pointer hover:shadow-md transition-shadow" onClick={() => setSeverityFilter('all')}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium flex items-center justify-between">
                                        Total Anomalies
                                        {severityFilter === 'all' && <CheckCircle2 className="h-4 w-4 text-green-600" />}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-bold">{detectionResults.total_anomalies}</div>
                                    <p className="text-xs text-muted-foreground mt-1">All severity levels</p>
                                </CardContent>
                            </Card>
                            <Card className="cursor-pointer hover:shadow-md transition-shadow border-red-200" onClick={() => setSeverityFilter('high')}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium flex items-center justify-between">
                                        <span className="flex items-center gap-2">
                                            <AlertCircle className="h-4 w-4 text-red-600" />
                                            High Severity
                                        </span>
                                        {severityFilter === 'high' && <CheckCircle2 className="h-4 w-4 text-green-600" />}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-bold text-red-600">
                                        {detectionResults.by_severity.high || 0}
                                    </div>
                                    <p className="text-xs text-red-600 mt-1">{severityConfig.high.description}</p>
                                </CardContent>
                            </Card>
                            <Card className="cursor-pointer hover:shadow-md transition-shadow border-yellow-200" onClick={() => setSeverityFilter('medium')}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium flex items-center justify-between">
                                        <span className="flex items-center gap-2">
                                            <AlertTriangle className="h-4 w-4 text-yellow-600" />
                                            Medium Severity
                                        </span>
                                        {severityFilter === 'medium' && <CheckCircle2 className="h-4 w-4 text-green-600" />}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-bold text-yellow-600">
                                        {detectionResults.by_severity.medium || 0}
                                    </div>
                                    <p className="text-xs text-yellow-600 mt-1">{severityConfig.medium.description}</p>
                                </CardContent>
                            </Card>
                            <Card className="cursor-pointer hover:shadow-md transition-shadow border-blue-200" onClick={() => setSeverityFilter('low')}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium flex items-center justify-between">
                                        <span className="flex items-center gap-2">
                                            <AlertCircle className="h-4 w-4 text-blue-600" />
                                            Low Severity
                                        </span>
                                        {severityFilter === 'low' && <CheckCircle2 className="h-4 w-4 text-green-600" />}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-2xl font-bold text-blue-600">
                                        {detectionResults.by_severity.low || 0}
                                    </div>
                                    <p className="text-xs text-blue-600 mt-1">{severityConfig.low.description}</p>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Status Message */}
                        <div className="flex justify-between items-center text-sm">
                            <div className="text-muted-foreground">
                                Showing {filteredAnomalies.length} of {detectionResults.total_anomalies} anomal{detectionResults.total_anomalies === 1 ? 'y' : 'ies'}
                                {severityFilter !== 'all' && ` (${severityFilter} severity)`}
                            </div>
                            {detectionResults.flagged_count !== undefined && detectionResults.flagged_count > 0 && (
                                <Badge variant="secondary" className="font-normal">
                                    <Flag className="mr-1 h-3 w-3" />
                                    {detectionResults.flagged_count} attendance record{detectionResults.flagged_count === 1 ? '' : 's'} flagged
                                </Badge>
                            )}
                        </div>

                        {/* Anomalies List - Desktop */}
                        <div className="hidden md:block shadow rounded-md overflow-hidden">
                            <div className="overflow-x-auto">
                                {filteredAnomalies.length === 0 ? (
                                    <div className="p-8 text-center text-muted-foreground bg-card border rounded-lg">
                                        {detectionResults.total_anomalies === 0
                                            ? 'No anomalies detected for the selected criteria'
                                            : `No ${severityFilter} severity anomalies found`}
                                    </div>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-12"></TableHead>
                                                <TableHead>Severity</TableHead>
                                                <TableHead>Employee</TableHead>
                                                <TableHead>Anomaly Type</TableHead>
                                                <TableHead>Description</TableHead>
                                                <TableHead>Records</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {filteredAnomalies.map((anomaly, index) => (
                                                <>
                                                    <TableRow
                                                        key={index}
                                                        className="cursor-pointer hover:bg-muted/50"
                                                        onClick={() => toggleRow(index)}
                                                    >
                                                        <TableCell>
                                                            {expandedRows.has(index) ? (
                                                                <ChevronUp className="h-4 w-4" />
                                                            ) : (
                                                                <ChevronDown className="h-4 w-4" />
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge className={severityConfig[anomaly.severity].className}>
                                                                {getSeverityIcon(anomaly.severity)}
                                                                <span className="ml-1">{severityConfig[anomaly.severity].label}</span>
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="font-medium">{anomaly.user.name}</TableCell>
                                                        <TableCell>
                                                            <span className="text-sm">
                                                                {anomaly.type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="text-sm text-muted-foreground">
                                                            {anomaly.description}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge variant="outline">{anomaly.records.length} scan{anomaly.records.length === 1 ? '' : 's'}</Badge>
                                                        </TableCell>
                                                    </TableRow>
                                                    {expandedRows.has(index) && (
                                                        <TableRow>
                                                            <TableCell colSpan={6} className="bg-muted/30">
                                                                <div className="p-4 space-y-3">
                                                                    <h4 className="text-sm font-semibold">Related Biometric Records:</h4>
                                                                    <Table>
                                                                        <TableHeader>
                                                                            <TableRow>
                                                                                <TableHead>Date/Time</TableHead>
                                                                                <TableHead>Site/Location</TableHead>
                                                                            </TableRow>
                                                                        </TableHeader>
                                                                        <TableBody>
                                                                            {anomaly.records.map((record, ridx) => (
                                                                                <TableRow key={ridx}>
                                                                                    <TableCell className="font-mono text-sm">
                                                                                        {format(new Date(record.scan_datetime), 'MMM d, yyyy HH:mm:ss')}
                                                                                    </TableCell>
                                                                                    <TableCell>{record.site}</TableCell>
                                                                                </TableRow>
                                                                            ))}
                                                                        </TableBody>
                                                                    </Table>
                                                                    {Object.keys(anomaly.details).length > 0 && (
                                                                        <div className="mt-3">
                                                                            <h4 className="text-sm font-semibold mb-2">Additional Details:</h4>
                                                                            <div className="grid gap-2">
                                                                                {Object.entries(anomaly.details).map(([key, value]) => (
                                                                                    <div key={key} className="flex gap-2 text-sm">
                                                                                        <span className="font-medium">{key.replace(/_/g, ' ')}:</span>
                                                                                        <span className="text-muted-foreground">{String(value)}</span>
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </TableCell>
                                                        </TableRow>
                                                    )}
                                                </>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </div>
                        </div>

                        {/* Anomalies List - Mobile */}
                        <div className="md:hidden space-y-4">
                            {filteredAnomalies.length === 0 ? (
                                <div className="py-12 text-center text-muted-foreground border rounded-lg bg-card">
                                    {detectionResults.total_anomalies === 0
                                        ? 'No anomalies detected for the selected criteria'
                                        : `No ${severityFilter} severity anomalies found`}
                                </div>
                            ) : (
                                filteredAnomalies.map((anomaly, index) => (
                                    <div key={index} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2 mb-2">
                                                    <Badge className={severityConfig[anomaly.severity].className}>
                                                        {getSeverityIcon(anomaly.severity)}
                                                        <span className="ml-1">{severityConfig[anomaly.severity].label}</span>
                                                    </Badge>
                                                    <Badge variant="outline">{anomaly.records.length} scan{anomaly.records.length === 1 ? '' : 's'}</Badge>
                                                </div>
                                                <div className="text-lg font-semibold">{anomaly.user.name}</div>
                                                <div className="text-sm text-muted-foreground">
                                                    {anomaly.type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                </div>
                                            </div>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => toggleRow(index)}
                                            >
                                                {expandedRows.has(index) ? (
                                                    <ChevronUp className="h-4 w-4" />
                                                ) : (
                                                    <ChevronDown className="h-4 w-4" />
                                                )}
                                            </Button>
                                        </div>

                                        <p className="text-sm">{anomaly.description}</p>

                                        {expandedRows.has(index) && (
                                            <div className="pt-3 border-t space-y-3">
                                                <h4 className="text-sm font-semibold">Related Records:</h4>
                                                <div className="space-y-2">
                                                    {anomaly.records.map((record, ridx) => (
                                                        <div key={ridx} className="flex justify-between text-sm p-2 bg-muted/30 rounded">
                                                            <span className="font-mono text-xs">
                                                                {format(new Date(record.scan_datetime), 'MMM d HH:mm')}
                                                            </span>
                                                            <span className="text-muted-foreground">{record.site}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                                {Object.keys(anomaly.details).length > 0 && (
                                                    <div className="pt-2">
                                                        <h4 className="text-sm font-semibold mb-2">Details:</h4>
                                                        {Object.entries(anomaly.details).map(([key, value]) => (
                                                            <div key={key} className="text-sm">
                                                                <span className="font-medium">{key.replace(/_/g, ' ')}: </span>
                                                                <span className="text-muted-foreground">{String(value)}</span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
