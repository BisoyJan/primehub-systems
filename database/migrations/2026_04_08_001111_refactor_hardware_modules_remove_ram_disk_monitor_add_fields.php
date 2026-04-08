<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add new columns to pc_specs
        Schema::table('pc_specs', function (Blueprint $table) {
            $table->unsignedInteger('ram_gb')->default(0)->after('sata_ports');
            $table->unsignedInteger('disk_gb')->default(0)->after('ram_gb');
            $table->string('available_ports')->nullable()->after('disk_gb');
        });

        // 2. Migrate existing RAM data: sum capacity_gb * quantity from pivot
        DB::statement('
            UPDATE pc_specs
            SET ram_gb = COALESCE((
                SELECT SUM(rs.capacity_gb * prs.quantity)
                FROM pc_spec_ram_spec prs
                JOIN ram_specs rs ON rs.id = prs.ram_spec_id
                WHERE prs.pc_spec_id = pc_specs.id
            ), 0)
        ');

        // 3. Migrate existing Disk data: sum capacity_gb from pivot
        DB::statement('
            UPDATE pc_specs
            SET disk_gb = COALESCE((
                SELECT SUM(ds.capacity_gb)
                FROM pc_spec_disk_spec pds
                JOIN disk_specs ds ON ds.id = pds.disk_spec_id
                WHERE pds.pc_spec_id = pc_specs.id
            ), 0)
        ');

        // 4. Add release_date to processor_specs
        Schema::table('processor_specs', function (Blueprint $table) {
            $table->date('release_date')->nullable()->after('boost_clock_ghz');
        });

        // 5. Clean up stocks for removed spec types and processor
        DB::table('stocks')->whereIn('stockable_type', [
            'App\\Models\\RamSpec',
            'App\\Models\\DiskSpec',
            'App\\Models\\MonitorSpec',
            'App\\Models\\ProcessorSpec',
        ])->delete();

        // 6. Drop pivot tables
        Schema::dropIfExists('pc_spec_ram_spec');
        Schema::dropIfExists('pc_spec_disk_spec');
        Schema::dropIfExists('monitor_pc_spec');
        Schema::dropIfExists('monitor_station');

        // 7. Drop spec tables
        Schema::dropIfExists('ram_specs');
        Schema::dropIfExists('disk_specs');
        Schema::dropIfExists('monitor_specs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate monitor_specs
        Schema::create('monitor_specs', function (Blueprint $table) {
            $table->id();
            $table->string('brand');
            $table->string('model');
            $table->decimal('screen_size', 4, 1);
            $table->string('resolution');
            $table->string('panel_type');
            $table->json('ports')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Recreate disk_specs
        Schema::create('disk_specs', function (Blueprint $table) {
            $table->id();
            $table->string('manufacturer');
            $table->string('model');
            $table->unsignedBigInteger('capacity_gb');
            $table->timestamps();
        });

        // Recreate ram_specs
        Schema::create('ram_specs', function (Blueprint $table) {
            $table->id();
            $table->string('manufacturer');
            $table->string('model');
            $table->unsignedBigInteger('capacity_gb');
            $table->string('type');
            $table->string('speed');
            $table->string('form_factor');
            $table->timestamps();
        });

        // Recreate pivot tables
        Schema::create('pc_spec_ram_spec', function (Blueprint $table) {
            $table->foreignId('pc_spec_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ram_spec_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->primary(['pc_spec_id', 'ram_spec_id']);
        });

        Schema::create('pc_spec_disk_spec', function (Blueprint $table) {
            $table->foreignId('pc_spec_id')->constrained()->cascadeOnDelete();
            $table->foreignId('disk_spec_id')->constrained()->cascadeOnDelete();
            $table->primary(['pc_spec_id', 'disk_spec_id']);
        });

        Schema::create('monitor_pc_spec', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pc_spec_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_spec_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });

        Schema::create('monitor_station', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_spec_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });

        // Remove release_date from processor_specs
        Schema::table('processor_specs', function (Blueprint $table) {
            $table->dropColumn('release_date');
        });

        // Remove new columns from pc_specs
        Schema::table('pc_specs', function (Blueprint $table) {
            $table->dropColumn(['ram_gb', 'disk_gb', 'available_ports']);
        });
    }
};
