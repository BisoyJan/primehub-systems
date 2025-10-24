/**
 * QR Code Configuration
 *
 * This file contains configuration for QR code generation.
 * Update the base URL to match your production domain.
 */

// Automatically uses the current domain (recommended for development and production)
export const QR_CODE_BASE_URL = typeof window !== 'undefined' ? window.location.origin : '';

// Alternative: Set a specific URL for production
// export const QR_CODE_BASE_URL = 'https://primehub-systems.yourdomain.com';

/**
 * Generate a QR code URL for a PC Spec
 * @param pcId - The ID of the PC Spec
 * @returns The full URL to the PC Spec detail page
 */
export function getPcSpecQRUrl(pcId: number): string {
    return `${QR_CODE_BASE_URL}/pcspecs/${pcId}`;
}

/**
 * QR Code Size configurations (in pixels)
 */
export const QR_CODE_SIZES = {
    small: 128,
    medium: 256,
    large: 512,
} as const;

/**
 * Default QR code size for printing
 */
export const DEFAULT_QR_SIZE = QR_CODE_SIZES.medium;
