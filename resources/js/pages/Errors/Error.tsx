import { Head } from '@inertiajs/react';
import { ArrowLeft, Home, RefreshCw, ServerCrash, ShieldX, Lock, AlertTriangle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';

interface ErrorPageProps {
    status: number;
    message?: string;
}

const errorConfig: Record<number, { title: string; description: string; icon: React.ElementType; pill: string }> = {
    403: {
        title: 'Access Denied',
        description: "You don't have permission to access this page. Please contact your administrator if you believe this is a mistake.",
        icon: ShieldX,
        pill: 'Forbidden',
    },
    404: {
        title: 'Lost in Space',
        description: "Oops! The page you're looking for doesn't exist or has been moved to another dimension.",
        icon: AlertTriangle,
        pill: 'Page Not Found',
    },
    500: {
        title: 'Server Error',
        description: "Something went wrong on our end. Our team has been notified and we're working to fix it.",
        icon: ServerCrash,
        pill: 'Internal Error',
    },
    503: {
        title: 'Under Maintenance',
        description: "We're performing scheduled maintenance. Please check back shortly.",
        icon: Lock,
        pill: 'Service Unavailable',
    },
};

const quotes = [
    'Not all who wander are lost, but this page definitely is.',
    'The best journeys sometimes take unexpected detours.',
    'Every wrong turn is a chance to discover something new.',
    'Lost pages are just adventures waiting to be found.',
    'Sometimes the path less traveled doesn\'t exist at all.',
];

export default function Error({ status, message }: ErrorPageProps) {
    const config = errorConfig[status] || errorConfig[404];
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const [currentQuote, setCurrentQuote] = useState(quotes[0]);
    const [fadeOut, setFadeOut] = useState(false);

    // Canvas particle animation
    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        let width = (canvas.width = window.innerWidth);
        let height = (canvas.height = window.innerHeight);
        const particles: Particle[] = [];
        const particleCount = 50;
        const connectionDistance = 150;
        const mouseDistance = 250;
        const mouse = { x: null as number | null, y: null as number | null };
        let animationFrameId: number;

        class Particle {
            x: number;
            y: number;
            vx: number;
            vy: number;
            size: number;
            color: string;
            angle: number;
            spinSpeed: number;

            constructor() {
                this.x = Math.random() * width;
                this.y = Math.random() * height;
                this.vx = (Math.random() - 0.5) * 1;
                this.vy = (Math.random() - 0.5) * 1;
                this.size = Math.random() * 8 + 4;
                this.color = Math.random() > 0.5 ? 'rgba(139, 92, 246, 0.4)' : 'rgba(91, 33, 182, 0.4)';
                this.angle = 0;
                this.spinSpeed = (Math.random() - 0.5) * 0.05;
            }

            update() {
                this.x += this.vx;
                this.y += this.vy;
                this.angle += this.spinSpeed;

                if (this.x < 0 || this.x > width) this.vx *= -1;
                if (this.y < 0 || this.y > height) this.vy *= -1;

                if (mouse.x != null && mouse.y != null) {
                    const dx = mouse.x - this.x;
                    const dy = mouse.y - this.y;
                    const distance = Math.sqrt(dx * dx + dy * dy);

                    if (distance < mouseDistance) {
                        const forceDirectionX = dx / distance;
                        const forceDirectionY = dy / distance;
                        const force = (mouseDistance - distance) / mouseDistance;
                        this.x -= forceDirectionX * force * 2;
                        this.y -= forceDirectionY * force * 2;
                    }
                }
            }

            draw() {
                ctx!.save();
                ctx!.translate(this.x, this.y);
                ctx!.rotate(this.angle);
                ctx!.fillStyle = this.color;
                ctx!.fillRect(-this.size / 2, -this.size / 2, this.size, this.size);
                ctx!.restore();
            }
        }

        const handleResize = () => {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        };

        const handleMouseMove = (e: MouseEvent) => {
            mouse.x = e.x;
            mouse.y = e.y;
        };

        const handleTouchMove = (e: TouchEvent) => {
            mouse.x = e.touches[0].clientX;
            mouse.y = e.touches[0].clientY;
        };

        window.addEventListener('resize', handleResize);
        window.addEventListener('mousemove', handleMouseMove);
        window.addEventListener('touchmove', handleTouchMove);

        // Initialize particles
        for (let i = 0; i < particleCount; i++) {
            particles.push(new Particle());
        }

        const animate = () => {
            ctx.clearRect(0, 0, width, height);

            for (let i = 0; i < particles.length; i++) {
                particles[i].update();
                particles[i].draw();

                for (let j = i; j < particles.length; j++) {
                    const dx = particles[i].x - particles[j].x;
                    const dy = particles[i].y - particles[j].y;
                    const distance = Math.sqrt(dx * dx + dy * dy);

                    if (distance < connectionDistance) {
                        ctx.beginPath();
                        ctx.strokeStyle = `rgba(139, 92, 246, ${0.2 - (distance / connectionDistance) * 0.2})`;
                        ctx.lineWidth = 1;
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.stroke();
                    }
                }

                if (mouse.x != null && mouse.y != null) {
                    const dx = particles[i].x - mouse.x;
                    const dy = particles[i].y - mouse.y;
                    const distance = Math.sqrt(dx * dx + dy * dy);

                    if (distance < connectionDistance) {
                        ctx.beginPath();
                        ctx.strokeStyle = `rgba(255, 255, 255, ${0.2 - distance / connectionDistance})`;
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(mouse.x, mouse.y);
                        ctx.stroke();
                    }
                }
            }
            animationFrameId = requestAnimationFrame(animate);
        };

        animate();

        return () => {
            window.removeEventListener('resize', handleResize);
            window.removeEventListener('mousemove', handleMouseMove);
            window.removeEventListener('touchmove', handleTouchMove);
            cancelAnimationFrame(animationFrameId);
        };
    }, []);

    // Quote rotation
    useEffect(() => {
        let quoteIndex = 0;
        const interval = setInterval(() => {
            setFadeOut(true);
            setTimeout(() => {
                quoteIndex = (quoteIndex + 1) % quotes.length;
                setCurrentQuote(quotes[quoteIndex]);
                setFadeOut(false);
            }, 500);
        }, 6000);

        return () => clearInterval(interval);
    }, []);

    const handleGoBack = () => {
        window.history.back();
    };

    const handleGoHome = () => {
        window.location.href = '/';
    };

    const handleRefresh = () => {
        window.location.reload();
    };

    return (
        <>
            <Head title={`${status} - ${config.title}`} />

            <div className="relative min-h-screen w-full overflow-hidden bg-[#02040a] [background-image:radial-gradient(circle_at_20%_20%,#5b21b6_0%,#02040a_50%)]">
                {/* Canvas Background */}
                <canvas ref={canvasRef} className="absolute inset-0 z-0" />

                {/* Content */}
                <div className="relative z-10 flex min-h-screen flex-col items-center justify-center p-5 text-center text-white">
                    {/* Brand Logo */}
                    <div className="mb-4 animate-[logoFloat_3s_ease-in-out_infinite] text-5xl font-bold tracking-tight uppercase">
                        <span className="text-white">PRIME</span>
                        <span className="text-violet-500 drop-shadow-[0_0_20px_rgba(139,92,246,0.5)]">HUB</span>
                    </div>

                    {/* Status Pill */}
                    <div className="mb-8 inline-block rounded-full border border-violet-500/30 bg-violet-500/10 px-6 py-2 text-sm font-medium text-violet-400 backdrop-blur-sm">
                        {config.pill}
                    </div>

                    {/* Error Code */}
                    <div className="m-0 text-[8rem] font-bold leading-none text-violet-500 drop-shadow-[0_0_40px_rgba(139,92,246,0.3)]">
                        {status}
                    </div>

                    {/* Title */}
                    <h2 className="mt-2 mb-4 text-4xl font-semibold">{config.title}</h2>

                    {/* Description */}
                    <p className="mx-auto mb-8 max-w-lg leading-relaxed text-slate-400">
                        {message || config.description}
                    </p>

                    {/* Action Buttons */}
                    <div className="flex flex-wrap items-center justify-center gap-3">
                        <Button
                            onClick={handleGoBack}
                            className="gap-2 rounded-xl bg-gradient-to-br from-violet-500 to-violet-800 px-7 py-6 text-base font-medium text-white transition-all hover:-translate-y-0.5 hover:shadow-[0_10px_30px_-5px_rgba(139,92,246,0.4)]"
                        >
                            <ArrowLeft className="h-5 w-5" />
                            Go Back
                        </Button>

                        <Button
                            onClick={handleGoHome}
                            variant="outline"
                            className="gap-2 rounded-xl border-violet-500/30 bg-transparent px-7 py-6 text-base font-medium text-violet-400 transition-all hover:-translate-y-0.5 hover:bg-violet-500/10 hover:text-violet-300"
                        >
                            <Home className="h-5 w-5" />
                            Home
                        </Button>

                        {status === 500 && (
                            <Button
                                onClick={handleRefresh}
                                variant="outline"
                                className="gap-2 rounded-xl border-violet-500/30 bg-transparent px-7 py-6 text-base font-medium text-violet-400 transition-all hover:-translate-y-0.5 hover:bg-violet-500/10 hover:text-violet-300"
                            >
                                <RefreshCw className="h-5 w-5" />
                                Try Again
                            </Button>
                        )}
                    </div>

                    {/* Quote */}
                    <div className="mt-12 max-w-xl animate-[fadeInUp_1s_ease-out_0.5s_both] border-l-4 border-violet-500 pl-6 text-left">
                        <p className={`text-lg italic text-slate-400 transition-opacity duration-500 ${fadeOut ? 'opacity-0' : 'opacity-80'}`}>
                            <span className="mr-1 text-2xl font-bold text-slate-400">"</span>
                            {currentQuote}
                            <span className="ml-1 text-2xl font-bold text-slate-400">"</span>
                        </p>
                    </div>

                    {/* Footer */}
                    <div className="mt-8 pb-6 text-xs text-slate-400 opacity-60">
                        Powered by PrimeHub Systems
                    </div>
                </div>
            </div>

            <style>{`
                @keyframes logoFloat {
                    0%, 100% { transform: translateY(0); }
                    50% { transform: translateY(-8px); }
                }
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(20px);
                    }
                    to {
                        opacity: 0.8;
                        transform: translateY(0);
                    }
                }
            `}</style>
        </>
    );
}
