import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import UseAnimations from 'react-useanimations';
import alertTriangle from 'react-useanimations/lib/alertTriangle';

const LEAVE_TYPES_WITH_REMINDER = ['VL', 'UPTO', 'LOA', 'ML'];

export default function LeaveReminderAlert({ leaveType }: { leaveType: string }) {
    if (!LEAVE_TYPES_WITH_REMINDER.includes(leaveType)) return null;

    return (
        <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
            <AlertTitle className="flex items-center gap-2 text-amber-800 dark:text-amber-200">
                <UseAnimations animation={alertTriangle} size={25} speed={0.5} strokeColor="currentColor" />
                Reminder
            </AlertTitle>
            <AlertDescription className="text-amber-700 dark:text-amber-300">
                <ul className="list-disc space-y-1 text-sm pl-13">
                    <li>Inform your <strong>clients</strong> at least <strong>two weeks in advance</strong> before applying for leave.</li>
                    <li>Notify your <strong>Team Lead (TL)</strong> or <strong>Admins</strong> of your planned leave prior to filing.</li>
                </ul>
            </AlertDescription>
        </Alert>
    );
}
