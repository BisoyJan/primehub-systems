import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import type { LeaveRequestDocument } from '@/types';
import { Eye, FileText, Upload, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

const MAX_DOCUMENTS = 10;
const MAX_FILE_SIZE = 4 * 1024 * 1024; // 4MB
const ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

interface LeaveDocumentsUploadProps {
    leaveType: string;
    files: File[];
    onFilesChange: (files: File[]) => void;
    errors: Record<string, string>;
    existingDocuments?: LeaveRequestDocument[];
    removedDocumentIds?: number[];
    onRemoveExisting?: (id: number) => void;
    documentViewUrl?: (leaveRequestId: number, documentId: number) => string;
    progress?: { percentage?: number } | null;
}

function getLabel(leaveType: string): string {
    if (leaveType === 'SL') {
        return 'Medical Certificate';
    }
    if (leaveType === 'BL') {
        return 'Death Certificate';
    }
    return 'Supporting Document';
}

function getDescription(leaveType: string): string {
    if (leaveType === 'SL') {
        return 'Upload your medical certificate(s) to have leave credits deducted. Without a certificate, the leave will be recorded as unpaid.';
    }
    if (leaveType === 'BL') {
        return 'Upload a death certificate to support your bereavement leave request.';
    }
    if (leaveType === 'IW') {
        return 'Upload supporting document(s) (e.g., appointment slip, event proof) to auto-excuse attendance points.';
    }
    return 'Upload any supporting document(s) for your unpaid time off request.';
}

export function LeaveDocumentsUpload({
    leaveType,
    files,
    onFilesChange,
    errors,
    existingDocuments = [],
    removedDocumentIds = [],
    onRemoveExisting,
    documentViewUrl,
    progress,
}: LeaveDocumentsUploadProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [previews, setPreviews] = useState<string[]>([]);
    const [warning, setWarning] = useState<string | null>(null);

    const activeExisting = existingDocuments.filter((d) => !removedDocumentIds.includes(d.id));
    const totalCount = activeExisting.length + files.length;
    const canAddMore = totalCount < MAX_DOCUMENTS;

    const addFiles = useCallback(
        (incoming: File[]) => {
            if (incoming.length === 0) {
                return;
            }

            setWarning(null);

            const currentTotal = activeExisting.length + files.length;
            const availableSlots = MAX_DOCUMENTS - currentTotal;

            if (availableSlots <= 0) {
                setWarning(`Maximum of ${MAX_DOCUMENTS} documents allowed. You already have ${currentTotal}.`);
                return;
            }

            const validFiles = incoming.filter((f) => ALLOWED_TYPES.includes(f.type) && f.size <= MAX_FILE_SIZE);
            const skippedCount = incoming.length - validFiles.length;
            const excessCount = Math.max(0, validFiles.length - availableSlots);
            const filesToAdd = validFiles.slice(0, availableSlots);

            const warnings: string[] = [];
            if (excessCount > 0) {
                warnings.push(`${excessCount} file${excessCount > 1 ? 's were' : ' was'} not added — limit is ${MAX_DOCUMENTS}.`);
            }
            if (skippedCount > 0) {
                warnings.push(`${skippedCount} file${skippedCount > 1 ? 's were' : ' was'} skipped (invalid type or exceeds 4MB).`);
            }
            if (warnings.length > 0) {
                setWarning(warnings.join(' '));
            }

            if (filesToAdd.length > 0) {
                const newPreviewUrls = filesToAdd.map((f) => (f.type.startsWith('image/') ? URL.createObjectURL(f) : ''));
                onFilesChange([...files, ...filesToAdd]);
                setPreviews((prev) => [...prev, ...newPreviewUrls]);
            }
        },
        [activeExisting.length, files, onFilesChange],
    );

    useEffect(() => {
        if (!warning) {
            return;
        }
        const timer = setTimeout(() => setWarning(null), 5000);
        return () => clearTimeout(timer);
    }, [warning]);

    const handleFileSelect = useCallback(
        (e: React.ChangeEvent<HTMLInputElement>) => {
            const selected = Array.from(e.target.files || []);
            addFiles(selected);
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        },
        [addFiles],
    );

    const handleRemoveNewFile = useCallback(
        (index: number) => {
            const currentFiles = [...files];
            currentFiles.splice(index, 1);
            onFilesChange(currentFiles);

            setPreviews((prev) => {
                const updated = [...prev];
                if (updated[index]) {
                    URL.revokeObjectURL(updated[index]);
                }
                updated.splice(index, 1);
                return updated;
            });
        },
        [files, onFilesChange],
    );

    // Collect document-related errors
    const documentErrors: string[] = [];
    if (errors.medical_cert_files) {
        documentErrors.push(errors.medical_cert_files);
    }
    Object.keys(errors).forEach((key) => {
        if (key.startsWith('medical_cert_files.')) {
            documentErrors.push(errors[key]);
        }
    });

    const isImageMime = (mime: string | null) => !!mime && mime.startsWith('image/');

    return (
        <div className="space-y-4">
            <div>
                <Label className="text-base font-medium">{getLabel(leaveType)} (Optional)</Label>
                <p className="mt-1 text-sm text-muted-foreground">{getDescription(leaveType)}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                    {totalCount}/{MAX_DOCUMENTS} files • JPEG, PNG, GIF, WebP or PDF (max 4MB each)
                </p>
            </div>

            {/* Preview Grid */}
            {(activeExisting.length > 0 || files.length > 0) && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    {/* Existing documents */}
                    {activeExisting.map((document) => {
                        const url = documentViewUrl ? documentViewUrl(document.leave_request_id, document.id) : '#';
                        return (
                            <div key={`existing-${document.id}`} className="group relative aspect-square overflow-hidden rounded-lg border bg-muted/30">
                                {isImageMime(document.mime_type) ? (
                                    <img src={url} alt={document.original_filename} className="h-full w-full object-cover" />
                                ) : (
                                    <div className="flex h-full w-full flex-col items-center justify-center gap-2 p-2">
                                        <FileText className="h-8 w-8 text-muted-foreground" />
                                        <span className="w-full truncate text-center text-xs text-muted-foreground">{document.original_filename}</span>
                                    </div>
                                )}
                                <div className="absolute inset-x-0 bottom-0 flex justify-between gap-1 bg-black/50 p-1 opacity-0 transition-opacity group-hover:opacity-100">
                                    <a href={url} target="_blank" rel="noopener noreferrer" className="rounded p-1 text-white hover:bg-white/20" title="View">
                                        <Eye className="h-4 w-4" />
                                    </a>
                                    {onRemoveExisting && (
                                        <button
                                            type="button"
                                            onClick={() => onRemoveExisting(document.id)}
                                            className="rounded p-1 text-white hover:bg-red-500/70"
                                            title="Remove"
                                        >
                                            <X className="h-4 w-4" />
                                        </button>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    {/* New files */}
                    {files.map((file, index) => (
                        <div key={`new-${index}-${file.name}`} className="group relative aspect-square overflow-hidden rounded-lg border bg-muted/30">
                            {file.type.startsWith('image/') && previews[index] ? (
                                <img src={previews[index]} alt={file.name} className="h-full w-full object-cover" />
                            ) : (
                                <div className="flex h-full w-full flex-col items-center justify-center gap-2 p-2">
                                    <FileText className="h-8 w-8 text-muted-foreground" />
                                    <span className="w-full truncate text-center text-xs text-muted-foreground">{file.name}</span>
                                </div>
                            )}
                            <div className="absolute inset-x-0 bottom-0 flex justify-end bg-black/50 p-1 opacity-0 transition-opacity group-hover:opacity-100">
                                <button
                                    type="button"
                                    onClick={() => handleRemoveNewFile(index)}
                                    className="rounded p-1 text-white hover:bg-red-500/70"
                                    title="Remove"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Upload dropzone */}
            {canAddMore && (
                <div className="rounded-lg border-2 border-dashed p-6 text-center transition-colors hover:border-primary/50">
                    <input
                        ref={fileInputRef}
                        type="file"
                        id="medical_cert_files"
                        multiple
                        accept="image/jpeg,image/jpg,image/png,image/gif,image/webp,application/pdf"
                        onChange={handleFileSelect}
                        className="hidden"
                    />
                    <label htmlFor="medical_cert_files" className="flex cursor-pointer flex-col items-center gap-2">
                        <div className="rounded-full bg-muted p-3">
                            <Upload className="h-6 w-6 text-muted-foreground" />
                        </div>
                        <div>
                            <p className="font-medium">Click to upload document(s)</p>
                            <p className="mt-1 text-xs text-muted-foreground">You can select multiple files</p>
                        </div>
                    </label>
                </div>
            )}

            {warning && <p className="text-sm text-amber-600 dark:text-amber-400">{warning}</p>}

            {documentErrors.map((message, index) => (
                <p key={index} className="text-sm text-red-500">
                    {message}
                </p>
            ))}

            {/* Upload progress */}
            {progress && (progress.percentage ?? 0) > 0 && (progress.percentage ?? 0) < 100 && (
                <div className="space-y-2">
                    <Progress value={progress.percentage ?? 0} className="h-2" />
                    <p className="text-center text-xs text-muted-foreground">Uploading... {progress.percentage ?? 0}%</p>
                </div>
            )}
        </div>
    );
}
