import { type BreadcrumbItem, } from '@/types';
import { Form, Head, } from '@inertiajs/react';
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
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useFlashMessage } from '@/hooks';
import { Timer, Bell } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Preferences',
        href: '/settings/preferences',
    },
];

interface User {
    inactivity_timeout: number | null;
}

interface NotificationPreferences {
    [key: string]: boolean;
}

interface PreferencesProps {
    user: User;
    notificationPreferences: NotificationPreferences;
}

const notificationTypeLabels: Record<string, { label: string; description: string; category: string }> = {
    system: { label: 'System', description: 'General system messages and announcements', category: 'General' },
    leave_request: { label: 'Leave Requests', description: 'Updates on leave request submissions and status changes', category: 'Leave & Attendance' },
    attendance_status: { label: 'Attendance Status', description: 'Daily attendance status and point notifications', category: 'Leave & Attendance' },
    undertime_approval: { label: 'Undertime Approval', description: 'Undertime request approvals and rejections', category: 'Leave & Attendance' },
    it_concern: { label: 'IT Concerns', description: 'IT support ticket updates and status changes', category: 'IT & Equipment' },
    maintenance_due: { label: 'Maintenance Due', description: 'Station maintenance schedule reminders', category: 'IT & Equipment' },
    pc_assignment: { label: 'PC Assignment', description: 'PC/station assignment changes', category: 'IT & Equipment' },
    medication_request: { label: 'Medication Requests', description: 'Medication request submissions and approvals', category: 'Requests' },
    coaching_session: { label: 'Coaching Sessions', description: 'New coaching session assignments', category: 'Coaching' },
    coaching_acknowledged: { label: 'Coaching Acknowledged', description: 'Coaching session acknowledgment confirmations', category: 'Coaching' },
    coaching_reviewed: { label: 'Coaching Reviewed', description: 'Coaching session review completions', category: 'Coaching' },
    coaching_ready_for_review: { label: 'Coaching Ready for Review', description: 'Coaching sessions ready for your review', category: 'Coaching' },
    coaching_pending_reminder: { label: 'Coaching Reminders', description: 'Reminders for pending coaching sessions', category: 'Coaching' },
    coaching_unacknowledged_alert: { label: 'Coaching Alerts', description: 'Alerts for unacknowledged coaching sessions', category: 'Coaching' },
    break_overage: { label: 'Break Overage', description: 'Notifications when break time is exceeded', category: 'Leave & Attendance' },
    account_deletion: { label: 'Account Deletion', description: 'Account deletion notifications', category: 'General' },
    account_reactivation: { label: 'Account Reactivation', description: 'Account reactivation notifications', category: 'General' },
    account_restored: { label: 'Account Restored', description: 'Account restoration notifications', category: 'General' },
};

const categories = ['General', 'Leave & Attendance', 'IT & Equipment', 'Requests', 'Coaching'];

export default function Preferences({ user, notificationPreferences }: PreferencesProps) {
    useFlashMessage();
    const [autoLogoutEnabled, setAutoLogoutEnabled] = useState(user.inactivity_timeout !== null);
    const [inactivityTimeout, setInactivityTimeout] = useState<string>(
        user.inactivity_timeout?.toString() || '15'
    );
    const [notifPrefs, setNotifPrefs] = useState<NotificationPreferences>(notificationPreferences);

    const timeoutOptions = [
        { value: '5', label: '5 minutes' },
        { value: '10', label: '10 minutes' },
        { value: '15', label: '15 minutes' },
        { value: '30', label: '30 minutes' },
        { value: '60', label: '1 hour' },
        { value: '120', label: '2 hours' },
        { value: '240', label: '4 hours' },
        { value: '480', label: '8 hours' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Preferences" />

            <SettingsLayout>
                <div className="space-y-8">
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
                                {/* Auto Logout Card */}
                                <Card>
                                    <CardHeader>
                                        <div className="flex items-center gap-2">
                                            <Timer className="h-5 w-5" />
                                            <div>
                                                <CardTitle>Automatic Logout</CardTitle>
                                                <CardDescription>
                                                    Configure automatic logout after a period of inactivity
                                                </CardDescription>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-4">
                                            <div className="flex items-center justify-between">
                                                <div className="space-y-0.5">
                                                    <Label htmlFor="auto_logout_toggle">
                                                        Enable Auto Logout
                                                    </Label>
                                                    <p className="text-sm text-muted-foreground">
                                                        Automatically log out after being inactive
                                                    </p>
                                                </div>
                                                <Switch
                                                    id="auto_logout_toggle"
                                                    checked={autoLogoutEnabled}
                                                    onCheckedChange={setAutoLogoutEnabled}
                                                />
                                            </div>

                                            {/* Hidden input to send the actual value */}
                                            <input
                                                type="hidden"
                                                name="inactivity_timeout"
                                                value={autoLogoutEnabled ? inactivityTimeout : ''}
                                            />

                                            {autoLogoutEnabled && (
                                                <div className="space-y-2">
                                                    <Label htmlFor="inactivity_timeout_select">
                                                        Inactivity Timeout
                                                    </Label>
                                                    <Select
                                                        value={inactivityTimeout}
                                                        onValueChange={setInactivityTimeout}
                                                    >
                                                        <SelectTrigger id="inactivity_timeout_select">
                                                            <SelectValue placeholder="Select timeout duration" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {timeoutOptions.map((option) => (
                                                                <SelectItem key={option.value} value={option.value}>
                                                                    {option.label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    <p className="text-sm text-muted-foreground">
                                                        You will receive a warning 1 minute before being logged out.
                                                    </p>
                                                </div>
                                            )}

                                            <div className={`rounded-lg border p-4 ${autoLogoutEnabled
                                                ? 'border-amber-200 bg-amber-50 dark:border-amber-200/10 dark:bg-amber-700/10'
                                                : 'border-green-200 bg-green-50 dark:border-green-200/10 dark:bg-green-700/10'
                                                }`}>
                                                <h4 className={`mb-2 font-medium ${autoLogoutEnabled
                                                    ? 'text-amber-900 dark:text-amber-100'
                                                    : 'text-green-900 dark:text-green-100'
                                                    }`}>
                                                    Current Status
                                                </h4>
                                                <div className="space-y-1 text-sm">
                                                    <p className={
                                                        autoLogoutEnabled
                                                            ? 'text-amber-800 dark:text-amber-200'
                                                            : 'text-green-800 dark:text-green-200'
                                                    }>
                                                        {autoLogoutEnabled ? (
                                                            <>
                                                                <span className="font-medium">Auto logout enabled:</span>{' '}
                                                                You will be logged out after{' '}
                                                                {timeoutOptions.find(o => o.value === inactivityTimeout)?.label || inactivityTimeout + ' minutes'}{' '}
                                                                of inactivity.
                                                            </>
                                                        ) : (
                                                            <>
                                                                <span className="font-medium">Auto logout disabled:</span>{' '}
                                                                You will stay logged in until you manually log out or your session expires.
                                                            </>
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Hidden inputs for notification preferences */}
                                {Object.entries(notifPrefs).map(([type, enabled]) => (
                                    <input
                                        key={type}
                                        type="hidden"
                                        name={`notification_preferences[${type}]`}
                                        value={enabled ? '1' : '0'}
                                    />
                                ))}

                                {/* Notification Preferences Card */}
                                <Card>
                                    <CardHeader>
                                        <div className="flex items-center gap-2">
                                            <Bell className="h-5 w-5" />
                                            <div>
                                                <CardTitle>Notification Preferences</CardTitle>
                                                <CardDescription>
                                                    Choose which types of notifications you want to receive
                                                </CardDescription>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-6">
                                            {categories.map((category) => {
                                                const typesInCategory = Object.entries(notificationTypeLabels)
                                                    .filter(([, meta]) => meta.category === category);

                                                if (typesInCategory.length === 0) return null;

                                                return (
                                                    <div key={category} className="space-y-3">
                                                        <h4 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">
                                                            {category}
                                                        </h4>
                                                        <div className="space-y-3">
                                                            {typesInCategory.map(([type, meta]) => (
                                                                <div key={type} className="flex items-center justify-between">
                                                                    <div className="space-y-0.5">
                                                                        <Label htmlFor={`notif_${type}`}>
                                                                            {meta.label}
                                                                        </Label>
                                                                        <p className="text-sm text-muted-foreground">
                                                                            {meta.description}
                                                                        </p>
                                                                    </div>
                                                                    <Switch
                                                                        id={`notif_${type}`}
                                                                        checked={notifPrefs[type] ?? true}
                                                                        onCheckedChange={(checked) =>
                                                                            setNotifPrefs((prev) => ({
                                                                                ...prev,
                                                                                [type]: checked,
                                                                            }))
                                                                        }
                                                                    />
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </CardContent>
                                </Card>

                                <div className="flex items-center gap-4">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                    >
                                        {processing ? 'Saving...' : 'Save Preferences'}
                                    </Button>

                                    {recentlySuccessful && (
                                        <p className="text-sm text-green-600 dark:text-green-400">
                                            Preferences saved successfully!
                                        </p>
                                    )}
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
