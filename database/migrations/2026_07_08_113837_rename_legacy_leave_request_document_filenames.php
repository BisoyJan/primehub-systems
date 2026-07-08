<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Legacy documents backfilled from the old single `medical_cert_path`
     * column used the raw stored filename (e.g. "medcert_173_...jpg") as
     * their display name. That name is misleading for leave types other
     * than Sick Leave (BL/UPTO/IW never had anything to do with a medical
     * certificate), so replace it with a generic display name while
     * keeping the original file extension.
     */
    public function up(): void
    {
        DB::table('leave_request_documents')
            ->where('file_path', 'like', 'medical_certificates/%')
            ->orderBy('id')
            ->chunkById(200, function ($documents): void {
                foreach ($documents as $document) {
                    $extension = pathinfo($document->original_filename, PATHINFO_EXTENSION);
                    $genericName = $extension !== ''
                        ? "Supporting Document.{$extension}"
                        : 'Supporting Document';

                    DB::table('leave_request_documents')
                        ->where('id', $document->id)
                        ->update(['original_filename' => $genericName]);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * The original legacy filenames are not preserved anywhere else, so
     * this data cleanup cannot be reversed.
     */
    public function down(): void
    {
        //
    }
};
