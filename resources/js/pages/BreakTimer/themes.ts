import { useState, useMemo, useCallback, useEffect } from 'react';

// ─── Types ───────────────────────────────────────────────────

interface ButtonColors {
    from: string;
    to: string;
}

export interface TimerTheme {
    id: string;
    name: string;
    icon: string;
    quote: string;
    /** True if the theme is always dark regardless of system mode */
    alwaysDark: boolean;
    // Page background [light, dark]
    bgLight: string;
    bgDark: string;
    // Glass card tint
    glassLight: string;
    glassDark: string;
    // Ring stroke colors
    ringActive: string;
    ringPaused: string;
    ringOverage: string;
    ringDanger: string;
    ringTrack: string;
    ringTrackDark: string;
    // Effects
    ringGlow: boolean;
    ringAnimated: boolean;
    // Status label text [light, dark]
    statusActive: [string, string];
    statusPaused: [string, string];
    statusOverage: [string, string];
    statusDanger: [string, string];
    // Buttons [from, to] gradient
    btnBreak: ButtonColors;
    btnLunch: ButtonColors;
    btnCombined: ButtonColors;
    btnCombinedBreak: ButtonColors;
    btnPause: ButtonColors;
    btnResume: ButtonColors;
    btnEnd: ButtonColors;
}

// ─── Theme Definitions ───────────────────────────────────────

export const THEMES: TimerTheme[] = [
    // 0. Default (no background, no decorations)
    {
        id: 'default',
        name: 'Default',
        icon: '⚡',
        quote: 'Stay focused, take a break',
        alwaysDark: false,
        bgLight: 'none',
        bgDark: 'none',
        glassLight: 'rgba(255,255,255,0.35)',
        glassDark: 'rgba(40,40,40,0.45)',
        ringActive: '#6366f1',
        ringPaused: '#94a3b8',
        ringOverage: '#f59e0b',
        ringDanger: '#ef4444',
        ringTrack: '#e2e8f0',
        ringTrackDark: '#334155',
        ringGlow: false,
        ringAnimated: false,
        statusActive: ['#4f46e5', '#818cf8'],
        statusPaused: ['#64748b', '#94a3b8'],
        statusOverage: ['#d97706', '#f59e0b'],
        statusDanger: ['#dc2626', '#ef4444'],
        btnBreak: { from: '#6366f1', to: '#4f46e5' },
        btnLunch: { from: '#8b5cf6', to: '#7c3aed' },
        btnCombined: { from: '#6366f1', to: '#7c3aed' },
        btnCombinedBreak: { from: '#0ea5e9', to: '#0284c7' },
        btnPause: { from: '#94a3b8', to: '#64748b' },
        btnResume: { from: '#4f46e5', to: '#6366f1' },
        btnEnd: { from: '#64748b', to: '#475569' },
    },
    // 1. Cozy Cafe
    {
        id: 'cozy-cafe',
        name: 'Cozy Café',
        icon: '☕',
        quote: 'Take a moment, savor the pause',
        alwaysDark: false,
        bgLight: 'linear-gradient(160deg, #f5ebe0 0%, #e8d5c4 30%, #f2e2d0 60%, #faf6f1 100%)',
        bgDark: 'linear-gradient(160deg, #2c1a0e 0%, #3d2417 35%, #2a1810 65%, #1a0e07 100%)',
        glassLight: 'rgba(255,255,255,0.35)',
        glassDark: 'rgba(60,35,18,0.45)',
        ringActive: '#d4a574',
        ringPaused: '#c2a888',
        ringOverage: '#e8956a',
        ringDanger: '#c0392b',
        ringTrack: '#ede0d4',
        ringTrackDark: '#3d2417',
        ringGlow: false,
        ringAnimated: false,
        statusActive: ['#a0785a', '#d4a574'],
        statusPaused: ['#9c8b74', '#c2a888'],
        statusOverage: ['#c96b3c', '#e8956a'],
        statusDanger: ['#a33025', '#c0392b'],
        btnBreak: { from: '#c49a6c', to: '#a0785a' },
        btnLunch: { from: '#b07156', to: '#8b5e3c' },
        btnCombined: { from: '#9c7e5a', to: '#7a5e42' },
        btnCombinedBreak: { from: '#b08850', to: '#8a6a3a' },
        btnPause: { from: '#c2a888', to: '#a89070' },
        btnResume: { from: '#a0785a', to: '#c49a6c' },
        btnEnd: { from: '#8b6e50', to: '#a0785a' },
    },
    // 2. Rainy Window
    {
        id: 'rainy-window',
        name: 'Rainy Window',
        icon: '🌧️',
        quote: 'Let the rhythm of rain calm your mind',
        alwaysDark: false,
        bgLight: 'linear-gradient(180deg, #cfd8dc 0%, #b0bec5 30%, #c5ced8 60%, #e0e4e8 100%)',
        bgDark: 'linear-gradient(180deg, #1a2332 0%, #243447 35%, #1e2d3d 65%, #111827 100%)',
        glassLight: 'rgba(255,255,255,0.30)',
        glassDark: 'rgba(30,45,61,0.50)',
        ringActive: '#7b9bb5',
        ringPaused: '#90a4ae',
        ringOverage: '#e09560',
        ringDanger: '#c0392b',
        ringTrack: '#d5dde2',
        ringTrackDark: '#263545',
        ringGlow: false,
        ringAnimated: false,
        statusActive: ['#5a7f99', '#7b9bb5'],
        statusPaused: ['#78909c', '#90a4ae'],
        statusOverage: ['#c07840', '#e09560'],
        statusDanger: ['#a33025', '#c0392b'],
        btnBreak: { from: '#7b9bb5', to: '#5a7f99' },
        btnLunch: { from: '#6b8fa8', to: '#4f7590' },
        btnCombined: { from: '#607d8b', to: '#546e7a' },
        btnCombinedBreak: { from: '#5c8a9a', to: '#45707e' },
        btnPause: { from: '#90a4ae', to: '#78909c' },
        btnResume: { from: '#5a7f99', to: '#7b9bb5' },
        btnEnd: { from: '#546e7a', to: '#607d8b' },
    },
    // 3. Sakura Garden
    {
        id: 'sakura',
        name: 'Sakura',
        icon: '🌸',
        quote: 'Like petals, let your worries drift away',
        alwaysDark: false,
        bgLight: 'linear-gradient(135deg, #fce4ec 0%, #f8bbd0 25%, #fce4ec 50%, #fff0f3 75%, #fce4ec 100%)',
        bgDark: 'linear-gradient(135deg, #3d1f2e 0%, #4a2438 30%, #352030 60%, #2a1520 100%)',
        glassLight: 'rgba(255,240,243,0.40)',
        glassDark: 'rgba(60,30,45,0.45)',
        ringActive: '#f48fb1',
        ringPaused: '#ce93d8',
        ringOverage: '#e09560',
        ringDanger: '#c0392b',
        ringTrack: '#fce4ec',
        ringTrackDark: '#4a2438',
        ringGlow: false,
        ringAnimated: false,
        statusActive: ['#e06090', '#f48fb1'],
        statusPaused: ['#ab47bc', '#ce93d8'],
        statusOverage: ['#c07840', '#e09560'],
        statusDanger: ['#a33025', '#c0392b'],
        btnBreak: { from: '#f48fb1', to: '#e06090' },
        btnLunch: { from: '#ce93d8', to: '#ab47bc' },
        btnCombined: { from: '#e57ca0', to: '#c06088' },
        btnCombinedBreak: { from: '#ec8faa', to: '#d0708e' },
        btnPause: { from: '#ce93d8', to: '#ba68c8' },
        btnResume: { from: '#e06090', to: '#f48fb1' },
        btnEnd: { from: '#ab7098', to: '#c06088' },
    },
    // 4. Ocean Tide
    {
        id: 'ocean-tide',
        name: 'Ocean Tide',
        icon: '🌊',
        quote: 'Breathe with the waves',
        alwaysDark: false,
        bgLight: 'linear-gradient(180deg, #e0f7fa 0%, #b2ebf2 25%, #c5f0f5 50%, #e0f7fa 75%, #f0fbfc 100%)',
        bgDark: 'linear-gradient(180deg, #0a2e3d 0%, #0d3d4f 30%, #0a3545 60%, #051d28 100%)',
        glassLight: 'rgba(255,255,255,0.30)',
        glassDark: 'rgba(10,50,65,0.45)',
        ringActive: '#4dd0e1',
        ringPaused: '#80cbc4',
        ringOverage: '#e09560',
        ringDanger: '#c0392b',
        ringTrack: '#d8f3f6',
        ringTrackDark: '#0d3d4f',
        ringGlow: false,
        ringAnimated: false,
        statusActive: ['#00acc1', '#4dd0e1'],
        statusPaused: ['#4db6ac', '#80cbc4'],
        statusOverage: ['#c07840', '#e09560'],
        statusDanger: ['#a33025', '#c0392b'],
        btnBreak: { from: '#4dd0e1', to: '#00acc1' },
        btnLunch: { from: '#26c6da', to: '#0097a7' },
        btnCombined: { from: '#4db6ac', to: '#00897b' },
        btnCombinedBreak: { from: '#26a69a', to: '#00796b' },
        btnPause: { from: '#80cbc4', to: '#4db6ac' },
        btnResume: { from: '#00acc1', to: '#4dd0e1' },
        btnEnd: { from: '#00897b', to: '#00acc1' },
    },
    // 5. Neon City
    {
        id: 'neon-city',
        name: 'Neon City',
        icon: '🌃',
        quote: 'The city never sleeps, but you should rest',
        alwaysDark: true,
        bgLight: 'linear-gradient(160deg, #1a1a2e 0%, #16213e 30%, #1a1a2e 60%, #0f0f1e 100%)',
        bgDark: 'linear-gradient(160deg, #0a0a1a 0%, #12122d 30%, #0d0d20 60%, #050510 100%)',
        glassLight: 'rgba(25,25,50,0.50)',
        glassDark: 'rgba(15,15,35,0.55)',
        ringActive: '#bb86fc',
        ringPaused: '#64ffda',
        ringOverage: '#ff6e7f',
        ringDanger: '#f44336',
        ringTrack: '#1e1e3a',
        ringTrackDark: '#1e1e3a',
        ringGlow: true,
        ringAnimated: false,
        statusActive: ['#bb86fc', '#d4b0ff'],
        statusPaused: ['#64ffda', '#a0ffe0'],
        statusOverage: ['#ff6e7f', '#ff9aa8'],
        statusDanger: ['#f44336', '#ff7961'],
        btnBreak: { from: '#bb86fc', to: '#7c4dff' },
        btnLunch: { from: '#ff6e7f', to: '#d84060' },
        btnCombined: { from: '#64ffda', to: '#00bfa5' },
        btnCombinedBreak: { from: '#40c4ff', to: '#00b0ff' },
        btnPause: { from: '#64ffda', to: '#1de9b6' },
        btnResume: { from: '#7c4dff', to: '#bb86fc' },
        btnEnd: { from: '#00bfa5', to: '#64ffda' },
    },
    // 6. Golden Hour
    {
        id: 'golden-hour',
        name: 'Golden Hour',
        icon: '🌅',
        quote: 'Chase the golden moments',
        alwaysDark: false,
        bgLight: 'linear-gradient(180deg, #fff8e1 0%, #ffe0b2 25%, #ffccbc 50%, #fce4ec 75%, #fff8e1 100%)',
        bgDark: 'linear-gradient(180deg, #3e2723 0%, #4e342e 25%, #bf360c20 50%, #3e2723 75%, #2c1810 100%)',
        glassLight: 'rgba(255,248,225,0.35)',
        glassDark: 'rgba(62,39,35,0.45)',
        ringActive: '#ffb74d',
        ringPaused: '#ffab91',
        ringOverage: '#ef5350',
        ringDanger: '#c62828',
        ringTrack: '#ffe8c8',
        ringTrackDark: '#4e342e',
        ringGlow: false,
        ringAnimated: false,
        statusActive: ['#f09030', '#ffb74d'],
        statusPaused: ['#e08070', '#ffab91'],
        statusOverage: ['#d84040', '#ef5350'],
        statusDanger: ['#b71c1c', '#c62828'],
        btnBreak: { from: '#ffb74d', to: '#f09030' },
        btnLunch: { from: '#ff8a65', to: '#e8704a' },
        btnCombined: { from: '#ffab91', to: '#e08070' },
        btnCombinedBreak: { from: '#ff8a65', to: '#e06040' },
        btnPause: { from: '#ffcc80', to: '#ffb74d' },
        btnResume: { from: '#f09030', to: '#ffb74d' },
        btnEnd: { from: '#e08070', to: '#f09030' },
    },
    // 7. Deep Forest
    {
        id: 'deep-forest',
        name: 'Deep Forest',
        icon: '🌲',
        quote: 'Find your peace among the trees',
        alwaysDark: false,
        bgLight: 'linear-gradient(180deg, #e8f5e9 0%, #c8e6c9 25%, #dcedc8 50%, #f1f8e9 75%, #e8f5e9 100%)',
        bgDark: 'linear-gradient(180deg, #1b3a28 0%, #0d2818 30%, #152e1f 60%, #0a1f12 100%)',
        glassLight: 'rgba(255,255,255,0.30)',
        glassDark: 'rgba(20,45,30,0.45)',
        ringActive: '#66bb6a',
        ringPaused: '#a5d6a7',
        ringOverage: '#e09560',
        ringDanger: '#c0392b',
        ringTrack: '#d8eed9',
        ringTrackDark: '#1b3a28',
        ringGlow: false,
        ringAnimated: false,
        statusActive: ['#43a047', '#66bb6a'],
        statusPaused: ['#6ea670', '#a5d6a7'],
        statusOverage: ['#c07840', '#e09560'],
        statusDanger: ['#a33025', '#c0392b'],
        btnBreak: { from: '#66bb6a', to: '#43a047' },
        btnLunch: { from: '#81c784', to: '#66bb6a' },
        btnCombined: { from: '#4caf50', to: '#388e3c' },
        btnCombinedBreak: { from: '#2e7d32', to: '#1b5e20' },
        btnPause: { from: '#a5d6a7', to: '#81c784' },
        btnResume: { from: '#43a047', to: '#66bb6a' },
        btnEnd: { from: '#388e3c', to: '#43a047' },
    },
    // 8. Snowfall
    {
        id: 'snowfall',
        name: 'Snowfall',
        icon: '❄️',
        quote: 'Let stillness wash over you',
        alwaysDark: false,
        bgLight: 'linear-gradient(180deg, #f8f9ff 0%, #e8eaf6 30%, #f0f1fa 60%, #ffffff 100%)',
        bgDark: 'linear-gradient(180deg, #263238 0%, #37474f 30%, #2c3e4a 60%, #1a252b 100%)',
        glassLight: 'rgba(255,255,255,0.45)',
        glassDark: 'rgba(38,50,56,0.50)',
        ringActive: '#90caf9',
        ringPaused: '#b0bec5',
        ringOverage: '#e09560',
        ringDanger: '#c0392b',
        ringTrack: '#e8ecf2',
        ringTrackDark: '#37474f',
        ringGlow: false,
        ringAnimated: false,
        statusActive: ['#5e9ed0', '#90caf9'],
        statusPaused: ['#90a4ae', '#b0bec5'],
        statusOverage: ['#c07840', '#e09560'],
        statusDanger: ['#a33025', '#c0392b'],
        btnBreak: { from: '#90caf9', to: '#5e9ed0' },
        btnLunch: { from: '#7bb4e8', to: '#4a8cc0' },
        btnCombined: { from: '#b0bec5', to: '#78909c' },
        btnCombinedBreak: { from: '#80a0b0', to: '#607888' },
        btnPause: { from: '#b0bec5', to: '#90a4ae' },
        btnResume: { from: '#5e9ed0', to: '#90caf9' },
        btnEnd: { from: '#78909c', to: '#90a4ae' },
    },
    // 9. Moonlit
    {
        id: 'moonlit',
        name: 'Moonlit',
        icon: '🌙',
        quote: 'Rest under the gentle moonlight',
        alwaysDark: false,
        bgLight: 'linear-gradient(160deg, #ede7f6 0%, #d1c4e9 30%, #e8dff5 60%, #f5f0ff 100%)',
        bgDark: 'linear-gradient(160deg, #1a1040 0%, #2d1b69 30%, #1f1050 60%, #0d0826 100%)',
        glassLight: 'rgba(255,255,255,0.30)',
        glassDark: 'rgba(30,20,65,0.50)',
        ringActive: '#b39ddb',
        ringPaused: '#9fa8da',
        ringOverage: '#e09560',
        ringDanger: '#c0392b',
        ringTrack: '#e0d8ee',
        ringTrackDark: '#2d1b69',
        ringGlow: true,
        ringAnimated: false,
        statusActive: ['#8e72c0', '#b39ddb'],
        statusPaused: ['#7986cb', '#9fa8da'],
        statusOverage: ['#c07840', '#e09560'],
        statusDanger: ['#a33025', '#c0392b'],
        btnBreak: { from: '#b39ddb', to: '#8e72c0' },
        btnLunch: { from: '#9575cd', to: '#7e57c2' },
        btnCombined: { from: '#7986cb', to: '#5c6bc0' },
        btnCombinedBreak: { from: '#5c6bc0', to: '#3f51b5' },
        btnPause: { from: '#9fa8da', to: '#7986cb' },
        btnResume: { from: '#8e72c0', to: '#b39ddb' },
        btnEnd: { from: '#5c6bc0', to: '#7986cb' },
    },
    // 10. Aurora
    {
        id: 'aurora',
        name: 'Aurora',
        icon: '✨',
        quote: 'Watch the magic unfold',
        alwaysDark: false,
        bgLight: 'linear-gradient(135deg, #e8f5e9 0%, #e0f7fa 25%, #ede7f6 50%, #fce4ec 75%, #e8f5e9 100%)',
        bgDark: 'linear-gradient(135deg, #0d1f2d 0%, #0a2e3d 25%, #1a1040 50%, #2d1b35 75%, #0d1f2d 100%)',
        glassLight: 'rgba(255,255,255,0.25)',
        glassDark: 'rgba(15,30,45,0.45)',
        ringActive: '#80cbc4',
        ringPaused: '#b39ddb',
        ringOverage: '#e09560',
        ringDanger: '#c0392b',
        ringTrack: '#d8ede8',
        ringTrackDark: '#1a2a3d',
        ringGlow: true,
        ringAnimated: true,
        statusActive: ['#4db6ac', '#80cbc4'],
        statusPaused: ['#8e72c0', '#b39ddb'],
        statusOverage: ['#c07840', '#e09560'],
        statusDanger: ['#a33025', '#c0392b'],
        btnBreak: { from: '#80cbc4', to: '#4db6ac' },
        btnLunch: { from: '#b39ddb', to: '#8e72c0' },
        btnCombined: { from: '#f48fb1', to: '#e06090' },
        btnCombinedBreak: { from: '#e57373', to: '#d32f2f' },
        btnPause: { from: '#4db6ac', to: '#00897b' },
        btnResume: { from: '#8e72c0', to: '#b39ddb' },
        btnEnd: { from: '#00897b', to: '#4db6ac' },
    },
    {
        id: 'cyberpunk',
        name: 'Cyberpunk',
        icon: '🌃',
        quote: 'Wake up, samurai. Time to work.',
        alwaysDark: true,
        bgLight: 'radial-gradient(circle at 10% 20%, rgb(30, 0, 45) 0%, rgb(9, 9, 14) 90%)',
        bgDark: 'radial-gradient(circle at 10% 20%, rgb(30, 0, 45) 0%, rgb(9, 9, 14) 90%)',
        glassLight: 'rgba(255, 0, 100, 0.1)',
        glassDark: 'rgba(255, 0, 100, 0.1)',
        ringActive: '#00ffcc', // Neon Cyan
        ringPaused: '#ff00ff', // Neon Pink
        ringOverage: '#ffff00', // Yellow Danger
        ringDanger: '#ff0033', // Red Danger
        ringTrack: 'rgba(0, 255, 204, 0.15)',
        ringTrackDark: 'rgba(0, 255, 204, 0.15)',
        ringGlow: true,
        ringAnimated: false,
        statusActive: ['#00ffcc', '#00ffcc'],
        statusPaused: ['#ff00ff', '#ff00ff'],
        statusOverage: ['#ffff00', '#ffff00'],
        statusDanger: ['#ff0033', '#ff0033'],
        btnBreak: { from: '#00ffcc', to: '#00ccaa' },
        btnLunch: { from: '#ff00ff', to: '#cc00cc' },
        btnCombined: { from: '#ffff00', to: '#cccc00' },
        btnCombinedBreak: { from: '#ffaa00', to: '#cc8800' },
        btnPause: { from: '#ff00ff', to: '#cc00cc' },
        btnResume: { from: '#00ffcc', to: '#00ccaa' },
        btnEnd: { from: '#330066', to: '#220044' },
    },
    {
        id: 'synthwave',
        name: 'Synthwave',
        icon: '🌇',
        quote: '80s Retro Drive',
        alwaysDark: true,
        bgLight: 'linear-gradient(180deg, #1f013d 0%, #3d0043 50%, #900a6e 100%)',
        bgDark: 'linear-gradient(180deg, #1f013d 0%, #3d0043 50%, #900a6e 100%)',
        glassLight: 'rgba(255, 105, 180, 0.15)',
        glassDark: 'rgba(255, 105, 180, 0.15)',
        ringActive: '#ff71ce', // Hot Pink
        ringPaused: '#01cdfe', // Cyan
        ringOverage: '#fffb96', // Yellow
        ringDanger: '#ff0055', // Red
        ringTrack: 'rgba(255, 113, 206, 0.2)',
        ringTrackDark: 'rgba(255, 113, 206, 0.2)',
        ringGlow: true,
        ringAnimated: false,
        statusActive: ['#ff71ce', '#ff71ce'],
        statusPaused: ['#01cdfe', '#01cdfe'],
        statusOverage: ['#fffb96', '#fffb96'],
        statusDanger: ['#ff0055', '#ff0055'],
        btnBreak: { from: '#ff71ce', to: '#cc5ba5' },
        btnLunch: { from: '#01cdfe', to: '#01a4cb' },
        btnCombined: { from: '#05ffa1', to: '#04cc81' },
        btnCombinedBreak: { from: '#b967ff', to: '#9452cc' },
        btnPause: { from: '#01cdfe', to: '#01a4cb' },
        btnResume: { from: '#ff71ce', to: '#cc5ba5' },
        btnEnd: { from: '#3f015c', to: '#280142' },
    },
];

// ─── Hooks ──────────────────────────────────────────────────

const STORAGE_KEY = 'break-timer-theme';

export function useTimerTheme() {
    const [themeId, setThemeId] = useState<string>(() => {
        try {
            return localStorage.getItem(STORAGE_KEY) ?? 'default';
        } catch {
            return 'default';
        }
    });

    const theme = useMemo(
        () => THEMES.find((t) => t.id === themeId) ?? THEMES[0],
        [themeId],
    );

    const setTheme = useCallback((id: string) => {
        setThemeId(id);
        try {
            localStorage.setItem(STORAGE_KEY, id);
        } catch {
            // localStorage unavailable
        }
    }, []);

    return { theme, themeId, setTheme, themes: THEMES } as const;
}

export function useIsDark() {
    const [isDark, setIsDark] = useState(() =>
        typeof document !== 'undefined'
            ? document.documentElement.classList.contains('dark')
            : false,
    );

    useEffect(() => {
        const el = document.documentElement;
        const observer = new MutationObserver(() => {
            setIsDark(el.classList.contains('dark'));
        });
        observer.observe(el, { attributes: true, attributeFilter: ['class'] });
        return () => observer.disconnect();
    }, []);

    return isDark;
}

// ─── Helpers ────────────────────────────────────────────────

export function btnStyle(colors: ButtonColors): React.CSSProperties {
    return {
        background: `linear-gradient(to right, ${colors.from}, ${colors.to})`,
        boxShadow: `0 8px 20px -4px ${colors.from}50`,
    };
}

export function getGlassStyle(theme: TimerTheme, isDark: boolean): React.CSSProperties {
    return {
        background: isDark ? theme.glassDark : theme.glassLight,
    };
}

export function getPageBackground(theme: TimerTheme, isDark: boolean): React.CSSProperties {
    return {
        background: isDark ? theme.bgDark : theme.bgLight,
        transition: 'background 0.6s ease',
    };
}

export function getRingColor(
    theme: TimerTheme,
    hasSession: boolean,
    isOverage: boolean,
    overageSeconds: number,
    isPaused: boolean,
): string {
    if (!hasSession) return 'transparent';
    if (isOverage && overageSeconds >= 30) return theme.ringDanger;
    if (isOverage) return theme.ringOverage;
    if (isPaused) return theme.ringPaused;
    return theme.ringActive;
}

export function getStatusColor(
    theme: TimerTheme,
    isDark: boolean,
    hasSession: boolean,
    isOverage: boolean,
    overageSeconds: number,
    isPaused: boolean,
): string {
    const idx = isDark ? 1 : 0;
    if (!hasSession) return isDark ? '#a1a1aa' : '#71717a'; // muted-foreground
    if (isOverage && overageSeconds >= 30) return theme.statusDanger[idx];
    if (isOverage) return theme.statusOverage[idx];
    if (isPaused) return theme.statusPaused[idx];
    return theme.statusActive[idx];
}

export function getTimerColor(
    theme: TimerTheme,
    isDark: boolean,
    isOverage: boolean,
    overageSeconds: number,
): string | undefined {
    const idx = isDark ? 1 : 0;
    if (isOverage && overageSeconds >= 30) return theme.statusDanger[idx];
    if (isOverage) return theme.statusOverage[idx];
    return undefined; // use default text color
}
