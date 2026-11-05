import { AnimatedQuotes } from '@/components/animated-quotes';
import { Spinning3DCube } from '@/components/ui/spinning-3d-cube';
import { gsap } from 'gsap';
import { useEffect, useRef, type PropsWithChildren } from 'react';

interface AuthLayoutProps {
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSplitAnimatedLayout({
    children,
    title,
    description,
}: PropsWithChildren<AuthLayoutProps>) {
    const logoContainerRef = useRef<HTMLDivElement>(null);
    const formContainerRef = useRef<HTMLDivElement>(null);
    const logoRef = useRef<HTMLImageElement>(null);
    const titleRef = useRef<HTMLHeadingElement>(null);
    const taglineRef = useRef<HTMLParagraphElement>(null);
    const cubeRef = useRef<HTMLDivElement>(null);
    const dividerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const ctx = gsap.context(() => {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                gsap.set(
                    [
                        logoContainerRef.current,
                        cubeRef.current,
                        logoRef.current,
                        titleRef.current,
                        taglineRef.current,
                        formContainerRef.current,
                        dividerRef.current,
                    ],
                    { clearProps: 'all' },
                );
                return;
            }

            // Animate logo container from left
            gsap.from(logoContainerRef.current, {
                x: -100,
                opacity: 0,
                duration: 1,
                ease: 'power3.out',
            });

            // Animate 3D cube with scale
            gsap.from(cubeRef.current, {
                scale: 0,
                opacity: 0,
                duration: 1,
                delay: 0.2,
                ease: 'back.out(1.7)',
            });

            // Animate logo with blur to focus
            gsap.from(logoRef.current, {
                filter: 'blur(20px)',
                scale: 1.2,
                opacity: 0,
                duration: 1.5,
                delay: 0.3,
                ease: 'power2.out',
            });

            // Animate title
            gsap.from(titleRef.current, {
                y: 30,
                opacity: 0,
                duration: 0.8,
                delay: 0.6,
                ease: 'power2.out',
            });

            // Animate tagline
            gsap.from(taglineRef.current, {
                y: 20,
                opacity: 0,
                duration: 0.8,
                delay: 0.8,
                ease: 'power2.out',
            });

            // Animate form container from right
            gsap.from(formContainerRef.current, {
                x: 100,
                opacity: 0,
                duration: 1,
                delay: 0.4,
                ease: 'power3.out',
            });

            // Animate divider line with fade and scale
            gsap.from(dividerRef.current, {
                scaleY: 0,
                opacity: 0,
                duration: 1.2,
                delay: 0.5,
                ease: 'power2.out',
            });

            // Add floating animation to logo
            gsap.to(logoRef.current, {
                y: -10,
                duration: 2,
                repeat: -1,
                yoyo: true,
                ease: 'power1.inOut',
                delay: 1.5,
            });
        });

        return () => ctx.revert();
    }, []);

    return (
        <div className="dark relative flex min-h-screen bg-gradient-to-br from-gray-900 via-slate-900 to-black motion-reduce:**:animate-none!">
            {/* Left Side - Logo and Branding */}
            <div
                ref={logoContainerRef}
                className="relative hidden overflow-hidden lg:flex lg:w-1/2"
            >
                {/* Animated Background Pattern */}
                <div className="absolute inset-0 opacity-10">
                    <div className="auth-grid-pattern absolute inset-0" />
                </div>

                {/* Gradient Overlay */}
                <div className="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent" />

                {/* Content */}
                <div className="relative z-10 flex w-full flex-col items-center justify-center p-12">
                    <div className="max-w-lg space-y-8 text-center">
                        {/* 3D Spinning Cube */}
                        <div ref={cubeRef} className="mb-6 flex justify-center">
                            <Spinning3DCube size={120} />
                        </div>

                        {/* Logo with Modern Card */}
                        <div className="flex justify-center">
                            <img
                                ref={logoRef}
                                src="/primehub-logo.png"
                                alt="PrimeHub Logo"
                                className="h-24 w-auto"
                            />
                        </div>

                        {/* Title with Modern Typography */}
                        <h1
                            ref={titleRef}
                            className="text-6xl font-black tracking-tight text-white drop-shadow-2xl"
                        >
                            Welcome Back
                        </h1>

                        {/* Animated Motivational Quotes */}
                        <div
                            ref={taglineRef}
                            className="flex min-h-[120px] items-center justify-center"
                        >
                            <AnimatedQuotes className="mx-auto max-w-md text-center text-lg leading-relaxed font-light text-white/90" />
                        </div>

                        {/* Modern Progress Dots */}
                        <div className="flex justify-center gap-3 pt-8">
                            {[...Array(3)].map((_, i) => (
                                <div key={i} className="relative">
                                    <div
                                        className={`animate-modern-pulse h-3 w-3 rounded-full bg-white/70 shadow-lg animate-modern-pulse-delay-${i}`}
                                    />
                                    <div
                                        className={`animate-ping-slow absolute inset-0 h-3 w-3 rounded-full bg-white/30 animate-ping-delay-${i}`}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Modern Floating Orbs */}
                <div className="animate-float absolute top-20 left-20 h-64 w-64 rounded-full bg-blue-500/25 blur-3xl" />
                <div className="animate-float-delayed absolute right-20 bottom-20 h-80 w-80 rounded-full bg-indigo-500/10 blur-3xl" />
                <div className="animate-float-slow absolute top-1/2 left-1/4 h-40 w-40 rounded-full bg-purple-500/15 blur-2xl" />
            </div>

            {/* Subtle Vertical Divider - Blended */}
            <div
                ref={dividerRef}
                className="auth-divider absolute top-0 bottom-0 left-1/2 hidden w-px origin-top lg:block"
            />

            {/* Right Side - Login Form */}
            <div className="flex w-full items-center justify-center p-5 sm:p-8 lg:w-1/2">
                <div ref={formContainerRef} className="w-full max-w-lg">
                    {/* Mobile Logo */}
                    <div className="mb-8 flex justify-center lg:hidden">
                        <div className="rounded-2xl border border-white/10 bg-white/5 p-4 shadow-xl backdrop-blur-xl">
                            <img
                                src="/primehub-logo.png"
                                alt="PrimeHub Logo"
                                className="h-16 w-auto"
                            />
                        </div>
                    </div>

                    {/* Glass Morphism Card Container */}
                    <div className="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-2xl backdrop-blur-2xl sm:p-8">
                        {/* Title and Description */}
                        <div className="mb-8 space-y-3">
                            <h2 className="text-3xl font-bold tracking-tight text-white">
                                {title}
                            </h2>
                            <p className="text-sm font-medium text-gray-300">
                                {description}
                            </p>
                        </div>

                        {/* Form Content */}
                        <div className="space-y-6">{children}</div>
                    </div>

                    {/* Footer Text */}
                    <p className="mt-6 text-center text-sm text-gray-400">
                        Powered by PrimeHub Systems
                    </p>
                </div>
            </div>

            <style>{`
                .auth-grid-pattern {
                    background-image:
                        linear-gradient(90deg, rgba(255,255,255,0.05) 1px, transparent 1px),
                        linear-gradient(rgba(255,255,255,0.05) 1px, transparent 1px);
                    background-size: 50px 50px;
                }

                .auth-divider {
                    background: linear-gradient(to bottom, transparent 0%, rgba(255, 255, 255, 0.05) 20%, rgba(255, 255, 255, 0.05) 80%, transparent 100%);
                }

                @keyframes pulse {
                    0%, 100% {
                        opacity: 0.5;
                        transform: scale(1);
                    }
                    50% {
                        opacity: 1;
                        transform: scale(1.2);
                    }
                }

                @keyframes modernPulse {
                    0%, 100% {
                        opacity: 0.7;
                        transform: scale(1);
                    }
                    50% {
                        opacity: 1;
                        transform: scale(1.3);
                    }
                }

                .animate-modern-pulse {
                    animation: modernPulse 3s ease-in-out infinite;
                }

                .animate-modern-pulse-delay-0 {
                    animation-delay: 0s;
                }

                .animate-modern-pulse-delay-1 {
                    animation-delay: 0.4s;
                }

                .animate-modern-pulse-delay-2 {
                    animation-delay: 0.8s;
                }

                .animate-ping-slow {
                    animation: ping 3s cubic-bezier(0, 0, 0.2, 1) infinite;
                }

                .animate-ping-delay-0 {
                    animation-delay: 0s;
                }

                .animate-ping-delay-1 {
                    animation-delay: 0.4s;
                }

                .animate-ping-delay-2 {
                    animation-delay: 0.8s;
                }

                @keyframes ping {
                    75%, 100% {
                        transform: scale(2);
                        opacity: 0;
                    }
                }

                @keyframes float {
                    0%, 100% {
                        transform: translate(0, 0) scale(1);
                    }
                    33% {
                        transform: translate(30px, -30px) scale(1.1);
                    }
                    66% {
                        transform: translate(-20px, 20px) scale(0.9);
                    }
                }

                @keyframes float-delayed {
                    0%, 100% {
                        transform: translate(0, 0) scale(1);
                    }
                    33% {
                        transform: translate(-30px, 30px) scale(0.9);
                    }
                    66% {
                        transform: translate(20px, -20px) scale(1.1);
                    }
                }

                @keyframes float-slow {
                    0%, 100% {
                        transform: translate(0, 0);
                    }
                    50% {
                        transform: translate(15px, 15px);
                    }
                }

                .animate-float {
                    animation: float 20s ease-in-out infinite;
                }

                .animate-float-delayed {
                    animation: float-delayed 25s ease-in-out infinite;
                }

                .animate-float-slow {
                    animation: float-slow 15s ease-in-out infinite;
                }
            `}</style>
        </div>
    );
}
