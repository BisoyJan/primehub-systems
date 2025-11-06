import React, { useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { usePageMeta } from "@/hooks/use-page-meta";
import { useFlashMessage } from "@/hooks/use-flash-message";
import { PageHeader } from "@/components/PageHeader";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { AlertCircle, Upload, FileText, CheckCircle2 } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";

interface Site {
    id: number;
    name: string;
}

interface PageProps {
    sites: Site[];
    errors?: Record<string, string>;
    [key: string]: unknown;
}

export default function AttendanceImport() {
    const { sites, errors } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: "Import Attendance",
        breadcrumbs: [
            { title: "Attendance", href: "/attendance" },
            { title: "Import", href: "/attendance/import" },
        ],
    });

    useFlashMessage();

    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [fileDate, setFileDate] = useState("");
    const [siteId, setSiteId] = useState<string>("");
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file && file.name.endsWith(".txt")) {
            setSelectedFile(file);
        } else {
            setSelectedFile(null);
            alert("Please select a valid .txt file");
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!selectedFile || !fileDate) {
            alert("Please select a file and specify the file date");
            return;
        }

        setIsSubmitting(true);

        const formData = new FormData();
        formData.append("file", selectedFile);
        formData.append("file_date", fileDate);
        if (siteId) {
            formData.append("site_id", siteId);
        }

        router.post("/attendance/import", formData, {
            onFinish: () => setIsSubmitting(false),
            preserveScroll: true,
        });
    };

    const handleCancel = () => {
        router.get("/attendance");
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Import Attendance File"
                    description="Upload daily attendance log file (.txt) from the biometric device"
                />

                <div className="max-w-3xl mx-auto w-full">
                    <Card>
                        <CardHeader>
                            <CardTitle>Upload Attendance Log</CardTitle>
                            <CardDescription>
                                Import the daily attendance log file. The system will automatically match time-in and
                                time-out records across consecutive days.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                {/* File Upload */}
                                <div className="space-y-2">
                                    <Label htmlFor="file">Attendance File (.txt)</Label>
                                    <div className="flex items-center gap-3">
                                        <Input
                                            id="file"
                                            type="file"
                                            accept=".txt"
                                            onChange={handleFileChange}
                                            className="flex-1"
                                            required
                                        />
                                        {selectedFile && (
                                            <div className="flex items-center gap-2 text-sm text-green-600">
                                                <CheckCircle2 className="h-4 w-4" />
                                                {selectedFile.name}
                                            </div>
                                        )}
                                    </div>
                                    {errors?.file && (
                                        <p className="text-sm text-destructive">{errors.file}</p>
                                    )}
                                </div>

                                {/* File Date */}
                                <div className="space-y-2">
                                    <Label htmlFor="file_date">File Date *</Label>
                                    <Input
                                        id="file_date"
                                        type="date"
                                        value={fileDate}
                                        onChange={(e) => setFileDate(e.target.value)}
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Select the date this file represents (usually the date when the file was
                                        generated)
                                    </p>
                                    {errors?.file_date && (
                                        <p className="text-sm text-destructive">{errors.file_date}</p>
                                    )}
                                </div>

                                {/* Site Selection */}
                                <div className="space-y-2">
                                    <Label htmlFor="site">Site (Optional)</Label>
                                    <Select value={siteId} onValueChange={setSiteId}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select a site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="">No Site</SelectItem>
                                            {sites.map((site) => (
                                                <SelectItem key={site.id} value={site.id.toString()}>
                                                    {site.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        Associate imported attendance records with a specific site
                                    </p>
                                </div>

                                {/* Instructions */}
                                <Alert>
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>
                                        <strong>Import Instructions:</strong>
                                        <ul className="list-disc list-inside mt-2 space-y-1 text-sm">
                                            <li>Upload one file per day in sequential order</li>
                                            <li>
                                                The system matches time-in (evening) with time-out (next morning) across
                                                consecutive imports
                                            </li>
                                            <li>Supported format: Tab-separated .txt files from biometric devices</li>
                                            <li>Make sure the file follows the format: No, DevNo, UserId, Name, Mode, DateTime</li>
                                            <li><strong>Multi-device support:</strong> Employee names are matched case-insensitively across different biometric devices</li>
                                            <li><strong>Note:</strong> UserId may differ between devices - the system uses employee names as the primary identifier</li>
                                        </ul>
                                    </AlertDescription>
                                </Alert>

                                {/* Action Buttons */}
                                <div className="flex justify-end gap-3">
                                    <Button type="button" variant="outline" onClick={handleCancel} disabled={isSubmitting}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={!selectedFile || !fileDate || isSubmitting}>
                                        {isSubmitting ? (
                                            <>Processing...</>
                                        ) : (
                                            <>
                                                <Upload className="mr-2 h-4 w-4" />
                                                Import File
                                            </>
                                        )}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {/* File Format Example */}
                    <Card className="mt-6">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="h-5 w-5" />
                                Expected File Format
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <pre className="bg-muted p-4 rounded-md text-xs overflow-x-auto">
                                {`No	DevNo	UserId	Name	Mode	DateTime
1	1	10	Nodado A	FP	2025-11-05  05:50:25
2	1	10	Nodado A	FP	2025-11-05  19:52:27
3	1	20	Smith J	FP	2025-11-05  06:15:30
4	1	20	Smith J	FP	2025-11-05  20:00:00`}
                            </pre>
                            <p className="text-sm text-muted-foreground mt-2">
                                Tab-separated values with DateTime format: YYYY-MM-DD HH:MM:SS
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
