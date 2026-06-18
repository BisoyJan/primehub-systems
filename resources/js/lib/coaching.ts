export function getCurrentWeekOfMonth(): number {
    const now = new Date();
    return Math.min(Math.ceil(now.getDate() / 7), 4);
}
