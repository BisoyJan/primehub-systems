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
    return (
        <DecorWrap>
            {/* Large coffee cup silhouette bottom-right */}
            <svg className="absolute bottom-2 right-4 h-36 w-28" style={{ opacity: opacity * 0.7 }} viewBox="0 0 80 100">
                <rect x="10" y="25" width="44" height="50" rx="6" fill={cup} fillOpacity="0.2" />
                <path d="M54 35 Q72 38, 70 52 Q68 64, 54 64" fill="none" stroke={cup} strokeWidth="3" strokeOpacity="0.18" />
                <rect x="4" y="75" width="56" height="6" rx="3" fill={cup} fillOpacity="0.15" />
            </svg>
            {/* Smaller cup bottom-left */}
            <svg className="absolute bottom-4 left-6 h-24 w-20" style={{ opacity: opacity * 0.45 }} viewBox="0 0 60 80">
                <rect x="10" y="30" width="30" height="35" rx="5" fill={cup} fillOpacity="0.15" />
                <rect x="6" y="65" width="38" height="4" rx="2" fill={cup} fillOpacity="0.12" />
            </svg>
            {/* Rising steam wisps — 3 streams */}
            <motion.svg className="absolute bottom-36 right-8 h-36 w-16" style={{ opacity }} viewBox="0 0 50 120" {...floatY(8, 3.5)}>
                <path d="M25 110 Q14 85, 25 65 Q36 45, 25 20" fill="none" stroke={steam} strokeWidth="2.5" strokeOpacity="0.35" strokeLinecap="round" />
            </motion.svg>
            <motion.svg className="absolute bottom-40 right-16 h-32 w-14" style={{ opacity: opacity * 0.8 }} viewBox="0 0 45 100" {...floatY(7, 4.5)}>
                <path d="M22 90 Q12 68, 22 50 Q32 32, 22 12" fill="none" stroke={steam} strokeWidth="2" strokeOpacity="0.3" strokeLinecap="round" />
            </motion.svg>
            <motion.svg className="absolute bottom-32 right-24 h-28 w-12" style={{ opacity: opacity * 0.6 }} viewBox="0 0 40 90" {...floatY(6, 5.5)}>
                <path d="M20 80 Q12 60, 20 45 Q28 30, 20 10" fill="none" stroke={steam} strokeWidth="1.8" strokeOpacity="0.25" strokeLinecap="round" />
            </motion.svg>
            {/* Scattered coffee beans */}
            {[
                { x: 'left-6', y: 'top-8', size: 'h-14 w-14', rot: -30, d: 0 },
                { x: 'left-20', y: 'top-16', size: 'h-10 w-10', rot: 20, d: 1.5 },
                { x: 'right-20', y: 'top-12', size: 'h-12 w-12', rot: -15, d: 2.5 },
                { x: 'left-12', y: 'bottom-32', size: 'h-10 w-10', rot: 40, d: 3.5 },
            ].map((b, i) => (
                <motion.svg key={i} className={`absolute ${b.x} ${b.y} ${b.size}`} style={{ opacity: opacity * 0.5 }} viewBox="0 0 40 40" {...floatY(4, 6 + i)}>
                    <ellipse cx="20" cy="20" rx="10" ry="14" fill={cup} fillOpacity="0.22" transform={`rotate(${b.rot} 20 20)`} />
                    <line x1="14" y1="12" x2="26" y2="28" stroke={cup} strokeWidth="1.2" strokeOpacity="0.2" strokeLinecap="round" />
                </motion.svg>
            ))}
        </DecorWrap>
    );
}

// ─── 2. Rainy Window — Raindrops, streaks & ripples ──────────

function RainyWindowDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const drop = isDark ? '#7b9bb5' : '#5a7f99';
    return (
        <DecorWrap>
            {/* Falling rain streaks — more, varied */}
            {[
                { x: '8%', delay: 0, h: 50, w: 2.5 },
                { x: '18%', delay: 0.8, h: 38, w: 2 },
                { x: '28%', delay: 1.6, h: 55, w: 3 },
                { x: '38%', delay: 0.3, h: 42, w: 2 },
                { x: '48%', delay: 1.2, h: 48, w: 2.5 },
                { x: '58%', delay: 2.0, h: 36, w: 2 },
                { x: '68%', delay: 0.6, h: 52, w: 3 },
                { x: '78%', delay: 1.8, h: 40, w: 2 },
                { x: '88%', delay: 0.4, h: 46, w: 2.5 },
                { x: '95%', delay: 1.4, h: 34, w: 2 },
            ].map((r, i) => (
                <motion.div
                    key={i}
                    className="absolute top-0"
                    style={{ left: r.x, opacity: opacity * 0.6 }}
                    animate={{ y: ['0%', '120%'], opacity: [0.5, 0] }}
                    transition={{ duration: 2 + (i % 3) * 0.3, repeat: Infinity, delay: r.delay, ease: 'linear' }}
                >
                    <svg width={r.w * 2} height={r.h} viewBox={`0 0 ${r.w * 2} ${r.h}`}>
                        <line x1={r.w} y1="0" x2={r.w} y2={r.h} stroke={drop} strokeWidth={r.w} strokeLinecap="round" strokeOpacity="0.45" />
                    </svg>
                </motion.div>
            ))}
            {/* Ripple circles at bottom — multiple */}
            {[
                { x: '15%', delay: 0, size: 80 },
                { x: '55%', delay: 1.5, size: 60 },
                { x: '80%', delay: 3, size: 70 },
            ].map((rp, i) => (
                <motion.svg
                    key={i}
                    className="absolute bottom-4"
                    style={{ left: rp.x, opacity: opacity * 0.35, width: rp.size, height: rp.size / 2 }}
                    viewBox="0 0 80 40"
                    animate={{ scale: [0.7, 1.3, 0.7], opacity: [0.35, 0.08, 0.35] }}
                    transition={{ duration: 3.5, repeat: Infinity, delay: rp.delay }}
                >
                    <ellipse cx="40" cy="20" rx="35" ry="12" fill="none" stroke={drop} strokeWidth="1.5" />
                    <ellipse cx="40" cy="20" rx="20" ry="7" fill="none" stroke={drop} strokeWidth="1" />
                </motion.svg>
            ))}
            {/* Water droplets on glass */}
            {[
                { x: '12%', y: '25%', r: 6 },
                { x: '42%', y: '15%', r: 4 },
                { x: '72%', y: '35%', r: 5 },
                { x: '30%', y: '55%', r: 4 },
                { x: '85%', y: '20%', r: 3 },
            ].map((d, i) => (
                <svg key={i} className="absolute" style={{ left: d.x, top: d.y, opacity: opacity * 0.3 }} width={d.r * 4} height={d.r * 4} viewBox="0 0 20 20">
                    <circle cx="10" cy="10" r={d.r} fill={drop} fillOpacity="0.15" />
                    <circle cx="8" cy="8" r={d.r * 0.3} fill="white" fillOpacity="0.2" />
                </svg>
            ))}
        </DecorWrap>
    );
}

// ─── 3. Sakura — Floating cherry blossom petals & branches ───

function SakuraDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const petal = isDark ? '#f48fb1' : '#e06090';
    const branchColor = isDark ? '#8b5e6b' : '#6b4050';
    const petals = [
        { top: '5%', left: '8%', size: 28, delay: 0, dur: 8 },
        { top: '12%', left: '75%', size: 22, delay: 1.2, dur: 9 },
        { top: '30%', left: '3%', size: 20, delay: 2.5, dur: 7 },
        { top: '45%', left: '88%', size: 26, delay: 0.8, dur: 10 },
        { top: '25%', left: '55%', size: 18, delay: 3.5, dur: 8 },
        { top: '55%', left: '20%', size: 24, delay: 1.8, dur: 9 },
        { top: '60%', left: '70%', size: 16, delay: 4, dur: 7 },
        { top: '8%', left: '40%', size: 20, delay: 2, dur: 11 },
        { top: '70%', left: '45%', size: 22, delay: 0.5, dur: 8 },
    ];
    return (
        <DecorWrap>
            {petals.map((p, i) => (
                <motion.svg
                    key={i}
                    className="absolute"
                    style={{ top: p.top, left: p.left, opacity: opacity * 0.75 }}
                    width={p.size}
                    height={p.size}
                    viewBox="0 0 20 20"
                    animate={{
                        y: [0, 30, 60],
                        x: [0, 12, -6],
                        rotate: [0, 60, 120],
                        opacity: [0.7, 0.45, 0.1],
                    }}
                    transition={{ duration: p.dur, repeat: Infinity, delay: p.delay, ease: 'easeInOut' }}
                >
                    <path d="M10 2 Q16 8, 10 18 Q4 8, 10 2Z" fill={petal} fillOpacity="0.55" />
                </motion.svg>
            ))}
            {/* Branch top-right with blossoms */}
            <svg className="absolute -top-2 -right-2 h-32 w-40" style={{ opacity: opacity * 0.5 }} viewBox="0 0 120 90">
                <path d="M5 80 Q30 70, 55 50 Q75 35, 100 18 Q110 12, 118 5" fill="none" stroke={branchColor} strokeWidth="2.5" strokeLinecap="round" strokeOpacity="0.45" />
                <path d="M55 50 Q60 40, 70 38" fill="none" stroke={branchColor} strokeWidth="1.5" strokeLinecap="round" strokeOpacity="0.35" />
                <circle cx="60" cy="45" r="6" fill={petal} fillOpacity="0.35" />
                <circle cx="78" cy="32" r="5" fill={petal} fillOpacity="0.3" />
                <circle cx="95" cy="20" r="5.5" fill={petal} fillOpacity="0.32" />
                <circle cx="110" cy="10" r="4.5" fill={petal} fillOpacity="0.28" />
                <circle cx="70" cy="38" r="4" fill={petal} fillOpacity="0.25" />
            </svg>
            {/* Branch bottom-left */}
            <svg className="absolute -bottom-2 -left-4 h-28 w-36" style={{ opacity: opacity * 0.4 }} viewBox="0 0 110 80">
                <path d="M110 10 Q80 20, 55 40 Q35 55, 10 72" fill="none" stroke={branchColor} strokeWidth="2" strokeLinecap="round" strokeOpacity="0.35" />
                <circle cx="55" cy="40" r="5" fill={petal} fillOpacity="0.28" />
                <circle cx="35" cy="55" r="4.5" fill={petal} fillOpacity="0.25" />
                <circle cx="18" cy="66" r="4" fill={petal} fillOpacity="0.22" />
            </svg>
        </DecorWrap>
    );
}

// ─── 4. Ocean Tide — Waves, bubbles & sea life ───────────────

function OceanTideDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const wave = isDark ? '#4dd0e1' : '#00acc1';
    const foam = isDark ? '#80deea' : '#4dd0e1';
    return (
        <DecorWrap>
            {/* Layered waves at bottom */}
            <motion.svg
                className="absolute bottom-0 left-0 h-32 w-full"
                style={{ opacity: opacity * 0.5 }}
                viewBox="0 0 400 110"
                preserveAspectRatio="none"
                {...floatX(6, 5)}
            >
                <path d="M0 50 Q50 25, 100 50 T200 50 T300 50 T400 50 V110H0Z" fill={wave} fillOpacity="0.12" />
                <path d="M0 65 Q60 45, 120 65 T240 65 T360 65 L400 65 V110H0Z" fill={wave} fillOpacity="0.09" />
                <path d="M0 80 Q70 65, 140 80 T280 80 T400 80 V110H0Z" fill={wave} fillOpacity="0.06" />
            </motion.svg>
            {/* Foam line */}
            <motion.svg
                className="absolute bottom-28 left-0 h-6 w-full"
                style={{ opacity: opacity * 0.3 }}
                viewBox="0 0 400 20"
                preserveAspectRatio="none"
                {...floatX(4, 7)}
            >
                <path d="M0 10 Q20 5, 40 10 T80 10 T120 10 T160 10 T200 10 T240 10 T280 10 T320 10 T360 10 T400 10" fill="none" stroke={foam} strokeWidth="1.5" strokeOpacity="0.25" />
            </motion.svg>
            {/* Rising bubbles — more, larger */}
            {[
                { cx: '15%', cy: '65%', r: 10, delay: 0 },
                { cx: '30%', cy: '75%', r: 7, delay: 1.2 },
                { cx: '50%', cy: '70%', r: 12, delay: 0.5 },
                { cx: '70%', cy: '80%', r: 8, delay: 2 },
                { cx: '85%', cy: '72%', r: 9, delay: 1.5 },
                { cx: '40%', cy: '60%', r: 6, delay: 3 },
            ].map((b, i) => (
                <motion.div
                    key={i}
                    className="absolute"
                    style={{ left: b.cx, top: b.cy, opacity: opacity * 0.5 }}
                    animate={{ y: [-10, -60], opacity: [0.5, 0] }}
                    transition={{ duration: 4 + i * 0.5, repeat: Infinity, delay: b.delay }}
                >
                    <svg width={b.r * 3} height={b.r * 3} viewBox={`0 0 ${b.r * 3} ${b.r * 3}`}>
                        <circle cx={b.r * 1.5} cy={b.r * 1.5} r={b.r} fill="none" stroke={wave} strokeWidth="1.2" strokeOpacity="0.5" />
                        <circle cx={b.r * 1.2} cy={b.r * 1.2} r={b.r * 0.25} fill="white" fillOpacity="0.2" />
                    </svg>
                </motion.div>
            ))}
            {/* Seagulls — two, larger */}
            {[
                { x: 'right-10', y: 'top-6', w: 'w-20 h-10', d: 0 },
                { x: 'right-28', y: 'top-14', w: 'w-14 h-8', d: 1.5 },
            ].map((g, i) => (
                <motion.svg key={i} className={`absolute ${g.x} ${g.y} ${g.w}`} style={{ opacity: opacity * 0.35 }} viewBox="0 0 50 20" {...floatY(4, 5 + i)}>
                    <path d="M0 15 Q12 3, 25 10 Q38 3, 50 15" fill="none" stroke={isDark ? '#b0bec5' : '#546e7a'} strokeWidth="2" strokeLinecap="round" />
                </motion.svg>
            ))}
        </DecorWrap>
    );
}

// ─── 5. Neon City — Buildings, signs & glowing lights ────────

function NeonCityDecor({ opacity }: { opacity: number }) {
    return (
        <DecorWrap>
            {/* City skyline silhouette — taller */}
            <svg className="absolute bottom-0 left-0 h-40 w-full" style={{ opacity: opacity * 0.6 }} viewBox="0 0 400 140" preserveAspectRatio="none">
                <path d="M0 140 V90 H25 V60 H45 V90 H70 V40 H95 V90 H115 V65 H140 V90 H170 V30 H195 V90 H220 V70 H250 V90 H275 V45 H300 V90 H330 V60 H355 V90 H385 V75 H400 V140Z" fill="#0a0a1e" fillOpacity="0.65" />
                {/* Lit windows — more spread */}
                {[
                    { x: 32, y: 70, c: '#bb86fc' }, { x: 32, y: 80, c: '#64ffda' },
                    { x: 78, y: 50, c: '#ff6e7f' }, { x: 78, y: 60, c: '#bb86fc' }, { x: 78, y: 75, c: '#ffab40' },
                    { x: 122, y: 72, c: '#64ffda' }, { x: 122, y: 82, c: '#ff6e7f' },
                    { x: 178, y: 40, c: '#bb86fc' }, { x: 178, y: 55, c: '#64ffda' }, { x: 178, y: 70, c: '#ffab40' },
                    { x: 232, y: 78, c: '#ff6e7f' },
                    { x: 283, y: 55, c: '#bb86fc' }, { x: 283, y: 68, c: '#64ffda' },
                    { x: 340, y: 68, c: '#ffab40' }, { x: 340, y: 78, c: '#bb86fc' },
                    { x: 390, y: 82, c: '#64ffda' },
                ].map((w, i) => (
                    <rect key={i} x={w.x} y={w.y} width="6" height="5" rx="0.8" fill={w.c} fillOpacity={0.35 + (i % 3) * 0.1} />
                ))}
            </svg>
            {/* Neon glow orbs — larger, more */}
            {[
                { x: 'top-4 right-6', s: 32, c: '#bb86fc', delay: 0, dur: 3 },
                { x: 'top-1/4 left-4', s: 24, c: '#64ffda', delay: 1.2, dur: 4 },
                { x: 'top-10 left-1/3', s: 20, c: '#ff6e7f', delay: 2, dur: 3.5 },
                { x: 'top-1/3 right-10', s: 18, c: '#ffab40', delay: 0.8, dur: 4.5 },
            ].map((g, i) => (
                <motion.div
                    key={i}
                    className={`absolute ${g.x}`}
                    style={{ opacity: opacity * 0.6 }}
                    animate={{ scale: [1, 1.4, 1], opacity: [0.4, 0.7, 0.4] }}
                    transition={{ duration: g.dur, repeat: Infinity, ease: 'easeInOut', delay: g.delay }}
                >
                    <svg width={g.s} height={g.s} viewBox="0 0 40 40">
                        <circle cx="20" cy="20" r="6" fill={g.c} />
                        <circle cx="20" cy="20" r="14" fill={g.c} fillOpacity="0.18" />
                        <circle cx="20" cy="20" r="20" fill={g.c} fillOpacity="0.06" />
                    </svg>
                </motion.div>
            ))}
            {/* Neon sign line */}
            <motion.svg
                className="absolute top-8 left-8 h-4 w-28"
                style={{ opacity: opacity * 0.35 }}
                viewBox="0 0 100 12"
                animate={{ opacity: [0.3, 0.6, 0.3] }}
                transition={{ duration: 2, repeat: Infinity, ease: 'easeInOut' }}
            >
                <line x1="5" y1="6" x2="95" y2="6" stroke="#ff6e7f" strokeWidth="2" strokeLinecap="round" />
            </motion.svg>
        </DecorWrap>
    );
}

// ─── 6. Golden Hour — Sun, rays & floating particles ─────────

function GoldenHourDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const ray = isDark ? '#ffb74d' : '#f09030';
    return (
        <DecorWrap>
            {/* Large sun glow top-right */}
            <svg className="absolute -top-16 -right-16 h-56 w-56" style={{ opacity: opacity * 0.55 }} viewBox="0 0 160 160">
                <circle cx="80" cy="80" r="35" fill={ray} fillOpacity="0.2" />
                <circle cx="80" cy="80" r="55" fill={ray} fillOpacity="0.1" />
                <circle cx="80" cy="80" r="75" fill={ray} fillOpacity="0.04" />
            </svg>
            {/* Diagonal light rays — more, wider */}
            <svg className="absolute top-0 right-0 h-full w-full" style={{ opacity: opacity * 0.2 }} viewBox="0 0 400 600" preserveAspectRatio="none">
                <line x1="400" y1="0" x2="100" y2="600" stroke={ray} strokeWidth="60" strokeOpacity="0.08" />
                <line x1="350" y1="0" x2="50" y2="600" stroke={ray} strokeWidth="35" strokeOpacity="0.06" />
                <line x1="300" y1="0" x2="0" y2="600" stroke={ray} strokeWidth="20" strokeOpacity="0.04" />
            </svg>
            {/* Floating dust/pollen particles — many, larger */}
            {[
                { x: '20%', y: '25%', s: 8, delay: 0 },
                { x: '55%', y: '45%', s: 10, delay: 1.2 },
                { x: '80%', y: '20%', s: 7, delay: 2.5 },
                { x: '12%', y: '60%', s: 9, delay: 1.8 },
                { x: '65%', y: '65%', s: 6, delay: 0.5 },
                { x: '40%', y: '30%', s: 8, delay: 3 },
                { x: '88%', y: '50%', s: 7, delay: 2 },
                { x: '35%', y: '75%', s: 6, delay: 0.8 },
            ].map((p, i) => (
                <motion.div
                    key={i}
                    className="absolute"
                    style={{ left: p.x, top: p.y, opacity: opacity * 0.6 }}
                    animate={{ y: [-8, 8, -8], x: [0, 5, 0], opacity: [0.3, 0.7, 0.3] }}
                    transition={{ duration: 4 + i * 0.5, repeat: Infinity, delay: p.delay, ease: 'easeInOut' }}
                >
                    <svg width={p.s} height={p.s} viewBox="0 0 10 10">
                        <circle cx="5" cy="5" r="3.5" fill={ray} fillOpacity="0.5" />
                        <circle cx="5" cy="5" r="5" fill={ray} fillOpacity="0.15" />
                    </svg>
                </motion.div>
            ))}
            {/* Warm haze at bottom */}
            <svg className="absolute bottom-0 left-0 h-20 w-full" style={{ opacity: opacity * 0.25 }} viewBox="0 0 400 60" preserveAspectRatio="none">
                <rect x="0" y="0" width="400" height="60" fill={ray} fillOpacity="0.08" />
            </svg>
        </DecorWrap>
    );
}

// ─── 7. Deep Forest — Trees, ferns & fireflies ──────────────

function DeepForestDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const trunk = isDark ? '#4a6b3a' : '#3a5a2a';
    const leaf = isDark ? '#66bb6a' : '#43a047';
    const firefly = isDark ? '#c5e84c' : '#aed581';
    return (
        <DecorWrap>
            {/* Tree silhouettes at bottom edges — larger */}
            <svg className="absolute bottom-0 -left-4 h-52 w-28" style={{ opacity: opacity * 0.55 }} viewBox="0 0 80 160">
                <rect x="34" y="100" width="12" height="55" fill={trunk} fillOpacity="0.28" />
                <polygon points="40,10 8,90 72,90" fill={leaf} fillOpacity="0.18" />
                <polygon points="40,30 14,95 66,95" fill={leaf} fillOpacity="0.14" />
            </svg>
            <svg className="absolute bottom-0 -right-4 h-44 w-24" style={{ opacity: opacity * 0.48 }} viewBox="0 0 70 140">
                <rect x="28" y="85" width="10" height="50" fill={trunk} fillOpacity="0.24" />
                <polygon points="33,8 6,78 60,78" fill={leaf} fillOpacity="0.15" />
            </svg>
            {/* Smaller background trees */}
            <svg className="absolute bottom-0 left-16 h-36 w-18" style={{ opacity: opacity * 0.3 }} viewBox="0 0 50 110">
                <rect x="20" y="70" width="8" height="35" fill={trunk} fillOpacity="0.2" />
                <polygon points="24,12 4,65 44,65" fill={leaf} fillOpacity="0.1" />
            </svg>
            <svg className="absolute bottom-0 right-16 h-32 w-16" style={{ opacity: opacity * 0.25 }} viewBox="0 0 45 100">
                <rect x="18" y="60" width="7" height="35" fill={trunk} fillOpacity="0.18" />
                <polygon points="21,10 3,55 39,55" fill={leaf} fillOpacity="0.08" />
            </svg>
            {/* Fern fronds at bottom corners */}
            <svg className="absolute bottom-2 left-2 h-20 w-24" style={{ opacity: opacity * 0.4 }} viewBox="0 0 80 60">
                <path d="M10 55 Q20 35, 40 25 Q50 20, 65 10" fill="none" stroke={leaf} strokeWidth="2" strokeOpacity="0.25" strokeLinecap="round" />
                <path d="M25 36 Q30 30, 38 28" fill="none" stroke={leaf} strokeWidth="1.5" strokeOpacity="0.2" strokeLinecap="round" />
                <path d="M35 28 Q42 22, 50 18" fill="none" stroke={leaf} strokeWidth="1.5" strokeOpacity="0.18" strokeLinecap="round" />
            </svg>
            {/* Fireflies floating — more, larger glow */}
            {[
                { x: '18%', y: '35%', delay: 0, s: 12 },
                { x: '65%', y: '25%', delay: 1.2, s: 10 },
                { x: '45%', y: '50%', delay: 2.5, s: 14 },
                { x: '82%', y: '45%', delay: 1, s: 10 },
                { x: '30%', y: '65%', delay: 3, s: 12 },
                { x: '75%', y: '60%', delay: 0.5, s: 10 },
                { x: '55%', y: '35%', delay: 2, s: 8 },
            ].map((f, i) => (
                <motion.div
                    key={i}
                    className="absolute"
                    style={{ left: f.x, top: f.y }}
                    animate={{
                        y: [0, -12, 0, 8, 0],
                        x: [0, 8, -5, 4, 0],
                        opacity: [0.1, 0.6, 0.15, 0.7, 0.1],
                    }}
                    transition={{ duration: 4 + i * 0.6, repeat: Infinity, delay: f.delay, ease: 'easeInOut' }}
                >
                    <svg width={f.s} height={f.s} viewBox="0 0 14 14">
                        <circle cx="7" cy="7" r="3" fill={firefly} />
                        <circle cx="7" cy="7" r="6" fill={firefly} fillOpacity="0.25" />
                    </svg>
                </motion.div>
            ))}
        </DecorWrap>
    );
}

// ─── 8. Snowfall — Drifting snowflakes & frost ───────────────

function SnowfallDecor({ opacity, isDark }: { opacity: number; isDark: boolean }) {
    const snow = isDark ? '#e0e4e8' : '#90a4ae';
    const flakes = [
        { x: '5%', size: 10, delay: 0, dur: 7 },
        { x: '14%', size: 6, delay: 1.5, dur: 9 },
        { x: '24%', size: 12, delay: 0.8, dur: 6 },
        { x: '34%', size: 8, delay: 2.5, dur: 8 },
        { x: '44%', size: 10, delay: 0.3, dur: 10 },
        { x: '54%', size: 7, delay: 3, dur: 7 },
        { x: '64%', size: 14, delay: 1, dur: 8 },
        { x: '74%', size: 8, delay: 2, dur: 9 },
        { x: '84%', size: 10, delay: 0.5, dur: 7 },
        { x: '92%', size: 6, delay: 1.8, dur: 10 },
    ];
    return (
        <DecorWrap>
            {flakes.map((f, i) => (
                <motion.div
                    key={i}
                    className="absolute -top-2"
                    style={{ left: f.x, opacity: opacity * 0.7 }}
                    animate={{
                        y: ['0vh', '100vh'],
                        x: [0, f.size * 3, -f.size * 2, f.size * 2, 0],
                        rotate: [0, 360],
                    }}
                    transition={{
                        duration: f.dur,
                        repeat: Infinity,
                        delay: f.delay,
                        ease: 'linear',
                    }}
                >
                    <svg width={f.size * 2} height={f.size * 2} viewBox="0 0 20 20">
                        <circle cx="10" cy="10" r="4" fill={snow} fillOpacity="0.5" />
                        {/* Starburst arms */}
                        <line x1="10" y1="2" x2="10" y2="18" stroke={snow} strokeWidth="0.8" strokeOpacity="0.35" />
                        <line x1="2" y1="10" x2="18" y2="10" stroke={snow} strokeWidth="0.8" strokeOpacity="0.35" />
                        <line x1="4" y1="4" x2="16" y2="16" stroke={snow} strokeWidth="0.6" strokeOpacity="0.25" />
                        <line x1="16" y1="4" x2="4" y2="16" stroke={snow} strokeWidth="0.6" strokeOpacity="0.25" />
                    </svg>
                </motion.div>
            ))}
            {/* Frost corners */}
            <svg className="absolute top-0 left-0 h-24 w-24" style={{ opacity: opacity * 0.3 }} viewBox="0 0 80 80">
                <path d="M0 0 Q30 5, 20 30 Q15 45, 0 50" fill="none" stroke={snow} strokeWidth="1.5" strokeOpacity="0.25" />
                <path d="M0 0 Q8 20, 5 40" fill="none" stroke={snow} strokeWidth="1" strokeOpacity="0.18" />
            </svg>
            <svg className="absolute top-0 right-0 h-24 w-24" style={{ opacity: opacity * 0.25 }} viewBox="0 0 80 80">
                <path d="M80 0 Q50 5, 60 30 Q65 45, 80 50" fill="none" stroke={snow} strokeWidth="1.5" strokeOpacity="0.25" />
            </svg>
            {/* Ground snow mound */}
            <svg className="absolute bottom-0 left-0 h-12 w-full" style={{ opacity: opacity * 0.35 }} viewBox="0 0 400 40" preserveAspectRatio="none">
                <path d="M0 40 L0 30 Q50 18, 100 25 Q150 15, 200 22 Q250 12, 300 20 Q350 16, 400 25 L400 40Z" fill={snow} fillOpacity="0.12" />
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
            {/* Crescent moon top-right — larger with glow halo */}
            <motion.svg className="absolute top-2 right-4 h-28 w-28" style={{ opacity: opacity * 1.2 }} viewBox="0 0 70 70" {...floatY(4, 8)}>
                <circle cx="35" cy="35" r="18" fill={moonColor} fillOpacity="0.3" />
                <circle cx="40" cy="28" r="14" fill={isDark ? '#1a1040' : '#ede7f6'} fillOpacity="0.88" />
                {/* Multi-layer glow */}
                <circle cx="35" cy="35" r="28" fill={moonColor} fillOpacity="0.06" />
                <circle cx="35" cy="35" r="35" fill={moonColor} fillOpacity="0.03" />
            </motion.svg>
            {/* Twinkling stars — more, varied sizes */}
            {[
                { x: '8%', y: '8%', s: 14, delay: 0 },
                { x: '25%', y: '15%', s: 10, delay: 1 },
                { x: '60%', y: '5%', s: 12, delay: 2 },
                { x: '78%', y: '20%', s: 8, delay: 1.5 },
                { x: '12%', y: '65%', s: 10, delay: 3 },
                { x: '90%', y: '55%', s: 12, delay: 0.5 },
                { x: '40%', y: '10%', s: 8, delay: 2.5 },
                { x: '55%', y: '70%', s: 10, delay: 1.2 },
                { x: '85%', y: '40%', s: 8, delay: 3.5 },
                { x: '5%', y: '40%', s: 12, delay: 0.8 },
            ].map((st, i) => (
                <motion.div
                    key={i}
                    className="absolute"
                    style={{ left: st.x, top: st.y }}
                    animate={{ opacity: [0.15, 0.6, 0.15], scale: [0.7, 1.2, 0.7] }}
                    transition={{ duration: 2.5 + (i % 4), repeat: Infinity, delay: st.delay, ease: 'easeInOut' }}
                >
                    <svg width={st.s} height={st.s} viewBox="0 0 16 16">
                        <path d="M8 0 L9.5 6 L16 8 L9.5 10 L8 16 L6.5 10 L0 8 L6.5 6Z" fill={starColor} fillOpacity="0.55" />
                    </svg>
                </motion.div>
            ))}
            {/* Wispy cloud bottom-left */}
            <motion.svg className="absolute bottom-16 left-2 h-12 w-32" style={{ opacity: opacity * 0.3 }} viewBox="0 0 120 40" {...floatX(6, 12)}>
                <ellipse cx="40" cy="25" rx="35" ry="12" fill={cloudColor} fillOpacity="0.2" />
                <ellipse cx="65" cy="20" rx="25" ry="10" fill={cloudColor} fillOpacity="0.18" />
                <ellipse cx="25" cy="22" rx="20" ry="8" fill={cloudColor} fillOpacity="0.15" />
            </motion.svg>
            {/* Another cloud top-left */}
            <motion.svg className="absolute top-20 left-4 h-10 w-28" style={{ opacity: opacity * 0.22 }} viewBox="0 0 100 30" {...floatX(4, 15)}>
                <ellipse cx="35" cy="18" rx="28" ry="10" fill={cloudColor} fillOpacity="0.18" />
                <ellipse cx="60" cy="14" rx="20" ry="8" fill={cloudColor} fillOpacity="0.14" />
            </motion.svg>
        </DecorWrap>
    );
}

// ─── 10. Aurora — Northern lights waves & stars ──────────────

function AuroraDecor({ opacity }: { opacity: number }) {
    return (
        <DecorWrap>
            {/* Aurora wave bands — taller, multiple layers */}
            <motion.svg
                className="absolute top-0 left-0 h-64 w-full"
                style={{ opacity: opacity * 0.5 }}
                viewBox="0 0 400 220"
                preserveAspectRatio="none"
                animate={{ y: [0, -10, 0] }}
                transition={{ duration: 8, repeat: Infinity, ease: 'easeInOut' }}
            >
                <path d="M0 100 Q80 50, 200 90 T400 100 V0 H0Z" fill="url(#auroraGrad1)" fillOpacity="0.18" />
                <path d="M0 130 Q100 70, 200 110 T400 130 V0 H0Z" fill="url(#auroraGrad2)" fillOpacity="0.12" />
                <path d="M0 160 Q120 100, 250 140 T400 160 V0 H0Z" fill="url(#auroraGrad3)" fillOpacity="0.08" />
                <defs>
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
            </motion.svg>
            {/* Second aurora band with different timing */}
            <motion.svg
                className="absolute top-0 left-0 h-48 w-full"
                style={{ opacity: opacity * 0.3 }}
                viewBox="0 0 400 180"
                preserveAspectRatio="none"
                animate={{ y: [0, 8, 0], x: [-5, 5, -5] }}
                transition={{ duration: 10, repeat: Infinity, ease: 'easeInOut' }}
            >
                <path d="M0 90 Q60 40, 150 80 Q250 50, 400 70 V0 H0Z" fill="url(#auroraGrad2)" fillOpacity="0.1" />
            </motion.svg>
            {/* Sparkle dots — more, larger */}
            {[
                { x: '15%', y: '25%', s: 14, delay: 0 },
                { x: '40%', y: '15%', s: 12, delay: 1.2 },
                { x: '65%', y: '30%', s: 10, delay: 2 },
                { x: '30%', y: '45%', s: 12, delay: 1.5 },
                { x: '80%', y: '20%', s: 10, delay: 0.5 },
                { x: '50%', y: '50%', s: 8, delay: 2.5 },
                { x: '10%', y: '55%', s: 10, delay: 3 },
                { x: '90%', y: '40%', s: 12, delay: 0.8 },
            ].map((s, i) => (
                <motion.div
                    key={i}
                    className="absolute"
                    style={{ left: s.x, top: s.y }}
                    animate={{ opacity: [0.1, 0.6, 0.1], scale: [0.5, 1.1, 0.5] }}
                    transition={{ duration: 2.5 + (i % 3), repeat: Infinity, delay: s.delay, ease: 'easeInOut' }}
                >
                    <svg width={s.s} height={s.s} viewBox="0 0 16 16">
                        <path d="M8 0 L9.5 6 L16 8 L9.5 10 L8 16 L6.5 10 L0 8 L6.5 6Z" fill="#80cbc4" fillOpacity="0.55" />
                    </svg>
                </motion.div>
            ))}
            {/* Mountain silhouettes at bottom */}
            <svg className="absolute bottom-0 left-0 h-20 w-full" style={{ opacity: opacity * 0.4 }} viewBox="0 0 400 60" preserveAspectRatio="none">
                <path d="M0 60 L0 40 L60 15 L100 35 L160 10 L220 30 L280 8 L340 28 L400 12 L400 60Z" fill="#0a1020" fillOpacity="0.5" />
            </svg>
        </DecorWrap>
    );
}
