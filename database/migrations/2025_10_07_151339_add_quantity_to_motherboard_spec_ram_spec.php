<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motherboard_spec_ram_spec', function (Blueprint $table) {
            if (! Schema::hasColumn('motherboard_spec_ram_spec', 'quantity')) {
                $table->unsignedInteger('quantity')->default(1)->after('ram_spec_id');
            }
            // add pivot timestamps if they don't exist
            if (! Schema::hasColumn('motherboard_spec_ram_spec', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        Schema::table('motherboard_spec_ram_spec', function (Blueprint $table) {
            if (Schema::hasColumn('motherboard_spec_ram_spec', 'quantity')) {
                $table->dropColumn('quantity');
            }
            if (Schema::hasColumn('motherboard_spec_ram_spec', 'created_at')) {
                $table->dropTimestamps();
            }
        });
    }
};
