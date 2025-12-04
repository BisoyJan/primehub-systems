"use client";

import { motion, Variants } from "framer-motion";
import { cn } from "@/lib/utils";
import { ElementType } from "react";

type AnimationType = "blurIn" | "blurInUp" | "blurInDown";

interface TextAnimateProps {
    children: string;
    animation?: AnimationType;
    as?: ElementType;
    className?: string;
    delay?: number;
    duration?: number;
}

const blurInVariants: Variants = {
    hidden: { filter: "blur(10px)", opacity: 0 },
    visible: { filter: "blur(0px)", opacity: 1 },
};

const blurInUpVariants: Variants = {
    hidden: { filter: "blur(10px)", opacity: 0, y: 20 },
    visible: { filter: "blur(0px)", opacity: 1, y: 0 },
};

const blurInDownVariants: Variants = {
    hidden: { filter: "blur(10px)", opacity: 0, y: -20 },
    visible: { filter: "blur(0px)", opacity: 1, y: 0 },
};

const getVariants = (animation: AnimationType): Variants => {
    switch (animation) {
        case "blurInUp":
            return blurInUpVariants;
        case "blurInDown":
            return blurInDownVariants;
        case "blurIn":
        default:
            return blurInVariants;
    }
};

export function TextAnimate({
    children,
    animation = "blurIn",
    as: Component = "p",
    className,
    delay = 0,
    duration = 9,
}: TextAnimateProps) {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const MotionComponent = motion[Component as keyof typeof motion] as any;

    return (
        <MotionComponent
            initial="hidden"
            animate="visible"
            variants={getVariants(animation)}
            transition={{
                duration,
                delay,
                ease: "easeOut",
            }}
            className={cn(className)}
        >
            {children}
        </MotionComponent>
    );
}
