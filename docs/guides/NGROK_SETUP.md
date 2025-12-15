# Ngrok Setup Guide

This guide explains how to set up ngrok tunneling for development access from external devices.

## Problem

When running the app locally and using ngrok to share it with other devices, those devices get errors like:
```
GET http://localhost:5174/@vite/client net::ERR_CONNECTION_REFUSED
```

This happens because the browser tries to load Vite assets from `localhost:5174`, which doesn't exist on the external device.

## Solution

Configure Vite to use the ngrok URLs for asset serving and Hot Module Replacement (HMR).

## Setup Steps

### 1. Start ngrok and get your URLs

When you run `composer run dev:ngrok`, ngrok will start and display URLs like:
```
Forwarding  https://xxxx-xxx-xxx-xxx.ngrok-free.app -> http://localhost:8000
Forwarding  https://yyyy-yyy-yyy-yyy.ngrok-free.app -> http://localhost:5174
```

You'll need BOTH URLs:
- **Laravel app URL**: The one pointing to port 8000
- **Vite dev server URL**: The one pointing to port 5174

### 2. Create/Update your .env file

Add or update these variables in your `.env` file:

```env
# Your main ngrok URL (for the Laravel app on port 8000)
APP_URL=https://xxxx-xxx-xxx-xxx.ngrok-free.app

# Vite configuration for ngrok
VITE_HOST=0.0.0.0
VITE_HMR_HOST=yyyy-yyy-yyy-yyy.ngrok-free.app
VITE_HMR_PROTOCOL=wss
VITE_HMR_PORT=443

# Make sure ASSET_URL uses the Vite ngrok URL
ASSET_URL=https://yyyy-yyy-yyy-yyy.ngrok-free.app
```

**Important Notes:**
- `VITE_HMR_HOST` should be the **hostname only** (without `https://`)
- `VITE_HMR_PROTOCOL` should be `wss` (WebSocket Secure) for ngrok
- `VITE_HMR_PORT` should be `443` for ngrok's HTTPS tunnel
- `ASSET_URL` should point to the Vite ngrok URL

### 3. Restart the development servers

Stop the current `composer run dev:ngrok` command and restart it:

```bash
composer run dev:ngrok
```

### 4. Access from other devices

Now you can access the app from other devices using the Laravel ngrok URL:
```
https://xxxx-xxx-xxx-xxx.ngrok-free.app
```

The Vite assets will load correctly through the Vite ngrok tunnel.

## Alternative Methods

### Option 1: Manual ngrok Command (Recommended - Simplest)

Instead of `composer run dev:ngrok`, run the servers separately:

**Step 1: Start the dev servers normally**
```bash
composer run dev
```

**Step 2: In another terminal, start ngrok**
```bash
ngrok http 8000
```

**Step 3: Update your .env**
```env
APP_URL=https://xxxx.ngrok-free.app
ASSET_URL=${APP_URL}
```

**Step 4: Restart dev servers**
```bash
# Stop composer run dev (Ctrl+C) and restart
composer run dev
```

This works because:
- Vite runs on `0.0.0.0:5174` (accessible from your local network)
- Ngrok tunnels Laravel (port 8000) which proxies Vite requests
- No need for a second ngrok tunnel

### Option 2: Production Build (No Vite Dev Server)

For testing without hot reload:

**Step 1: Build the frontend**
```bash
npm run build
```

**Step 2: Start only Laravel and ngrok**
```bash
# Terminal 1
php artisan serve

# Terminal 2
ngrok http 8000
```

**Step 3: Update .env**
```env
APP_URL=https://xxxx.ngrok-free.app
```

No Vite dev server needed - uses compiled assets.

### Option 3: Local Network Access (No ngrok)

If devices are on the same network:

**Step 1: Find your local IP**
```bash
ipconfig  # Windows
# Look for IPv4 Address (e.g., 192.168.1.100)
```

**Step 2: Update .env**
```env
APP_URL=http://192.168.1.100:8000
```

**Step 3: Run dev servers**
```bash
composer run dev
```

**Step 4: Access from other devices**
```
http://192.168.1.100:8000
```

No ngrok needed if on same WiFi/network.

### Option 4: Custom Ngrok Command

Create a simpler command without the full dev stack:

Add to `composer.json` scripts:
```json
"ngrok": "ngrok http 8000"
```

Then run separately:
```bash
composer run dev    # Terminal 1
composer run ngrok  # Terminal 2
```

## Troubleshooting

### Still getting localhost errors?

1. Make sure you restarted the dev servers after updating `.env`
2. Clear your browser cache on the external device
3. Check that the ngrok URLs in `.env` match what ngrok displays

### Ngrok URLs keep changing?

Free ngrok accounts get random URLs each time. Consider:
- Using ngrok's fixed domain feature (paid)
- Or update `.env` each time you restart ngrok

### HMR not working?

1. Check browser console for WebSocket connection errors
2. Verify `VITE_HMR_HOST` is correct (hostname only, no protocol)
3. Make sure `VITE_HMR_PROTOCOL=wss` and `VITE_HMR_PORT=443`

### CSRF token mismatch errors?

Make sure `SESSION_DOMAIN` in `.env` is not set, or set it to your ngrok domain:
```env
SESSION_DOMAIN=.xxxx-xxx-xxx-xxx.ngrok-free.app
```

## For Local Development (without ngrok)

When developing locally without ngrok, you can remove or comment out the Vite variables:

```env
APP_URL=http://localhost:8000
# VITE_HOST=0.0.0.0
# VITE_HMR_HOST=
# VITE_HMR_PROTOCOL=
# VITE_HMR_PORT=
# ASSET_URL=
```

Or just use the defaults without setting them.

---

*Last updated: December 15, 2025*
