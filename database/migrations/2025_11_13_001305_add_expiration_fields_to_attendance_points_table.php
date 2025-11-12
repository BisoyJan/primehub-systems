<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_points', function (Blueprint $table) {
            // Expiration tracking
            $table->date('expires_at')->nullable()->after('is_excused')->index();
            $table->enum('expiration_type', ['sro', 'gbro', 'none'])->default('sro')->after('expires_at');
            $table->boolean('is_expired')->default(false)->after('expiration_type')->index();
            $table->date('expired_at')->nullable()->after('is_expired');

            // Violation details
            $table->text('violation_details')->nullable()->after('notes');
            $table->integer('tardy_minutes')->nullable()->after('violation_details');
            $table->integer('undertime_minutes')->nullable()->after('tardy_minutes');

            // GBRO tracking
            $table->boolean('eligible_for_gbro')->default(true)->after('undertime_minutes');
            $table->date('gbro_applied_at')->nullable()->after('eligible_for_gbro');
            $table->string('gbro_batch_id')->nullable()->after('gbro_applied_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_points', function (Blueprint $table) {
            $table->dropColumn([
                'expires_at',
                'expiration_type',
                'is_expired',
                'expired_at',
                'violation_details',
                'tardy_minutes',
                'undertime_minutes',
                'eligible_for_gbro',
                'gbro_applied_at',
                'gbro_batch_id',
            ]);
        });
    }
};
