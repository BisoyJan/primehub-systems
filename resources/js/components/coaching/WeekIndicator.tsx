import { getCurrentWeekOfMonth } from '@/lib/coaching';

export { getCurrentWeekOfMonth };

export function WeekIndicator({ coachedWeeks }: { coachedWeeks?: Record<number, boolean> }) {
    const currentWeek = getCurrentWeekOfMonth();
    return (
        <div className="flex items-center gap-[3px]">
            {[1, 2, 3, 4].map((wk) => {
                const isCoached = coachedWeeks?.[wk] ?? false;
                const isPast = wk <= currentWeek;
                return (
                    <span
                        key={wk}
                        className={`inline-block h-2.5 w-2.5 rounded-full border ${
                            isCoached
                                ? 'border-green-500 bg-green-500'
                                : isPast
                                    ? 'border-red-300 bg-red-100 dark:border-red-700 dark:bg-red-950/40'
                                    : 'border-gray-300 bg-transparent dark:border-gray-600'
                        }`}
                        title={`Week ${wk}: ${isCoached ? 'Coached' : isPast ? 'Missed' : 'Upcoming'}`}
                    />
                );
            })}
        </div>
    );
}
