import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import { type PropsWithChildren } from 'react';
import { AnimatedQuotes } from '@/components/animated-quotes';
import { Spinning3DCube } from '@/components/ui/spinning-3d-cube';

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

            // Animate logo with scale and rotation
            gsap.from(logoRef.current, {
                scale: 0.5,
                rotation: -180,
                opacity: 0,
                duration: 1.2,
                delay: 0.3,
                ease: 'back.out(1.7)',
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
        <div className="flex min-h-screen bg-gradient-to-br from-gray-900 via-slate-900 to-black relative">
            {/* Left Side - Logo and Branding */}
            <div
                ref={logoContainerRef}
                className="hidden lg:flex lg:w-1/2 relative overflow-hidden"
            >
                {/* Animated Background Pattern */}
                <div className="absolute inset-0 opacity-10">
                    <div className="absolute inset-0" style={{
                        backgroundImage: `
                            linear-gradient(90deg, rgba(255,255,255,0.05) 1px, transparent 1px),
                            linear-gradient(rgba(255,255,255,0.05) 1px, transparent 1px)
                        `,
                        backgroundSize: '50px 50px'
                    }} />
                </div>

                {/* Gradient Overlay */}
                <div className="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent" />

                {/* Content */}
                <div className="relative z-10 flex flex-col items-center justify-center w-full p-12">
                    <div className="text-center space-y-8 max-w-lg">
                        {/* 3D Spinning Cube */}
                        <div ref={cubeRef} className="flex justify-center mb-6">
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
                            className="text-6xl font-black text-white drop-shadow-2xl tracking-tight"
                        >
                            Welcome Back
                        </h1>

                        {/* Animated Motivational Quotes */}
                        <div ref={taglineRef} className="min-h-[120px] flex items-center justify-center">
                            <AnimatedQuotes className="text-lg font-light text-white/90 max-w-md mx-auto leading-relaxed text-center" />
                        </div>

                        {/* Modern Progress Dots */}
                        <div className="flex justify-center gap-3 pt-8">
                            {[...Array(3)].map((_, i) => (
                                <div
                                    key={i}
                                    className="relative"
                                >
                                    <div className="w-3 h-3 rounded-full bg-white/70 shadow-lg"
                                        style={{
                                            animation: `modernPulse 3s ease-in-out infinite ${i * 0.4}s`
                                        }}
                                    />
                                    <div className="absolute inset-0 w-3 h-3 rounded-full bg-white/30 animate-ping"
                                        style={{
                                            animationDelay: `${i * 0.4}s`,
                                            animationDuration: '3s'
                                        }}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Modern Floating Orbs */}
                <div className="absolute top-20 left-20 w-64 h-64 bg-blue-500/25 rounded-full blur-3xl animate-float" />
                <div className="absolute bottom-20 right-20 w-80 h-80 bg-indigo-500/10 rounded-full blur-3xl animate-float-delayed" />
                <div className="absolute top-1/2 left-1/4 w-40 h-40 bg-purple-500/15 rounded-full blur-2xl animate-float-slow" />
            </div>

            {/* Subtle Vertical Divider - Blended */}
            <div 
                ref={dividerRef}
                className="hidden lg:block absolute left-1/2 top-0 bottom-0 w-px origin-top"
                style={{
                    background: 'linear-gradient(to bottom, transparent 0%, rgba(255, 255, 255, 0.05) 20%, rgba(255, 255, 255, 0.05) 80%, transparent 100%)'
                }}
            />

            {/* Right Side - Login Form */}
            <div className="w-full lg:w-1/2 flex items-center justify-center p-8">
                <div
                    ref={formContainerRef}
                    className="w-full max-w-md"
                >
                    {/* Mobile Logo */}
                    <div className="lg:hidden flex justify-center mb-8">
                        <div className="bg-white/5 backdrop-blur-xl rounded-2xl p-4 shadow-xl border border-white/10">
                            <img
                                src="/primehub-logo.png"
                                alt="PrimeHub Logo"
                                className="h-16 w-auto"
                            />
                        </div>
                    </div>

                    {/* Glass Morphism Card Container */}
                    <div className="bg-white/5 backdrop-blur-2xl rounded-3xl shadow-2xl p-8 border border-white/10">
                        {/* Title and Description */}
                        <div className="space-y-3 mb-8">
                            <h2 className="text-3xl font-bold tracking-tight text-white">
                                {title}
                            </h2>
                            <p className="text-sm text-gray-300 font-medium">
                                {description}
                            </p>
                        </div>

                        {/* Form Content */}
                        <div className="space-y-6">
                            {children}
                        </div>
                    </div>

                    {/* Footer Text */}
                    <p className="text-center text-sm text-gray-400 mt-6">
                        Powered by PrimeHub Systems
                    </p>
                </div>
            </div>

            <style>{`
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
