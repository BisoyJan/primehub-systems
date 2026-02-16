import { useState, useCallback } from 'react';
import Cropper, { type Area } from 'react-easy-crop';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { ZoomIn, ZoomOut, RotateCw } from 'lucide-react';

interface AvatarCropDialogProps {
    open: boolean;
    imageSrc: string;
    onCropComplete: (croppedBlob: Blob) => void;
    onClose: () => void;
}

async function getCroppedImage(imageSrc: string, pixelCrop: Area): Promise<Blob> {
    const image = await createImage(imageSrc);
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        throw new Error('Failed to get canvas context');
    }

    canvas.width = pixelCrop.width;
    canvas.height = pixelCrop.height;

    ctx.drawImage(
        image,
        pixelCrop.x,
        pixelCrop.y,
        pixelCrop.width,
        pixelCrop.height,
        0,
        0,
        pixelCrop.width,
        pixelCrop.height,
    );

    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error('Canvas toBlob failed'));
                }
            },
            'image/jpeg',
            0.92,
        );
    });
}

function createImage(url: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.addEventListener('load', () => resolve(image));
        image.addEventListener('error', (error) => reject(error));
        image.src = url;
    });
}

export function AvatarCropDialog({ open, imageSrc, onCropComplete, onClose }: AvatarCropDialogProps) {
    const [crop, setCrop] = useState({ x: 0, y: 0 });
    const [zoom, setZoom] = useState(1);
    const [rotation, setRotation] = useState(0);
    const [croppedAreaPixels, setCroppedAreaPixels] = useState<Area | null>(null);
    const [processing, setProcessing] = useState(false);

    const onCropChange = useCallback((location: { x: number; y: number }) => {
        setCrop(location);
    }, []);

    const onZoomChange = useCallback((newZoom: number) => {
        setZoom(newZoom);
    }, []);

    const handleCropComplete = useCallback((_: Area, croppedPixels: Area) => {
        setCroppedAreaPixels(croppedPixels);
    }, []);

    const handleSave = async () => {
        if (!croppedAreaPixels) return;

        setProcessing(true);
        try {
            const croppedBlob = await getCroppedImage(imageSrc, croppedAreaPixels);
            onCropComplete(croppedBlob);
        } catch (error) {
            console.error('Error cropping image:', error);
        } finally {
            setProcessing(false);
        }
    };

    const handleRotate = () => {
        setRotation((prev) => (prev + 90) % 360);
    };

    const handleClose = () => {
        setCrop({ x: 0, y: 0 });
        setZoom(1);
        setRotation(0);
        onClose();
    };

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && handleClose()}>
            <DialogContent className="max-w-[95vw] sm:max-w-lg">
                <DialogTitle>Crop Profile Picture</DialogTitle>
                <DialogDescription>Drag to reposition and use the slider to zoom in or out.</DialogDescription>

                <div className="relative mx-auto aspect-square w-full max-w-sm overflow-hidden rounded-lg bg-neutral-900">
                    <Cropper
                        image={imageSrc}
                        crop={crop}
                        zoom={zoom}
                        rotation={rotation}
                        aspect={1}
                        cropShape="round"
                        showGrid={false}
                        objectFit="contain"
                        onCropChange={onCropChange}
                        onZoomChange={onZoomChange}
                        onCropComplete={handleCropComplete}
                    />
                </div>

                <div className="flex items-center gap-3">
                    <ZoomOut className="size-4 shrink-0 text-muted-foreground" />
                    <input
                        type="range"
                        min={1}
                        max={3}
                        step={0.05}
                        value={zoom}
                        onChange={(e) => setZoom(Number(e.target.value))}
                        className="h-1.5 w-full cursor-pointer appearance-none rounded-full bg-muted accent-primary"
                        aria-label="Zoom level"
                    />
                    <ZoomIn className="size-4 shrink-0 text-muted-foreground" />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="shrink-0"
                        onClick={handleRotate}
                        title="Rotate 90°"
                    >
                        <RotateCw className="size-4" />
                    </Button>
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="secondary" onClick={handleClose} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="button" onClick={handleSave} disabled={processing}>
                        {processing ? 'Saving...' : 'Save'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
