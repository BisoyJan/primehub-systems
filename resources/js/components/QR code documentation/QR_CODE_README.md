# QR Code Feature for PC Specs

## Overview
This feature allows you to generate and print QR codes for PC specifications. Each QR code links to the detailed view of a specific PC, enabling easy tracking and identification by scanning with any QR code reader.

## Features
- ✅ **Bulk Selection**: Select multiple PCs using checkboxes
- ✅ **Single/Multiple QR Generation**: Generate QR codes for one or many PCs at once
- ✅ **Print-Ready Layout**: Each QR code is formatted on its own page for easy printing
- ✅ **Configurable URL**: Base URL stored in config file for easy updates
- ✅ **Clean Component Structure**: Separate QR code component for maintainability

## File Structure
```
resources/js/
├── components/
│   └── QRCodePrintView.tsx        # QR code print view component
├── config/
│   └── qrcode.ts                  # QR code configuration (URLs, sizes)
└── pages/Computer/PcSpecs/
    └── Index.tsx                  # Updated with QR code selection
```

## How to Use

### 1. Select PCs for QR Code Generation
- Navigate to the PC Specs page (`/pcspecs`)
- Use checkboxes to select one or more PCs
- Click "Select All" checkbox in the header to select all PCs on the current page

### 2. Generate QR Codes
- Once PCs are selected, a blue banner appears showing the count
- Click **"Generate QR Codes for Print"** button
- A print preview window opens showing all QR codes

### 3. Print QR Codes
- Review the QR codes in the preview
- Click **"Print QR Codes"** button or use `Ctrl+P` (Windows) / `Cmd+P` (Mac)
- Each QR code will be printed on a separate page
- Cut out the QR codes and attach them to the corresponding PCs

### 4. Scan QR Codes
- Use any QR code scanner app or smartphone camera
- Scanning the QR code opens the PC detail page in the browser
- View full specifications, maintenance history, and more

## Configuration

### Update Base URL
Edit `resources/js/config/qrcode.ts` to change the QR code URL:

```typescript
// Option 1: Auto-detect domain (default, works for dev and production)
export const QR_CODE_BASE_URL = typeof window !== 'undefined' ? window.location.origin : '';

// Option 2: Set specific production URL
export const QR_CODE_BASE_URL = 'https://primehub-systems.yourdomain.com';
```

### Adjust QR Code Size
Modify the size in `resources/js/config/qrcode.ts`:

```typescript
export const QR_CODE_SIZES = {
    small: 128,   // 128x128 pixels
    medium: 256,  // 256x256 pixels (default)
    large: 512,   // 512x512 pixels
} as const;

export const DEFAULT_QR_SIZE = QR_CODE_SIZES.medium; // Change this
```

## Technical Details

### Dependencies
- **react-qr-code**: Library for generating QR codes in React
- Install: `npm install react-qr-code`

### QR Code Data
Each QR code encodes a URL in the format:
```
{BASE_URL}/pcspecs/{PC_ID}
```

Example: `https://primehub-systems.yourdomain.com/pcspecs/123`

### Print Styling
The component uses `@media print` CSS to:
- Hide navigation and controls when printing
- Force page breaks between QR codes
- Optimize layout for physical printing

## Usage Examples

### Example 1: Single PC QR Code
1. Select one PC using its checkbox
2. Click "Generate QR Codes for Print"
3. Print and attach to the PC

### Example 2: Bulk QR Code Generation
1. Click "Select All" to select all PCs on the page
2. Or manually select multiple PCs
3. Click "Generate QR Codes for Print"
4. Print all QR codes at once
5. Match and attach each QR code to its corresponding PC

## Troubleshooting

### QR Code Doesn't Scan
- Ensure the QR code is printed clearly (no smudging or damage)
- Check that the base URL in config is correct
- Verify the PC detail page route exists: `/pcspecs/{id}`

### Wrong URL in QR Code
- Update `QR_CODE_BASE_URL` in `resources/js/config/qrcode.ts`
- Clear browser cache and rebuild: `npm run build`

### Print Layout Issues
- Try printing in landscape mode for larger QR codes
- Adjust `DEFAULT_QR_SIZE` in config if QR codes are too small/large
- Ensure printer margins are set to minimal

## Future Enhancements
- [x] Add QR code generation on Create page (single PC)
- [x] Include additional data in QR code (e.g., PC Number, Model)
- [x] Export QR codes as images (PNG/SVG) for external use
- [x] Generate QR codes server-side for offline printing
- [x] Add custom branding/logo to QR codes

## Support
For issues or questions, contact the development team or open an issue in the repository.
