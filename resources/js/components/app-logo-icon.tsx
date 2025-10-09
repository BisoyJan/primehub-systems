import { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 64 64"
            xmlns="http://www.w3.org/2000/svg"
            role="img"
            aria-label="PrimeHub 3D logo"
        >
            <defs>
                <linearGradient id="g_top" x1="0" x2="1" y1="0" y2="1">
                    <stop offset="0" stopColor="#4f46e5" />
                    <stop offset="1" stopColor="#3b82f6" />
                </linearGradient>

                <linearGradient id="g_front" x1="0" x2="1" y1="0" y2="1">
                    <stop offset="0" stopColor="#2563eb" />
                    <stop offset="1" stopColor="#1e40af" />
                </linearGradient>

                <linearGradient id="g_side" x1="0" x2="1" y1="0" y2="1">
                    <stop offset="0" stopColor="#1e3a8a" />
                    <stop offset="1" stopColor="#0f172a" />
                </linearGradient>

                <filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">
                    <feDropShadow dx="0" dy="2" stdDeviation="3" floodColor="#000" floodOpacity="0.25" />
                </filter>

                <filter id="inner" x="-20%" y="-20%" width="140%" height="140%">
                    <feGaussianBlur stdDeviation="1" result="b" />
                    <feComposite in="SourceGraphic" in2="b" operator="over" />
                </filter>
            </defs>


            <g filter="url(#shadow)">

                <path
                    d="M8 20 L32 8 L56 20 L32 32 Z"
                    fill="url(#g_top)"
                    stroke="rgba(0,0,0,0.06)"
                    strokeWidth="0.5"
                />


                <path
                    d="M8 20 L8 44 L32 56 L32 32 Z"
                    fill="url(#g_front)"
                    stroke="rgba(0,0,0,0.06)"
                    strokeWidth="0.5"
                />


                <path
                    d="M56 20 L56 44 L32 56 L32 32 Z"
                    fill="url(#g_side)"
                    stroke="rgba(0,0,0,0.06)"
                    strokeWidth="0.5"
                />
            </g>


            <path
                d="M12 22 L32 12 L52 22 L32 30 Z"
                fill="rgba(255,255,255,0.08)"
                filter="url(#inner)"
            />


            <path d="M8 20 L32 8 L56 20" fill="none" stroke="rgba(255,255,255,0.08)" strokeWidth="0.6" />
            <path d="M8 20 L8 44 L32 56" fill="none" stroke="rgba(0,0,0,0.12)" strokeWidth="0.6" />
            <path d="M56 20 L56 44 L32 56" fill="none" stroke="rgba(0,0,0,0.12)" strokeWidth="0.6" />


            <g transform="translate(32,32) scale(0.9) translate(-32,-32)">
                <path
                    d="M32 20 L40 34 L24 34 Z"
                    fill="rgba(255,255,255,0.92)"
                    opacity="0.95"
                />
                <path
                    d="M32 24 L36.5 32 L27.5 32 Z"
                    fill="rgba(74,74,255,0.12)"
                />
            </g>
        </svg>
    );
}
