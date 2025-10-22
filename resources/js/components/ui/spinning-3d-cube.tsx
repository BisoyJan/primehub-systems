import { useAnimationFrame, motion } from "framer-motion";
import { useRef } from "react";

interface Spinning3DCubeProps {
    size?: number;
    className?: string;
}

export function Spinning3DCube({
    size = 100,
    className = ""
}: Spinning3DCubeProps) {
    const ref = useRef<HTMLDivElement>(null);

    useAnimationFrame((t) => {
        if (!ref.current) return;
        const rotateX = Math.cos(t / 3000) * 25;
        const rotateY = (t / 20) % 360;
        ref.current.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
    });

    // Define colors matching PrimeHub logo - gradient from light to dark blue
    const lightBlue = "#60a5fa"; // blue-400
    const mediumBlue = "#3b82f6"; // blue-500
    const darkBlue = "#2563eb"; // blue-600

    const createFaceStyle = (backgroundColor: string) => ({
        position: "absolute" as const,
        width: size,
        height: size,
        backgroundColor,
        border: "2px solid rgba(255, 255, 255, 0.3)",
        display: "grid",
        gridTemplateColumns: "repeat(2, 1fr)",
        gridTemplateRows: "repeat(2, 1fr)",
        gap: "4px",
        padding: "8px",
    });

    const gridCellStyle = {
        backgroundColor: "rgba(255, 255, 255, 0.2)",
        borderRadius: "3px",
        border: "1px solid rgba(255, 255, 255, 0.4)",
    };

    return (
        <div className={`flex items-center justify-center ${className}`} style={{ perspective: "1000px" }}>
            <div
                ref={ref}
                style={{
                    width: size,
                    height: size,
                    position: "relative",
                    transformStyle: "preserve-3d",
                }}
            >
                {/* Front face - Light blue (top face in logo) */}
                <motion.div
                    style={{
                        ...createFaceStyle(lightBlue),
                        transform: `translateZ(${size / 2}px)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={gridCellStyle} />
                    ))}
                </motion.div>

                {/* Back face - Dark blue */}
                <motion.div
                    style={{
                        ...createFaceStyle(darkBlue),
                        transform: `translateZ(-${size / 2}px) rotateY(180deg)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.1 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={gridCellStyle} />
                    ))}
                </motion.div>

                {/* Right face - Medium blue (right face in logo) */}
                <motion.div
                    style={{
                        ...createFaceStyle(mediumBlue),
                        transform: `rotateY(90deg) translateZ(${size / 2}px)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.2 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={gridCellStyle} />
                    ))}
                </motion.div>

                {/* Left face - Dark blue (left face in logo) */}
                <motion.div
                    style={{
                        ...createFaceStyle(darkBlue),
                        transform: `rotateY(-90deg) translateZ(${size / 2}px)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.3 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={gridCellStyle} />
                    ))}
                </motion.div>

                {/* Top face - Light blue */}
                <motion.div
                    style={{
                        ...createFaceStyle(lightBlue),
                        transform: `rotateX(90deg) translateZ(${size / 2}px)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.4 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={gridCellStyle} />
                    ))}
                </motion.div>

                {/* Bottom face - Dark blue */}
                <motion.div
                    style={{
                        ...createFaceStyle(darkBlue),
                        transform: `rotateX(-90deg) translateZ(${size / 2}px)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.5 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={gridCellStyle} />
                    ))}
                </motion.div>
            </div>
        </div>
    );
}
