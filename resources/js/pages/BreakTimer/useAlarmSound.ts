import { useState, useEffect, useRef, useCallback } from 'react';

export interface AlarmOption {
    id: string;
    name: string;
    icon: string;
}

export const ALARM_OPTIONS: AlarmOption[] = [
    { id: 'none', name: 'No Sound', icon: '🔇' },
    { id: 'zen', name: 'Zen Bell', icon: '🎐' },
    { id: 'corporate', name: 'Corporate', icon: '🏢' },
    { id: 'arcade', name: '8-Bit', icon: '👾' },
    { id: 'beep', name: 'Beep', icon: '🔔' },
    { id: 'urgent', name: 'Urgent', icon: '🚨' },
    { id: 'chime', name: 'Chime', icon: '🎵' },
    { id: 'alert', name: 'Alert', icon: '📢' },
    { id: 'buzzer', name: 'Buzzer', icon: '⏰' },
];

const STORAGE_KEY = 'breakTimer_alarmSound';
const VOLUME_KEY = 'breakTimer_alarmVolume';

function getAudioContext(): AudioContext | null {
    try {
        return new (window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext)();
    } catch {
        return null;
    }
}

function playTone(ctx: AudioContext, freq: number | number[], duration: number, type: OscillatorType = 'sine', gain = 0.3, attack = 0.05) {
    const freqs = Array.isArray(freq) ? freq : [freq];

    freqs.forEach(f => {
        const osc = ctx.createOscillator();
        const vol = ctx.createGain();
        osc.type = type;
        osc.frequency.value = f;

        // Attack (fade in to prevent harsh popping)
        vol.gain.setValueAtTime(0, ctx.currentTime);
        vol.gain.linearRampToValueAtTime(gain, ctx.currentTime + attack);

        // Decay (fade out smoothly)
        vol.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);

        osc.connect(vol);
        vol.connect(ctx.destination);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + duration);
    });
}

function playSoundPattern(alarmId: string, volume: number) {
    const ctx = getAudioContext();
    if (!ctx) return;

    const v = volume * 0.4; // Slightly reduced global volume for smoother sound

    switch (alarmId) {
        case 'zen': {
            // Very soft meditation bowl sound (sine wave chord)
            playTone(ctx, [392, 494 * 2], 3.0, 'sine', v * 0.8, 0.4);
            break;
        }
        case 'corporate': {
            // Smooth transit/airport notification chord
            const notes = [330, 440, 554]; // E4, A4, C#5
            notes.forEach((freq, i) => {
                setTimeout(() => playTone(ctx, freq, 1.2, 'sine', v, 0.05), i * 150);
            });
            break;
        }
        case 'arcade': {
            // Fast 8-bit retro video game sequence
            const notes = [440, 554, 659, 880, 1109];
            notes.forEach((freq, i) => {
                setTimeout(() => playTone(ctx, freq, 0.1, 'square', v * 0.3, 0.01), i * 80);
            });
            break;
        }
        case 'beep': {
            // Three short beeps
            playTone(ctx, 880, 0.15, 'sine', v);
            setTimeout(() => playTone(ctx, 880, 0.15, 'sine', v), 200);
            setTimeout(() => playTone(ctx, 880, 0.15, 'sine', v), 400);
            break;
        }
        case 'urgent': {
            // Less piercing alternating tones
            for (let i = 0; i < 6; i++) {
                setTimeout(() => playTone(ctx, i % 2 === 0 ? 800 : 600, 0.15, 'square', v * 0.4, 0.02), i * 180);
            }
            break;
        }
        case 'chime': {
            // Ascending notes resolving to a chord
            const notes = [523, 659, 784]; // C5, E5, G5
            notes.forEach((freq, i) => {
                setTimeout(() => playTone(ctx, freq, 0.4, 'sine', v), i * 200);
            });
            setTimeout(() => playTone(ctx, [523, 659, 784, 1047], 1.5, 'sine', v * 0.8), 600);
            break;
        }
        case 'alert': {
            // Two-tone siren, lower frequency and longer sweeps
            for (let i = 0; i < 4; i++) {
                setTimeout(() => playTone(ctx, [i % 2 === 0 ? 500 : 700, i % 2 === 0 ? 505 : 705], 0.25, 'sawtooth', v * 0.3, 0.05), i * 300);
            }
            break;
        }
        case 'buzzer': {
            // Low, smooth buzzing
            playTone(ctx, 180, 0.8, 'square', v * 0.4, 0.1);
            setTimeout(() => playTone(ctx, 180, 0.8, 'square', v * 0.4, 0.1), 1000);
            break;
        }
    }
}

export function useAlarmSound() {
    const [alarmId, setAlarmIdState] = useState<string>(() => {
        try { return localStorage.getItem(STORAGE_KEY) || 'beep'; } catch { return 'beep'; }
    });
    const [volume, setVolumeState] = useState<number>(() => {
        try { return parseFloat(localStorage.getItem(VOLUME_KEY) || '0.7'); } catch { return 0.7; }
    });

    const hasTriggeredRef = useRef(false);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const titleFlashRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const originalTitleRef = useRef<string>(document.title);

    const setAlarmId = useCallback((id: string) => {
        setAlarmIdState(id);
        try { localStorage.setItem(STORAGE_KEY, id); } catch { /* noop */ }
    }, []);

    const setVolume = useCallback((v: number) => {
        setVolumeState(v);
        try { localStorage.setItem(VOLUME_KEY, String(v)); } catch { /* noop */ }
    }, []);

    const preview = useCallback((id?: string) => {
        playSoundPattern(id ?? alarmId, volume);
    }, [alarmId, volume]);

    const stopTitleFlash = useCallback(() => {
        if (titleFlashRef.current) {
            clearInterval(titleFlashRef.current);
            titleFlashRef.current = null;
        }
        document.title = originalTitleRef.current;
    }, []);

    const startTitleFlash = useCallback(() => {
        stopTitleFlash();
        originalTitleRef.current = document.title.replace(/^⚠️ (OVERBREAK!) - /, '');
        let toggle = false;
        titleFlashRef.current = setInterval(() => {
            toggle = !toggle;
            document.title = toggle
                ? `⚠️ OVERBREAK! - ${originalTitleRef.current}`
                : originalTitleRef.current;
        }, 1000);
    }, [stopTitleFlash]);

    const showBrowserNotification = useCallback(() => {
        if (!('Notification' in window)) return;

        const createNotification = () => {
            const n = new Notification('⚠️ Break Timer Overage!', {
                body: 'Your break time has ended. Please return to work.',
                icon: '/favicon.ico',
                tag: 'break-timer-overage', // prevents duplicate notifications
                requireInteraction: true,
            });
            n.onclick = () => {
                window.focus();
                n.close();
            };
        };

        if (Notification.permission === 'granted') {
            createNotification();
        } else if (Notification.permission !== 'denied') {
            Notification.requestPermission().then((perm) => {
                if (perm === 'granted') {
                    createNotification();
                }
            });
        }
    }, []);

    const stopAlarm = useCallback(() => {
        if (intervalRef.current) {
            clearInterval(intervalRef.current);
            intervalRef.current = null;
        }
        stopTitleFlash();
    }, [stopTitleFlash]);

    const triggerAlarm = useCallback(() => {
        // Always flash tab and show notification, even if sound is off
        startTitleFlash();
        showBrowserNotification();

        if (alarmId === 'none') return;
        playSoundPattern(alarmId, volume);
        // Loop continuously until the user ends the timer
        stopAlarm();
        startTitleFlash(); // restart after stopAlarm clears it
        const repeatMs: Record<string, number> = {
            zen: 5000,
            corporate: 4000,
            arcade: 3000,
            beep: 4000,
            urgent: 2500,
            chime: 3500,
            alert: 2000,
            buzzer: 3000,
        };
        const interval = repeatMs[alarmId] ?? 2000;
        intervalRef.current = setInterval(() => {
            playSoundPattern(alarmId, volume);
        }, interval);
    }, [alarmId, volume, stopAlarm, startTitleFlash, showBrowserNotification]);

    // Cleanup on unmount
    useEffect(() => {
        return () => {
            stopAlarm();
        };
    }, [stopAlarm]);

    // Request notification permission early
    useEffect(() => {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }, []);

    /**
     * Call this every tick with the current remainingSeconds.
     * Triggers alarm once when crossing from >=0 to <0, repeats while overage.
     * Stops when session ends or remainingSeconds goes back to >=0.
     */
    const checkOverage = useCallback((remainingSeconds: number, isActive: boolean) => {
        if (!isActive || remainingSeconds >= 0) {
            // Not in overage — reset trigger state and stop repeating
            if (hasTriggeredRef.current) {
                hasTriggeredRef.current = false;
                stopAlarm();
            }
            return;
        }

        // In overage
        if (!hasTriggeredRef.current) {
            hasTriggeredRef.current = true;
            triggerAlarm();
        }
    }, [triggerAlarm, stopAlarm]);

    return { alarmId, setAlarmId, volume, setVolume, preview, checkOverage, stopAlarm };
}
