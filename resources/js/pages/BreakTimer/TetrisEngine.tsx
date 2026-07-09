import { useEffect, useRef, useState } from 'react';

/**
 * A real, self-playing Tetris engine rendered as an ambient background.
 * Implements standard mechanics: 10x20 board, 7-bag randomizer, SRS-style
 * rotation states with wall kicks, gravity, line clearing, scoring, and a
 * heuristic auto-player (El-Tetris weights) so it plays like a real game.
 *
 * Purely visual: pointer-events-none, aria-hidden. No user interaction.
 */

const COLS = 10;
const ROWS = 20;

type Cell = string | null;

interface PieceDef {
    color: string;
    /** Each rotation state is a list of 4 [row, col] cell offsets. */
    rotations: [number, number][][];
}

const PIECES: Record<string, PieceDef> = {
    I: {
        color: '#00d4ff',
        rotations: [
            [[1, 0], [1, 1], [1, 2], [1, 3]],
            [[0, 2], [1, 2], [2, 2], [3, 2]],
            [[2, 0], [2, 1], [2, 2], [2, 3]],
            [[0, 1], [1, 1], [2, 1], [3, 1]],
        ],
    },
    O: {
        color: '#ffe600',
        rotations: [
            [[0, 1], [0, 2], [1, 1], [1, 2]],
            [[0, 1], [0, 2], [1, 1], [1, 2]],
            [[0, 1], [0, 2], [1, 1], [1, 2]],
            [[0, 1], [0, 2], [1, 1], [1, 2]],
        ],
    },
    T: {
        color: '#b026ff',
        rotations: [
            [[0, 1], [1, 0], [1, 1], [1, 2]],
            [[0, 1], [1, 1], [1, 2], [2, 1]],
            [[1, 0], [1, 1], [1, 2], [2, 1]],
            [[0, 1], [1, 0], [1, 1], [2, 1]],
        ],
    },
    S: {
        color: '#39ff14',
        rotations: [
            [[0, 1], [0, 2], [1, 0], [1, 1]],
            [[0, 1], [1, 1], [1, 2], [2, 2]],
            [[1, 1], [1, 2], [2, 0], [2, 1]],
            [[0, 0], [1, 0], [1, 1], [2, 1]],
        ],
    },
    Z: {
        color: '#ff2d95',
        rotations: [
            [[0, 0], [0, 1], [1, 1], [1, 2]],
            [[0, 2], [1, 1], [1, 2], [2, 1]],
            [[1, 0], [1, 1], [2, 1], [2, 2]],
            [[0, 1], [1, 0], [1, 1], [2, 0]],
        ],
    },
    J: {
        color: '#2979ff',
        rotations: [
            [[0, 0], [1, 0], [1, 1], [1, 2]],
            [[0, 1], [0, 2], [1, 1], [2, 1]],
            [[1, 0], [1, 1], [1, 2], [2, 2]],
            [[0, 1], [1, 1], [2, 0], [2, 1]],
        ],
    },
    L: {
        color: '#ff8a00',
        rotations: [
            [[0, 2], [1, 0], [1, 1], [1, 2]],
            [[0, 1], [1, 1], [2, 1], [2, 2]],
            [[1, 0], [1, 1], [1, 2], [2, 0]],
            [[0, 0], [0, 1], [1, 1], [2, 1]],
        ],
    },
};

const PIECE_KEYS = Object.keys(PIECES);

const STEP_MS = 70; // time between auto-player actions
const CLEAR_MS = 170; // line-clear flash duration
const OVER_MS = 650; // game-over flash duration
const SCORE_TABLE = [0, 40, 100, 300, 1200];

interface ActivePiece {
    key: string;
    rot: number;
    r: number;
    c: number;
}

function emptyBoard(): Cell[][] {
    return Array.from({ length: ROWS }, () => Array<Cell>(COLS).fill(null));
}

function cellsOf(p: ActivePiece): [number, number][] {
    return PIECES[p.key].rotations[p.rot].map(([dr, dc]) => [p.r + dr, p.c + dc]);
}

function collides(board: Cell[][], p: ActivePiece): boolean {
    for (const [r, c] of cellsOf(p)) {
        if (c < 0 || c >= COLS || r >= ROWS) return true;
        if (r >= 0 && board[r][c]) return true;
    }
    return false;
}

function makeBag(): string[] {
    const bag = [...PIECE_KEYS];
    for (let i = bag.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [bag[i], bag[j]] = [bag[j], bag[i]];
    }
    return bag;
}

interface Metrics {
    agg: number;
    holes: number;
    bump: number;
}

function boardMetrics(board: Cell[][]): Metrics {
    const heights = new Array(COLS).fill(0);
    for (let c = 0; c < COLS; c++) {
        for (let r = 0; r < ROWS; r++) {
            if (board[r][c]) {
                heights[c] = ROWS - r;
                break;
            }
        }
    }
    let holes = 0;
    for (let c = 0; c < COLS; c++) {
        let seen = false;
        for (let r = 0; r < ROWS; r++) {
            if (board[r][c]) seen = true;
            else if (seen) holes++;
        }
    }
    let agg = 0;
    for (const h of heights) agg += h;
    let bump = 0;
    for (let c = 0; c < COLS - 1; c++) bump += Math.abs(heights[c] - heights[c + 1]);
    return { agg, holes, bump };
}

/** Heuristic planner: choose the best {rot, col} landing for the piece. */
function planMove(board: Cell[][], piece: ActivePiece): { rot: number; c: number } | null {
    let best: { rot: number; c: number } | null = null;
    let bestScore = -Infinity;

    for (let rot = 0; rot < 4; rot++) {
        const offsets = PIECES[piece.key].rotations[rot];
        const minDc = Math.min(...offsets.map((o) => o[1]));
        const maxDc = Math.max(...offsets.map((o) => o[1]));

        for (let c = -minDc; c < COLS - maxDc; c++) {
            const t: ActivePiece = { key: piece.key, rot, r: -2, c };
            while (!collides(board, { ...t, r: t.r + 1 })) t.r++;
            if (collides(board, t)) continue;

            const sim = board.map((row) => row.slice());
            let ok = true;
            for (const [rr, cc] of cellsOf(t)) {
                if (rr < 0) {
                    ok = false;
                    break;
                }
                sim[rr][cc] = '1';
            }
            if (!ok) continue;

            let lines = 0;
            for (let r = 0; r < ROWS; r++) {
                if (sim[r].every((x) => x)) lines++;
            }
            const m = boardMetrics(sim);
            const score =
                -0.510066 * m.agg + 0.760666 * lines - 0.35663 * m.holes - 0.184483 * m.bump;
            if (score > bestScore) {
                bestScore = score;
                best = { rot, c };
            }
        }
    }
    return best;
}

/** Pick a random legal landing — used to simulate human mistakes. */
function pickRandomMove(board: Cell[][], piece: ActivePiece): { rot: number; c: number } | null {
    const options: { rot: number; c: number }[] = [];
    for (let rot = 0; rot < 4; rot++) {
        const offsets = PIECES[piece.key].rotations[rot];
        const minDc = Math.min(...offsets.map((o) => o[1]));
        const maxDc = Math.max(...offsets.map((o) => o[1]));
        for (let c = -minDc; c < COLS - maxDc; c++) {
            const t: ActivePiece = { key: piece.key, rot, r: -2, c };
            while (!collides(board, { ...t, r: t.r + 1 })) t.r++;
            if (collides(board, t)) continue;
            let ok = true;
            for (const [rr] of cellsOf(t)) {
                if (rr < 0) {
                    ok = false;
                    break;
                }
            }
            if (ok) options.push({ rot, c });
        }
    }
    if (options.length === 0) return null;
    return options[Math.floor(Math.random() * options.length)];
}

function tryRotate(board: Cell[][], p: ActivePiece): ActivePiece | null {
    const nrot = (p.rot + 1) % 4;
    for (const dc of [0, -1, 1, -2, 2]) {
        const np = { ...p, rot: nrot, c: p.c + dc };
        if (!collides(board, np)) return np;
    }
    return null;
}

function ghostOf(board: Cell[][], p: ActivePiece): ActivePiece {
    const g = { ...p };
    while (!collides(board, { ...g, r: g.r + 1 })) g.r++;
    return g;
}

export function TetrisEngine({ opacity }: { opacity: number }) {
    const wrapRef = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ w: 0, h: 0 });

    const boardRef = useRef<Cell[][]>(emptyBoard());
    const pieceRef = useRef<ActivePiece | null>(null);
    const targetRef = useRef<{ rot: number; c: number } | null>(null);
    const bagRef = useRef<string[]>(makeBag());
    const phaseRef = useRef<'play' | 'clear' | 'over'>('play');
    const clearRowsRef = useRef<number[]>([]);
    const clearStartRef = useRef(0);
    const overStartRef = useRef(0);
    const lastStepRef = useRef(0);
    const scoreRef = useRef(0);
    const linesRef = useRef(0);
    const rafRef = useRef(0);
    const [, setTick] = useState(0);

    // Measure container. Clamp to the visible viewport height below the
    // container's top so the board can't balloon past the fold if the parent
    // (min-h-[...]) grows taller than the viewport due to page content.
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
        function nextKey(): string {
            if (bagRef.current.length === 0) bagRef.current = makeBag();
            return bagRef.current.pop()!;
        }

        function spawn(): ActivePiece {
            return { key: nextKey(), rot: 0, r: -1, c: 3 };
        }

        function lockPiece() {
            const board = boardRef.current;
            const p = pieceRef.current!;
            const color = PIECES[p.key].color;
            for (const [r, c] of cellsOf(p)) {
                if (r >= 0 && r < ROWS && c >= 0 && c < COLS) board[r][c] = color;
            }
            const full: number[] = [];
            for (let r = 0; r < ROWS; r++) {
                if (board[r].every((x) => x)) full.push(r);
            }
            pieceRef.current = null;
            targetRef.current = null;
            if (full.length) {
                clearRowsRef.current = full;
                clearStartRef.current = performance.now();
                phaseRef.current = 'clear';
                scoreRef.current += SCORE_TABLE[full.length] ?? 0;
                linesRef.current += full.length;
            }
        }

        function removeRows() {
            const board = boardRef.current;
            const rows = clearRowsRef.current;
            const remaining = board.filter((_, r) => !rows.includes(r));
            while (remaining.length < ROWS) remaining.unshift(Array<Cell>(COLS).fill(null));
            boardRef.current = remaining;
            clearRowsRef.current = [];
            phaseRef.current = 'play';
        }

        function step() {
            const board = boardRef.current;
            let p = pieceRef.current;

            if (!p) {
                const np = spawn();
                if (collides(board, np)) {
                    // Top out — trigger a game-over flash, then reset.
                    phaseRef.current = 'over';
                    overStartRef.current = performance.now();
                    return;
                }
                pieceRef.current = np;
                // Play imperfectly like a human: 10%–25% of pieces use a random
                // (sub-optimal) placement, which builds the stack higher and can
                // eventually top out and fail.
                const mistakeChance = 0.1 + Math.random() * 0.15;
                const plan = Math.random() < mistakeChance
                    ? pickRandomMove(boardRef.current, np)
                    : planMove(boardRef.current, np);
                targetRef.current = plan ?? planMove(boardRef.current, np) ?? { rot: np.rot, c: np.c };
                return;
            }

            const t = targetRef.current!;

            if (p.rot !== t.rot) {
                const np = tryRotate(board, p);
                if (np) {
                    pieceRef.current = np;
                    return;
                }
            } else if (p.c !== t.c) {
                const dir = t.c > p.c ? 1 : -1;
                const np = { ...p, c: p.c + dir };
                if (!collides(board, np)) {
                    pieceRef.current = np;
                    return;
                }
            }

            // Gravity
            const down = { ...p, r: p.r + 1 };
            if (!collides(board, down)) {
                pieceRef.current = down;
                return;
            }
            lockPiece();
            p = pieceRef.current;
        }

        function loop(now: number) {
            if (phaseRef.current === 'over') {
                if (now - overStartRef.current >= OVER_MS) {
                    boardRef.current = emptyBoard();
                    bagRef.current = makeBag();
                    pieceRef.current = null;
                    targetRef.current = null;
                    scoreRef.current = 0;
                    linesRef.current = 0;
                    phaseRef.current = 'play';
                }
            } else if (phaseRef.current === 'clear') {
                if (now - clearStartRef.current >= CLEAR_MS) removeRows();
            } else if (now - lastStepRef.current >= STEP_MS) {
                lastStepRef.current = now;
                step();
            }
            setTick((k) => (k + 1) % 1000000);
            rafRef.current = requestAnimationFrame(loop);
        }

        rafRef.current = requestAnimationFrame(loop);
        return () => cancelAnimationFrame(rafRef.current);
    }, []);

    // ─── Rendering ───────────────────────────────────────────
    const { w, h } = size;
    const cell = w && h ? Math.max(8, Math.floor(Math.min((w * 0.9) / COLS, (h * 0.94) / ROWS))) : 0;
    const boardW = cell * COLS;
    const boardH = cell * ROWS;
    const boardLeft = (w - boardW) / 2;
    const boardTop = (h - boardH) / 2;

    const board = boardRef.current;
    const piece = pieceRef.current;
    const clearing = clearRowsRef.current;
    const vis = Math.min(1, opacity * 2.4); // boost block visibility behind glass card

    const ghost = piece && phaseRef.current === 'play' ? ghostOf(board, piece) : null;

    function blockStyle(color: string, r: number, c: number): React.CSSProperties {
        return {
            position: 'absolute',
            left: c * cell,
            top: r * cell,
            width: cell - 2,
            height: cell - 2,
            borderRadius: 3,
            background: `linear-gradient(135deg, ${color}, ${color}aa)`,
            border: `1px solid ${color}`,
            boxShadow: `0 0 5px ${color}, 0 0 12px ${color}66, inset 0 0 4px rgba(255,255,255,0.4)`,
        };
    }

    return (
        <div ref={wrapRef} className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            {/* Matrix grid background */}
            <div
                className="absolute inset-0"
                style={{
                    backgroundImage:
                        'linear-gradient(rgba(0,212,255,0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(176,38,255,0.05) 1px, transparent 1px)',
                    backgroundSize: `${cell || 22}px ${cell || 22}px`,
                    opacity: opacity * 0.7,
                }}
            />
            <div
                className="absolute inset-0"
                style={{
                    background: 'radial-gradient(circle at 50% 45%, transparent 25%, rgba(5,3,20,0.75) 100%)',
                    opacity,
                }}
            />

            {cell > 0 && (
                <div style={{ position: 'absolute', left: boardLeft, top: boardTop, width: boardW, height: boardH }}>
                    {/* Well panel */}
                    <div
                        className="absolute inset-0"
                        style={{
                            background: 'rgba(10,6,26,0.35)',
                            border: '1px solid rgba(0,212,255,0.25)',
                            boxShadow: '0 0 30px rgba(0,212,255,0.15), inset 0 0 40px rgba(176,38,255,0.08)',
                            borderRadius: 4,
                        }}
                    />
                    {/* Column grid lines */}
                    <div
                        className="absolute inset-0"
                        style={{
                            backgroundImage:
                                'linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px)',
                            backgroundSize: `${cell}px ${cell}px`,
                            opacity: opacity * 0.9,
                        }}
                    />

                    {/* Ghost piece */}
                    {ghost &&
                        cellsOf(ghost).map(([r, c], i) =>
                            r < 0 ? null : (
                                <div
                                    key={`ghost-${i}`}
                                    style={{
                                        position: 'absolute',
                                        left: c * cell,
                                        top: r * cell,
                                        width: cell - 2,
                                        height: cell - 2,
                                        borderRadius: 3,
                                        border: `1px dashed ${PIECES[ghost.key].color}`,
                                        opacity: opacity * 0.6,
                                    }}
                                />
                            ),
                        )}

                    {/* Locked cells */}
                    {board.map((row, r) =>
                        row.map((color, c) =>
                            color ? (
                                <div key={`b-${r}-${c}`} style={{ ...blockStyle(color, r, c), opacity: vis }} />
                            ) : null,
                        ),
                    )}

                    {/* Active piece */}
                    {piece &&
                        cellsOf(piece).map(([r, c], i) =>
                            r < 0 ? null : (
                                <div
                                    key={`p-${i}`}
                                    style={{ ...blockStyle(PIECES[piece.key].color, r, c), opacity: vis }}
                                />
                            ),
                        )}

                    {/* Line-clear flash */}
                    {phaseRef.current === 'clear' &&
                        clearing.map((r) => (
                            <div
                                key={`clr-${r}`}
                                style={{
                                    position: 'absolute',
                                    left: 0,
                                    top: r * cell,
                                    width: boardW,
                                    height: cell,
                                    background: 'linear-gradient(90deg, #ffffff, #00d4ff, #ffffff)',
                                    boxShadow: '0 0 20px #00d4ff, 0 0 40px #ffffff',
                                    borderRadius: 2,
                                    mixBlendMode: 'screen',
                                    opacity: Math.min(1, opacity * 3),
                                }}
                            />
                        ))}

                    {/* Game over flash */}
                    {phaseRef.current === 'over' && (
                        <div
                            style={{
                                position: 'absolute',
                                inset: 0,
                                background: 'rgba(255,45,149,0.22)',
                                border: '2px solid #ff2d95',
                                boxShadow: '0 0 40px #ff2d95, inset 0 0 30px rgba(255,45,149,0.4)',
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
                                    color: '#ff2d95',
                                    textShadow: '0 0 12px #ff2d95',
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
                            top: -Math.max(18, cell * 0.9),
                            left: 2,
                            fontFamily: 'monospace',
                            fontSize: Math.max(9, cell * 0.42),
                            fontWeight: 700,
                            letterSpacing: 1,
                            color: '#00d4ff',
                            textShadow: '0 0 8px #00d4ff',
                            opacity: Math.min(1, opacity * 2.2),
                            whiteSpace: 'nowrap',
                        }}
                    >
                        SCORE {scoreRef.current}  ·  LINES {linesRef.current}
                    </div>
                </div>
            )}
        </div>
    );
}
