import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export function HeaderDateTime() {
    const { url } = usePage();
    const [date, setDate] = useState(new Date());

    useEffect(() => {
        const timer = setInterval(() => setDate(new Date()), 1000);
        return () => clearInterval(timer);
    }, []);

    // Hide if on dashboard
    // Check for exactly /dashboard or simply / if that's the home
    // Also handle possible query parameters or trailing slashes if necessary,
    // but usually exact match on the path part is enough for 'dashboard' check.
    if (url === '/dashboard' || url === '/' || url.startsWith('/dashboard?')) {
        return null;
    }

    const dateOptions: Intl.DateTimeFormatOptions = {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    };

    const timeOptions: Intl.DateTimeFormatOptions = {
        hour: 'numeric',
        minute: 'numeric',
        hour12: true,
    };

    const dateString = date.toLocaleDateString('en-US', dateOptions);
    const timeString = date.toLocaleTimeString('en-US', timeOptions);

    return (
        <div className="mr-4 hidden flex-col items-end text-xs text-muted-foreground @3xl/header:flex">
            <span className="font-medium">{dateString}</span>
            <span>{timeString}</span>
        </div>
    );
}
