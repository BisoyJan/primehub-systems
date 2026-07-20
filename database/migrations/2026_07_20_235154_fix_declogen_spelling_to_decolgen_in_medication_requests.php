<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Corrects the misspelled "Declogen" enum value to the correct "Decolgen".
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE medication_requests MODIFY medication_type ENUM('Declogen', 'Decolgen', 'Biogesic', 'Mefenamic Acid', 'Kremil-S', 'Cetirizine', 'Saridon', 'Diatabs') NOT NULL");

        DB::table('medication_requests')
            ->where('medication_type', 'Declogen')
            ->update(['medication_type' => 'Decolgen']);

        DB::statement("ALTER TABLE medication_requests MODIFY medication_type ENUM('Decolgen', 'Biogesic', 'Mefenamic Acid', 'Kremil-S', 'Cetirizine', 'Saridon', 'Diatabs') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE medication_requests MODIFY medication_type ENUM('Decolgen', 'Declogen', 'Biogesic', 'Mefenamic Acid', 'Kremil-S', 'Cetirizine', 'Saridon', 'Diatabs') NOT NULL");

        DB::table('medication_requests')
            ->where('medication_type', 'Decolgen')
            ->update(['medication_type' => 'Declogen']);

        DB::statement("ALTER TABLE medication_requests MODIFY medication_type ENUM('Declogen', 'Biogesic', 'Mefenamic Acid', 'Kremil-S', 'Cetirizine', 'Saridon', 'Diatabs') NOT NULL");
    }
};

