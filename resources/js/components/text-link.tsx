import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ComponentProps } from 'react';

type LinkProps = ComponentProps<typeof Link>;

export default function TextLink({
    className = '',
    children,
    ...props
}: LinkProps) {
    return (
        <Link
            className={cn(
                'text-blue-400 hover:text-blue-300 underline decoration-blue-400/50 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-blue-300',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
