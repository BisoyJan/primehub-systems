<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case OnTime = 'on_time';
    case Tardy = 'tardy';
    case HalfDayAbsence = 'half_day_absence';
    case AdvisedAbsence = 'advised_absence';
    case Ncns = 'ncns';
    case Undertime = 'undertime';
    case UndertimeMoreThanHour = 'undertime_more_than_hour';
    case FailedBioIn = 'failed_bio_in';
    case FailedBioOut = 'failed_bio_out';
    case PresentNoBio = 'present_no_bio';
    case NonWorkDay = 'non_work_day';
    case OnLeave = 'on_leave';
    /** System-assigned when the processor detects ambiguous scan patterns. Not settable via verify form. */
    case NeedsManualReview = 'needs_manual_review';

    /** Validation rule string for the primary status field. */
    public static function validationIn(): string
    {
        return 'in:'.implode(',', array_column(self::cases(), 'value'));
    }
}
