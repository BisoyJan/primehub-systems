import React, { useEffect } from 'react';
import QRCode from 'react-qr-code';
import { Button } from '@/components/ui/button';
import { X } from 'lucide-react';
import { getPcSpecQRUrl } from '@/config/qrcode';

interface PcSpec {
    id: number;
    pc_number?: string | null;
    manufacturer: string;
    model: string;
    chipset: string;
    memory_type: string;
    form_factor: string;
}

interface QRCodePrintViewProps {
    pcSpecs: PcSpec[];
    onClose: () => void;
}

/**
 * QRCodePrintView - Component for printing QR codes for PC Specs
 * Displays QR codes that link to the PC Spec detail page
 * Each QR code is on its own page for easy printing and cutting
 */
export function QRCodePrintView({ pcSpecs, onClose }: QRCodePrintViewProps) {
    useEffect(() => {
        // Add print-specific styles
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                @page {
                    size: A4 portrait;
                    margin: 1cm;
                }
                html, body {
                    margin: 0;
                    padding: 0;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                body * {
                    visibility: hidden;
                }
                #qr-print-container {
                    visibility: visible !important;
                    position: absolute !important;
                    left: 0 !important;
                    top: 0 !important;
                    width: 100% !important;
                    background: white !important;
                }
                #qr-print-container * {
                    visibility: visible !important;
                }
                .no-print {
                    display: none !important;
                }
                .print-content {
                    padding: 0 !important;
                }
                .qr-grid-container {
                    display: grid !important;
                    grid-template-columns: repeat(2, 1fr) !important;
                    gap: 0.5cm !important;
                    padding: 0 !important;
                }
                .qr-item {
                    page-break-inside: avoid !important;
                    break-inside: avoid !important;
                    padding: 0.4cm !important;
                    border: 1px solid #d1d5db !important;
                    border-radius: 0.3cm !important;
                    background: white !important;
                    margin-bottom: 0.5cm !important;
                }
                /* Force black text color for PC number in print */
                .qr-item .text-gray-900 {
                    color: #000000 !important;
                }
                /* Remove the automatic page breaks */
                .qr-item:nth-child(6n)::after {
                    content: none !important;
                }
            }
        `;
        document.head.appendChild(style);

        return () => {
            document.head.removeChild(style);
        };
    }, []);

    const handlePrint = () => {
        window.print();
    };

    return (
        <div
            id="qr-print-container"
            className="fixed inset-0 bg-white dark:bg-gray-950 z-50 overflow-auto"
        >
            {/* Header with close and print buttons - hidden when printing */}
            <div className="no-print sticky top-0 bg-white dark:bg-gray-900 border-b dark:border-gray-700 shadow-sm p-4 flex justify-between items-center">
                <div>
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white">QR Code Print Preview</h2>
                    <p className="text-sm text-gray-600 dark:text-gray-400">
                        {pcSpecs.length} PC{pcSpecs.length !== 1 ? 's' : ''} selected for printing
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button onClick={handlePrint} variant="default">
                        Print QR Codes
                    </Button>
                    <Button onClick={onClose} variant="outline" size="icon">
                        <X className="h-4 w-4" />
                    </Button>
                </div>
            </div>

            {/* QR Code Grid - multiple per page when printed */}
            <div className="p-4 print-content">
                <div className="qr-grid-container grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {pcSpecs.map((pc) => {
                        // Generate URL for this PC Spec using config
                        const qrUrl = getPcSpecQRUrl(pc.id);

                        return (
                            <div
                                key={pc.id}
                                className="qr-item flex flex-col items-center justify-center border border-gray-300 dark:border-gray-700 rounded-lg p-2 bg-white dark:bg-gray-800"
                            >
                                {/* QR Code */}
                                <div className="bg-white dark:bg-white p-1 rounded">
                                    <QRCode
                                        value={qrUrl}
                                        size={140}
                                        style={{ height: 'auto', maxWidth: '100%', width: '100%' }}
                                        viewBox={`0 0 110 110`}
                                    />
                                </div>

                                {/* PC Number - Large and Bold */}
                                <div className="mt-1 text-center">
                                    <div className="text-sm font-bold text-gray-900 dark:text-white">
                                        {pc.pc_number || `PC #${pc.id}`}
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Footer - hidden when printing */}
            <div className="no-print p-4 border-t dark:border-gray-700 bg-gray-50 dark:bg-gray-900 text-center text-sm text-gray-600 dark:text-gray-400">
                <p>Multiple QR codes will be printed per page in a grid layout</p>
                <p className="mt-1">Cut and attach to the corresponding PC for easy tracking</p>
            </div>
        </div>
    );
}
