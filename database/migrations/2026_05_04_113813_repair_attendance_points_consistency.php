<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-repair migration for attendance_points (audit findings).
 *
 *  1. NCNS / FTN rows must have eligible_for_gbro = false.
 *  2. Excused rows cannot also be expired — reset expiration columns.
 *  3. Active NCNS rows must use expiration_type='none' (1-year roll-off),
 *     never 'sro' (which is the 6-month rule for standard violations).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // (1) NCNS / FTN must never be GBRO-eligible.
            DB::table('attendance_points')
                ->where('point_type', 'whole_day_absence')
                ->where('eligible_for_gbro', true)
                ->update(['eligible_for_gbro' => false]);

            // (2) Excused + expired is contradictory — clear expiration flags.
            DB::table('attendance_points')
                ->where('is_excused', true)
                ->where('is_expired', true)
                ->update([
                    'is_expired' => false,
                    'expired_at' => null,
                    'gbro_applied_at' => null,
                    'gbro_batch_id' => null,
                ]);

            // (3) Active NCNS rows tagged 'sro' get reverted to 'none'.
            DB::table('attendance_points')
                ->where('point_type', 'whole_day_absence')
                ->where('is_expired', false)
                ->where('expiration_type', '!=', 'none')
                ->update(['expiration_type' => 'none']);
        });
    }

    public function down(): void
    {
        // Data-repair migration is intentionally non-reversible.
    }
};
