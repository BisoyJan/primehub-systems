import React from 'react';
import { motion } from 'framer-motion';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Users, AlertTriangle, UserX, UserMinus } from 'lucide-react';
import type { UserAccountStats } from '../types';

export interface UserAccountStatsWidgetProps {
    userAccountStats?: UserAccountStats;
}

export const UserAccountStatsWidget: React.FC<UserAccountStatsWidgetProps> = ({
    userAccountStats,
}) => {
    if (!userAccountStats) return null;

    const { total, by_role, pending_approvals, recently_deactivated, resigned } = userAccountStats;

    return (
        <motion.div
            initial={{ opacity: 0, x: 20 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3, delay: 0.1 }}
        >
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center justify-between text-sm">
                        <span className="flex items-center gap-2">
                            <Users className="h-4 w-4" />
                            User Accounts
                        </span>
                        <span className="text-lg font-bold">{total}</span>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {/* Role Breakdown */}
                    <div className="space-y-1.5">
                        {Object.entries(by_role).map(([role, count]) => (
                            <div
                                key={role}
                                className="flex items-center justify-between text-xs"
                            >
                                <span className="text-muted-foreground">{role}</span>
                                <span className="font-medium">{count}</span>
                            </div>
                        ))}
                    </div>

                    <Separator />

                    {/* Alerts */}
                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="flex items-center gap-1.5 text-xs">
                                <AlertTriangle className={`h-3.5 w-3.5 ${pending_approvals > 0 ? 'text-yellow-500' : 'text-muted-foreground'}`} />
                                Pending Approvals
                            </span>
                            {pending_approvals > 0 ? (
                                <Badge variant="outline" className="bg-yellow-500/10 text-yellow-700 border-yellow-500/30 text-[10px] px-1.5">
                                    {pending_approvals}
                                </Badge>
                            ) : (
                                <span className="text-xs text-muted-foreground">0</span>
                            )}
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="flex items-center gap-1.5 text-xs">
                                <UserMinus className={`h-3.5 w-3.5 ${resigned > 0 ? 'text-orange-500' : 'text-muted-foreground'}`} />
                                Resigned
                            </span>
                            <span className="text-xs font-medium">{resigned}</span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="flex items-center gap-1.5 text-xs">
                                <UserX className={`h-3.5 w-3.5 ${recently_deactivated > 0 ? 'text-red-500' : 'text-muted-foreground'}`} />
                                Recently Deactivated
                            </span>
                            <span className="text-xs font-medium">{recently_deactivated}</span>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </motion.div>
    );
};
