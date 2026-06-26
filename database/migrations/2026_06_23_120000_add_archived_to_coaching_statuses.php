<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE coaching_sessions MODIFY COLUMN ack_status ENUM('Pending','Acknowledged','Disputed','Archived') NOT NULL DEFAULT 'Pending'");
        DB::statement("ALTER TABLE coaching_sessions MODIFY COLUMN compliance_status ENUM('Awaiting_Agent_Ack','For_Review','Verified','Rejected','Archived') NOT NULL DEFAULT 'Awaiting_Agent_Ack'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE coaching_sessions MODIFY COLUMN ack_status ENUM('Pending','Acknowledged','Disputed') NOT NULL DEFAULT 'Pending'");
        DB::statement("ALTER TABLE coaching_sessions MODIFY COLUMN compliance_status ENUM('Awaiting_Agent_Ack','For_Review','Verified','Rejected') NOT NULL DEFAULT 'Awaiting_Agent_Ack'");
    }
};
