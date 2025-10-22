import { useAnimationFrame, motion } from "framer-motion";
import { useRef, useState } from "react";

interface Spinning3DCubeProps {
    size?: number;
    className?: string;
}

export function Spinning3DCube({
    size = 100,
    className = ""
}: Spinning3DCubeProps) {
    const ref = useRef<HTMLDivElement>(null);
    const [rotationSpeed] = useState(() => ({
        x: (Math.random() - 0.5) * 0.02, // Random X rotation speed (very slow)
        y: (Math.random() - 0.5) * 0.04 + 0.025, // Random Y rotation speed (very slow, slightly biased positive)
        z: (Math.random() - 0.5) * 0.015, // Random Z rotation speed (very slow)
    }));

    useAnimationFrame((t) => {
        if (!ref.current) return;
        const rotateX = t * rotationSpeed.x;
        const rotateY = t * rotationSpeed.y;
        const rotateZ = t * rotationSpeed.z;
        ref.current.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg) rotateZ(${rotateZ}deg)`;
    });

    // Define colors matching PrimeHub logo - gradient from light to dark blue
    const lightBlue = "#60a5fa"; // blue-400
    const mediumBlue = "#3b82f6"; // blue-500
    const darkBlue = "#2563eb"; // blue-600

    const createFaceStyle = () => ({
        position: "absolute" as const,
        width: size,
        height: size,
        backgroundColor: "transparent",
        border: "none",
        display: "grid",
        gridTemplateColumns: "repeat(2, 1fr)",
        gridTemplateRows: "repeat(2, 1fr)",
        gap: "4px",
        padding: "8px",
    });

    const createGridCellStyle = (color: string) => ({
        backgroundColor: color,
        borderRadius: "3px",
        border: "1px solid rgba(0, 0, 0, 0.6)",
    });

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
                        ...createFaceStyle(),
                        transform: `translateZ(${size / 2}px)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={createGridCellStyle(lightBlue)} />
                    ))}
                </motion.div>

                {/* Back face - Dark blue */}
                <motion.div
                    style={{
                        ...createFaceStyle(),
                        transform: `translateZ(-${size / 2}px) rotateY(180deg)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.1 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={createGridCellStyle(darkBlue)} />
                    ))}
                </motion.div>

                {/* Right face - Medium blue (right face in logo) */}
                <motion.div
                    style={{
                        ...createFaceStyle(),
                        transform: `rotateY(90deg) translateZ(${size / 2}px)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.2 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={createGridCellStyle(mediumBlue)} />
                    ))}
                </motion.div>

                {/* Left face - Dark blue (left face in logo) */}
                <motion.div
                    style={{
                        ...createFaceStyle(),
                        transform: `rotateY(-90deg) translateZ(${size / 2}px)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.3 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={createGridCellStyle(darkBlue)} />
                    ))}
                </motion.div>

                {/* Top face - Light blue */}
                <motion.div
                    style={{
                        ...createFaceStyle(),
                        transform: `rotateX(90deg) translateZ(${size / 2}px)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.4 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={createGridCellStyle(lightBlue)} />
                    ))}
                </motion.div>

                {/* Bottom face - Dark blue */}
                <motion.div
                    style={{
                        ...createFaceStyle(),
                        transform: `rotateX(-90deg) translateZ(${size / 2}px)`,
                    }}
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.5, delay: 0.5 }}
                >
                    {[...Array(4)].map((_, i) => (
                        <div key={i} style={createGridCellStyle(darkBlue)} />
                    ))}
                </motion.div>
            </div>
        </div>
    );
}
