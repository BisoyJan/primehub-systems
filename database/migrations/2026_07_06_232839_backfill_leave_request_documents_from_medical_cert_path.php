<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Copy existing single medical_cert_path files into the new
     * leave_request_documents table so legacy uploads remain viewable
     * in the multi-file UI.
     */
    public function up(): void
    {
        DB::table('leave_requests')
            ->whereNotNull('medical_cert_path')
            ->where('medical_cert_path', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($requests): void {
                foreach ($requests as $request) {
                    $path = $request->medical_cert_path;

                    $exists = DB::table('leave_request_documents')
                        ->where('leave_request_id', $request->id)
                        ->where('file_path', $path)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $fileSize = null;
                    $mimeType = null;

                    if (Storage::disk('local')->exists($path)) {
                        $fileSize = Storage::disk('local')->size($path);
                        $mimeType = Storage::disk('local')->mimeType($path) ?: null;
                    }

                    // The legacy single-file column stored no user-facing filename,
                    // so use a generic display name (extension preserved) instead of
                    // the internal storage filename (e.g. "medcert_173_...jpg"),
                    // which is misleading for non-SL leave types like BL/UPTO/IW.
                    $extension = pathinfo($path, PATHINFO_EXTENSION);
                    $displayName = $extension !== ''
                        ? "Supporting Document.{$extension}"
                        : 'Supporting Document';

                    DB::table('leave_request_documents')->insert([
                        'leave_request_id' => $request->id,
                        'file_path' => $path,
                        'original_filename' => $displayName,
                        'mime_type' => $mimeType,
                        'file_size' => $fileSize,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * Remove backfilled rows that still match an existing medical_cert_path.
     */
    public function down(): void
    {
        DB::table('leave_request_documents')
            ->whereIn('file_path', function ($query): void {
                $query->select('medical_cert_path')
                    ->from('leave_requests')
                    ->whereNotNull('medical_cert_path');
            })
            ->delete();
    }
};
