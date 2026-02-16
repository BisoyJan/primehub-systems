import React from 'react';
import { motion } from 'framer-motion';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export interface StatCardProps {
    title: string;
    value: React.ReactNode;
    icon: React.ComponentType<{ className?: string }>;
    description?: string;
    onClick: () => void;
    variant?: 'default' | 'warning' | 'danger' | 'success';
    delay?: number;
}

const variantStyles: Record<NonNullable<StatCardProps['variant']>, string> = {
    default: 'hover:border-primary/50',
    warning: 'border-orange-500/30 hover:border-orange-500/50',
    danger: 'border-red-500/30 hover:border-red-500/50',
    success: 'border-green-500/30 hover:border-green-500/50',
};

export const StatCard: React.FC<StatCardProps> = ({
    title,
    value,
    icon: Icon,
    description,
    onClick,
    variant = 'default',
    delay = 0,
}) => {
    return (
        <motion.div
            layout
            initial={{ opacity: 0, y: 20, scale: 0.95 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            whileHover={{ scale: 1.04, y: -4 }}
            transition={{ duration: 0.3, type: 'spring', stiffness: 200, delay }}
        >
            <Card
                className={`cursor-pointer transition-all hover:shadow-lg ${variantStyles[variant]}`}
                onClick={onClick}
            >
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium">{title}</CardTitle>
                    <Icon className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div className="text-2xl font-bold">{value}</div>
                    {description && (
                        <p className="text-xs text-muted-foreground mt-1">{description}</p>
                    )}
                </CardContent>
            </Card>
        </motion.div>
    );
};
