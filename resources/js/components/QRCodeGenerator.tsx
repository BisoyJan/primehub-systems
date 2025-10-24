import React, { useRef } from 'react';
import QRCode from 'react-qr-code';
import { Button } from '@/components/ui/button';
import { Download } from 'lucide-react';
import { getPcSpecQRUrl, DEFAULT_QR_SIZE } from '@/config/qrcode';

interface PcSpecData {
    id: number;
    pc_number?: string | null;
    manufacturer: string;
    model: string;
    chipset?: string;
    memory_type?: string;
    form_factor?: string;
}

interface QRCodeGeneratorProps {
    pcSpec: PcSpecData;
    size?: number;
    includeMetadata?: boolean;
    showExport?: boolean;
}

/**
 * QRCodeGenerator - Single QR code component with export functionality
 * Can encode URL only or include additional PC metadata
 */
export function QRCodeGenerator({
    pcSpec,
    size = DEFAULT_QR_SIZE,
    includeMetadata = false,
    showExport = false,
}: QRCodeGeneratorProps) {
    const qrRef = useRef<HTMLDivElement>(null);

    // Generate QR code data
    const getQRData = () => {
        const url = getPcSpecQRUrl(pcSpec.id);

        if (!includeMetadata) {
            return url;
        }

        // Include additional metadata as JSON
        const metadata = {
            url,
            pc_number: pcSpec.pc_number || `PC-${pcSpec.id}`,
            manufacturer: pcSpec.manufacturer,
            model: pcSpec.model,
            form_factor: pcSpec.form_factor,
            memory_type: pcSpec.memory_type,
        };

        return JSON.stringify(metadata);
    };

    // Export QR code as PNG
    const exportAsPNG = () => {
        if (!qrRef.current) return;

        const svg = qrRef.current.querySelector('svg');
        if (!svg) return;

        const svgData = new XMLSerializer().serializeToString(svg);
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const img = new Image();

        canvas.width = size;
        canvas.height = size;

        img.onload = () => {
            ctx?.drawImage(img, 0, 0);
            canvas.toBlob((blob) => {
                if (!blob) return;
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.download = `qr-${pcSpec.pc_number || pcSpec.id}.png`;
                link.href = url;
                link.click();
                URL.revokeObjectURL(url);
            });
        };

        img.src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svgData)));
    };

    // Export QR code as SVG
    const exportAsSVG = () => {
        if (!qrRef.current) return;

        const svg = qrRef.current.querySelector('svg');
        if (!svg) return;

        const svgData = new XMLSerializer().serializeToString(svg);
        const blob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.download = `qr-${pcSpec.pc_number || pcSpec.id}.svg`;
        link.href = url;
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="flex flex-col items-center gap-3">
            <div ref={qrRef} className="bg-white p-4 rounded border">
                <QRCode
                    value={getQRData()}
                    size={size}
                    style={{ height: 'auto', maxWidth: '100%', width: '100%' }}
                    viewBox={`0 0 ${size} ${size}`}
                />
            </div>

            {showExport && (
                <div className="flex gap-2">
                    <Button
                        onClick={exportAsPNG}
                        variant="outline"
                        size="sm"
                        className="gap-1"
                    >
                        <Download className="h-4 w-4" />
                        PNG
                    </Button>
                    <Button
                        onClick={exportAsSVG}
                        variant="outline"
                        size="sm"
                        className="gap-1"
                    >
                        <Download className="h-4 w-4" />
                        SVG
                    </Button>
                </div>
            )}
        </div>
    );
}
