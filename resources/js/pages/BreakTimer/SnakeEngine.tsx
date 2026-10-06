import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * A real, self-playing Snake engine rendered as an ambient background.
 * The snake uses BFS to find the shortest path to the food, but only commits
 * to it when it can still reach its own tail afterwards (classic survival
 * heuristic). Otherwise it follows the move that keeps the most open space,
 * which lets it survive long enough to fill a large portion of the board.
 * On death it flashes GAME OVER and auto-restarts. Neon arcade aesthetic.
 *
 * Purely visual: pointer-events-none, aria-hidden. No user interaction.
 */

const GW = 32;
const GH = 24;
const CELLS = GW * GH;

const SNAKE_MS = 110; // step interval
const DYING_MS = 700; // flash on death
const OVER_MS = 1200; // GAME OVER banner
const START_LEN = 4;

type Phase = 'play' | 'dying' | 'over';

const DIRS: [number, number][] = [
    [0, 1], // right
    [1, 0], // down
    [0, -1], // left
    [-1, 0], // up
];

const idx = (r: number, c: number): number => r * GW + c;
const rowOf = (i: number): number => (i / GW) | 0;
const colOf = (i: number): number => i % GW;
const inBounds = (r: number, c: number): boolean => r >= 0 && r < GH && c >= 0 && c < GW;

interface Food {
    cell: number;
    golden: boolean;
}

export function SnakeEngine({ opacity }: { opacity: number }) {
    const wrapRef = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ w: 0, h: 0 });
    const [, setTick] = useState(0);

    // Snake body as a list of cell indices, head first.
    const bodyRef = useRef<number[]>([]);
    const occupiedRef = useRef<Uint8Array>(new Uint8Array(CELLS));
    const dirRef = useRef<[number, number]>(DIRS[0]);
    const foodRef = useRef<Food>({ cell: 0, golden: false });
    const scoreRef = useRef(0);
    const highRef = useRef(0);
    const phaseRef = useRef<Phase>('play');
    const phaseStartRef = useRef(0);
    const lastStepRef = useRef(0);
    const rafRef = useRef(0);

    // ─── Board helpers ───────────────────────────────────────

    useEffect(() => {
        function placeFood(): void {
            const free: number[] = [];
            const occupied = occupiedRef.current;
            for (let i = 0; i < CELLS; i++) {
                if (!occupied[i]) free.push(i);
            }
            if (free.length === 0) {
                foodRef.current = { cell: -1, golden: false };
                return;
            }
            const cell = free[(Math.random() * free.length) | 0];
            foodRef.current = { cell, golden: Math.random() < 0.15 };
        }

        function reset(): void {
            const occupied = occupiedRef.current;
            occupied.fill(0);
            const startRow = (GH / 2) | 0;
            const startCol = (GW / 2) | 0;
            const body: number[] = [];
            for (let i = 0; i < START_LEN; i++) {
                const cell = idx(startRow, startCol - i);
                body.push(cell);
                occupied[cell] = 1;
            }
            bodyRef.current = body;
            dirRef.current = DIRS[0];
            scoreRef.current = 0;
            phaseRef.current = 'play';
            placeFood();
        }

        /**
         * Breadth-first search over free cells. `blocked` marks impassable
         * cells; returns the distance map (-1 where unreachable).
         */
        function bfs(from: number, blocked: Uint8Array): Int16Array {
            const dist = new Int16Array(CELLS).fill(-1);
            const queue = new Int16Array(CELLS);
            let head = 0;
            let tail = 0;
            dist[from] = 0;
            queue[tail++] = from;
            while (head < tail) {
                const cur = queue[head++];
                const r = rowOf(cur);
                const c = colOf(cur);
                for (const [dr, dc] of DIRS) {
                    const nr = r + dr;
                    const nc = c + dc;
                    if (!inBounds(nr, nc)) continue;
                    const next = idx(nr, nc);
                    if (dist[next] !== -1 || blocked[next]) continue;
                    dist[next] = dist[cur] + 1;
                    queue[tail++] = next;
                }
            }
            return dist;
        }

        /**
         * Simulates the body after the head walks `path` (head-first order),
         * growing by one segment because the path ends on the food.
         */
        function simulateAfterPath(body: number[], path: number[]): number[] {
            const next = path.slice().reverse().concat(body);
            return next.slice(0, body.length + 1);
        }

        /** Reconstructs the path (excluding the start) from a BFS distance map. */
        function pathTo(dist: Int16Array, target: number): number[] | null {
            if (dist[target] <= 0) return null;
            const path: number[] = [];
            let cur = target;
            while (dist[cur] > 0) {
                path.push(cur);
                const r = rowOf(cur);
                const c = colOf(cur);
                let prev = -1;
                for (const [dr, dc] of DIRS) {
                    const nr = r + dr;
                    const nc = c + dc;
                    if (!inBounds(nr, nc)) continue;
                    const cand = idx(nr, nc);
                    if (dist[cand] === dist[cur] - 1) {
                        prev = cand;
                        break;
                    }
                }
                if (prev === -1) return null;
                cur = prev;
            }
            return path.reverse();
        }

        function blockedFrom(body: number[]): Uint8Array {
            const blocked = new Uint8Array(CELLS);
            // The tail cell moves away on the next step, so it is passable.
            for (let i = 0; i < body.length - 1; i++) {
                blocked[body[i]] = 1;
            }
            return blocked;
        }

        /** True when the snake can still reach its tail from `head`. */
        function tailReachable(body: number[]): boolean {
            if (body.length < 3) return true;
            const blocked = new Uint8Array(CELLS);
            for (let i = 1; i < body.length - 1; i++) {
                blocked[body[i]] = 1;
            }
            const dist = bfs(body[0], blocked);
            return dist[body[body.length - 1]] !== -1;
        }

        /** Counts cells reachable from a candidate head position. */
        function freeSpace(body: number[], head: number): number {
            const blocked = new Uint8Array(CELLS);
            for (let i = 0; i < body.length - 1; i++) {
                blocked[body[i]] = 1;
            }
            const dist = bfs(head, blocked);
            let count = 0;
            for (let i = 0; i < CELLS; i++) {
                if (dist[i] !== -1) count++;
            }
            return count;
        }

        /** Picks the next cell for the head, or -1 when trapped. */
        function chooseMove(): number {
            const body = bodyRef.current;
            const food = foodRef.current;
            const head = body[0];

            if (food.cell >= 0) {
                const dist = bfs(head, blockedFrom(body));
                const path = pathTo(dist, food.cell);
                if (path) {
                    const grown = simulateAfterPath(body, path);
                    if (tailReachable(grown)) return path[0];
                }
            }

            // No safe path to food: chase the tail / maximise open space.
            const r = rowOf(head);
            const c = colOf(head);
            let best = -1;
            let bestScore = -1;
            for (const [dr, dc] of DIRS) {
                const nr = r + dr;
                const nc = c + dc;
                if (!inBounds(nr, nc)) continue;
                const cand = idx(nr, nc);
                if (occupiedRef.current[cand] && cand !== body[body.length - 1]) continue;
                const moved = [cand, ...body.slice(0, body.length - 1)];
                const space = freeSpace(moved, cand) + (tailReachable(moved) ? CELLS : 0);
                if (space > bestScore) {
                    bestScore = space;
                    best = cand;
                }
            }
            return best;
        }

        function step(): void {
            const next = chooseMove();
            if (next === -1) {
                phaseRef.current = 'dying';
                phaseStartRef.current = performance.now();
                highRef.current = Math.max(highRef.current, scoreRef.current);
                return;
            }

            const body = bodyRef.current;
            const occupied = occupiedRef.current;
            const head = body[0];
            dirRef.current = [rowOf(next) - rowOf(head), colOf(next) - colOf(head)];

            const ate = next === foodRef.current.cell;
            if (!ate) {
                const tail = body.pop() as number;
                occupied[tail] = 0;
            }

            if (occupied[next]) {
                phaseRef.current = 'dying';
                phaseStartRef.current = performance.now();
                highRef.current = Math.max(highRef.current, scoreRef.current);
                return;
            }

            body.unshift(next);
            occupied[next] = 1;

            if (ate) {
                scoreRef.current += foodRef.current.golden ? 50 : 10;
                placeFood();
                if (foodRef.current.cell === -1) {
                    // Board cleared - celebrate and restart.
                    scoreRef.current += 500;
                    phaseRef.current = 'over';
                    phaseStartRef.current = performance.now();
                    highRef.current = Math.max(highRef.current, scoreRef.current);
                }
            }
        }

        reset();

        function loop(now: number): void {
            let dirty = false;

            if (phaseRef.current === 'dying') {
                if (now - phaseStartRef.current >= DYING_MS) {
                    phaseRef.current = 'over';
                    phaseStartRef.current = now;
                    dirty = true;
                }
            } else if (phaseRef.current === 'over') {
                if (now - phaseStartRef.current >= OVER_MS) {
                    reset();
                    dirty = true;
                }
            } else if (now - lastStepRef.current >= SNAKE_MS) {
                lastStepRef.current = now;
                step();
                dirty = true;
            }

            if (dirty) setTick((k) => (k + 1) % 1000000);
            rafRef.current = requestAnimationFrame(loop);
        }

        rafRef.current = requestAnimationFrame(loop);
        return () => cancelAnimationFrame(rafRef.current);
    }, []);

    // ─── Sizing ──────────────────────────────────────────────

    useEffect(() => {
        const el = wrapRef.current;
        if (!el) return;
        const ro = new ResizeObserver(([entry]) => {
            const { width, height } = entry.contentRect;
            setSize({ w: width, h: height });
        });
        ro.observe(el);
        return () => ro.disconnect();
    }, []);

    // ─── Rendering ───────────────────────────────────────────

    const HUD_ROWS = 1.4;
    const FIT = 0.86;
    const { w, h } = size;
    const cell = w && h ? Math.max(6, Math.floor(Math.min((w * FIT) / GW, (h * FIT) / (GH + HUD_ROWS)))) : 0;
    const boardW = cell * GW;
    const boardH = cell * GH;
    const hudH = Math.max(18, cell * 0.95);
    const offX = (w - boardW) / 2;
    const offY = (h - (boardH + hudH)) / 2 + hudH;
    const vis = Math.min(1, opacity * 2.4);
    const dying = phaseRef.current === 'dying';

    const gridEl = useMemo(() => {
        if (!cell) return null;
        return (
            <div
                style={{
                    position: 'absolute',
                    inset: 0,
                    backgroundImage:
                        'linear-gradient(rgba(57,255,20,0.10) 1px, transparent 1px), linear-gradient(90deg, rgba(57,255,20,0.10) 1px, transparent 1px)',
                    backgroundSize: `${cell}px ${cell}px`,
                    border: '2px solid rgba(57,255,20,0.45)',
                    boxShadow: '0 0 18px rgba(57,255,20,0.35), inset 0 0 28px rgba(57,255,20,0.08)',
                    borderRadius: 4,
                    opacity: vis,
                }}
            />
        );
    }, [cell, vis]);

    const body = bodyRef.current;
    const food = foodRef.current;
    const [dr, dc] = dirRef.current;
    const headAngle = dc === 1 ? 0 : dc === -1 ? 180 : dr === 1 ? 90 : 270;
    const bodyPath = cell
        ? body
              .map((segment, i) => {
                  const x = colOf(segment) * cell + cell / 2;
                  const y = rowOf(segment) * cell + cell / 2;
                  return `${i === 0 ? 'M' : 'L'}${x} ${y}`;
              })
              .join('')
        : '';

    return (
        <div ref={wrapRef} className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            <style>{`@keyframes snake-food-pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(0.6);opacity:0.65}}`}</style>

            {/* Arcade backdrop */}
            <div
                className="absolute inset-0"
                style={{ background: 'radial-gradient(circle at 50% 45%, transparent 25%, rgba(2,10,4,0.82) 100%)', opacity }}
            />

            {cell > 0 && (
                <div style={{ position: 'absolute', left: offX, top: offY, width: boardW, height: boardH }}>
                    {gridEl}

                    {/* Food */}
                    {food.cell >= 0 && (
                        <div
                            style={{
                                position: 'absolute',
                                left: colOf(food.cell) * cell + cell * 0.2,
                                top: rowOf(food.cell) * cell + cell * 0.2,
                                width: cell * 0.6,
                                height: cell * 0.6,
                                borderRadius: '50%',
                                background: food.golden ? '#ffe600' : '#ff2d95',
                                boxShadow: food.golden
                                    ? '0 0 10px #ffe600, 0 0 20px #ffe600'
                                    : '0 0 10px #ff2d95, 0 0 18px #ff2d95',
                                animation: 'snake-food-pulse 0.75s ease-in-out infinite',
                                opacity: vis,
                            }}
                        />
                    )}

                    {/* Snake body — single SVG polyline keeps long snakes cheap to render */}
                    {body.length > 0 && (
                        <svg
                            width={boardW}
                            height={boardH}
                            style={{ position: 'absolute', left: 0, top: 0, opacity: vis }}
                        >
                            <path
                                d={bodyPath}
                                stroke={dying ? '#ff2d95' : '#39ff14'}
                                strokeWidth={cell * 0.74}
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                fill="none"
                                style={{
                                    filter: `drop-shadow(0 0 ${Math.max(2, cell * 0.28)}px ${dying ? '#ff2d95' : '#39ff14'})`,
                                }}
                            />
                            <path
                                d={bodyPath}
                                stroke={dying ? '#ff86c0' : '#aaff66'}
                                strokeWidth={cell * 0.3}
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                fill="none"
                                opacity={0.5}
                            />
                        </svg>
                    )}

                    {/* Head */}
                    {body.length > 0 && (
                        <div
                            style={{
                                position: 'absolute',
                                left: colOf(body[0]) * cell,
                                top: rowOf(body[0]) * cell,
                                width: cell,
                                height: cell,
                                transform: `rotate(${headAngle}deg)`,
                                opacity: vis,
                            }}
                        >
                            <svg viewBox="0 0 100 100" width={cell} height={cell}>
                                <rect
                                    x="8"
                                    y="10"
                                    width="84"
                                    height="80"
                                    rx="32"
                                    fill={dying ? '#ff2d95' : '#aaff66'}
                                    style={{
                                        filter: `drop-shadow(0 0 ${Math.max(3, cell * 0.35)}px ${dying ? '#ff2d95' : '#39ff14'})`,
                                    }}
                                />
                                <circle cx="68" cy="34" r="9" fill="#041206" />
                                <circle cx="68" cy="66" r="9" fill="#041206" />
                            </svg>
                        </div>
                    )}

                    {/* Game over flash */}
                    {phaseRef.current === 'over' && (
                        <div
                            style={{
                                position: 'absolute',
                                inset: 0,
                                background: 'rgba(255,45,149,0.18)',
                                border: '2px solid #ff2d95',
                                boxShadow: '0 0 40px #ff2d95, inset 0 0 30px rgba(255,45,149,0.35)',
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
                                    color: '#39ff14',
                                    textShadow: '0 0 12px #39ff14',
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
                            color: '#39ff14',
                            textShadow: '0 0 8px #39ff14',
                            opacity: Math.min(1, opacity * 2.2),
                            whiteSpace: 'nowrap',
                        }}
                    >
                        <span>SCORE {scoreRef.current}</span>
                        <span style={{ color: '#00e5ff', textShadow: '0 0 8px #00e5ff' }}>LEN {body.length}</span>
                        <span style={{ color: '#ff2d95', textShadow: '0 0 8px #ff2d95' }}>
                            HI {Math.max(highRef.current, scoreRef.current)}
                        </span>
                    </div>
                </div>
            )}
        </div>
    );
}
