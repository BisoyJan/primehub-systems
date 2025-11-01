# PrimeHub Systems - Documentation & Guides

Welcome to the PrimeHub Systems documentation! This directory contains comprehensive guides to help you set up, deploy, and manage the application.

---

## 📚 Available Guides

### 🐋 Docker Setup Guides

#### [DOCKER_README.md](./DOCKER_README.md)
**Quick overview of Docker setup**
- Prerequisites and requirements
- Basic Docker commands
- Quick start guide
- Container overview

#### [DOCKER_SETUP.md](./DOCKER_SETUP.md)
**Complete Docker installation & configuration**
- Step-by-step setup instructions
- Environment configuration
- Database setup
- Troubleshooting tips

#### [DOCKER_ARCHITECTURE.md](./DOCKER_ARCHITECTURE.md)
**Understanding the Docker architecture**
- Container structure
- Service descriptions
- Network configuration
- Volume management
- Inter-service communication

#### [DOCKER_SUMMARY.md](./DOCKER_SUMMARY.md)
**Quick reference & cheat sheet**
- Common commands
- Port mappings
- Service URLs
- Maintenance tasks

#### [DOCKER_DEPLOYMENT_GUIDE.md](./DOCKER_DEPLOYMENT_GUIDE.md)
**Deploying to multiple computers (RECOMMENDED)**
- Using Docker on multiple PCs
- Syncing between computers
- Git-based deployment workflow
- Database transfer guide
- Docker Hub alternatives
- Best practices for multi-PC setup

---

### 💻 Local Development Setup

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

#### [NGROK_GUIDE.md](./NGROK_GUIDE.md)
**Expose your local app to the internet**
- Ngrok installation & setup
- HTTPS configuration
- Laravel integration
- Sharing with clients/teams
- Security best practices
- Alternatives to Ngrok

**Use this if:**
- Need to share work with remote team
- Testing on mobile devices
- Client demos
- Webhook testing

---

## 🚀 Quick Start

### Choose Your Setup Method:

| Method | Best For | Time to Setup | Difficulty |
|--------|----------|---------------|------------|
| **Docker** | Production-like environment, team consistency | 15-20 min | ⭐⭐ Easy |
| **Local** | Direct PHP debugging, lighter resources | 30-45 min | ⭐⭐⭐ Medium |
| **Ngrok** | Remote access, quick demos | 5 min | ⭐ Very Easy |

### Recommended Path:

1. **Start with Docker** → [DOCKER_SETUP.md](./DOCKER_SETUP.md)
   - Fastest way to get running
   - Consistent across all machines
   - Includes all services (MySQL, Redis, Queue)

2. **Add Ngrok for remote access** → [NGROK_GUIDE.md](./NGROK_GUIDE.md)
   - Share your local instance
   - Test on real devices
   - Demo to clients

3. **Optional: Local setup** → [LOCAL_SETUP_GUIDE.md](./LOCAL_SETUP_GUIDE.md)
   - For advanced debugging
   - Alternative to Docker

---

## 📖 Guide Usage Flowchart

```
Are you setting up for the first time?
│
├─ YES → Do you have Docker installed?
│        │
│        ├─ YES → Follow DOCKER_SETUP.md
│        │        │
│        │        └─ Need remote access? → Follow NGROK_GUIDE.md
│        │
│        └─ NO → Want to install Docker?
│                 │
│                 ├─ YES → Install Docker, then DOCKER_SETUP.md
│                 └─ NO → Follow LOCAL_SETUP_GUIDE.md
│
└─ NO → Already running?
         │
         ├─ Need to troubleshoot? → Check DOCKER_SUMMARY.md
         ├─ Want to understand architecture? → Read DOCKER_ARCHITECTURE.md
         └─ Need remote access? → Follow NGROK_GUIDE.md
```

---

## 🎯 Common Tasks

### Starting the Application

**With Docker:**
```bash
docker-compose up -d
```
[Details in DOCKER_SETUP.md](./DOCKER_SETUP.md)

**Without Docker:**
```bash
php artisan serve         # Terminal 1
npm run dev               # Terminal 2
php artisan queue:work    # Terminal 3
```
[Details in LOCAL_SETUP_GUIDE.md](./LOCAL_SETUP_GUIDE.md)

### Accessing from Other Devices

**Local Network:**
- Access via: `http://192.168.15.180`
- [Details in DOCKER_ARCHITECTURE.md](./DOCKER_ARCHITECTURE.md)

**Internet (anywhere):**
- Use Ngrok: `ngrok http 80`
- [Full guide in NGROK_GUIDE.md](./NGROK_GUIDE.md)

### Database Management

**With Docker:**
```bash
# HeidiSQL connection
Host: 127.0.0.1
Port: 3307
User: primehub_user
Password: secret
```

**Without Docker:**
```bash
Host: 127.0.0.1
Port: 3306
User: root
Password: your_mysql_password
```

### Running Migrations

**With Docker:**
```bash
docker exec primehub-app php artisan migrate
```

**Without Docker:**
```bash
php artisan migrate
```

---

## 🔧 Troubleshooting

### Docker Issues
→ See [DOCKER_SUMMARY.md](./DOCKER_SUMMARY.md) troubleshooting section

### Local Setup Issues
→ See [LOCAL_SETUP_GUIDE.md](./LOCAL_SETUP_GUIDE.md) common issues section

### Ngrok/Remote Access Issues
→ See [NGROK_GUIDE.md](./NGROK_GUIDE.md) troubleshooting section

### General Issues

| Problem | Solution | Guide |
|---------|----------|-------|
| Port conflicts | Change ports in docker-compose.yml or .env | DOCKER_SETUP.md |
| Queue not working | Check queue worker is running | DOCKER_ARCHITECTURE.md |
| HTTPS/SSL errors | Configure ASSET_URL in .env | NGROK_GUIDE.md |
| Database connection failed | Verify credentials in .env | All guides |
| Vite not loading | Restart node container/process | DOCKER_SUMMARY.md |

---

## 📂 Project Documentation Structure

```
docs/
├── guides/
│   ├── README.md                    ← You are here
│   ├── DOCKER_README.md             ← Docker overview
│   ├── DOCKER_SETUP.md              ← Docker installation
│   ├── DOCKER_ARCHITECTURE.md       ← Docker internals
│   ├── DOCKER_SUMMARY.md            ← Docker quick reference
│   ├── DOCKER_DEPLOYMENT_GUIDE.md   ← Multi-PC deployment
│   ├── LOCAL_SETUP_GUIDE.md         ← Local development
│   └── NGROK_GUIDE.md               ← Remote access
│
├── PHP_EXTENSIONS_SETUP.md          ← PHP extension configuration
└── QR_CODE_ZIP_GENERATION_SETUP_GUIDE.md  ← QR feature setup
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
| DOCKER_README.md | 2025-11-01 | Development Team |
| DOCKER_SETUP.md | 2025-11-01 | Development Team |
| DOCKER_ARCHITECTURE.md | 2025-11-01 | Development Team |
| DOCKER_SUMMARY.md | 2025-11-01 | Development Team |
| DOCKER_DEPLOYMENT_GUIDE.md | 2025-11-01 | Development Team |
| LOCAL_SETUP_GUIDE.md | 2025-11-01 | Development Team |
| NGROK_GUIDE.md | 2025-11-01 | Development Team |

---

**Happy developing! 🚀**

For questions or issues not covered in these guides, please consult the project maintainers or open an issue.
