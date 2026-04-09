import { memo } from 'react';
import { motion } from 'framer-motion';
import type { TimerTheme } from './themes';

interface ThemeDecorProps {
    theme: TimerTheme;
    isDark: boolean;
}

/**
 * Atmospheric SVG decorations that create scene-setting ambience for each theme.
 * Uses framer-motion for subtle floating/drifting animations.
 * All elements are pointer-events-none, aria-hidden, and absolute-positioned.
 */
function ThemeDecorInner({ theme, isDark }: ThemeDecorProps) {
    const o = isDark ? 0.32 : 0.42;

    switch (theme.id) {
        case 'cozy-cafe':
            return <CozyCafeDecor opacity={o} isDark={isDark} />;
        case 'rainy-window':
            return <RainyWindowDecor opacity={o} isDark={isDark} />;
        case 'sakura':
            return <SakuraDecor opacity={o} isDark={isDark} />;
        case 'ocean-tide':
            return <OceanTideDecor opacity={o} isDark={isDark} />;
        case 'neon-city':
            return <NeonCityDecor opacity={o} />;
        case 'golden-hour':
            return <GoldenHourDecor opacity={o} isDark={isDark} />;
        case 'deep-forest':
            return <DeepForestDecor opacity={o} isDark={isDark} />;
        case 'snowfall':
            return <SnowfallDecor opacity={o} isDark={isDark} />;
        case 'moonlit':
            return <MoonlitDecor opacity={o} isDark={isDark} />;
        case 'aurora':
            return <AuroraDecor opacity={o} />;
        case 'cyberpunk':
            return <CyberpunkDecor opacity={o} />;
        case 'synthwave':
            return <SynthwaveDecor opacity={o} />;
        default:
            return null;
    }
}

export const ThemeDecor = memo(ThemeDecorInner);

// ─── Shared wrapper ──────────────────────────────────────────

function DecorWrap({ children }: { children: React.ReactNode }) {
    return (
        <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            {children}
        </div>
    );
}

// ─── Float animation helper ─────────────────────────────────

const floatY = (distance = 10, duration = 6) => ({
    animate: { y: [0, -distance, 0] },
    transition: { duration, repeat: Infinity, ease: 'easeInOut' as const },
});

const floatX = (distance = 8, duration = 7) => ({
    animate: { x: [0, distance, 0] },
    transition: { duration, repeat: Infinity, ease: 'easeInOut' as const },
});

// ─── 1. Cozy Cafe — Steam wisps, coffee cups & beans ────────

function CozyCafeDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const steam = isDark ? '#d4a574' : '#a0785a';
    const cup = isDark ? '#8b6e50' : '#6b5040';
    const highlight = isDark ? '#fcd34d' : '#ffb300';

    return (
        <DecorWrap>
            <svg className="absolute h-0 w-0">
                <defs>
                    <linearGradient id="steamGrad1" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stopColor={steam} stopOpacity="0" />
                        <stop offset="40%" stopColor={steam} stopOpacity="0.8" />
                        <stop offset="100%" stopColor={steam} stopOpacity="0" />
                    </linearGradient>
                    <filter id="warmGlow">
                        <feGaussianBlur stdDeviation="6" result="coloredBlur" />
                        <feMerge>
                            <feMergeNode in="coloredBlur" />
                            <feMergeNode in="SourceGraphic" />
                        </feMerge>
                    </filter>
                    <filter id="moteGlow">
                        <feGaussianBlur stdDeviation="2" result="blur" />
                        <feMerge>
                            <feMergeNode in="blur" />
                            <feMergeNode in="SourceGraphic" />
                        </feMerge>
                    </filter>
                </defs>
            </svg>

            {/* Flickering background light source (Neon/Candle glow) */}
            <motion.div
                className="absolute top-1/4 left-1/4 rounded-full mix-blend-screen pointer-events-none"
                style={{ width: 200, height: 200, background: highlight, filter: 'blur(60px)', opacity: opacity * 0.15 }}
                animate={{ opacity: [opacity * 0.15, opacity * 0.3, opacity * 0.1, opacity * 0.25, opacity * 0.15], scale: [1, 1.05, 0.95, 1.02, 1] }}
                transition={{ duration: 4, repeat: Infinity, ease: "easeInOut" }}
            />

            {/* Ambient Floating Dust Motes */}
            {[...Array(20)].map((_, i) => (
                <motion.div
                    key={`mote-${i}`}
                    className="absolute rounded-full bg-white pointer-events-none"
                    style={{
                        width: Math.random() * 3 + 1,
                        height: Math.random() * 3 + 1,
                        left: `${Math.random() * 100}%`,
                        top: `${Math.random() * 100}%`,
                        filter: 'url(#moteGlow)',
                        opacity: opacity * 0.8
                    }}
                    animate={{
                        y: [-10, -40, 10],
                        x: [-10, 20, -10],
                        opacity: [0, opacity * 0.8, 0],
                        scale: [0.5, 1.5, 0.5]
                    }}
                    transition={{
                        duration: 8 + Math.random() * 6,
                        repeat: Infinity,
                        delay: Math.random() * 5,
                        ease: "easeOut"
                    }}
                />
            ))}

            {/* Large coffee cup silhouette bottom-right */}
            <svg className="absolute bottom-2 right-4 h-40 w-32 pointer-events-none" style={{ opacity: opacity * 0.85 }} viewBox="0 0 80 100">
                <rect x="10" y="25" width="44" height="50" rx="6" fill={cup} fillOpacity="0.25" />
                <path d="M54 35 Q74 38, 72 52 Q70 64, 54 64" fill="none" stroke={cup} strokeWidth="4" strokeOpacity="0.2" />
                <rect x="4" y="75" width="56" height="6" rx="3" fill={cup} fillOpacity="0.2" />
            </svg>

            {/* Smaller cup bottom-left */}
            <svg className="absolute bottom-4 left-6 h-28 w-24 pointer-events-none" style={{ opacity: opacity * 0.6 }} viewBox="0 0 60 80">
                <rect x="10" y="30" width="30" height="35" rx="5" fill={cup} fillOpacity="0.2" />
                <rect x="6" y="65" width="38" height="4" rx="2" fill={cup} fillOpacity="0.15" />
            </svg>

            {/* More visible, thicker rising steam wisps */}
            <motion.svg className="absolute bottom-36 right-10 h-48 w-20 pointer-events-none" style={{ opacity }} viewBox="0 0 50 120"
                animate={{ y: [0, -25, 0], x: [0, 10, -5, 0], opacity: [0.5, 1, 0.4] }}
                transition={{ duration: 4.5, repeat: Infinity, ease: 'easeInOut' }}
                filter="url(#warmGlow)"
            >
                <path d="M25 110 Q10 80, 25 55 Q40 30, 25 5" fill="none" stroke="url(#steamGrad1)" strokeWidth="5" strokeLinecap="round" />
            </motion.svg>
            <motion.svg className="absolute bottom-40 right-20 h-40 w-16 pointer-events-none" style={{ opacity: opacity * 0.9 }} viewBox="0 0 45 100"
                animate={{ y: [0, -30, 0], x: [0, -8, 6, 0], opacity: [0.3, 0.9, 0.3] }}
                transition={{ duration: 5.5, repeat: Infinity, ease: 'easeInOut', delay: 1.2 }}
                filter="url(#warmGlow)"
            >
                <path d="M22 90 Q8 65, 22 45 Q36 25, 22 5" fill="none" stroke="url(#steamGrad1)" strokeWidth="4.5" strokeLinecap="round" />
            </motion.svg>
            <motion.svg className="absolute bottom-32 right-28 h-36 w-14 pointer-events-none" style={{ opacity: opacity * 0.7 }} viewBox="0 0 40 90"
                animate={{ y: [0, -20, 0], x: [0, 5, -3, 0], opacity: [0.2, 0.8, 0.2] }}
                transition={{ duration: 4, repeat: Infinity, ease: 'easeInOut', delay: 0.7 }}
                filter="url(#warmGlow)"
            >
                <path d="M20 80 Q8 55, 20 40 Q32 25, 20 5" fill="none" stroke="url(#steamGrad1)" strokeWidth="4" strokeLinecap="round" />
            </motion.svg>

            {/* Scattered coffee beans */}
            {[
                { x: 'left-6', y: 'top-8', size: 'h-14 w-14', rot: -30, d: 0 },
                { x: 'left-20', y: 'top-16', size: 'h-10 w-10', rot: 20, d: 1.5 },
                { x: 'right-20', y: 'top-12', size: 'h-12 w-12', rot: -15, d: 2.5 },
                { x: 'left-12', y: 'bottom-32', size: 'h-10 w-10', rot: 40, d: 3.5 },
            ].map((b, i) => (
                <motion.svg key={i} className={`absolute ${b.x} ${b.y} ${b.size} pointer-events-none`} style={{ opacity: opacity * 0.6 }} viewBox="0 0 40 40"
                    animate={{ y: [0, 8, 0], opacity: [0.4, 0.8, 0.4], rotate: [0, 8, 0] }}
                    transition={{ duration: 4 + i, repeat: Infinity, ease: 'easeInOut', delay: b.d }}
                >
                    <ellipse cx="20" cy="20" rx="10" ry="14" fill={cup} fillOpacity="0.3" transform={`rotate(${b.rot} 20 20)`} />
                    <line x1="14" y1="12" x2="26" y2="28" stroke={cup} strokeWidth="1.5" strokeOpacity="0.3" strokeLinecap="round" />
                </motion.svg>
            ))}
        </DecorWrap>
    );
}

// ─── 2. Rainy Window — Raindrops, streaks & ripples ──────────

function RainyWindowDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const drop = isDark ? '#7b9bb5' : '#5a7f99';
    const cloud = isDark ? '#1e293b' : '#94a3b8';

    // Multiple distinct layers of parallax rain
    const rainLayers = [
        // Layer 1: Distant, slow, highly blurred
        ...Array.from({ length: 8 }).map((_, i) => ({ layer: 1, x: `${10 + i * 12}%`, delay: Math.random() * 2, h: 25, w: 1, blur: 3, dur: 2.5, dropOp: 0.2 })),
        // Layer 2: Mid-ground, medium speed, moderate blur
        ...Array.from({ length: 12 }).map((_, i) => ({ layer: 2, x: `${5 + i * 8}%`, delay: Math.random() * 2, h: 40, w: 2, blur: 1.5, dur: 1.5, dropOp: 0.5 })),
        // Layer 3: Foreground, fast, sharp
        ...Array.from({ length: 6 }).map((_, i) => ({ layer: 3, x: `${15 + i * 15}%`, delay: Math.random() * 2, h: 60, w: 3, blur: 0, dur: 0.8, dropOp: 0.8 }))
    ];

    return (
        <DecorWrap>
            <svg className="absolute h-0 w-0">
                <defs>
                    <linearGradient id="dropGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stopColor={drop} stopOpacity="0" />
                        <stop offset="50%" stopColor={drop} stopOpacity="0.9" />
                        <stop offset="100%" stopColor={drop} stopOpacity="0.2" />
                    </linearGradient>
                    <filter id="cloudFilter">
                        <feTurbulence type="fractalNoise" baseFrequency="0.015" numOctaves="3" result="noise" />
                        <feDisplacementMap in="SourceGraphic" in2="noise" scale="20" xChannelSelector="R" yChannelSelector="G" />
                        <feGaussianBlur stdDeviation="8" />
                    </filter>
                </defs>
            </svg>

            {/* Parallax Rain Layers */}
            {rainLayers.map((r, i) => (
                <motion.div
                    key={`rain-${i}`}
                    className="absolute top-0 pointer-events-none"
                    style={{ left: r.x, opacity: opacity * r.dropOp, filter: `blur(${r.blur}px)` }}
                    animate={{ top: ['-10%', '120%'], opacity: [0, opacity * r.dropOp, opacity * r.dropOp, 0] }}
                    transition={{ duration: r.dur, repeat: Infinity, delay: r.delay, ease: 'linear' }}
                >
                    <svg width={r.w * 2} height={r.h} viewBox={`0 0 ${r.w * 2} ${r.h}`}>
                        <line x1={r.w} y1="0" x2={r.w} y2={r.h} stroke={drop} strokeWidth={r.w} strokeLinecap="round" />
                    </svg>
                </motion.div>
            ))}

            {/* Rolling Fog/Clouds at Bottom */}
            <motion.div
                className="absolute bottom-[-10%] w-[150%] h-[30%] pointer-events-none"
                style={{
                    background: cloud,
                    opacity: opacity * 0.4,
                    filter: "url(#cloudFilter) blur(10px)",
                    borderRadius: "50% 50% 0 0",
                    left: "-25%",
                }}
                animate={{
                    x: ['-5%', '5%', '-5%'],
                    y: ['0%', '5%', '0%'],
                    scaleY: [1, 1.2, 1]
                }}
                transition={{ duration: 10, repeat: Infinity, ease: "easeInOut" }}
            />

            <motion.div
                className="absolute bottom-[-5%] w-[120%] h-[20%] pointer-events-none"
                style={{
                    background: drop,
                    opacity: opacity * 0.3,
                    filter: "url(#cloudFilter) blur(5px)",
                    borderRadius: "50% 50% 0 0",
                    left: "-10%",
                }}
                animate={{ x: ['5%', '-5%', '5%'], y: ['0%', '-5%', '0%'] }}
                transition={{ duration: 8, repeat: Infinity, ease: "easeInOut" }}
            />

            {/* Water droplets on glass with shifting */}
            {[
                { x: '12%', y: '25%', r: 6 },
                { x: '42%', y: '15%', r: 4 },
                { x: '72%', y: '35%', r: 5 },
                { x: '30%', y: '55%', r: 4 },
                { x: '85%', y: '20%', r: 3 },
            ].map((d, i) => (
                <motion.svg key={`drop-glass-${i}`} className="absolute pointer-events-none" style={{ left: d.x, top: d.y, opacity: opacity * 0.6 }} width={d.r * 4} height={d.r * 4} viewBox="0 0 20 20"
                    animate={{ y: [0, 2, 0, 4, 0], x: [0, 1, 0, -1, 0] }}
                    transition={{ duration: 3 + i, repeat: Infinity, delay: i * 0.8, ease: 'easeInOut' }}
                >
                    <circle cx="10" cy="10" r={d.r} fill={drop} fillOpacity="0.4" filter="blur(0.5px)" />
                    <circle cx="8" cy="8" r={d.r * 0.3} fill="white" fillOpacity="0.8" />
                </motion.svg>
            ))}
        </DecorWrap>
    );
}

// ─── 3. Sakura — Floating cherry blossom petals & branches ───

function SakuraDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const petal = isDark ? '#f48fb1' : '#e06090';
    const branchColor = isDark ? '#5c3a46' : '#4a2a35'; // Darker for thicker branches
    const birdColor = isDark ? '#a1a1aa' : '#64748b';

    const petals = [
        { top: '-10%', left: '10%', size: 32, delay: 0, dur: 7, blur: 0 },
        { top: '-10%', left: '80%', size: 24, delay: 1.2, dur: 8, blur: 1 },
        { top: '-10%', left: '30%', size: 28, delay: 2.5, dur: 6, blur: 2 },
        { top: '-10%', left: '90%', size: 30, delay: 0.8, dur: 9, blur: 0 },
        { top: '-10%', left: '50%', size: 20, delay: 3.5, dur: 7, blur: 3 },
        { top: '-10%', left: '20%', size: 26, delay: 1.8, dur: 8, blur: 1 },
        { top: '-10%', left: '70%', size: 18, delay: 4, dur: 6, blur: 2 },
        { top: '-10%', left: '40%', size: 24, delay: 2, dur: 10, blur: 0 },
        { top: '-20%', left: '60%', size: 28, delay: 0.5, dur: 7, blur: 1 },
        { top: '20%', left: '-10%', size: 28, delay: 2.2, dur: 8, blur: 0 },
        { top: '40%', left: '-10%', size: 22, delay: 3.8, dur: 9, blur: 2 },
    ];

    return (
        <DecorWrap>
            <svg className="absolute h-0 w-0">
                <defs>
                    <radialGradient id="petalGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor="#ffffff" stopOpacity="0.9" />
                        <stop offset="100%" stopColor={petal} stopOpacity="0" />
                    </radialGradient>
                    <filter id="branchShadow">
                        <feDropShadow dx="2" dy="4" stdDeviation="3" floodOpacity="0.2" />
                    </filter>
                </defs>
            </svg>

            {/* Distant animated birds */}
            {[
                { top: '15%', delay: 0, dur: 25, scale: 0.4 },
                { top: '25%', delay: 8, dur: 35, scale: 0.25 },
                { top: '10%', delay: 15, dur: 20, scale: 0.5 },
            ].map((bird, i) => (
                <motion.svg
                    key={`bird-${i}`}
                    className="absolute pointer-events-none"
                    style={{ top: bird.top, opacity: opacity * 0.4, width: 40 * bird.scale, height: 20 * bird.scale }}
                    viewBox="0 0 40 20"
                    animate={{ x: ['-10vw', '110vw'], y: [0, -15, 10, -5, 0] }}
                    transition={{ duration: bird.dur, repeat: Infinity, delay: bird.delay, ease: "linear" }}
                >
                    <motion.path
                        d="M 10,10 Q 15,5 20,10 Q 25,5 30,10 Q 25,12 20,15 Q 15,12 10,10"
                        fill={birdColor}
                        animate={{
                            d: [
                                "M 10,10 Q 15,5 20,10 Q 25,5 30,10 Q 25,12 20,15 Q 15,12 10,10", // Wings up
                                "M 10,15 Q 15,18 20,15 Q 25,18 30,15 Q 25,12 20,10 Q 15,12 10,15"  // Wings down
                            ]
                        }}
                        transition={{ duration: 0.5, repeat: Infinity, repeatType: "mirror", ease: "easeInOut" }}
                    />
                </motion.svg>
            ))}

            {/* Swirling Petals */}
            {petals.map((p, i) => (
                <motion.svg
                    key={`petal-${i}`}
                    className="absolute pointer-events-none"
                    style={{ top: p.top, left: p.left, opacity: opacity * 0.9, filter: `blur(${p.blur}px)` }}
                    width={p.size}
                    height={p.size}
                    viewBox="0 0 20 20"
                    animate={{
                        y: ['0vh', '40vh', '110vh'],
                        x: [0, 80 + Math.random() * 50, -40 - Math.random() * 50],
                        rotate: [0, 180, 360],
                        scale: [1, 1.2, 0.8, 1], // Added depth scale
                        opacity: [0, 0.9, 0.6, 0],
                    }}
                    transition={{ duration: p.dur, repeat: Infinity, delay: p.delay, ease: 'easeInOut' }}
                >
                    <path d="M10 2 Q16 8, 10 18 Q4 8, 10 2Z" fill={petal} fillOpacity="0.7" />
                    <path d="M10 2 Q16 8, 10 18 Q4 8, 10 2Z" fill="url(#petalGlow)" />
                </motion.svg>
            ))}

            {/* Thicker Foreground wind-swayed branch (Top-Right) */}
            <motion.svg
                className="absolute -top-4 -right-10 h-64 w-80 pointer-events-none origin-top-right"
                style={{ opacity: opacity * 0.85, filter: 'url(#branchShadow)' }}
                viewBox="0 0 200 150"
                animate={{ rotate: [-2, 2, -1, 3, -2] }}
                transition={{ duration: 7, repeat: Infinity, ease: 'easeInOut' }}
            >
                <path d="M10 140 Q60 110, 110 70 Q140 50, 180 20 Q195 10, 210 0" fill="none" stroke={branchColor} strokeWidth="8" strokeLinecap="round" />
                <path d="M110 70 Q120 50, 140 40" fill="none" stroke={branchColor} strokeWidth="4" strokeLinecap="round" />
                <path d="M60 110 Q40 90, 30 70" fill="none" stroke={branchColor} strokeWidth="3" strokeLinecap="round" />
                {/* Glowing branch blossoms */}
                {[
                    { cx: 120, cy: 65, r: 8 }, { cx: 155, cy: 45, r: 7 },
                    { cx: 185, cy: 25, r: 8.5 }, { cx: 205, cy: 10, r: 6.5 },
                    { cx: 140, cy: 40, r: 6 }, { cx: 60, cy: 105, r: 7 },
                    { cx: 30, cy: 70, r: 6 }, { cx: 100, cy: 80, r: 8 }
                ].map((b, i) => (
                    <g key={`b-${i}`}>
                        <circle cx={b.cx} cy={b.cy} r={b.r * 1.8} fill={petal} fillOpacity="0.3" filter="blur(3px)" />
                        <circle cx={b.cx} cy={b.cy} r={b.r} fill={petal} fillOpacity="0.8" />
                        <circle cx={b.cx - 2} cy={b.cy - 2} r={b.r * 0.4} fill="#fff" fillOpacity="0.5" />
                    </g>
                ))}
            </motion.svg>

            {/* Thicker Foreground wind-swayed branch (Bottom-Left) */}
            <motion.svg
                className="absolute -bottom-10 -left-10 h-56 w-72 pointer-events-none origin-bottom-left"
                style={{ opacity: opacity * 0.75, filter: 'url(#branchShadow)' }}
                viewBox="0 0 180 140"
                animate={{ rotate: [1, -2, 0, -1, 1] }}
                transition={{ duration: 6.5, repeat: Infinity, ease: 'easeInOut', delay: 1 }}
            >
                <path d="M180 20 Q130 40, 90 70 Q60 90, 20 130 Q10 140, 0 150" fill="none" stroke={branchColor} strokeWidth="7" strokeLinecap="round" />
                <path d="M90 70 Q110 90, 130 110" fill="none" stroke={branchColor} strokeWidth="4" strokeLinecap="round" />
                {[
                    { cx: 90, cy: 70, r: 7.5 }, { cx: 60, cy: 90, r: 6.5 },
                    { cx: 25, cy: 125, r: 8 }, { cx: 130, cy: 110, r: 6 },
                    { cx: 110, cy: 55, r: 7 }
                ].map((b, i) => (
                    <g key={`bl-${i}`}>
                        <circle cx={b.cx} cy={b.cy} r={b.r * 1.8} fill={petal} fillOpacity="0.25" filter="blur(3px)" />
                        <circle cx={b.cx} cy={b.cy} r={b.r} fill={petal} fillOpacity="0.75" />
                        <circle cx={b.cx - 2} cy={b.cy - 2} r={b.r * 0.4} fill="#fff" fillOpacity="0.5" />
                    </g>
                ))}
            </motion.svg>
        </DecorWrap>
    );
}

function OceanTideDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const waveLight = isDark ? '#4dd0e1' : '#00acc1';
    const waveMid = isDark ? '#00acc1' : '#00838f';
    const waveDark = isDark ? '#00838f' : '#006064';
    const causticColor = isDark ? '#ffffff' : '#e0f7fa';

    return (
        <DecorWrap>
            <svg className="absolute h-0 w-0">
                <defs>
                    <linearGradient id="waveGrad1" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stopColor={waveLight} stopOpacity="0.45" />
                        <stop offset="100%" stopColor={waveLight} stopOpacity="0.05" />
                    </linearGradient>
                    <linearGradient id="waveGrad2" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stopColor={waveMid} stopOpacity="0.5" />
                        <stop offset="100%" stopColor={waveMid} stopOpacity="0.1" />
                    </linearGradient>
                    <linearGradient id="waveGrad3" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stopColor={waveDark} stopOpacity="0.6" />
                        <stop offset="100%" stopColor={waveDark} stopOpacity="0.15" />
                    </linearGradient>
                    <radialGradient id="bubbleGlow" cx="30%" cy="30%" r="50%">
                        <stop offset="0%" stopColor="#ffffff" stopOpacity="0.9" />
                        <stop offset="100%" stopColor={waveLight} stopOpacity="0" />
                    </radialGradient>
                    <filter id="causticBlur" x="-20%" y="-20%" width="140%" height="140%">
                        <feGaussianBlur stdDeviation="4" result="blur" />
                        <feColorMatrix type="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 18 -7" />
                    </filter>
                </defs>
            </svg>

            {/* Caustic Shimmering Overlay */}
            <motion.div
                className="absolute inset-0 mix-blend-overlay pointer-events-none"
                style={{ opacity: opacity * 0.15 }}
            >
                <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
                    {[0, 1].map((i) => (
                        <motion.ellipse
                            key={`caustic-${i}`}
                            cx={i === 0 ? "30%" : "70%"}
                            cy={i === 0 ? "50%" : "40%"}
                            rx="40%" ry="20%"
                            fill="none"
                            stroke={causticColor}
                            strokeWidth="2"
                            filter="url(#causticBlur)"
                            animate={{
                                rx: ["40%", "45%", "35%", "40%"],
                                ry: ["20%", "25%", "15%", "20%"],
                                opacity: [0.4, 0.8, 0.4]
                            }}
                            transition={{
                                duration: 8 + i * 2,
                                repeat: Infinity,
                                ease: "easeInOut",
                                delay: i * 2
                            }}
                        />
                    ))}
                </svg>
            </motion.div>

            {/* Background Slow Waves */}
            <motion.svg
                className="absolute bottom-0 left-0 w-[200%] h-[35%]"
                style={{ opacity: opacity * 0.7 }}
                viewBox="0 0 1000 200"
                preserveAspectRatio="none"
                animate={{ x: ['0%', '-50%'] }}
                transition={{ duration: 25, repeat: Infinity, ease: 'linear' }}
            >
                <path d="M0,120 C150,200 350,20 500,120 C650,220 850,20 1000,120 L1000,200 L0,200 Z" fill="url(#waveGrad1)" />
            </motion.svg>

            {/* Dynamic Middle Waves */}
            <motion.svg
                className="absolute bottom-0 left-0 w-[200%] h-[25%]"
                style={{ opacity: opacity * 0.8 }}
                viewBox="0 0 1000 200"
                preserveAspectRatio="none"
                animate={{ x: ['-50%', '0%'] }}
                transition={{ duration: 18, repeat: Infinity, ease: 'linear' }}
            >
                <path d="M0,100 C200,40 300,160 500,100 C700,40 800,160 1000,100 L1000,200 L0,200 Z" fill="url(#waveGrad2)" />
            </motion.svg>

            {/* Foreground Fast Waves */}
            <motion.svg
                className="absolute bottom-0 left-0 w-[200%] h-[18%]"
                style={{ opacity: opacity * 0.9 }}
                viewBox="0 0 1000 200"
                preserveAspectRatio="none"
                animate={{ x: ['0%', '-50%'] }}
                transition={{ duration: 12, repeat: Infinity, ease: 'linear' }}
            >
                <path d="M0,80 C150,0 350,150 500,80 C650,0 850,150 1000,80 L1000,200 L0,200 Z" fill="url(#waveGrad3)" />
            </motion.svg>

            {/* Schools of Fish */}
            {[
                { groupDelay: 0, groupY: '70%', duration: 20 },
                { groupDelay: 5, groupY: '40%', duration: 15 },
                { groupDelay: 12, groupY: '85%', duration: 25 }
            ].map((school, groupIndex) => (
                <motion.div
                    key={`school-${groupIndex}`}
                    className="absolute"
                    style={{ top: school.groupY, opacity: opacity * 0.6 }}
                    animate={{ left: ['-20%', '120%'] }}
                    transition={{ duration: school.duration, repeat: Infinity, delay: school.groupDelay, ease: 'linear' }}
                >
                    {[0, 1, 2].map(fish => (
                        <motion.svg
                            key={`fish-${groupIndex}-${fish}`}
                            className="absolute"
                            style={{
                                top: `${-10 + fish * 15}px`,
                                left: `${-fish * 20}px`,
                                width: 24, height: 12,
                                fill: waveDark
                            }}
                            animate={{ rotate: [-5, 5, -5], y: [-5, 5, -5] }}
                            transition={{ duration: 2, repeat: Infinity, delay: fish * 0.3 }}
                        >
                            <path d="M0 6 C 5 2, 15 2, 20 6 L 24 0 L 24 12 L 20 6 C 15 10, 5 10, 0 6 Z" />
                        </motion.svg>
                    ))}
                </motion.div>
            ))}

            {/* Drifting Bubbles */}
            {[
                { x: '10%', size: 16, delay: 0, sway: 15 },
                { x: '30%', size: 24, delay: 1.5, sway: 25 },
                { x: '45%', size: 12, delay: 0.5, sway: 10 },
                { x: '65%', size: 20, delay: 2.2, sway: 20 },
                { x: '85%', size: 18, delay: 1.0, sway: 18 },
                { x: '20%', size: 14, delay: 3.5, sway: 12 },
                { x: '75%', size: 22, delay: 2.8, sway: 22 },
                { x: '55%', size: 10, delay: 4.1, sway: 8 },
            ].map((b, i) => (
                <motion.div
                    key={`bubble-${i}`}
                    className="absolute bottom-0 rounded-full border border-[rgba(255,255,255,0.4)] shadow-[inset_0_0_8px_rgba(255,255,255,0.4)]"
                    style={{ left: b.x, width: b.size, height: b.size, opacity: opacity * 0.6, background: 'url(#bubbleGlow)' }}
                    animate={{
                        y: ['100px', '-120vh'],
                        x: [0, b.sway, -b.sway, 0],
                        scale: [0.8, 1.2, 0.9]
                    }}
                    transition={{
                        y: { duration: 8 + (i % 4) * 3, repeat: Infinity, delay: b.delay, ease: 'linear' },
                        x: { duration: 3 + (i % 2), repeat: Infinity, ease: 'easeInOut' },
                        scale: { duration: 4, repeat: Infinity, ease: 'easeInOut' }
                    }}
                >
                    <div className="absolute top-1 left-1 bg-white rounded-full w-[30%] h-[30%] opacity-70"></div>
                </motion.div>
            ))}
        </DecorWrap>
    );
}

// ─── 5. Neon City — Buildings, signs & glowing lights ────────

function NeonCityDecor({ opacity }: { opacity: number }) {
    return (
        <DecorWrap>
            <svg style={{ position: 'absolute', width: 0, height: 0 }}>
                <defs>
                    <radialGradient id="cyberOrbGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor="#ffffff" stopOpacity="0.9" />
                        <stop offset="20%" stopColor="currentColor" stopOpacity="0.7" />
                        <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                    </radialGradient>
                    <linearGradient id="neonBuildingGrad" x1="0%" y1="100%" x2="0%" y2="0%">
                        <stop offset="0%" stopColor="#0a0a1e" stopOpacity="0.9" />
                        <stop offset="100%" stopColor="#1a1a3a" stopOpacity="0.7" />
                    </linearGradient>
                </defs>
            </svg>

            {/* City skyline silhouette — taller with gradient */}
            <motion.svg
                className="absolute bottom-0 left-0 h-[45%] w-[110%]"
                style={{ opacity: opacity * 0.8 }}
                viewBox="0 0 400 140"
                preserveAspectRatio="none"
                animate={{ x: ['0%', '-5%'] }}
                transition={{ duration: 40, repeat: Infinity, ease: 'linear', repeatType: 'mirror' }}
            >
                <path d="M0 140 V90 H25 V60 H45 V90 H70 V40 H95 V90 H115 V65 H140 V90 H170 V30 H195 V90 H220 V70 H250 V90 H275 V45 H300 V90 H330 V60 H355 V90 H385 V75 H400 V140Z" fill="url(#neonBuildingGrad)" />
                {/* Lit windows — more spread & occasionally blinking */}
                {[
                    { x: 32, y: 70, c: '#bb86fc', d: 0 }, { x: 32, y: 80, c: '#64ffda', d: 1 },
                    { x: 78, y: 50, c: '#ff6e7f', d: 2 }, { x: 78, y: 60, c: '#bb86fc', d: 0.5 }, { x: 78, y: 75, c: '#ffab40', d: 1.5 },
                    { x: 122, y: 72, c: '#64ffda', d: 3 }, { x: 122, y: 82, c: '#ff6e7f', d: 0.2 },
                    { x: 178, y: 40, c: '#bb86fc', d: 2.2 }, { x: 178, y: 55, c: '#64ffda', d: 1.8 }, { x: 178, y: 70, c: '#ffab40', d: 0 },
                    { x: 232, y: 78, c: '#ff6e7f', d: 1 },
                    { x: 283, y: 55, c: '#bb86fc', d: 2.5 }, { x: 283, y: 68, c: '#64ffda', d: 0 },
                    { x: 340, y: 68, c: '#ffab40', d: 1.2 }, { x: 340, y: 78, c: '#bb86fc', d: 3 },
                    { x: 390, y: 82, c: '#64ffda', d: 0.5 },
                ].map((w, i) => (
                    <motion.rect
                        key={`win-${i}`}
                        x={w.x} y={w.y} width="6" height="5" rx="0.5"
                        fill={w.c}
                        animate={{ opacity: [0.1, 0.9, 0.2, 1, 0.4] }}
                        transition={{ duration: 4 + (i % 3), repeat: Infinity, delay: w.d }}
                    />
                ))}
            </motion.svg>

            {/* Neon glow orbs — drifting heavily and blurred */}
            {[
                { x: '10%', y: '10%', s: 40, c: '#bb86fc', delay: 0, dur: 5 },
                { x: '80%', y: '25%', s: 50, c: '#64ffda', delay: 1.2, dur: 6 },
                { x: '40%', y: '45%', s: 30, c: '#ff6e7f', delay: 2, dur: 4.5 },
                { x: '70%', y: '60%', s: 45, c: '#ffab40', delay: 0.8, dur: 5.5 },
                { x: '20%', y: '70%', s: 35, c: '#00e5ff', delay: 3, dur: 7 },
            ].map((g, i) => (
                <motion.div
                    key={`orb-${i}`}
                    className="absolute rounded-full"
                    style={{ left: g.x, top: g.y, width: g.s, height: g.s, color: g.c, filter: 'blur(8px)', background: 'url(#cyberOrbGlow)', opacity: opacity * 0.7 }}
                    animate={{
                        y: [-20, 20, -20],
                        x: [-15, 15, -15],
                        scale: [0.8, 1.3, 0.8],
                        opacity: [0.3, 0.8, 0.3]
                    }}
                    transition={{ duration: g.dur, repeat: Infinity, ease: 'easeInOut', delay: g.delay }}
                />
            ))}

            {/* Laser grid/Neon line sweeping across */}
            <motion.div
                className="absolute left-0 w-full h-[1px] bg-gradient-to-r from-transparent via-[#ff6e7f] to-transparent"
                style={{ opacity: opacity * 0.6, boxShadow: "0 0 10px #ff6e7f, 0 0 20px #ff6e7f" }}
                animate={{ top: ['-10%', '110%'] }}
                transition={{ duration: 12, repeat: Infinity, ease: 'linear' }}
            />
        </DecorWrap>
    );
}

// ─── 6. Golden Hour — Sun, rays & floating particles ─────────

function GoldenHourDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const ray = isDark ? '#ffb74d' : '#f09030';
    return (
        <DecorWrap>
            <svg style={{ position: 'absolute', width: 0, height: 0 }}>
                <defs>
                    <radialGradient id="sunBurst" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor="#ffffff" stopOpacity="0.9" />
                        <stop offset="30%" stopColor={ray} stopOpacity="0.7" />
                        <stop offset="100%" stopColor={ray} stopOpacity="0" />
                    </radialGradient>
                    <radialGradient id="dustParticle" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor="#ffffff" stopOpacity="0.8" />
                        <stop offset="40%" stopColor={ray} stopOpacity="0.5" />
                        <stop offset="100%" stopColor={ray} stopOpacity="0" />
                    </radialGradient>
                </defs>
            </svg>

            {/* Glowing Sun top-right */}
            <motion.div
                className="absolute top-[-5%] right-[-5%] w-[45%] aspect-square rounded-full mix-blend-screen"
                style={{ opacity: opacity * 0.8, background: 'url(#sunBurst)' }}
                animate={{ scale: [1, 1.05, 1], opacity: [0.7, 0.9, 0.7] }}
                transition={{ duration: 8, repeat: Infinity, ease: "easeInOut" }}
            />

            {/* Diagonal light rays — thick cinematic blooming */}
            <svg className="absolute top-0 right-0 h-full w-full" style={{ opacity: opacity * 0.35, filter: 'blur(4px)' }} viewBox="0 0 400 600" preserveAspectRatio="none">
                <motion.line animate={{ strokeOpacity: [0.08, 0.15, 0.08] }} transition={{ duration: 5, repeat: Infinity }} x1="400" y1="0" x2="100" y2="600" stroke={ray} strokeWidth="80" strokeOpacity="0.1" />
                <motion.line animate={{ strokeOpacity: [0.05, 0.1, 0.05] }} transition={{ duration: 7, delay: 2, repeat: Infinity }} x1="350" y1="0" x2="50" y2="600" stroke={ray} strokeWidth="45" strokeOpacity="0.08" />
                <motion.line animate={{ strokeOpacity: [0.03, 0.08, 0.03] }} transition={{ duration: 6, delay: 1, repeat: Infinity }} x1="280" y1="0" x2="-20" y2="600" stroke={ray} strokeWidth="30" strokeOpacity="0.06" />
            </svg>

            {/* Floating dust/pollen particles with depth of field blur */}
            {[
                { x: '15%', y: '25%', s: 16, delay: 0, b: 2 },
                { x: '55%', y: '45%', s: 24, delay: 1.2, b: 4 },
                { x: '80%', y: '20%', s: 12, delay: 2.5, b: 1 },
                { x: '12%', y: '60%', s: 18, delay: 1.8, b: 3 },
                { x: '65%', y: '65%', s: 10, delay: 0.5, b: 0 },
                { x: '40%', y: '30%', s: 14, delay: 3, b: 1 },
                { x: '88%', y: '50%', s: 20, delay: 2, b: 5 },
                { x: '35%', y: '75%', s: 12, delay: 0.8, b: 2 },
                { x: '50%', y: '10%', s: 22, delay: 1.5, b: 4 },
                { x: '25%', y: '85%', s: 16, delay: 3.5, b: 2 },
            ].map((p, i) => (
                <motion.div
                    key={`dust-${i}`}
                    className="absolute rounded-full mix-blend-screen"
                    style={{ left: p.x, top: p.y, width: p.s, height: p.s, background: 'url(#dustParticle)', opacity: opacity * 0.7, filter: `blur(${p.b}px)` }}
                    animate={{
                        y: [-20, 20, -20],
                        x: [-10, 15, -10],
                        opacity: [0, 0.8, 0],
                        scale: [0.8, 1.2, 0.8]
                    }}
                    transition={{ duration: 6 + i, repeat: Infinity, delay: p.delay, ease: 'easeInOut' }}
                />
            ))}

            {/* Warm haze at bottom */}
            <motion.div
                className="absolute bottom-0 left-0 h-1/3 w-full bg-gradient-to-t from-[var(--ray-color)] to-transparent mix-blend-screen"
                style={{ opacity: opacity * 0.15, '--ray-color': ray } as React.CSSProperties}
            />
        </DecorWrap>
    );
}

// ─── 7. Deep Forest — Trees, ferns & fireflies ──────────────

function DeepForestDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const trunkLight = isDark ? '#4a6b3a' : '#3a5a2a';
    const trunkDark = isDark ? '#2e4526' : '#233d16';
    const leafPrimary = isDark ? '#66bb6a' : '#43a047';
    const leafSecondary = isDark ? '#388e3c' : '#2e7d32';
    const firefly = '#c5e84c';

    return (
        <DecorWrap>
            <svg className="absolute h-0 w-0">
                <defs>
                    <radialGradient id="fireflyGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor={firefly} stopOpacity="1" />
                        <stop offset="20%" stopColor={firefly} stopOpacity="0.8" />
                        <stop offset="60%" stopColor={firefly} stopOpacity="0.2" />
                        <stop offset="100%" stopColor={firefly} stopOpacity="0" />
                    </radialGradient>
                    <linearGradient id="treeGradFront" x1="0%" y1="100%" x2="0%" y2="0%">
                        <stop offset="0%" stopColor={trunkLight} stopOpacity="0.7" />
                        <stop offset="100%" stopColor={leafPrimary} stopOpacity="0.8" />
                    </linearGradient>
                    <linearGradient id="treeGradBack" x1="0%" y1="100%" x2="0%" y2="0%">
                        <stop offset="0%" stopColor={trunkDark} stopOpacity="0.9" />
                        <stop offset="100%" stopColor={leafSecondary} stopOpacity="0.9" />
                    </linearGradient>
                </defs>
            </svg>

            {/* Deep Background Trees */}
            <motion.svg
                className="absolute bottom-0 left-10 h-72 w-48 origin-bottom"
                style={{ opacity: opacity * 0.4 }}
                viewBox="0 0 100 200"
                animate={{ rotate: [-1, 1, -1] }}
                transition={{ duration: 25, repeat: Infinity, ease: "easeInOut", delay: 2 }}
            >
                <rect x="45" y="80" width="10" height="120" fill={trunkDark} fillOpacity="0.6" />
                <polygon points="50,10 10,130 90,130" fill="url(#treeGradBack)" />
                <polygon points="50,40 20,150 80,150" fill="url(#treeGradBack)" />
            </motion.svg>

            <motion.svg
                className="absolute bottom-0 right-10 h-[22rem] w-56 origin-bottom"
                style={{ opacity: opacity * 0.5 }}
                viewBox="0 0 100 200"
                animate={{ rotate: [1.5, -1.5, 1.5] }}
                transition={{ duration: 20, repeat: Infinity, ease: "easeInOut" }}
            >
                <rect x="42" y="60" width="16" height="140" fill={trunkDark} fillOpacity="0.7" />
                <polygon points="50,0 5,110 95,110" fill="url(#treeGradBack)" />
                <polygon points="50,30 15,130 85,130" fill="url(#treeGradBack)" />
            </motion.svg>

            {/* Foreground Parallax Trees */}
            <motion.svg
                className="absolute bottom-0 -left-12 h-[28rem] w-64 origin-bottom"
                style={{ opacity: opacity * 0.85 }}
                viewBox="0 0 100 200"
                animate={{ rotate: [-2, 2, -2] }}
                transition={{ duration: 16, repeat: Infinity, ease: "easeInOut" }}
            >
                <rect x="40" y="40" width="20" height="160" fill={trunkLight} fillOpacity="0.8" />
                <polygon points="50,-10 0,100 100,100" fill="url(#treeGradFront)" />
                <polygon points="50,20 10,120 90,120" fill="url(#treeGradFront)" />
            </motion.svg>

            <motion.svg
                className="absolute bottom-0 -right-8 h-[24rem] w-52 origin-bottom"
                style={{ opacity: opacity * 0.9 }}
                viewBox="0 0 100 200"
                animate={{ rotate: [2, -2, 2] }}
                transition={{ duration: 14, repeat: Infinity, ease: "easeInOut", delay: 1 }}
            >
                <rect x="42" y="70" width="16" height="130" fill={trunkLight} fillOpacity="0.8" />
                <polygon points="50,10 5,120 95,120" fill="url(#treeGradFront)" />
            </motion.svg>

            {/* Swaying Foreground Vines */}
            <motion.svg
                className="absolute top-0 left-[15%] w-16 h-[60%] origin-top pointer-events-none"
                style={{ opacity: opacity * 0.6 }}
                viewBox="0 0 50 200"
                animate={{ rotate: [-5, 5, -5] }}
                transition={{ duration: 8, repeat: Infinity, ease: "easeInOut" }}
            >
                <path d="M25,0 C30,50 10,100 25,150 C40,200 25,250 25,250" fill="none" stroke={leafPrimary} strokeWidth="3" />
                {[...Array(8)].map((_, i) => (
                    <ellipse key={`leaf-${i}`} cx={25 + (i % 2 === 0 ? -8 : 8)} cy={20 + i * 20} rx="6" ry="3" fill={leafSecondary} transform={`rotate(${i % 2 === 0 ? 30 : -30} ${25 + (i % 2 === 0 ? -8 : 8)} ${20 + i * 20})`} />
                ))}
            </motion.svg>

            {/* Forest Floor Fern Fronds */}
            {[
                { left: '-2%', scale: 1.2, rot: 15, delay: 0, opacity: 0.9 },
                { left: '20%', scale: 0.8, rot: 5, delay: 1, opacity: 0.6 },
                { left: '75%', scale: 1.1, rot: -10, delay: 2, opacity: 0.85 },
                { left: '90%', scale: 1.4, rot: -25, delay: 0.5, opacity: 1 }
            ].map((fern, i) => (
                <motion.svg
                    key={`fern-${i}`}
                    className="absolute bottom-0 h-28 w-32 origin-bottom"
                    style={{ left: fern.left, opacity: opacity * fern.opacity, transformOrigin: 'bottom center' }}
                    viewBox="0 0 80 60"
                    animate={{ rotate: [fern.rot - 3, fern.rot + 3, fern.rot - 3] }}
                    transition={{ duration: 10 + i * 2, repeat: Infinity, ease: "easeInOut", delay: fern.delay }}
                >
                    <g transform={`scale(${fern.scale})`}>
                        <path d="M10 55 Q30 25, 65 10" fill="none" stroke={leafPrimary} strokeWidth="4" strokeLinecap="round" />
                        <path d="M22 40 Q30 30, 42 25" fill="none" stroke={leafSecondary} strokeWidth="3" strokeLinecap="round" />
                        <path d="M35 28 Q45 20, 55 15" fill="none" stroke={leafSecondary} strokeWidth="2.5" strokeLinecap="round" />
                    </g>
                </motion.svg>
            ))}

            {/* Swarm of Fireflies */}
            {[
                { x: '15%', y: '65%', delay: 0, s: 22, dur: 4 },
                { x: '60%', y: '40%', delay: 1.2, s: 16, dur: 5 },
                { x: '40%', y: '80%', delay: 2.5, s: 24, dur: 3 },
                { x: '85%', y: '55%', delay: 1, s: 18, dur: 6 },
                { x: '25%', y: '35%', delay: 3, s: 28, dur: 4.5 },
                { x: '75%', y: '85%', delay: 0.5, s: 16, dur: 5.5 },
                { x: '50%', y: '50%', delay: 2, s: 14, dur: 3.5 },
                { x: '10%', y: '55%', delay: 1.8, s: 20, dur: 7 },
                { x: '90%', y: '45%', delay: 0.8, s: 26, dur: 4.2 },
                { x: '35%', y: '70%', delay: 1.5, s: 18, dur: 5.2 },
                { x: '65%', y: '30%', delay: 0.3, s: 22, dur: 4.8 },
                { x: '80%', y: '75%', delay: 2.2, s: 15, dur: 6.5 },
            ].map((f, i) => (
                <motion.div
                    key={`ff-${i}`}
                    className="absolute rounded-full shadow-[0_0_12px_rgba(197,232,76,0.6)]"
                    style={{ left: f.x, top: f.y, width: f.s, height: f.s, background: 'url(#fireflyGlow)', opacity: opacity, filter: 'blur(1px)' }}
                    animate={{
                        y: [0, -30, 15, -15, 0],
                        x: [0, 20, -20, 15, 0],
                        opacity: [0, 1, 0.4, 1, 0],
                        scale: [0.6, 1.3, 0.9, 1.2, 0.6]
                    }}
                    transition={{
                        duration: f.dur,
                        repeat: Infinity,
                        delay: f.delay,
                        ease: 'easeInOut'
                    }}
                >
                    {/* Inner bright core */}
                    <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-1/4 h-1/4 bg-white rounded-full opacity-80" />
                </motion.div>
            ))}
        </DecorWrap>
    );
}

// ─── 8. Snowfall — Drifting snowflakes & frost ───────────────

function SnowfallDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const snow = isDark ? '#e0e4e8' : '#90a4ae';
    const flakes = [
        { x: '5%', size: 10, delay: 0, dur: 7, blur: 0 },
        { x: '14%', size: 6, delay: 1.5, dur: 9, blur: 2 },
        { x: '24%', size: 12, delay: 0.8, dur: 6, blur: 0 },
        { x: '34%', size: 8, delay: 2.5, dur: 8, blur: 1 },
        { x: '44%', size: 14, delay: 0.3, dur: 10, blur: 3 },
        { x: '54%', size: 7, delay: 3, dur: 7, blur: 0 },
        { x: '64%', size: 16, delay: 1, dur: 8, blur: 1 },
        { x: '74%', size: 8, delay: 2, dur: 9, blur: 2 },
        { x: '84%', size: 10, delay: 0.5, dur: 7, blur: 0 },
        { x: '92%', size: 6, delay: 1.8, dur: 10, blur: 1 },
        { x: '20%', size: 18, delay: 2.1, dur: 11, blur: 4 },
        { x: '80%', size: 24, delay: 0.7, dur: 13, blur: 5 },
    ];
    return (
        <DecorWrap>
            <svg style={{ position: 'absolute', width: 0, height: 0 }}>
                <defs>
                    <radialGradient id="snowGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor="#ffffff" stopOpacity="0.9" />
                        <stop offset="50%" stopColor={snow} stopOpacity="0.4" />
                        <stop offset="100%" stopColor={snow} stopOpacity="0" />
                    </radialGradient>
                </defs>
            </svg>
            {flakes.map((f, i) => (
                <motion.div
                    key={`snow-${i}`}
                    className="absolute -top-[10%]"
                    style={{ left: f.x, opacity: opacity * 0.85, filter: `blur(${f.blur}px)` }}
                    animate={{
                        y: ['0vh', '110vh'],
                        x: [0, f.size * 4, -f.size * 3, f.size * 2, 0],
                        rotate: [0, 360, 720],
                    }}
                    transition={{
                        duration: f.dur,
                        repeat: Infinity,
                        delay: f.delay,
                        ease: 'linear',
                    }}
                >
                    <svg width={f.size * 2} height={f.size * 2} viewBox="0 0 20 20">
                        <circle cx="10" cy="10" r="4" fill="url(#snowGlow)" />
                        <circle cx="10" cy="10" r="2" fill="#ffffff" fillOpacity="0.8" />
                        {/* Starburst arms */}
                        <line x1="10" y1="2" x2="10" y2="18" stroke={snow} strokeWidth="1" strokeOpacity="0.5" />
                        <line x1="2" y1="10" x2="18" y2="10" stroke={snow} strokeWidth="1" strokeOpacity="0.5" />
                        <line x1="4" y1="4" x2="16" y2="16" stroke={snow} strokeWidth="0.8" strokeOpacity="0.4" />
                        <line x1="16" y1="4" x2="4" y2="16" stroke={snow} strokeWidth="0.8" strokeOpacity="0.4" />
                    </svg>
                </motion.div>
            ))}
            {/* Frost corners */}
            <svg className="absolute top-0 left-0 h-32 w-32" style={{ opacity: opacity * 0.45, filter: 'blur(1px)' }} viewBox="0 0 80 80">
                <path d="M0 0 Q30 5, 20 30 Q15 45, 0 50" fill="none" stroke={snow} strokeWidth="2.5" strokeOpacity="0.35" />
                <path d="M0 0 Q8 20, 5 40" fill="none" stroke={snow} strokeWidth="1.5" strokeOpacity="0.25" />
                <path d="M0 0 Q45 10, 35 45" fill="none" stroke={snow} strokeWidth="1" strokeOpacity="0.15" />
            </svg>
            <svg className="absolute top-0 right-0 h-32 w-32" style={{ opacity: opacity * 0.45, filter: 'blur(1px)' }} viewBox="0 0 80 80">
                <path d="M80 0 Q50 5, 60 30 Q65 45, 80 50" fill="none" stroke={snow} strokeWidth="2.5" strokeOpacity="0.35" />
                <path d="M80 0 Q35 10, 45 45" fill="none" stroke={snow} strokeWidth="1" strokeOpacity="0.15" />
            </svg>
            {/* Ground snow mound — multi-layered parallax */}
            <svg className="absolute bottom-0 left-0 h-20 w-full" style={{ opacity: opacity * 0.5, filter: 'blur(2px)' }} viewBox="0 0 400 60" preserveAspectRatio="none">
                <path d="M0 60 L0 30 Q50 10, 100 25 Q150 40, 200 20 Q250 0, 300 15 Q350 30, 400 20 L400 60Z" fill={snow} fillOpacity="0.08" />
                <path d="M0 60 L0 45 Q50 25, 100 35 Q150 45, 200 35 Q250 25, 300 40 Q350 55, 400 40 L400 60Z" fill={snow} fillOpacity="0.15" />
            </svg>
        </DecorWrap>
    );
}

// ─── 9. Moonlit — Moon, stars, clouds & soft glow ────────────

function MoonlitDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const moonColor = isDark ? '#b39ddb' : '#8e72c0';
    const starColor = isDark ? '#d1c4e9' : '#9c8fba';
    const cloudColor = isDark ? '#37306b' : '#d1c4e9';
    return (
        <DecorWrap>
            <svg style={{ position: 'absolute', width: 0, height: 0 }}>
                <defs>
                    <radialGradient id="moonGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor="#ffffff" stopOpacity="0.9" />
                        <stop offset="25%" stopColor={moonColor} stopOpacity="0.6" />
                        <stop offset="100%" stopColor={moonColor} stopOpacity="0" />
                    </radialGradient>
                    <radialGradient id="starGlow" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor="#ffffff" stopOpacity="1" />
                        <stop offset="100%" stopColor={starColor} stopOpacity="0" />
                    </radialGradient>
                </defs>
            </svg>

            {/* Glowing Moon */}
            <motion.div
                className="absolute top-4 right-8 w-32 h-32 rounded-full mix-blend-screen"
                style={{ opacity: opacity * 0.9, background: 'url(#moonGlow)' }}
                {...floatY(4, 8)}
            >
                <div className="absolute top-1/4 right-1/4 w-1/2 h-1/2 rounded-full bg-[#1a1040] opacity-80 backdrop-blur-sm shadow-inner" style={{ background: isDark ? '#1a1040' : '#ede7f6' }} />
            </motion.div>

            {/* Twinkling stars — complex scale/opacity blinking */}
            {[
                { x: '8%', y: '8%', s: 16, delay: 0 },
                { x: '25%', y: '15%', s: 12, delay: 1 },
                { x: '60%', y: '5%', s: 14, delay: 2 },
                { x: '78%', y: '20%', s: 10, delay: 1.5 },
                { x: '12%', y: '65%', s: 12, delay: 3 },
                { x: '90%', y: '55%', s: 14, delay: 0.5 },
                { x: '40%', y: '10%', s: 8, delay: 2.5 },
                { x: '55%', y: '70%', s: 12, delay: 1.2 },
                { x: '85%', y: '40%', s: 10, delay: 3.5 },
                { x: '5%', y: '40%', s: 14, delay: 0.8 },
                { x: '45%', y: '85%', s: 16, delay: 1.8 },
                { x: '75%', y: '80%', s: 8, delay: 2.2 },
            ].map((st, i) => (
                <motion.div
                    key={`star-${i}`}
                    className="absolute rounded-full mix-blend-screen"
                    style={{ left: st.x, top: st.y, width: st.s, height: st.s, background: 'url(#starGlow)', opacity: opacity * 0.8 }}
                    animate={{ opacity: [0, 0.7, 0.2, 1, 0], scale: [0.5, 1.2, 0.8, 1.5, 0.5] }}
                    transition={{ duration: 3 + (i % 5), repeat: Infinity, delay: st.delay, ease: 'easeInOut' }}
                />
            ))}

            {/* Parallax Wispy clouds */}
            <motion.svg className="absolute bottom-16 left-2 h-16 w-48" style={{ opacity: opacity * 0.4, filter: 'blur(3px)' }} viewBox="0 0 120 40" {...floatX(6, 12)}>
                <ellipse cx="40" cy="25" rx="35" ry="12" fill={cloudColor} fillOpacity="0.25" />
                <ellipse cx="65" cy="20" rx="25" ry="10" fill={cloudColor} fillOpacity="0.2" />
                <ellipse cx="25" cy="22" rx="20" ry="8" fill={cloudColor} fillOpacity="0.15" />
            </motion.svg>
            <motion.svg className="absolute top-20 left-4 h-12 w-36" style={{ opacity: opacity * 0.3, filter: 'blur(2px)' }} viewBox="0 0 100 30" {...floatX(4, 18)}>
                <ellipse cx="35" cy="18" rx="28" ry="10" fill={cloudColor} fillOpacity="0.2" />
                <ellipse cx="60" cy="14" rx="20" ry="8" fill={cloudColor} fillOpacity="0.15" />
            </motion.svg>
            <motion.svg className="absolute top-1/3 right-1/4 h-20 w-64" style={{ opacity: opacity * 0.25, filter: 'blur(4px)' }} viewBox="0 0 150 50" {...floatX(8, 22)}>
                <ellipse cx="75" cy="25" rx="60" ry="20" fill={cloudColor} fillOpacity="0.15" />
                <ellipse cx="110" cy="20" rx="40" ry="15" fill={cloudColor} fillOpacity="0.1" />
            </motion.svg>
        </DecorWrap>
    );
}

// ─── 10. Aurora — Northern lights waves & stars ──────────────

function AuroraDecor({ opacity }: { opacity: number }) {
    return (
        <DecorWrap>
            <svg style={{ position: 'absolute', width: 0, height: 0 }}>
                <defs>
                    <radialGradient id="auroraStar" cx="50%" cy="50%" r="50%">
                        <stop offset="0%" stopColor="#ffffff" stopOpacity="1" />
                        <stop offset="100%" stopColor="#80cbc4" stopOpacity="0" />
                    </radialGradient>
                    <linearGradient id="auroraGrad1" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stopColor="#80cbc4" />
                        <stop offset="50%" stopColor="#b39ddb" />
                        <stop offset="100%" stopColor="#f48fb1" />
                    </linearGradient>
                    <linearGradient id="auroraGrad2" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stopColor="#4dd0e1" />
                        <stop offset="50%" stopColor="#80cbc4" />
                        <stop offset="100%" stopColor="#b39ddb" />
                    </linearGradient>
                    <linearGradient id="auroraGrad3" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stopColor="#b39ddb" />
                        <stop offset="50%" stopColor="#4dd0e1" />
                        <stop offset="100%" stopColor="#80cbc4" />
                    </linearGradient>
                </defs>
            </svg>

            {/* Aurora wave bands — highly blurred and layered for realistic atmospheric look */}
            <motion.svg
                className="absolute top-0 left-0 h-full w-[150%] mix-blend-screen"
                style={{ opacity: opacity * 0.7, filter: 'blur(20px)' }}
                viewBox="0 0 600 300"
                preserveAspectRatio="none"
                animate={{ x: ['0%', '-30%'], scaleY: [1, 1.1, 1] }}
                transition={{ duration: 25, repeat: Infinity, ease: 'easeInOut' }}
            >
                <path d="M0 150 Q120 50, 300 120 T600 150 V0 H0Z" fill="url(#auroraGrad1)" fillOpacity="0.22" />
                <path d="M0 180 Q150 80, 300 140 T600 180 V0 H0Z" fill="url(#auroraGrad2)" fillOpacity="0.15" />
                <path d="M0 220 Q180 120, 350 180 T600 220 V0 H0Z" fill="url(#auroraGrad3)" fillOpacity="0.1" />
            </motion.svg>

            {/* Second counter-flowing aurora band */}
            <motion.svg
                className="absolute top-0 right-0 h-[80%] w-[150%] mix-blend-screen"
                style={{ opacity: opacity * 0.6, filter: 'blur(15px)' }}
                viewBox="0 0 600 250"
                preserveAspectRatio="none"
                animate={{ x: ['-30%', '0%'], scaleY: [1, 1.2, 1] }}
                transition={{ duration: 18, repeat: Infinity, ease: 'easeInOut' }}
            >
                <path d="M0 120 Q90 60, 220 110 Q350 80, 600 100 V0 H0Z" fill="url(#auroraGrad2)" fillOpacity="0.18" />
            </motion.svg>

            {/* Sparkle dots — behaving like distant stars */}
            {[
                { x: '15%', y: '25%', s: 16, delay: 0 },
                { x: '40%', y: '15%', s: 12, delay: 1.2 },
                { x: '65%', y: '30%', s: 14, delay: 2 },
                { x: '30%', y: '45%', s: 10, delay: 1.5 },
                { x: '80%', y: '20%', s: 18, delay: 0.5 },
                { x: '50%', y: '50%', s: 8, delay: 2.5 },
                { x: '10%', y: '55%', s: 12, delay: 3 },
                { x: '90%', y: '40%', s: 14, delay: 0.8 },
                { x: '75%', y: '65%', s: 10, delay: 1.8 },
                { x: '25%', y: '75%', s: 16, delay: 2.2 },
            ].map((s, i) => (
                <motion.div
                    key={`astar-${i}`}
                    className="absolute rounded-full"
                    style={{ left: s.x, top: s.y, width: s.s, height: s.s, background: 'url(#auroraStar)', opacity: opacity * 0.8 }}
                    animate={{ opacity: [0, 0.8, 0], scale: [0.5, 1.2, 0.5] }}
                    transition={{ duration: 4 + (i % 3), repeat: Infinity, delay: s.delay, ease: 'easeInOut' }}
                />
            ))}

            {/* Mountain silhouettes at bottom with slight blur for depth */}
            <svg className="absolute bottom-0 left-0 h-32 w-full" style={{ opacity: opacity * 0.6, filter: 'blur(1px)' }} viewBox="0 0 400 80" preserveAspectRatio="none">
                <path d="M0 80 L0 55 L40 25 L80 45 L130 15 L200 50 L260 20 L330 60 L400 30 L400 80Z" fill="#0a1020" fillOpacity="0.8" />
                <path d="M0 80 L0 65 L60 35 L110 55 L180 25 L240 60 L300 30 L380 70 L400 50 L400 80Z" fill="#111827" fillOpacity="0.6" />
            </svg>
        </DecorWrap>
    );
}

// ─── 11. Cyberpunk ──────────────────────────────────────────────
function CyberpunkDecor({ opacity }: { opacity: number }) {
    return (
        <DecorWrap>
            <div className="absolute inset-0 bg-cover bg-center mix-blend-overlay" style={{ backgroundImage: 'radial-gradient(circle at center, transparent 40%, #09090e 100%)', opacity: opacity * 0.8 }}></div>
            {/* Grid overlay */}
            <div
                className="absolute inset-0"
                style={{
                    backgroundImage: 'linear-gradient(rgba(0, 255, 204, 0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(0, 255, 204, 0.05) 1px, transparent 1px)',
                    backgroundSize: '30px 30px',
                    opacity: opacity * 0.6
                }}
            ></div>

            {/* Glitching Matrix Lines */}
            {[...Array(8)].map((_, i) => (
                <motion.div
                    key={i}
                    className="absolute font-mono text-[10px] sm:text-xs text-[#00ffcc] font-bold overflow-hidden whitespace-nowrap"
                    style={{ left: `${3 + i * 13}%`, top: '-20%', opacity: opacity * (0.15 + (i % 2) * 0.15) }}
                    animate={{ y: ['-10%', '130%'] }}
                    transition={{ duration: 7 + (i % 5) * 2, repeat: Infinity, ease: 'linear', delay: i * 0.5 }}
                >
                    {Array.from({ length: 15 }).map((_, j) => (
                        <div key={j} className="my-1 break-all">
                            {String.fromCharCode(0x30A0 + Math.random() * 96)}
                        </div>
                    ))}
                </motion.div>
            ))}

            {/* Hexagon tech accents */}
            <motion.svg className="absolute right-[10%] bottom-[10%] w-24 h-24" style={{ opacity: opacity * 0.3 }} viewBox="0 0 100 100"
                animate={{ rotate: 360, opacity: [0.1, 0.4, 0.1] }} transition={{ duration: 15, repeat: Infinity, ease: 'linear' }}
            >
                <polygon points="50,5 90,25 90,75 50,95 10,75 10,25" fill="none" stroke="#ff00ff" strokeWidth="1" strokeDasharray="5, 5" />
                <polygon points="50,15 80,30 80,70 50,85 20,70 20,30" fill="none" stroke="#00ffcc" strokeWidth="0.5" />
                <circle cx="50" cy="50" r="4" fill="#ffff00" />
            </motion.svg>
        </DecorWrap>
    );
}

// ─── 12. Synthwave ──────────────────────────────────────────────
function SynthwaveDecor({ opacity }: { opacity: number }) {
    return (
        <DecorWrap>
            {/* Sun */}
            <motion.div
                className="absolute left-1/2 bottom-[30%] h-32 w-32 -translate-x-1/2 rounded-full md:h-48 md:w-48"
                style={{
                    background: 'linear-gradient(180deg, #ffeb3b 0%, #ff9800 45%, #e91e63 55%, #9c27b0 100%)',
                    boxShadow: '0 0 40px 10px rgba(233, 30, 99, 0.5)',
                    opacity: opacity * 0.8
                }}
            >
                {/* Sun horizontal stripes */}
                <div className="absolute bottom-2 h-[2px] w-full bg-[#3d0043] shadow-[0_4px_0_0_#3d0043,0_10px_0_0_#3d0043,0_18px_0_0_#3d0043]"></div>
            </motion.div>

            {/* Glowing Retro Grid */}
            <div className="absolute bottom-0 w-full h-[35%] overflow-hidden" style={{ opacity: opacity * 0.8 }}>
                <div className="absolute bottom-0 w-full h-full" style={{ background: 'linear-gradient(180deg, transparent 0%, #1f013d 100%)' }}></div>
                <motion.div
                    className="w-full h-full"
                    style={{
                        backgroundImage: `linear-gradient(rgba(255, 113, 206, 0.8) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 113, 206, 0.8) 1px, transparent 1px)`,
                        backgroundSize: '40px 15px',
                        transform: 'perspective(300px) rotateX(60deg) scale(2.5) translateY(-50px)',
                    }}
                    animate={{ backgroundPosition: ['0px 0px', '0px 15px'] }}
                    transition={{ duration: 1, repeat: Infinity, ease: 'linear' }}
                />
            </div>

            {/* Distant palm trees silhouettes */}
            <svg className="absolute bottom-[30%] left-[10%] h-16 w-12" style={{ opacity: opacity * 0.6 }} viewBox="0 0 100 100">
                <path d="M45 100 L50 20 L55 100 Z" fill="#1f013d" />
                <path d="M50 20 Q10 10 0 30 Q30 30 50 20" fill="#1f013d" />
                <path d="M50 20 Q90 10 100 30 Q70 30 50 20" fill="#1f013d" />
                <path d="M50 20 Q20 40 10 60 Q40 50 50 20" fill="#1f013d" />
                <path d="M50 20 Q80 40 90 60 Q60 50 50 20" fill="#1f013d" />
            </svg>

            <svg className="absolute bottom-[28%] right-[15%] h-24 w-16" style={{ opacity: opacity * 0.5, transform: 'scaleX(-1)' }} viewBox="0 0 100 100">
                <path d="M45 100 L50 20 L55 100 Z" fill="#1f013d" />
                <path d="M50 20 Q10 10 0 30 Q30 30 50 20" fill="#1f013d" />
                <path d="M50 20 Q90 10 100 30 Q70 30 50 20" fill="#1f013d" />
                <path d="M50 20 Q20 40 10 60 Q40 50 50 20" fill="#1f013d" />
                <path d="M50 20 Q80 40 90 60 Q60 50 50 20" fill="#1f013d" />
            </svg>
        </DecorWrap>
    );
}
