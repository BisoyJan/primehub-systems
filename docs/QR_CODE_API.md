# QR Code API Documentation

## Overview
This API allows you to generate QR codes for PC specifications either individually or in bulk. QR codes can be generated in PNG or SVG format and optionally include PC metadata.

---

## Authentication
All endpoints require authentication. Include session cookies or authentication headers with your requests.

---

## Endpoints

### 1. Generate Single QR Code

**Endpoint:** `GET /pcspecs/{pcspec}/qrcode`

**Description:** Generate a QR code for a single PC specification.

**URL Parameters:**
- `pcspec` (required): PC Spec ID

**Query Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `format` | string | `png` | Output format: `png` or `svg` |
| `size` | integer | `256` | QR code size in pixels (64-1024) |
| `metadata` | boolean | `false` | Include PC metadata in QR code |

**Example Requests:**

```bash
# Basic PNG QR code
GET /pcspecs/123/qrcode

# SVG QR code with custom size
GET /pcspecs/123/qrcode?format=svg&size=512

# QR code with metadata
GET /pcspecs/123/qrcode?metadata=true
```

**Response:**
- **Content-Type:** `image/png` or `image/svg+xml`
- **Body:** Binary image data
- **Headers:** `Content-Disposition: inline; filename="pc-123-qrcode.png"`

**Example Response (PNG):**
```
Binary PNG data
```

**Example Response (SVG with metadata):**
```xml
<svg xmlns="http://www.w3.org/2000/svg" ...>
  <!-- QR code paths -->
</svg>
```

**QR Code Content (URL only):**
```
https://yourdomain.com/pcspecs/123
```

**QR Code Content (with metadata):**
```json
{
    "url": "https://yourdomain.com/pcspecs/123",
    "pc_number": "PC-2025-001",
    "manufacturer": "Dell",
    "model": "OptiPlex 7090",
    "form_factor": "ATX",
    "memory_type": "DDR4"
}
```

---

### 2. Generate Bulk QR Codes (ZIP)

**Endpoint:** `POST /pcspecs/qrcode/bulk`

**Description:** Generate QR codes for multiple PC specifications and download as a ZIP file.

**Request Body:**
```json
{
    "pc_spec_ids": [1, 2, 3, 4, 5],
    "format": "png",
    "size": 256,
    "metadata": false
}
```

**Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `pc_spec_ids` | array[integer] | Yes | Array of PC Spec IDs |
| `format` | string | No | Output format: `png` or `svg` (default: `png`) |
| `size` | integer | No | QR code size in pixels, 64-1024 (default: `256`) |
| `metadata` | boolean | No | Include PC metadata (default: `false`) |

**Example Requests:**

```bash
# Basic bulk generation
POST /pcspecs/qrcode/bulk
Content-Type: application/json

{
    "pc_spec_ids": [1, 2, 3]
}

# Bulk with custom settings
POST /pcspecs/qrcode/bulk
Content-Type: application/json

{
    "pc_spec_ids": [1, 2, 3, 4, 5],
    "format": "svg",
    "size": 512,
    "metadata": true
}
```

**Response:**
- **Content-Type:** `application/zip`
- **Body:** ZIP file containing all QR codes
- **Headers:** `Content-Disposition: attachment; filename="pc-qrcodes-2025-10-24-143022.zip"`

**ZIP Contents:**
```
pc-qrcodes-2025-10-24-143022.zip
├── PC-2025-001.png
├── PC-2025-002.png
├── PC-2025-003.png
├── PC-2025-004.png
└── PC-2025-005.png
```

**Error Response (404):**
```json
{
    "error": "No PC Specs found"
}
```

**Error Response (500):**
```json
{
    "error": "Could not create ZIP file"
}
```

---

## Error Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 400 | Bad Request (invalid parameters) |
| 401 | Unauthorized (not authenticated) |
| 404 | Not Found (PC Spec doesn't exist) |
| 422 | Validation Error (invalid input) |
| 500 | Server Error |

---

## Validation Rules

### Single QR Code
- `format`: Must be `png` or `svg`
- `size`: Must be integer between 64 and 1024
- `metadata`: Must be boolean or string `"true"`/`"false"`

### Bulk QR Codes
- `pc_spec_ids`: Required, must be array of integers
- `pc_spec_ids.*`: Each ID must exist in database
- `format`: Optional, must be `png` or `svg`
- `size`: Optional, must be integer between 64 and 1024
- `metadata`: Optional, must be boolean

---

## Rate Limiting

**Single QR Generation:**
- Limit: 100 requests per minute
- Burst: 10 requests per second

**Bulk QR Generation:**
- Limit: 10 requests per minute
- Max IDs per request: 100

---

## Best Practices

### For Best Scan Results
1. Use size of at least 256x256 pixels
2. Use PNG format for printing
3. Use SVG format for scalability
4. Include metadata only if needed (makes QR code denser)

### For Performance
1. Use bulk endpoint for multiple QR codes
2. Cache generated QR codes on client side
3. Use appropriate size (larger = slower)
4. Batch requests during off-peak hours

### For Integration
1. Store QR code URLs in database
2. Generate QR codes asynchronously for large batches
3. Use webhooks for completion notifications
4. Implement retry logic for failed requests

---

## Code Examples

### PHP (Laravel)
```php
use Illuminate\Support\Facades\Http;

// Single QR code
$response = Http::get(route('pcspecs.qrcode', [
    'pcspec' => 123,
]), [
    'format' => 'png',
    'size' => 256,
    'metadata' => true,
]);

file_put_contents('qr-code.png', $response->body());

// Bulk QR codes
$response = Http::post(route('pcspecs.qrcode.bulk'), [
    'pc_spec_ids' => [1, 2, 3, 4, 5],
    'format' => 'png',
    'size' => 256,
    'metadata' => false,
]);

file_put_contents('qr-codes.zip', $response->body());
```

### JavaScript (Fetch)
```javascript
// Single QR code
async function downloadQRCode(pcId) {
    const response = await fetch(`/pcspecs/${pcId}/qrcode?format=png&size=256`);
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.href = url;
    a.download = `pc-${pcId}-qr.png`;
    a.click();
    
    URL.revokeObjectURL(url);
}

// Bulk QR codes
async function downloadBulkQRCodes(pcIds) {
    const response = await fetch('/pcspecs/qrcode/bulk', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('[name=csrf-token]').content,
        },
        body: JSON.stringify({
            pc_spec_ids: pcIds,
            format: 'png',
            size: 256,
            metadata: false,
        }),
    });
    
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.href = url;
    a.download = `qr-codes-${Date.now()}.zip`;
    a.click();
    
    URL.revokeObjectURL(url);
}
```

### cURL
```bash
# Single QR code
curl -o qr-code.png \
  -H "Cookie: laravel_session=..." \
  "https://yourdomain.com/pcspecs/123/qrcode?format=png&size=256"

# Bulk QR codes
curl -X POST \
  -H "Content-Type: application/json" \
  -H "Cookie: laravel_session=..." \
  -d '{"pc_spec_ids":[1,2,3],"format":"png","size":256}' \
  -o qr-codes.zip \
  "https://yourdomain.com/pcspecs/qrcode/bulk"
```

### Python (Requests)
```python
import requests

# Single QR code
response = requests.get(
    'https://yourdomain.com/pcspecs/123/qrcode',
    params={'format': 'png', 'size': 256, 'metadata': True},
    cookies={'laravel_session': '...'}
)

with open('qr-code.png', 'wb') as f:
    f.write(response.content)

# Bulk QR codes
response = requests.post(
    'https://yourdomain.com/pcspecs/qrcode/bulk',
    json={
        'pc_spec_ids': [1, 2, 3, 4, 5],
        'format': 'png',
        'size': 256,
        'metadata': False
    },
    cookies={'laravel_session': '...'}
)

with open('qr-codes.zip', 'wb') as f:
    f.write(response.content)
```

---

## Testing

### Test Single QR Code Generation
```bash
curl -i "http://localhost:8000/pcspecs/1/qrcode?format=png&size=256"
```

Expected:
```
HTTP/1.1 200 OK
Content-Type: image/png
Content-Disposition: inline; filename="pc-1-qrcode.png"

[Binary PNG data]
```

### Test Bulk QR Code Generation
```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"pc_spec_ids":[1,2,3]}' \
  "http://localhost:8000/pcspecs/qrcode/bulk" \
  -o test-qr-codes.zip
```

Expected:
```
HTTP/1.1 200 OK
Content-Type: application/zip
Content-Disposition: attachment; filename="pc-qrcodes-2025-10-24-143022.zip"

[Binary ZIP data]
```

---

## Changelog

### Version 1.0.0 (2025-10-24)
- Initial release
- Single QR code generation (PNG, SVG)
- Bulk QR code generation (ZIP)
- Metadata support
- Configurable size and format

---

**Documentation Version:** 1.0.0  
**Last Updated:** October 24, 2025  
**API Version:** 1.0
