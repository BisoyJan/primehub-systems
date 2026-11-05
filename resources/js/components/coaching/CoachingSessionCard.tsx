import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/utils';
import { show } from '@/routes/coaching/sessions';
import type { CoachingPurposeLabels, CoachingSession } from '@/types';
import { Link } from '@inertiajs/react';
import { Calendar, Eye, User2 } from 'lucide-react';
import {
    AckStatusBadge,
    ComplianceStatusBadge,
    SeverityBadge,
} from './CoachingStatusBadge';

interface CoachingSessionCardProps {
    session: CoachingSession;
    purposes: CoachingPurposeLabels;
    showCoachee?: boolean;
    showCoach?: boolean;
}

export function CoachingSessionCard({
    session,
    purposes,
    showCoachee = true,
    showCoach = false,
}: CoachingSessionCardProps) {
    return (
        <div className="space-y-3 rounded-lg border bg-card p-4 shadow-sm">
            <div className="flex items-start justify-between gap-2">
                <div className="space-y-1">
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Calendar className="h-3.5 w-3.5" />
                        <span>{formatDate(session.session_date)}</span>
                    </div>
                    {showCoachee && session.coachee && (
                        <div className="flex items-center gap-2">
                            <User2 className="h-3.5 w-3.5 text-muted-foreground" />
                            <span className="text-sm font-medium">
                                {session.coachee.first_name}{' '}
                                {session.coachee.last_name}
                            </span>
                        </div>
                    )}
                    {showCoach && session.coach && (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <User2 className="h-3.5 w-3.5" />
                            <span>
                                Coach: {session.coach.first_name}{' '}
                                {session.coach.last_name}
                            </span>
                        </div>
                    )}
                </div>
                <SeverityBadge flag={session.severity_flag} />
            </div>

            <p className="text-sm text-muted-foreground">
                {purposes[session.purpose] ?? session.purpose}
            </p>

            <div className="flex flex-wrap items-center gap-2">
                <AckStatusBadge status={session.ack_status} />
                <ComplianceStatusBadge status={session.compliance_status} />
            </div>

            <div className="border-t pt-2">
                <Link href={show.url(session.id)}>
                    <Button variant="outline" size="sm" className="w-full">
                        <Eye className="mr-1.5 h-3.5 w-3.5" />
                        View Details
                    </Button>
                </Link>
            </div>
        </div>
    );
}
