import { Head, usePage, useForm } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { Settings, Save, RotateCcw } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';

import { settings as coachingSettings } from '@/routes/coaching';
import { update as settingsUpdate } from '@/routes/coaching/settings';

import type { CoachingStatusSetting } from '@/types';

interface DefaultConfig {
    value: number;
    label: string;
}

interface Props extends InertiaPageProps {
    settings: CoachingStatusSetting[];
    defaults: Record<string, DefaultConfig>;
}

export default function CoachingSettingsIndex() {
    const { settings, defaults } = usePage<Props>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Coaching Settings',
        breadcrumbs: [
            { title: 'Coaching Dashboard', href: coachingSettings().url },
            { title: 'Settings' },
        ],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    // Build initial form data from existing settings or defaults
    const initialSettings = Object.keys(defaults).map((key) => {
        const existing = settings.find((s) => s.key === key);
        return {
            key,
            value: existing ? existing.value : defaults[key].value,
        };
    });

    const form = useForm({
        settings: initialSettings,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(settingsUpdate().url);
    };

    const handleReset = () => {
        const resetSettings = Object.keys(defaults).map((key) => ({
            key,
            value: defaults[key].value,
        }));
        form.setData('settings', resetSettings);
    };

    const updateSettingValue = (index: number, value: string) => {
        const updated = [...form.data.settings];
        updated[index] = { ...updated[index], value: parseInt(value) || 0 };
        form.setData('settings', updated);
    };

    // Status labels to display based on threshold order
    const statusDescriptions: Record<string, { status: string; color: string }> = {
        coaching_done_max_days: { status: 'Coaching Done', color: 'text-green-600 dark:text-green-400' },
        needs_coaching_max_days: { status: 'Needs Coaching', color: 'text-yellow-600 dark:text-yellow-400' },
        badly_needs_coaching_max_days: { status: 'Badly Needs Coaching', color: 'text-orange-600 dark:text-orange-400' },
        no_record_days: { status: 'No Record / ASAP', color: 'text-red-600 dark:text-red-400' },
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative mx-auto flex w-full max-w-2xl flex-col gap-4 rounded-xl p-3 md:p-6">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader
                    title="Coaching Status Settings"
                    description="Configure the day thresholds that determine coaching status labels."
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Threshold explanation */}
                    <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground space-y-1">
                        <p className="font-medium text-foreground">How thresholds work:</p>
                        <p>
                            Each setting defines the maximum days since last coaching session for that status.
                            If the days exceed all thresholds, the status becomes <strong>"Please Coach ASAP"</strong>.
                        </p>
                    </div>

                    {/* Settings inputs */}
                    <div className="space-y-4">
                        {form.data.settings.map((setting, index) => {
                            const defaultConfig = defaults[setting.key];
                            const desc = statusDescriptions[setting.key];

                            return (
                                <div key={setting.key} className="rounded-lg border bg-card p-4 shadow-sm">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="flex-1">
                                            <Label htmlFor={`setting-${setting.key}`} className="flex items-center gap-2">
                                                <Settings className="h-4 w-4 text-muted-foreground" />
                                                {defaultConfig?.label ?? setting.key}
                                            </Label>
                                            {desc && (
                                                <p className={`mt-0.5 text-xs font-medium ${desc.color}`}>
                                                    Status: {desc.status}
                                                </p>
                                            )}
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                Default: {defaultConfig?.value ?? '—'} days
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2 sm:w-32">
                                            <Input
                                                id={`setting-${setting.key}`}
                                                type="number"
                                                min={1}
                                                max={365}
                                                value={setting.value}
                                                onChange={(e) => updateSettingValue(index, e.target.value)}
                                                className="text-center"
                                            />
                                            <span className="text-xs text-muted-foreground whitespace-nowrap">days</span>
                                        </div>
                                    </div>
                                    {form.errors[`settings.${index}.value` as keyof typeof form.errors] && (
                                        <p className="mt-1 text-sm text-red-600">
                                            {form.errors[`settings.${index}.value` as keyof typeof form.errors]}
                                        </p>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    {/* Actions */}
                    <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="outline" onClick={handleReset} disabled={form.processing}>
                            <RotateCcw className="mr-2 h-4 w-4" /> Reset to Defaults
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            <Save className="mr-2 h-4 w-4" />
                            {form.processing ? 'Saving...' : 'Save Settings'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
