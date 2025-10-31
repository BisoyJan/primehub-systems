# Ngrok Guide - Expose Local Docker App to the Internet

This guide explains how to use Ngrok to make your local PrimeHub Systems application accessible from anywhere on the internet.

---

## What is Ngrok?

Ngrok is a tool that creates a secure tunnel from the public internet to your local machine. It's perfect for:
- 🌍 Sharing your local development with clients/team members
- 📱 Testing on mobile devices
- 🔗 Webhook testing
- 🎯 Quick demos without deployment

---

## Prerequisites

- ✅ Docker containers running (`docker-compose up -d`)
- ✅ Application accessible at `http://localhost`
- ✅ Ngrok account (free at https://ngrok.com)

---

## Installation

### Windows (Already Installed)

Ngrok is already installed on your system at:
```
C:\Users\bisoy\AppData\Local\Microsoft\WindowsApps\ngrok
```

### Fresh Installation (if needed)

1. **Download**: https://ngrok.com/download
2. **Extract** to a folder (e.g., `C:\ngrok`)
3. **Add to PATH** (optional) or use full path

---

## Setup (One-Time Only)

### Step 1: Create Ngrok Account

1. Go to https://dashboard.ngrok.com/signup
2. Sign up with email or Google
3. Verify your email

### Step 2: Get Authentication Token

1. Go to https://dashboard.ngrok.com/get-started/your-authtoken
2. Copy your authtoken (looks like: `2abc123def456...`)

### Step 3: Configure Ngrok

Run this command **once** to save your authtoken:

```bash
ngrok config add-authtoken YOUR_AUTH_TOKEN_HERE
```

Example:
```bash
ngrok config add-authtoken 34Nn01u26WLjH5yUmVHekN1YJbk_4NqZ9a1gFN8mtN1Fc8vUe
```

✅ You'll see: `Authtoken saved to configuration file`

---

## Usage

### Starting Ngrok Tunnel

#### Basic Command:
```bash
ngrok http 80
```

This exposes your local port 80 (where nginx/Docker runs) to the internet.

#### With Custom Options:
```bash
# Specific region (faster for your location)
ngrok http 80 --region ap  # Asia Pacific
ngrok http 80 --region us  # United States
ngrok http 80 --region eu  # Europe

# With custom subdomain (requires paid plan)
ngrok http 80 --subdomain=primehub

# With basic auth (add password protection)
ngrok http 80 --basic-auth="username:password"
```

### Understanding the Output

When ngrok starts, you'll see:

```
Session Status                online
Account                       Jan Ramil Intong (Plan: Free)
Version                       3.24.0
Region                        Asia Pacific (ap)
Web Interface                 http://127.0.0.1:4040
Forwarding                    https://abc-xyz-123.ngrok-free.dev -> http://localhost:80

Connections                   ttl     opn     rt1     rt5     p50     p90
                              0       0       0.00    0.00    0.00    0.00
```

**Key Information:**
- **Forwarding**: Your public URL (share this!)
- **Web Interface**: Local dashboard to inspect requests
- **Connections**: Real-time traffic stats

---

## Configuring Laravel for Ngrok

### Step 1: Update `.env` File

Replace `APP_URL` and add `ASSET_URL` with your ngrok URL:

```env
APP_URL=https://your-ngrok-url.ngrok-free.dev
ASSET_URL=https://your-ngrok-url.ngrok-free.dev
```

**Example:**
```env
APP_URL=https://joetta-pseudoservile-linnea.ngrok-free.dev
ASSET_URL=https://joetta-pseudoservile-linnea.ngrok-free.dev
```

### Step 2: Clear Laravel Cache

After updating `.env`:

```bash
docker exec primehub-app php artisan config:clear
docker exec primehub-app php artisan cache:clear
docker exec primehub-app php artisan route:clear
```

Or use the shortcut:
```bash
docker exec primehub-app php artisan optimize:clear
```

### Step 3: Restart Containers (if needed)

```bash
docker-compose restart app nginx
```

---

## Complete Workflow

### Starting Everything

**Terminal 1: Start Docker**
```bash
cd C:\Users\bisoy\Desktop\Projects\primehub-systems
docker-compose up -d
```

**Terminal 2: Start Ngrok**
```bash
ngrok http 80
```

**Terminal 3: Update Laravel Config**
```bash
# Copy the ngrok URL from Terminal 2
# Update .env file with the new URL
# Then clear caches:
docker exec primehub-app php artisan optimize:clear
```

### Stopping Everything

1. **Stop Ngrok**: Press `Ctrl+C` in the ngrok terminal
2. **Stop Docker**: `docker-compose down`

---

## Ngrok Web Interface

While ngrok is running, access the web dashboard at:
```
http://127.0.0.1:4040
```

**Features:**
- 📊 View all HTTP requests in real-time
- 🔍 Inspect request/response headers and body
- 🔄 Replay requests for debugging
- 📈 See connection statistics
- ⚡ Monitor performance

---

## Common Issues & Solutions

### Issue 1: "ERR_NGROK_4018 - Authentication failed"

**Solution:**
```bash
ngrok config add-authtoken YOUR_AUTH_TOKEN
```

### Issue 2: Mixed Content Errors (HTTP/HTTPS)

**Solution:**
1. Update `APP_URL` in `.env` to use `https://`
2. Clear Laravel cache
3. Ensure `AppServiceProvider.php` forces HTTPS

### Issue 3: URL Changes Every Time

**Cause:** Free plan generates random URLs

**Solutions:**
- Paid plan ($8/month) for permanent subdomain
- Use Cloudflare Tunnel (free alternative)
- Update `.env` each time and clear cache

### Issue 4: 502 Bad Gateway

**Solution:**
1. Verify Docker is running: `docker-compose ps`
2. Restart containers: `docker-compose restart`
3. Check nginx logs: `docker-compose logs nginx`

### Issue 5: Session/Cookie Issues

**Solution:**
Add to `.env`:
```env
SESSION_DOMAIN=.ngrok-free.dev
SESSION_SECURE_COOKIE=true
```

Then clear cache:
```bash
docker exec primehub-app php artisan config:clear
```

---

## Quick Reference Commands

### Ngrok Commands
```bash
# Start tunnel
ngrok http 80

# Start with specific region
ngrok http 80 --region ap

# View version
ngrok version

# Update ngrok
ngrok update

# View config
ngrok config check

# View help
ngrok http --help
```

### Docker Commands
```bash
# Start containers
docker-compose up -d

# Stop containers
docker-compose down

# Restart specific container
docker-compose restart app

# View logs
docker-compose logs -f app
```

### Laravel Cache Commands
```bash
# Clear all caches
docker exec primehub-app php artisan optimize:clear

# Clear specific caches
docker exec primehub-app php artisan config:clear
docker exec primehub-app php artisan cache:clear
docker exec primehub-app php artisan route:clear
docker exec primehub-app php artisan view:clear
```

---

## Ngrok Plans Comparison

### Free Plan (Current)
- ✅ HTTPS enabled
- ✅ 1 online ngrok process
- ✅ 40 connections/minute
- ❌ Random URL each time
- ❌ 2-hour session timeout
- ❌ No custom domain

### Personal Plan ($8/month)
- ✅ Everything in Free
- ✅ Custom subdomain (`primehub.ngrok.io`)
- ✅ No session timeout
- ✅ 60 connections/minute
- ✅ 3 online processes

### Pro Plan ($20/month)
- ✅ Everything in Personal
- ✅ Custom domain (`app.yourdomain.com`)
- ✅ IP restrictions
- ✅ 120 connections/minute
- ✅ 10 online processes

---

## Alternatives to Ngrok

### 1. Cloudflare Tunnel (Free Forever)
```bash
# Better for production/long-term use
cloudflared tunnel --url http://localhost:80
```
- ✅ Free forever
- ✅ Custom domain
- ✅ Better performance
- ❌ Requires Cloudflare account

### 2. Tailscale (Private Network)
```bash
# Secure private access only
# Install Tailscale on all devices
```
- ✅ Free for personal use
- ✅ Secure private network
- ✅ No public exposure
- ❌ Requires installation on each device

### 3. LocalTunnel
```bash
npm install -g localtunnel
lt --port 80
```
- ✅ Free and open-source
- ✅ No account needed
- ❌ Less reliable
- ❌ No web interface

### 4. Serveo
```bash
ssh -R 80:localhost:80 serveo.net
```
- ✅ Free and simple
- ✅ No installation
- ❌ SSH access required
- ❌ Less features

---

## Security Best Practices

### 1. Use HTTPS (Ngrok does this automatically)
### 2. Add Basic Authentication (Optional)
```bash
ngrok http 80 --basic-auth="admin:secretpassword123"
```

### 3. Restrict IP Addresses (Paid plan)
```bash
ngrok http 80 --cidr-allow=203.0.113.0/24
```

### 4. Use Temporary URLs
- Don't share ngrok URLs publicly
- Regenerate URLs frequently
- Stop ngrok when not needed

### 5. Monitor Access
- Check ngrok dashboard (http://127.0.0.1:4040)
- Review access logs
- Watch for suspicious activity

---

## Troubleshooting Checklist

Before asking for help, verify:

- [ ] Docker containers are running: `docker-compose ps`
- [ ] Application works locally: http://localhost
- [ ] Ngrok is authenticated: `ngrok config check`
- [ ] `.env` has correct ngrok URL
- [ ] Laravel cache is cleared
- [ ] Windows Firewall allows connections
- [ ] No other process using port 80
- [ ] Ngrok terminal shows "online" status

---

## Example: Complete Setup Script

Save this as `start-ngrok.sh`:

```bash
#!/bin/bash

# Start Docker containers
echo "Starting Docker containers..."
docker-compose up -d

# Wait for containers to be ready
echo "Waiting for containers to start..."
sleep 10

# Start ngrok in background
echo "Starting ngrok tunnel..."
ngrok http 80 > /dev/null &

# Wait for ngrok to initialize
sleep 5

# Get ngrok URL
NGROK_URL=$(curl -s http://localhost:4040/api/tunnels | grep -o '"public_url":"https://[^"]*' | grep -o 'https://.*')

echo ""
echo "✅ Setup complete!"
echo "🌍 Public URL: $NGROK_URL"
echo "📊 Dashboard: http://127.0.0.1:4040"
echo ""
echo "⚠️  Don't forget to update .env with:"
echo "APP_URL=$NGROK_URL"
echo "ASSET_URL=$NGROK_URL"
echo ""
echo "Then run: docker exec primehub-app php artisan optimize:clear"
```

---

## Tips & Tricks

### 1. Save Ngrok URLs
Keep a log of your ngrok sessions:
```bash
ngrok http 80 | tee ngrok.log
```

### 2. Auto-Update .env (Advanced)
```bash
# Get ngrok URL programmatically
NGROK_URL=$(curl -s http://localhost:4040/api/tunnels | jq -r '.tunnels[0].public_url')
echo "APP_URL=$NGROK_URL" >> .env
```

### 3. Keep Ngrok Running
Use `screen` or `tmux` to keep ngrok running in background:
```bash
screen -S ngrok
ngrok http 80
# Press Ctrl+A then D to detach
```

### 4. Monitor Bandwidth
Check ngrok dashboard for data usage and optimize if needed.

### 5. Test Mobile Devices
Perfect for testing responsive design - just open the ngrok URL on your phone!

---

## Resources

- **Ngrok Documentation**: https://ngrok.com/docs
- **Ngrok Dashboard**: https://dashboard.ngrok.com
- **Ngrok API**: https://ngrok.com/docs/api
- **Community Forum**: https://github.com/inconshreveable/ngrok/issues
- **Status Page**: https://status.ngrok.com

---

## Quick Start Checklist

For daily use:

1. ☐ Start Docker: `docker-compose up -d`
2. ☐ Start Ngrok: `ngrok http 80`
3. ☐ Copy the HTTPS URL from ngrok output
4. ☐ Update `.env`: Change `APP_URL` and `ASSET_URL`
5. ☐ Clear cache: `docker exec primehub-app php artisan optimize:clear`
6. ☐ Test: Open the ngrok URL in browser
7. ☐ Share: Send URL to clients/team members

When done:
1. ☐ Stop Ngrok: `Ctrl+C`
2. ☐ Stop Docker: `docker-compose down`

---

**Remember:** 
- Keep the ngrok terminal open while sharing
- Update `.env` every time you restart ngrok (URL changes)
- Clear Laravel cache after updating `.env`
- Monitor the dashboard at http://127.0.0.1:4040

---

**Happy tunneling! 🚀**
