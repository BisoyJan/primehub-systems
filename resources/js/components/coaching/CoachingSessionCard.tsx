import { Link } from '@inertiajs/react';
import { Calendar, User2, Eye } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { AckStatusBadge, ComplianceStatusBadge, SeverityBadge } from './CoachingStatusBadge';
import type { CoachingSession, CoachingPurposeLabels } from '@/types';
import { show } from '@/routes/coaching/sessions';

interface CoachingSessionCardProps {
    session: CoachingSession;
    purposes: CoachingPurposeLabels;
    showAgent?: boolean;
    showTeamLead?: boolean;
}

export function CoachingSessionCard({
    session,
    purposes,
    showAgent = true,
    showTeamLead = false,
}: CoachingSessionCardProps) {
    return (
        <div className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
            <div className="flex items-start justify-between gap-2">
                <div className="space-y-1">
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Calendar className="h-3.5 w-3.5" />
                        <span>{new Date(session.session_date).toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' })}</span>
                    </div>
                    {showAgent && session.agent && (
                        <div className="flex items-center gap-2">
                            <User2 className="h-3.5 w-3.5 text-muted-foreground" />
                            <span className="font-medium text-sm">
                                {session.agent.first_name} {session.agent.last_name}
                            </span>
                        </div>
                    )}
                    {showTeamLead && session.team_lead && (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <User2 className="h-3.5 w-3.5" />
                            <span>TL: {session.team_lead.first_name} {session.team_lead.last_name}</span>
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

            <div className="pt-2 border-t">
                <Link href={show.url(session.id)}>
                    <Button variant="outline" size="sm" className="w-full">
                        <Eye className="h-3.5 w-3.5 mr-1.5" />
                        View Details
                    </Button>
                </Link>
            </div>
        </div>
    );
}
