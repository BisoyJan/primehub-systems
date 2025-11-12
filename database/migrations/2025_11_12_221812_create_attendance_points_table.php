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
        Schema::create('attendance_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('attendance_id')->constrained()->onDelete('cascade');
            $table->date('shift_date');
            $table->enum('point_type', ['whole_day_absence', 'half_day_absence', 'undertime', 'tardy']);
            $table->decimal('points', 3, 2); // 1.00, 0.50, 0.25
            $table->string('status')->nullable(); // attendance status reference
            $table->boolean('is_advised')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_excused')->default(false);
            $table->foreignId('excused_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('excused_at')->nullable();
            $table->text('excuse_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'shift_date']);
            $table->index('point_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_points');
    }
};
