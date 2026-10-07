import { home } from '@/routes';
import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

interface AuthEditorialLayoutProps {
    title: string;
    description?: string;
    tagline?: string;
}

export default function AuthEditorialLayout({
    children,
    title,
    description,
    tagline = 'Assets, attendance, and requests — managed in one quiet, dependable place.',
}: PropsWithChildren<AuthEditorialLayoutProps>) {
    const year = new Date().getFullYear();

    return (
        <div className="grid min-h-svh bg-background text-foreground lg:grid-cols-2">
            {/* Brand panel */}
            <aside className="relative hidden flex-col justify-between border-r border-border bg-muted/40 p-12 lg:flex xl:p-16">
                <Link href={home()} className="flex w-fit items-center gap-3">
                    <img
                        src="/primehub-logo.png"
                        alt="PrimeHub"
                        className="h-8 w-auto grayscale opacity-80 transition-opacity hover:opacity-100"
                    />
                    <span className="text-sm font-medium tracking-wide uppercase text-muted-foreground">
                        PrimeHub Systems
                    </span>
                </Link>

                <div className="max-w-md space-y-6">
                    <h1 className="font-serif text-5xl leading-[1.05] tracking-tight text-balance xl:text-6xl">
                        Clarity for every desk, every day.
                    </h1>
                    <p className="text-base leading-relaxed text-muted-foreground">
                        {tagline}
                    </p>
                </div>

                <div className="flex items-center justify-between text-xs tracking-wide text-muted-foreground uppercase">
                    <span>Internal portal</span>
                    <span>© {year} PrimeHub</span>
                </div>
            </aside>

            {/* Form panel */}
            <main className="flex flex-col px-6 py-10 sm:px-12 lg:px-16 xl:px-24">
                <div className="flex items-center justify-between lg:hidden">
                    <Link href={home()} className="flex items-center gap-3">
                        <img
                            src="/primehub-logo.png"
                            alt="PrimeHub"
                            className="h-7 w-auto grayscale opacity-80"
                        />
                        <span className="text-xs font-medium tracking-wide uppercase text-muted-foreground">
                            PrimeHub Systems
                        </span>
                    </Link>
                </div>

                <div className="flex flex-1 items-center">
                    <div className="mx-auto w-full max-w-sm space-y-10 py-12">
                        <header className="space-y-3">
                            <h2 className="font-serif text-3xl tracking-tight text-balance sm:text-4xl">
                                {title}
                            </h2>
                            {description && (
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </header>

                        {children}
                    </div>
                </div>

                <p className="text-center text-xs text-muted-foreground lg:hidden">
                    © {year} PrimeHub Systems
                </p>
            </main>
        </div>
    );
}
