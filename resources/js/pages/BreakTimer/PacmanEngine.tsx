import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * A real, self-playing Pac-Man engine rendered as an ambient background.
 * Pac-Man auto-navigates with BFS shortest-path to the nearest pellet while
 * avoiding ghosts; when a power pellet is eaten he hunts the frightened ghosts.
 * Four ghosts chase with their own targeting (Blinky/Pinky/Inky/Clyde style).
 * 3 lives, score/high-score, auto-restart. Cyberpunk neon aesthetic.
 *
 * Purely visual: pointer-events-none, aria-hidden. No user interaction.
 */

const MW = 28;
const MH = 31;

const PAC_MS = 140; // pac move interval
const GHOST_MS = 185; // ghost move interval
const FRIGHT_MS = 6500; // power-pellet duration
const DYING_MS = 900; // freeze after death
const OVER_MS = 1400; // game-over flash

type Dir = { dr: number; dc: number };
interface Pac {
    r: number;
    c: number;
    dir: Dir;
    mouth: boolean;
}
interface Ghost {
    r: number;
    c: number;
    dir: Dir;
    color: string;
    frightened: boolean;
    mode: 'house' | 'active';
    releaseAt: number;
    home: { r: number; c: number };
    corner: { r: number; c: number };
}

const DIRS: [number, number][] = [
    [-1, 0],
    [1, 0],
    [0, -1],
    [0, 1],
];

// Classic Pac-Man maze (28x31). '#' wall, '.' pellet, 'o' power pellet,
// '=' ghost-house gate (impassable to pac), ' ' open ghost-house interior.
const MAZE_TEMPLATE = [
    '############################',
    '#............##............#',
    '#.####.#####.##.#####.####.#',
    '#o####.#####.##.#####.####o#',
    '#.####.#####.##.#####.####.#',
    '#..........................#',
    '#.####.##.########.##.####.#',
    '#.####.##.########.##.####.#',
    '#......##....##....##......#',
    '######.#####.##.#####.######',
    '######.#####.##.#####.######',
    '######.##..........##.######',
    '######.##.###==###.##.######',
    '######.##.#      #.##.######',
    '######.##.#      #.##.######',
    '######.##.#      #.##.######',
    '######.##.########.##.######',
    '######.##..........##.######',
    '######.##.########.##.######',
    '######.##.########.##.######',
    '#............##............#',
    '#.####.#####.##.#####.####.#',
    '#.####.#####.##.#####.####.#',
    '#o..##................##..o#',
    '###.##.##.########.##.##.###',
    '###.##.##.########.##.##.###',
    '#......##....##....##......#',
    '#.##########.##.##########.#',
    '#.##########.##.##########.#',
    '#..........................#',
    '############################',
];

let MAZE: string[] = MAZE_TEMPLATE.slice();
// Whenever a maze is (re)generated we flip it vertically, which yields a
// genuinely different-looking layout (new pellet pattern, Pac-Man/ghost start
// corners swap ends) while guaranteeing it's still a valid, fully-connected
// maze - it's the same template, just mirrored top-to-bottom.
let flipped = false;

const PAC_START_BASE = { r: 23, c: 13 };
const GHOST_EXIT_BASE = { r: 11, c: 13 };
const GHOST_PEN_BASE = [
    { r: 14, c: 12 },
    { r: 14, c: 13 },
    { r: 14, c: 15 },
];

interface GhostMeta {
    color: string;
    corner: { r: number; c: number };
    home: { r: number; c: number };
}
const GHOSTS_META_BASE: GhostMeta[] = [
    { color: '#ff004d', corner: { r: 1, c: 26 }, home: { ...GHOST_EXIT_BASE } },
    { color: '#ff77e1', corner: { r: 1, c: 1 }, home: { ...GHOST_PEN_BASE[0] } },
    { color: '#00e5ff', corner: { r: 29, c: 26 }, home: { ...GHOST_PEN_BASE[1] } },
    { color: '#ff9d00', corner: { r: 29, c: 1 }, home: { ...GHOST_PEN_BASE[2] } },
];

function flipPoint(p: { r: number; c: number }): { r: number; c: number } {
    return { r: flipped ? MH - 1 - p.r : p.r, c: p.c };
}
function getPacStart(): { r: number; c: number } {
    return flipPoint(PAC_START_BASE);
}
function getGhostExit(): { r: number; c: number } {
    return flipPoint(GHOST_EXIT_BASE);
}
function getGhostPen(): { r: number; c: number }[] {
    return GHOST_PEN_BASE.map(flipPoint);
}
function getGhostsMeta(): GhostMeta[] {
    return GHOSTS_META_BASE.map((m) => ({ ...m, corner: flipPoint(m.corner), home: flipPoint(m.home) }));
}

function isWall(r: number, c: number): boolean {
    if (r < 0 || c < 0 || r >= MH || c >= MW) return true;
    const ch = MAZE[r][c];
    return ch === '#' || ch === '=';
}

function generateMaze() {
    flipped = !flipped;
    MAZE = flipped ? [...MAZE_TEMPLATE].reverse() : MAZE_TEMPLATE.slice();
}

function clamp(v: number, lo: number, hi: number): number {
    return Math.max(lo, Math.min(hi, v));
}

/** BFS: first step from (sr,sc) toward the nearest cell matching target(). */
function bfsNext(
    sr: number,
    sc: number,
    target: (r: number, c: number) => boolean,
    blocked: (r: number, c: number) => boolean,
): { r: number; c: number } | null {
    const start = sr * MW + sc;
    const prev = new Int32Array(MW * MH).fill(-2);
    prev[start] = -1;
    const q: number[] = [start];
    let qi = 0;
    let found = -1;
    while (qi < q.length) {
        const cur = q[qi++];
        const r = (cur / MW) | 0;
        const c = cur % MW;
        if (cur !== start && target(r, c)) {
            found = cur;
            break;
        }
        for (const [dr, dc] of DIRS) {
            const nr = r + dr;
            const nc = c + dc;
            if (isWall(nr, nc)) continue;
            const ni = nr * MW + nc;
            if (prev[ni] !== -2) continue;
            if (blocked(nr, nc)) continue;
            prev[ni] = cur;
            q.push(ni);
        }
    }
    if (found < 0) return null;
    let cur = found;
    while (prev[cur] !== start) {
        if (prev[cur] < 0) return null;
        cur = prev[cur];
    }
    return { r: (cur / MW) | 0, c: cur % MW };
}

export function PacmanEngine({ opacity }: { opacity: number }) {
    const wrapRef = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ w: 0, h: 0 });

    const pacRef = useRef<Pac>({ ...getPacStart(), dir: { dr: 0, dc: -1 }, mouth: true });
    const ghostsRef = useRef<Ghost[]>([]);
    const pelletsRef = useRef<Set<number>>(new Set());
    const powerRef = useRef<Set<number>>(new Set());
    const scoreRef = useRef(0);
    const highRef = useRef(0);
    const livesRef = useRef(3);
    const powerTimerRef = useRef(0);
    const eatComboRef = useRef(1);
    const phaseRef = useRef<'play' | 'dying' | 'over'>('play');
    const dyingStartRef = useRef(0);
    const overStartRef = useRef(0);
    const lastPacRef = useRef(0);
    const lastGhostRef = useRef(0);
    const lastFrameRef = useRef(0);
    const rafRef = useRef(0);
    const [, setTick] = useState(0);
    const [pelletVersion, setPelletVersion] = useState(0);
    const [mazeVersion, setMazeVersion] = useState(0);

    // Measure container. The parent uses min-h-[...] and can grow taller than
    // the viewport once page content (timer card, action buttons, etc.) needs
    // more room than that minimum - if we sized the maze off the raw
    // (possibly inflated) container height, the board would balloon past the
    // fold and force extra page scrolling. Clamp to the visible viewport
    // height below the container's top so the maze always fits on-screen.
    useEffect(() => {
        const el = wrapRef.current;
        if (!el) return;
        const update = () => {
            const top = el.getBoundingClientRect().top;
            const visibleH = Math.max(0, window.innerHeight - Math.max(0, top));
            setSize({ w: el.clientWidth, h: Math.min(el.clientHeight, visibleH) });
        };
        update();
        const ro = new ResizeObserver(update);
        ro.observe(el);
        window.addEventListener('resize', update);
        return () => {
            ro.disconnect();
            window.removeEventListener('resize', update);
        };
    }, []);

    useEffect(() => {
        function resetPellets() {
            // Flood-fill reachable cells from pac so isolated pellets / the ghost
            // house never leave uneatable dots (guards the win condition).
            const reachable = new Uint8Array(MW * MH);
            const pacStart = getPacStart();
            const startIdx = pacStart.r * MW + pacStart.c;
            reachable[startIdx] = 1;
            const q = [startIdx];
            let qi = 0;
            while (qi < q.length) {
                const cur = q[qi++];
                const r = (cur / MW) | 0;
                const c = cur % MW;
                for (const [dr, dc] of DIRS) {
                    const nr = r + dr;
                    const nc = c + dc;
                    if (isWall(nr, nc)) continue;
                    const ni = nr * MW + nc;
                    if (!reachable[ni]) {
                        reachable[ni] = 1;
                        q.push(ni);
                    }
                }
            }
            const pellets = new Set<number>();
            const power = new Set<number>();
            for (let r = 0; r < MH; r++) {
                for (let c = 0; c < MW; c++) {
                    const idx = r * MW + c;
                    if (!reachable[idx]) continue;
                    const ch = MAZE[r][c];
                    if (ch === 'o') power.add(idx);
                    else if (ch === '.') pellets.add(idx);
                }
            }
            pellets.delete(startIdx);
            pelletsRef.current = pellets;
            powerRef.current = power;
            setPelletVersion((v) => v + 1);
        }

        function resetPositions() {
            pacRef.current = { ...getPacStart(), dir: { dr: 0, dc: -1 }, mouth: true };
            const now = performance.now();
            const delays = [0, 3500, 8000, 13000];
            const ghostExit = getGhostExit();
            const ghostPen = getGhostPen();
            ghostsRef.current = getGhostsMeta().map((m, i) => {
                const start = i === 0 ? ghostExit : ghostPen[i - 1];
                return {
                    r: start.r,
                    c: start.c,
                    dir: { dr: -1, dc: 0 },
                    color: m.color,
                    frightened: false,
                    mode: (i === 0 ? 'active' : 'house') as 'house' | 'active',
                    releaseAt: now + delays[i],
                    home: { ...m.home },
                    corner: { ...m.corner },
                };
            });
            powerTimerRef.current = 0;
            eatComboRef.current = 1;
        }

        function fullReset() {
            highRef.current = Math.max(highRef.current, scoreRef.current);
            scoreRef.current = 0;
            livesRef.current = 3;
            generateMaze();
            setMazeVersion((v) => v + 1);
            resetPellets();
            resetPositions();
            phaseRef.current = 'play';
        }

        function eatAt(r: number, c: number) {
            const idx = r * MW + c;
            if (pelletsRef.current.has(idx)) {
                pelletsRef.current.delete(idx);
                scoreRef.current += 10;
                setPelletVersion((v) => v + 1);
            } else if (powerRef.current.has(idx)) {
                powerRef.current.delete(idx);
                scoreRef.current += 50;
                powerTimerRef.current = FRIGHT_MS;
                eatComboRef.current = 1;
                ghostsRef.current.forEach((g) => {
                    g.frightened = true;
                });
                setPelletVersion((v) => v + 1);
            }
        }

        function ghostField(): Int32Array {
            const dist = new Int32Array(MW * MH).fill(-1);
            const q: number[] = [];
            for (const g of ghostsRef.current) {
                if (g.mode === 'active' && !g.frightened) {
                    const i = g.r * MW + g.c;
                    if (dist[i] < 0) {
                        dist[i] = 0;
                        q.push(i);
                    }
                }
            }
            let qi = 0;
            while (qi < q.length) {
                const cur = q[qi++];
                const r = (cur / MW) | 0;
                const c = cur % MW;
                for (const [dr, dc] of DIRS) {
                    const nr = r + dr;
                    const nc = c + dc;
                    if (isWall(nr, nc)) continue;
                    const ni = nr * MW + nc;
                    if (dist[ni] < 0) {
                        dist[ni] = dist[cur] + 1;
                        q.push(ni);
                    }
                }
            }
            return dist;
        }

        const DANGER = 5;
        const distAt = (gd: Int32Array, r: number, c: number) => {
            const d = gd[r * MW + c];
            return d < 0 ? 999 : d;
        };
        const pelletTarget = (r: number, c: number) =>
            pelletsRef.current.has(r * MW + c) || powerRef.current.has(r * MW + c);

        function pacStep() {
            const pac = pacRef.current;
            eatAt(pac.r, pac.c);

            const gd = ghostField();
            const anyFright =
                powerTimerRef.current > 0 && ghostsRef.current.some((g) => g.frightened && g.mode === 'active');
            let step: { r: number; c: number } | null = null;

            if (anyFright) {
                // Hunt the nearest frightened ghost.
                step = bfsNext(
                    pac.r,
                    pac.c,
                    (r, c) =>
                        ghostsRef.current.some((g) => g.frightened && g.mode === 'active' && g.r === r && g.c === c),
                    () => false,
                );
                if (!step) step = bfsNext(pac.r, pac.c, pelletTarget, () => false);
            } else if (distAt(gd, pac.r, pac.c) <= DANGER) {
                // Threatened: flee toward the neighbour furthest from all ghosts,
                // tie-broken by whether that direction still leads to pellets.
                let best: { r: number; c: number } | null = null;
                let bestSafe = -1;
                let bestPellet = 9;
                for (const [dr, dc] of DIRS) {
                    const nr = pac.r + dr;
                    const nc = pac.c + dc;
                    if (isWall(nr, nc)) continue;
                    const safe = distAt(gd, nr, nc);
                    if (safe === 0) continue; // never step onto a ghost
                    const leadsToPellet = bfsNext(nr, nc, pelletTarget, (br, bc) => distAt(gd, br, bc) <= 1) ? 0 : 1;
                    if (safe > bestSafe || (safe === bestSafe && leadsToPellet < bestPellet)) {
                        bestSafe = safe;
                        bestPellet = leadsToPellet;
                        best = { r: nr, c: nc };
                    }
                }
                step = best;
            } else {
                // Safe: head to the nearest pellet, keeping a 1-tile safety margin.
                step = bfsNext(pac.r, pac.c, pelletTarget, (r, c) => distAt(gd, r, c) <= 1);
                if (!step) step = bfsNext(pac.r, pac.c, pelletTarget, (r, c) => distAt(gd, r, c) === 0);
                if (!step) {
                    let best: { r: number; c: number } | null = null;
                    let bestSafe = -1;
                    for (const [dr, dc] of DIRS) {
                        const nr = pac.r + dr;
                        const nc = pac.c + dc;
                        if (isWall(nr, nc)) continue;
                        const safe = distAt(gd, nr, nc);
                        if (safe > bestSafe) {
                            bestSafe = safe;
                            best = { r: nr, c: nc };
                        }
                    }
                    step = best;
                }
            }

            if (step) {
                pac.dir = { dr: step.r - pac.r, dc: step.c - pac.c };
                pac.r = step.r;
                pac.c = step.c;
                eatAt(pac.r, pac.c);
            }
            pac.mouth = !pac.mouth;

            if (pelletsRef.current.size === 0 && powerRef.current.size === 0) {
                scoreRef.current += 500;
                generateMaze();
                setMazeVersion((v) => v + 1);
                resetPellets();
                resetPositions();
            }
        }

        function ghostTarget(g: Ghost, i: number, pac: Pac): { r: number; c: number } {
            if (i === 1) {
                const tr = clamp(pac.r + pac.dir.dr * 4, 1, MH - 2);
                const tc = clamp(pac.c + pac.dir.dc * 4, 1, MW - 2);
                if (!isWall(tr, tc)) return { r: tr, c: tc };
                return { r: pac.r, c: pac.c };
            }
            if (i === 3) {
                const d = Math.abs(g.r - pac.r) + Math.abs(g.c - pac.c);
                if (d < 6) return g.corner;
                return { r: pac.r, c: pac.c };
            }
            return { r: pac.r, c: pac.c };
        }

        function ghostStep() {
            const pac = pacRef.current;
            ghostsRef.current.forEach((g, i) => {
                if (g.mode !== 'active') return;
                if (g.frightened) {
                    let best: { r: number; c: number } | null = null;
                    let bestScore = -Infinity;
                    for (const [dr, dc] of DIRS) {
                        const nr = g.r + dr;
                        const nc = g.c + dc;
                        if (isWall(nr, nc)) continue;
                        let sc = Math.abs(nr - pac.r) + Math.abs(nc - pac.c);
                        if (dr === -g.dir.dr && dc === -g.dir.dc) sc -= 5;
                        if (sc > bestScore) {
                            bestScore = sc;
                            best = { r: nr, c: nc };
                        }
                    }
                    if (best) {
                        g.dir = { dr: best.r - g.r, dc: best.c - g.c };
                        g.r = best.r;
                        g.c = best.c;
                    }
                    return;
                }
                const t = ghostTarget(g, i, pac);
                let step = bfsNext(g.r, g.c, (r, c) => r === t.r && c === t.c, () => false);
                if (!step) step = bfsNext(g.r, g.c, (r, c) => r === pac.r && c === pac.c, () => false);
                if (step) {
                    g.dir = { dr: step.r - g.r, dc: step.c - g.c };
                    g.r = step.r;
                    g.c = step.c;
                }
            });
        }

        function pacDie() {
            livesRef.current -= 1;
            powerTimerRef.current = 0;
            eatComboRef.current = 1;
            ghostsRef.current.forEach((g) => {
                g.frightened = false;
            });
            if (livesRef.current < 0) {
                phaseRef.current = 'over';
                overStartRef.current = performance.now();
            } else {
                phaseRef.current = 'dying';
                dyingStartRef.current = performance.now();
            }
        }

        function checkCollisions() {
            const pac = pacRef.current;
            for (const g of ghostsRef.current) {
                if (g.mode !== 'active') continue;
                if (g.r === pac.r && g.c === pac.c) {
                    if (g.frightened) {
                        scoreRef.current += 200 * eatComboRef.current;
                        eatComboRef.current *= 2;
                        g.frightened = false;
                        g.r = g.home.r;
                        g.c = g.home.c;
                        g.dir = { dr: -1, dc: 0 };
                        g.mode = 'house';
                        g.releaseAt = performance.now() + 3000;
                    } else {
                        pacDie();
                        return;
                    }
                }
            }
        }

        // Initialize
        resetPellets();
        resetPositions();
        lastFrameRef.current = performance.now();

        function loop(now: number) {
            const dt = now - lastFrameRef.current;
            lastFrameRef.current = now;
            let dirty = false;

            if (phaseRef.current === 'over') {
                if (now - overStartRef.current >= OVER_MS) {
                    fullReset();
                    dirty = true;
                }
            } else if (phaseRef.current === 'dying') {
                if (now - dyingStartRef.current >= DYING_MS) {
                    resetPositions();
                    phaseRef.current = 'play';
                    dirty = true;
                }
            } else {
                if (powerTimerRef.current > 0) {
                    const prev = powerTimerRef.current;
                    powerTimerRef.current -= dt;
                    if (powerTimerRef.current <= 0) {
                        ghostsRef.current.forEach((g) => {
                            g.frightened = false;
                        });
                        eatComboRef.current = 1;
                        dirty = true;
                    } else if (prev >= 1800 && powerTimerRef.current < 1800) {
                        dirty = true; // enter frightened blink state
                    }
                }
                // Release the next house ghost on schedule (staggered exit).
                for (const g of ghostsRef.current) {
                    if (g.mode === 'house' && now >= g.releaseAt) {
                        g.mode = 'active';
                        const ghostExit = getGhostExit();
                        g.r = ghostExit.r;
                        g.c = ghostExit.c;
                        g.dir = { dr: -1, dc: 0 };
                        if (powerTimerRef.current > 0) g.frightened = true;
                        dirty = true;
                    }
                }
                if (now - lastPacRef.current >= PAC_MS) {
                    lastPacRef.current = now;
                    pacStep();
                    checkCollisions();
                    dirty = true;
                }
                if (phaseRef.current === 'play' && now - lastGhostRef.current >= GHOST_MS) {
                    lastGhostRef.current = now;
                    ghostStep();
                    checkCollisions();
                    dirty = true;
                }
            }

            // Only re-render when something visually changed. The CSS transitions
            // on pac/ghosts smoothly interpolate between discrete steps, so we do
            // NOT need to re-render every animation frame (which caused stutter).
            if (dirty) setTick((k) => (k + 1) % 1000000);
            rafRef.current = requestAnimationFrame(loop);
        }

        rafRef.current = requestAnimationFrame(loop);
        return () => cancelAnimationFrame(rafRef.current);
    }, []);

    // ─── Rendering ───────────────────────────────────────────
    // Reserve ~1.4 extra rows of vertical space for the HUD strip rendered
    // above the board (score/lives/high-score), so the whole group - HUD +
    // maze - fits inside the container instead of overflowing/clipping it.
    const HUD_ROWS = 1.4;
    const FIT = 0.86;
    const { w, h } = size;
    const cell = w && h ? Math.max(8, Math.floor(Math.min((w * FIT) / MW, (h * FIT) / (MH + HUD_ROWS)))) : 0;
    const boardW = cell * MW;
    const boardH = cell * MH;
    const hudH = Math.max(18, cell * 0.95);
    const offX = (w - boardW) / 2;
    const offY = (h - (boardH + hudH)) / 2 + hudH;
    const vis = Math.min(1, opacity * 2.4);

    const wallColor = '#2b6bff';
    const wallPath = useMemo(() => {
        if (!cell) return '';
        let d = '';
        for (let r = 0; r < MH; r++) {
            for (let c = 0; c < MW; c++) {
                if (isWall(r, c)) continue;
                const x = c * cell;
                const y = r * cell;
                if (isWall(r - 1, c)) d += `M${x} ${y}h${cell}`;
                if (isWall(r + 1, c)) d += `M${x} ${y + cell}h${cell}`;
                if (isWall(r, c - 1)) d += `M${x} ${y}v${cell}`;
                if (isWall(r, c + 1)) d += `M${x + cell} ${y}v${cell}`;
            }
        }
        return d;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cell, mazeVersion]);
    const wallsEl =
        cell > 0 ? (
            <svg
                width={boardW}
                height={boardH}
                style={{
                    position: 'absolute',
                    left: 0,
                    top: 0,
                    filter: `drop-shadow(0 0 3px ${wallColor}) drop-shadow(0 0 6px ${wallColor}aa)`,
                }}
            >
                <path
                    d={wallPath}
                    stroke={wallColor}
                    strokeWidth={Math.max(1.5, cell * 0.14)}
                    fill="none"
                    strokeLinecap="round"
                />
            </svg>
        ) : null;

    const pelletsEl = useMemo(() => {
        if (!cell) return null;
        const out: React.ReactNode[] = [];
        pelletsRef.current.forEach((idx) => {
            const r = (idx / MW) | 0;
            const c = idx % MW;
            out.push(
                <div
                    key={`p-${idx}`}
                    style={{
                        position: 'absolute',
                        left: c * cell + cell / 2 - 1.5,
                        top: r * cell + cell / 2 - 1.5,
                        width: 3,
                        height: 3,
                        borderRadius: '50%',
                        background: '#ffe0f5',
                        boxShadow: '0 0 4px #ff77e1',
                        opacity: vis,
                    }}
                />,
            );
        });
        powerRef.current.forEach((idx) => {
            const r = (idx / MW) | 0;
            const c = idx % MW;
            out.push(
                <div
                    key={`pw-${idx}`}
                    style={{
                        position: 'absolute',
                        left: c * cell + cell / 2 - cell * 0.22,
                        top: r * cell + cell / 2 - cell * 0.22,
                        width: cell * 0.44,
                        height: cell * 0.44,
                        borderRadius: '50%',
                        background: '#ff2d95',
                        boxShadow: '0 0 10px #ff2d95, 0 0 18px #ff2d95',
                        animation: 'pac-pulse 0.7s ease-in-out infinite',
                        opacity: vis,
                    }}
                />,
            );
        });
        return out;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cell, pelletVersion, vis]);

    const pac = pacRef.current;
    const ghosts = ghostsRef.current;
    const pacAng =
        pac.dir.dc === 1 ? 0 : pac.dir.dc === -1 ? 180 : pac.dir.dr === 1 ? 90 : pac.dir.dr === -1 ? 270 : 0;
    const frightBlink = powerTimerRef.current > 0 && powerTimerRef.current < 1800;

    return (
        <div ref={wrapRef} className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            <style>{`@keyframes pac-pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(0.55);opacity:0.6}}`}</style>

            {/* Cyberpunk backdrop */}
            <div
                className="absolute inset-0"
                style={{
                    backgroundImage:
                        'linear-gradient(rgba(0,229,255,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(255,45,149,0.04) 1px, transparent 1px)',
                    backgroundSize: `${cell || 22}px ${cell || 22}px`,
                    opacity: opacity * 0.7,
                }}
            />
            <div
                className="absolute inset-0"
                style={{ background: 'radial-gradient(circle at 50% 45%, transparent 25%, rgba(5,3,20,0.8) 100%)', opacity }}
            />

            {cell > 0 && (
                <div style={{ position: 'absolute', left: offX, top: offY, width: boardW, height: boardH }}>
                    {wallsEl}
                    {pelletsEl}

                    {/* Pac-Man */}
                    <div
                        style={{
                            position: 'absolute',
                            left: pac.c * cell,
                            top: pac.r * cell,
                            width: cell,
                            height: cell,
                            transform: `rotate(${pacAng}deg)`,
                            transition: 'left 0.14s linear, top 0.14s linear',
                            opacity: vis,
                        }}
                    >
                        <svg viewBox="0 0 100 100" width={cell} height={cell} style={{ filter: 'drop-shadow(0 0 5px #ffe600)' }}>
                            <path
                                d={pac.mouth ? 'M50 50 L98 24 A48 48 0 1 0 98 76 Z' : 'M50 50 L98 42 A48 48 0 1 0 98 58 Z'}
                                fill="#ffe600"
                            />
                        </svg>
                    </div>

                    {/* Ghosts */}
                    {ghosts.map((g, i) => {
                        const body = g.frightened ? (frightBlink ? '#ffffff' : '#2136ff') : g.color;
                        const eyeDx = g.dir.dc * 2;
                        const eyeDy = g.dir.dr * 2;
                        return (
                            <div
                                key={`g-${i}`}
                                style={{
                                    position: 'absolute',
                                    left: g.c * cell,
                                    top: g.r * cell,
                                    width: cell,
                                    height: cell,
                                    transition: 'left 0.16s linear, top 0.16s linear',
                                    opacity: vis,
                                }}
                            >
                                <svg viewBox="0 0 100 100" width={cell} height={cell} style={{ filter: `drop-shadow(0 0 5px ${body})` }}>
                                    <path
                                        d="M8 92 V48 a42 42 0 0 1 84 0 V92 l-14 -11 -14 11 -14 -11 -14 11 -14 -11 Z"
                                        fill={body}
                                    />
                                    {g.frightened ? (
                                        <>
                                            <circle cx="36" cy="46" r="6" fill={frightBlink ? '#ff004d' : '#ffffff'} />
                                            <circle cx="64" cy="46" r="6" fill={frightBlink ? '#ff004d' : '#ffffff'} />
                                            <path d="M28 68 l8 -8 8 8 8 -8 8 8 8 -8 8 8" stroke={frightBlink ? '#ff004d' : '#ffffff'} strokeWidth="3" fill="none" />
                                        </>
                                    ) : (
                                        <>
                                            <ellipse cx="36" cy="44" rx="10" ry="12" fill="#ffffff" />
                                            <ellipse cx="64" cy="44" rx="10" ry="12" fill="#ffffff" />
                                            <circle cx={36 + eyeDx} cy={44 + eyeDy} r="5" fill="#001b6b" />
                                            <circle cx={64 + eyeDx} cy={44 + eyeDy} r="5" fill="#001b6b" />
                                        </>
                                    )}
                                </svg>
                            </div>
                        );
                    })}

                    {/* Game over flash */}
                    {phaseRef.current === 'over' && (
                        <div
                            style={{
                                position: 'absolute',
                                inset: 0,
                                background: 'rgba(255,0,77,0.2)',
                                border: '2px solid #ff004d',
                                boxShadow: '0 0 40px #ff004d, inset 0 0 30px rgba(255,0,77,0.35)',
                                borderRadius: 4,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                            }}
                        >
                            <span
                                style={{
                                    fontFamily: 'monospace',
                                    fontWeight: 800,
                                    fontSize: Math.max(13, cell * 0.85),
                                    color: '#ffe600',
                                    textShadow: '0 0 12px #ffe600',
                                    letterSpacing: 2,
                                    opacity: Math.min(1, opacity * 3),
                                }}
                            >
                                GAME OVER
                            </span>
                        </div>
                    )}

                    {/* HUD */}
                    <div
                        style={{
                            position: 'absolute',
                            top: -hudH,
                            left: 2,
                            right: 2,
                            display: 'flex',
                            justifyContent: 'space-between',
                            fontFamily: 'monospace',
                            fontSize: Math.max(9, cell * 0.4),
                            fontWeight: 700,
                            letterSpacing: 1,
                            color: '#00e5ff',
                            textShadow: '0 0 8px #00e5ff',
                            opacity: Math.min(1, opacity * 2.2),
                            whiteSpace: 'nowrap',
                        }}
                    >
                        <span>SCORE {scoreRef.current}</span>
                        <span style={{ color: '#ffe600', textShadow: '0 0 8px #ffe600' }}>
                            {'●'.repeat(Math.max(0, livesRef.current))}
                        </span>
                        <span style={{ color: '#ff2d95', textShadow: '0 0 8px #ff2d95' }}>
                            HI {Math.max(highRef.current, scoreRef.current)}
                        </span>
                    </div>
                </div>
            )}
        </div>
    );
}
