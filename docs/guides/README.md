# PrimeHub Systems - Documentation & Guides

Welcome to the PrimeHub Systems documentation! This directory contains comprehensive guides to help you set up, deploy, and manage the application.

---

## 📚 Available Guides

###  Local Development Setup

#### [LOCAL_SETUP_GUIDE.md](./LOCAL_SETUP_GUIDE.md)
**Running the project without Docker**
- PHP, MySQL, Redis setup on Windows
- Local development environment
- Step-by-step installation
- Common issues & solutions
- Performance tips

**Use this if:**
- You prefer native development
- Don't want to use Docker
- Need to debug PHP/Laravel directly
- Want lighter resource usage

---

### 🌐 Remote Access & Deployment

#### [NGROK_GUIDE.md](./NGROK_GUIDE.md) / [NGROK_SETUP.md](./NGROK_SETUP.md)
**Expose your local app to the internet**
- Ngrok installation & setup
- HTTPS configuration
- Laravel integration
- Vite HMR configuration for ngrok
- Sharing with clients/teams
- Security best practices

**Use this if:**
- Need to share work with remote team
- Testing on mobile devices
- Client demos
- Webhook testing

#### [VPS_HOSTING_COMPLETE_GUIDE.md](./VPS_HOSTING_COMPLETE_GUIDE.md)
**Complete VPS deployment guide (Production)**
- Hostinger VPS setup with Ubuntu 24.04
- Automated setup script
- Manual step-by-step instructions
- Nginx, PHP 8.4, MySQL, Redis configuration
- SSL with Let's Encrypt
- Supervisor queue workers
- Laravel scheduler setup
- Domain configuration

**Use this for:**
- Production deployment
- Full server control
- Custom infrastructure needs

#### [DIGITALOCEAN_DEPLOYMENT.md](./DIGITALOCEAN_DEPLOYMENT.md)
**DigitalOcean deployment options**
- App Platform deployment (managed, easiest)
- Droplet with Laravel Forge
- Manual Droplet setup
- CI/CD configuration
- Cost comparisons

**Use this for:**
- Quick cloud deployment
- Managed infrastructure
- Automatic scaling

#### [inactivity-logout.md](./inactivity-logout.md)
**Automatic logout on inactivity**
- Security feature documentation
- Backend middleware configuration
- Frontend hook implementation
- Customization options

---

## 🚀 Quick Start

### Choose Your Setup Method:

| Method | Best For | Time to Setup | Difficulty |
|--------|----------|---------------|------------|
| **Local** | Direct PHP debugging, full control | 30-45 min | ⭐⭐⭐ Medium |
| **Ngrok** | Remote access, quick demos | 5 min | ⭐ Very Easy |
| **VPS** | Production deployment | 1-2 hours | ⭐⭐⭐⭐ Advanced |
| **DigitalOcean** | Managed cloud hosting | 30 min | ⭐⭐ Easy |

### Recommended Path:

1. **Start with Local Setup** → [LOCAL_SETUP_GUIDE.md](./LOCAL_SETUP_GUIDE.md)
   - Direct PHP/Laravel debugging
   - Full control over environment
   - Includes MySQL, Redis, Queue setup

2. **Add Ngrok for remote access** → [NGROK_GUIDE.md](./NGROK_GUIDE.md)
   - Share your local instance
   - Test on real devices
   - Demo to clients

3. **Deploy to production** → [VPS_HOSTING_COMPLETE_GUIDE.md](./VPS_HOSTING_COMPLETE_GUIDE.md)
   - Full VPS setup with automated script
   - Production-ready configuration
   - SSL, domain, and monitoring

---

## 📖 Guide Usage Flowchart

```
Are you setting up for the first time?
│
├─ YES → Local development or Production?
│        │
│        ├─ Local → Follow LOCAL_SETUP_GUIDE.md
│        │         │
│        │         └─ Need remote access? → Follow NGROK_GUIDE.md
│        │
│        └─ Production → Choose hosting:
│                       │
│                       ├─ Own VPS → VPS_HOSTING_COMPLETE_GUIDE.md
│                       └─ Cloud Platform → DIGITALOCEAN_DEPLOYMENT.md
│
└─ NO → Already running?
         │
         ├─ Need security features? → Check inactivity-logout.md
         ├─ Need remote access? → Follow NGROK_GUIDE.md
         └─ Deploying to production? → VPS or DigitalOcean guides
```

---

## 🎯 Common Tasks

### Starting the Application

**Local Development:**
```bash
php artisan serve         # Terminal 1
npm run dev               # Terminal 2
php artisan queue:work    # Terminal 3
```
[Details in LOCAL_SETUP_GUIDE.md](./LOCAL_SETUP_GUIDE.md)

### Accessing from Other Devices

**Local Network:**
- Find your IP: `ipconfig` (Windows) or `ifconfig` (Linux/Mac)
- Access via: `http://YOUR_IP:8000`
- [Details in NGROK_SETUP.md](./NGROK_SETUP.md#option-3-local-network-access-no-ngrok)

**Internet (anywhere):**
- Use Ngrok: `ngrok http 8000`
- [Full guide in NGROK_GUIDE.md](./NGROK_GUIDE.md)

### Database Management

**Local MySQL:**
```bash
Host: 127.0.0.1
Port: 3306
User: root (or your configured user)
Password: your_mysql_password
Database: primehub_systems
```

**Production (VPS):**
```bash
Host: localhost (when SSH'd into server)
User: primehub_user
Database: primehub_systems
```

### Running Migrations

**Local:**
```bash
php artisan migrate
```

**Production (VPS):**
```bash
ssh primehub@your-server-ip
cd /var/www/primehub-systems
php artisan migrate --force
```

---

## 🔧 Troubleshooting

### Local Setup Issues
→ See [LOCAL_SETUP_GUIDE.md](./LOCAL_SETUP_GUIDE.md) common issues section

### Ngrok/Remote Access Issues
→ See [NGROK_GUIDE.md](./NGROK_GUIDE.md) and [NGROK_SETUP.md](./NGROK_SETUP.md) troubleshooting sections

### VPS/Production Issues
→ See [VPS_HOSTING_COMPLETE_GUIDE.md](./VPS_HOSTING_COMPLETE_GUIDE.md) troubleshooting section

### General Issues

| Problem | Solution | Guide |
|---------|----------|-------|
| Port conflicts | Change ports in .env or server config | LOCAL_SETUP_GUIDE.md |
| Queue not working | Check queue worker process is running | LOCAL_SETUP_GUIDE.md, VPS guide |
| HTTPS/SSL errors | Configure APP_URL and ASSET_URL in .env | NGROK_GUIDE.md |
| Database connection failed | Verify credentials in .env | All guides |
| Vite not loading | Restart npm run dev | LOCAL_SETUP_GUIDE.md, NGROK_SETUP.md |

---

## 📂 Project Documentation Structure

```
docs/
├── guides/
│   ├── README.md                         ← You are here
│   ├── LOCAL_SETUP_GUIDE.md              ← Local development setup
│   ├── NGROK_GUIDE.md                    ← Remote access (Docker/local)
│   ├── NGROK_SETUP.md                    ← Ngrok + Vite configuration
│   ├── VPS_HOSTING_COMPLETE_GUIDE.md     ← Production VPS deployment
│   ├── DIGITALOCEAN_DEPLOYMENT.md        ← DigitalOcean cloud deployment
│   └── inactivity-logout.md              ← Security feature docs
│
├── accounts/                             ← User management docs
├── api/                                  ← API routes reference
├── attendance/                           ← Attendance system docs
├── authorization/                        ← Permissions & roles
├── biometric/                            ← Biometric records
├── computer/                             ← PC specs & hardware
├── database/                             ← Database schema
├── form-requests/                        ← Leave, IT, Medication requests
├── leave/                                ← Leave management
├── QR/                                   ← QR code generation
├── setup/                                ← Initial setup guides
└── stations/                             ← Station management
```

---

## 🆘 Need More Help?

### Check Existing Documentation
1. Look in the appropriate guide above
2. Check the troubleshooting sections
3. Review the quick reference commands

### Common Resources
- **Laravel Docs**: https://laravel.com/docs
- **Docker Docs**: https://docs.docker.com
- **Inertia.js Docs**: https://inertiajs.com
- **React Docs**: https://react.dev

### Project-Specific Files
- `.github/copilot-instructions.md` - AI coding assistant guidelines
- `REFACTORING_GUIDE.md` - Code refactoring standards
- `composer.json` - PHP dependencies
- `package.json` - Node dependencies

---

## 🔄 Keeping Documentation Updated

When making changes to the project:

1. **Update relevant guide** if setup process changes
2. **Add new sections** for new features
3. **Update troubleshooting** with solutions to new issues
4. **Keep command examples** current with latest syntax

---

## 📝 Guide Maintenance

| Guide | Last Updated | Maintained By |
|-------|--------------|---------------|
| README.md | December 15, 2025 | Development Team |
| LOCAL_SETUP_GUIDE.md | December 15, 2025 | Development Team |
| NGROK_GUIDE.md | December 15, 2025 | Development Team |
| NGROK_SETUP.md | December 15, 2025 | Development Team |
| VPS_HOSTING_COMPLETE_GUIDE.md | December 15, 2025 | Development Team |
| DIGITALOCEAN_DEPLOYMENT.md | December 15, 2025 | Development Team |
| inactivity-logout.md | December 15, 2025 | Development Team |

---

**Happy developing! 🚀**

For questions or issues not covered in these guides, please consult the project maintainers or open an issue.
