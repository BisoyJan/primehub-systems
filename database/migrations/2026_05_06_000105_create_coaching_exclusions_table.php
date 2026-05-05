<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coaching_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('reason'); // enum-like; values managed in App\Models\CoachingExclusion
            $table->text('notes')->nullable();
            $table->foreignId('excluded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('excluded_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('revoke_notes')->nullable();
            $table->timestamps();

            // Composite index used by "active exclusion" lookups.
            $table->index(['user_id', 'revoked_at', 'expires_at'], 'coaching_exclusions_active_idx');
            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coaching_exclusions');
    }
};
