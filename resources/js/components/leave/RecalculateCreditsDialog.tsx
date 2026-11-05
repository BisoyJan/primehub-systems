import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { AlertTriangle, Loader2, RefreshCw } from 'lucide-react';
import { useEffect } from 'react';

interface RecalculateCreditsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeName: string;
    employeeRole: string;
    monthlyRate: number;
    initialHireDate: string; // YYYY-MM-DD or empty string
    postUrl: string;
    onSuccess?: () => void;
}

export default function RecalculateCreditsDialog({
    open,
    onOpenChange,
    employeeName,
    employeeRole,
    monthlyRate,
    initialHireDate,
    postUrl,
    onSuccess,
}: RecalculateCreditsDialogProps) {
    const form = useForm({
        hired_date: initialHireDate,
        reason: '',
    });

    // Sync hire date and reset reason whenever the dialog opens
    useEffect(() => {
        if (open) {
            form.setData({ hired_date: initialHireDate, reason: '' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, initialHireDate]);

    const handleOpenChange = (value: boolean) => {
        if (!value) {
            form.reset();
        }
        onOpenChange(value);
    };

    const submit = () => {
        form.post(postUrl, {
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
                onSuccess?.();
            },
        });
    };

    // --- credit preview calculation ---
    const previewData = (() => {
        if (!form.data.hired_date) return null;
        const hd = new Date(form.data.hired_date + 'T00:00:00');
        const regDate = new Date(hd);
        regDate.setMonth(regDate.getMonth() + 6);
        const today = new Date();
        const currentYear = today.getFullYear();
        const isFirstReg = regDate >= new Date(currentYear, 0, 1);
        const cursorStart = isFirstReg
            ? new Date(hd.getFullYear(), hd.getMonth() + 1, 1)
            : new Date(
                  Math.max(
                      new Date(
                          hd.getFullYear(),
                          hd.getMonth() + 1,
                          1,
                      ).getTime(),
                      new Date(currentYear, 0, 1).getTime(),
                  ),
              );
        let monthsEarned = 0;
        const cursor = new Date(cursorStart);
        while (cursor <= today) {
            if (!isFirstReg && cursor.getFullYear() !== currentYear) {
                cursor.setMonth(cursor.getMonth() + 1);
                continue;
            }
            const daysInMonth = new Date(
                cursor.getFullYear(),
                cursor.getMonth() + 1,
                0,
            ).getDate();
            const anniversaryDay = Math.min(hd.getDate(), daysInMonth);
            const anniversaryDate = new Date(
                cursor.getFullYear(),
                cursor.getMonth(),
                anniversaryDay,
            );
            if (anniversaryDate <= today) monthsEarned++;
            cursor.setMonth(cursor.getMonth() + 1);
        }
        return {
            regDate,
            today,
            currentYear,
            isFirstReg,
            monthsEarned,
            totalEarned: monthsEarned * monthlyRate,
            creditLabel: isFirstReg
                ? `Expected Total incl. First Reg. (${monthsEarned} Month${monthsEarned !== 1 ? 's' : ''})`
                : `Expected ${currentYear} (${monthsEarned} Month${monthsEarned !== 1 ? 's' : ''})`,
        };
    })();

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-[90vw] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Recalculate Leave Credits</DialogTitle>
                    <DialogDescription>
                        Reset and recalculate all leave credits for{' '}
                        <strong>{employeeName}</strong> based on the hire date
                        below.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    {/* Hire Date */}
                    <div className="space-y-2">
                        <Label htmlFor="rcld_hired_date">
                            Hire Date <span className="text-red-500">*</span>
                        </Label>
                        <Input
                            id="rcld_hired_date"
                            type="date"
                            value={form.data.hired_date}
                            onChange={(e) =>
                                form.setData('hired_date', e.target.value)
                            }
                            max={new Date().toISOString().substring(0, 10)}
                        />
                        {form.errors.hired_date && (
                            <p className="text-sm text-red-600">
                                {form.errors.hired_date}
                            </p>
                        )}
                    </div>

                    {/* Credit preview */}
                    {previewData && (
                        <div className="space-y-2 rounded-lg border bg-muted/50 p-3 text-sm">
                            <div className="flex items-center justify-between border-b pb-2 text-xs text-muted-foreground">
                                <span>Calculated as of</span>
                                <span className="font-medium text-foreground">
                                    {format(previewData.today, 'MMM d, yyyy')}
                                </span>
                            </div>
                            <div className="grid grid-cols-2 gap-3 pt-1">
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Regularization Date
                                    </p>
                                    <p className="font-semibold">
                                        {format(
                                            previewData.regDate,
                                            'MMM d, yyyy',
                                        )}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        {previewData.creditLabel}
                                    </p>
                                    <p className="font-semibold text-green-600 dark:text-green-400">
                                        {previewData.totalEarned.toFixed(2)}{' '}
                                        credits
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {employeeRole} ·{' '}
                                        {monthlyRate.toFixed(2)}/mo
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Destructive warning */}
                    <div className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive dark:border-destructive/40 dark:bg-destructive/20">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <div className="space-y-1">
                            <p className="font-medium">
                                This action will permanently delete:
                            </p>
                            <ul className="list-inside list-disc space-y-0.5 text-xs">
                                <li>
                                    All existing monthly leave credit records
                                </li>
                                <li>All carryover records for this employee</li>
                            </ul>
                            <p className="mt-1 text-xs">
                                Credits will be recalculated from scratch based
                                on the hire date above.
                            </p>
                        </div>
                    </div>

                    {/* Reason */}
                    <div className="space-y-2">
                        <Label htmlFor="rcld_reason">
                            Reason <span className="text-red-500">*</span>
                        </Label>
                        <Textarea
                            id="rcld_reason"
                            placeholder="e.g. Hired date corrected from Dec 11, 2025 to Nov 11, 2025..."
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            rows={3}
                        />
                        {form.errors.reason && (
                            <p className="text-sm text-red-600">
                                {form.errors.reason}
                            </p>
                        )}
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={submit}
                        disabled={
                            form.processing ||
                            !form.data.reason ||
                            !form.data.hired_date
                        }
                    >
                        {form.processing ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Recalculating...
                            </>
                        ) : (
                            <>
                                <RefreshCw className="mr-2 h-4 w-4" />
                                Recalculate Credits
                            </>
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
