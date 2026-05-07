<?php

namespace App\Enums;

enum AttendanceSecondaryStatus: string
{
    case Undertime = 'undertime';
    case UndertimeMoreThanHour = 'undertime_more_than_hour';
    case FailedBioOut = 'failed_bio_out';

    /** Validation rule string for the secondary_status field. */
    public static function validationIn(): string
    {
        return 'in:'.implode(',', array_column(self::cases(), 'value'));
    }
}
