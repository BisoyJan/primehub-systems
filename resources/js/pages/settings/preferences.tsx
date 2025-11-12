import { type BreadcrumbItem, type SharedData } from '@/types';
import { Form, Head, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useFlashMessage } from '@/hooks';
import { Clock } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Preferences',
        href: '/settings/preferences',
    },
];

interface User {
    time_format: '12' | '24';
}

interface PreferencesProps {
    user: User;
}

export default function Preferences({ user }: PreferencesProps) {
    useFlashMessage();
    const [timeFormat, setTimeFormat] = useState(user.time_format || '24');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Preferences" />

            <SettingsLayout>
                <div className="space-y-8">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <Clock className="h-5 w-5" />
                                <div>
                                    <CardTitle>Time Format Preferences</CardTitle>
                                    <CardDescription>
                                        Choose how time is displayed throughout the system
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <Form
                                method="patch"
                                action="/settings/preferences"
                                options={{
                                    preserveScroll: true,
                                }}
                                className="space-y-6"
                            >
                                {({ processing, recentlySuccessful }) => (
                                    <>
                                        <div className="space-y-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="time_format">
                                                    Time Format
                                                </Label>
                                                <Select
                                                    name="time_format"
                                                    value={timeFormat}
                                                    onValueChange={(value) => setTimeFormat(value as '12' | '24')}
                                                >
                                                    <SelectTrigger id="time_format">
                                                        <SelectValue placeholder="Select time format" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="24">
                                                            <div className="flex flex-col items-start">
                                                                <span className="font-medium">24-Hour Format</span>
                                                                <span className="text-xs text-muted-foreground">
                                                                    Examples: 09:30, 17:45, 23:59
                                                                </span>
                                                            </div>
                                                        </SelectItem>
                                                        <SelectItem value="12">
                                                            <div className="flex flex-col items-start">
                                                                <span className="font-medium">12-Hour Format (AM/PM)</span>
                                                                <span className="text-xs text-muted-foreground">
                                                                    Examples: 9:30 AM, 5:45 PM, 11:59 PM
                                                                </span>
                                                            </div>
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                <p className="text-sm text-muted-foreground">
                                                    This setting will apply to all time displays in attendance records,
                                                    schedules, and reports throughout the system.
                                                </p>
                                            </div>

                                            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                                <h4 className="font-medium text-blue-900 mb-2">Preview</h4>
                                                <div className="space-y-1 text-sm">
                                                    <p className="text-blue-800">
                                                        <span className="font-medium">Current format:</span>{' '}
                                                        {timeFormat === '24' ? (
                                                            <span>14:30 - 22:00</span>
                                                        ) : (
                                                            <span>2:30 PM - 10:00 PM</span>
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-4">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing ? 'Saving...' : 'Save Preferences'}
                                            </Button>

                                            {recentlySuccessful && (
                                                <p className="text-sm text-green-600">
                                                    Preferences saved successfully!
                                                </p>
                                            )}
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
