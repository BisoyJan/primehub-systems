import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { AlertTriangle, Loader2, RefreshCw } from 'lucide-react';
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
                    new Date(hd.getFullYear(), hd.getMonth() + 1, 1).getTime(),
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
            const daysInMonth = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
            const anniversaryDay = Math.min(hd.getDate(), daysInMonth);
            const anniversaryDate = new Date(cursor.getFullYear(), cursor.getMonth(), anniversaryDay);
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
            <DialogContent className="max-w-[90vw] sm:max-w-md max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Recalculate Leave Credits</DialogTitle>
                    <DialogDescription>
                        Reset and recalculate all leave credits for <strong>{employeeName}</strong> based on the hire
                        date below.
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
                            onChange={(e) => form.setData('hired_date', e.target.value)}
                            max={new Date().toISOString().substring(0, 10)}
                        />
                        {form.errors.hired_date && (
                            <p className="text-sm text-red-600">{form.errors.hired_date}</p>
                        )}
                    </div>

                    {/* Credit preview */}
                    {previewData && (
                        <div className="p-3 rounded-lg bg-muted/50 border text-sm space-y-2">
                            <div className="flex items-center justify-between text-xs text-muted-foreground border-b pb-2">
                                <span>Calculated as of</span>
                                <span className="font-medium text-foreground">
                                    {format(previewData.today, 'MMM d, yyyy')}
                                </span>
                            </div>
                            <div className="grid grid-cols-2 gap-3 pt-1">
                                <div>
                                    <p className="text-muted-foreground text-xs">Regularization Date</p>
                                    <p className="font-semibold">{format(previewData.regDate, 'MMM d, yyyy')}</p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground text-xs">{previewData.creditLabel}</p>
                                    <p className="font-semibold text-green-600 dark:text-green-400">
                                        {previewData.totalEarned.toFixed(2)} credits
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {employeeRole} · {monthlyRate.toFixed(2)}/mo
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Destructive warning */}
                    <div className="flex items-start gap-2 p-3 rounded-lg bg-destructive/10 border border-destructive/30 text-destructive text-sm dark:bg-destructive/20 dark:border-destructive/40">
                        <AlertTriangle className="h-4 w-4 mt-0.5 shrink-0" />
                        <div className="space-y-1">
                            <p className="font-medium">This action will permanently delete:</p>
                            <ul className="list-disc list-inside space-y-0.5 text-xs">
                                <li>All existing monthly leave credit records</li>
                                <li>All carryover records for this employee</li>
                            </ul>
                            <p className="text-xs mt-1">
                                Credits will be recalculated from scratch based on the hire date above.
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
                            onChange={(e) => form.setData('reason', e.target.value)}
                            rows={3}
                        />
                        {form.errors.reason && <p className="text-sm text-red-600">{form.errors.reason}</p>}
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={submit}
                        disabled={form.processing || !form.data.reason || !form.data.hired_date}
                    >
                        {form.processing ? (
                            <>
                                <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                Recalculating...
                            </>
                        ) : (
                            <>
                                <RefreshCw className="h-4 w-4 mr-2" />
                                Recalculate Credits
                            </>
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
