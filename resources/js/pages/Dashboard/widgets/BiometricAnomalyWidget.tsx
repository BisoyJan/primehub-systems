import React from 'react';
import { motion } from 'framer-motion';
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
    AlertTriangle,
    MapPin,
    Timer,
    Copy,
    Clock,
    Repeat,
    ChevronRight,
} from 'lucide-react';
import type { BiometricAnomalies } from '../types';

export interface BiometricAnomalyWidgetProps {
    biometricAnomalies?: BiometricAnomalies;
}

interface AnomalyItem {
    key: keyof Omit<BiometricAnomalies, 'total'>;
    label: string;
    icon: React.ComponentType<{ className?: string }>;
}

const ANOMALY_ITEMS: AnomalyItem[] = [
    { key: 'simultaneous_sites', label: 'Simultaneous Sites', icon: MapPin },
    { key: 'impossible_gaps', label: 'Impossible Gaps', icon: Timer },
    { key: 'duplicate_scans', label: 'Duplicate Scans', icon: Copy },
    { key: 'unusual_hours', label: 'Unusual Hours', icon: Clock },
    { key: 'excessive_scans', label: 'Excessive Scans', icon: Repeat },
];

export const BiometricAnomalyWidget: React.FC<BiometricAnomalyWidgetProps> = ({
    biometricAnomalies,
}) => {
    if (!biometricAnomalies) return null;

    const { total } = biometricAnomalies;
    const hasAnomalies = total > 0;

    return (
        <motion.div
            initial={{ opacity: 0, x: 20 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3, delay: 0.3 }}
        >
            <Card className={hasAnomalies ? 'border-yellow-500/30' : ''}>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center justify-between text-sm">
                        <span className="flex items-center gap-2">
                            <AlertTriangle className={`h-4 w-4 ${hasAnomalies ? 'text-yellow-500' : 'text-muted-foreground'}`} />
                            Biometric Anomalies
                        </span>
                        {hasAnomalies ? (
                            <Badge variant="outline" className="bg-yellow-500/10 text-yellow-700 border-yellow-500/30 text-[10px] px-1.5">
                                {total}
                            </Badge>
                        ) : (
                            <Badge variant="outline" className="bg-green-500/10 text-green-700 border-green-500/30 text-[10px] px-1.5">
                                Clear
                            </Badge>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                    {ANOMALY_ITEMS.map(({ key, label, icon: Icon }) => {
                        const count = biometricAnomalies[key];
                        return (
                            <div
                                key={key}
                                className="flex items-center justify-between text-xs"
                            >
                                <span className="flex items-center gap-2 text-muted-foreground">
                                    <Icon className={`h-3.5 w-3.5 ${count > 0 ? 'text-yellow-500' : ''}`} />
                                    {label}
                                </span>
                                <span className={`font-medium ${count > 0 ? 'text-yellow-700' : 'text-muted-foreground'}`}>
                                    {count}
                                </span>
                            </div>
                        );
                    })}

                    {hasAnomalies && (
                        <Link
                            href="/attendance/anomalies"
                            className="flex items-center justify-center gap-1 text-xs text-primary hover:underline pt-2"
                        >
                            View Details
                            <ChevronRight className="h-3 w-3" />
                        </Link>
                    )}
                </CardContent>
            </Card>
        </motion.div>
    );
};
