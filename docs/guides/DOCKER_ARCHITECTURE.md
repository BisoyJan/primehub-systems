# Docker Architecture - PrimeHub Systems

## System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         Docker Network                          │
│                      (primehub-network)                         │
│                                                                 │
│  ┌──────────────┐         ┌──────────────┐                    │
│  │   Browser    │────────▶│    Nginx     │                    │
│  │              │  :80    │  Web Server  │                    │
│  └──────────────┘         └──────┬───────┘                    │
│                                   │                            │
│                                   │ Proxy                      │
│                                   │                            │
│         ┌─────────────────────────┼────────────────────┐      │
│         │                         │                    │      │
│         ▼                         ▼                    ▼      │
│  ┌─────────────┐          ┌─────────────┐     ┌────────────┐ │
│  │    Node     │          │     App     │     │   Queue    │ │
│  │  Container  │          │  Container  │     │  Worker    │ │
│  │             │          │             │     │            │ │
│  │ Vite Dev    │          │ PHP-FPM     │     │ Laravel    │ │
│  │ Server      │          │ Laravel     │     │ Queue      │ │
│  │   :5173     │          │             │     │            │ │
│  └─────────────┘          └──────┬──────┘     └─────┬──────┘ │
│                                   │                  │        │
│                                   │                  │        │
│                          ┌────────┴──────────────────┘        │
│                          │                                    │
│                          │                                    │
│                 ┌────────┴─────────┐                         │
│                 │                  │                         │
│                 ▼                  ▼                         │
│          ┌─────────────┐    ┌─────────────┐                │
│          │    MySQL    │    │    Redis    │                │
│          │  Database   │    │   Cache &   │                │
│          │   :3306     │    │   Queue     │                │
│          │             │    │   :6379     │                │
│          └─────────────┘    └─────────────┘                │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

## Container Responsibilities

### 1. Nginx Container
**Image:** `nginx:alpine`
**Port:** 80 (external), 443 (SSL)
**Purpose:**
- Serves static files from `public/`
- Proxies PHP requests to App container
- Proxies Vite dev requests to Node container
- Handles SSL termination (production)
- Load balancing (if scaled)

**Volume Mounts:**
- `./public` → `/var/www/public` (static files)
- `./docker/nginx/conf.d` → `/etc/nginx/conf.d` (config)

### 2. App Container
**Image:** Custom PHP 8.2-FPM
**Port:** 9000 (internal)
**Purpose:**
- Runs Laravel application
- Processes PHP requests
- Executes Artisan commands
- Serves API endpoints

**Volume Mounts:**
- `./` → `/var/www` (application code)
- `./storage` → `/var/www/storage` (file storage)
- `./bootstrap/cache` → `/var/www/bootstrap/cache` (cache)

**Installed Extensions:**
- PDO MySQL, mbstring, exif, pcntl
- bcmath, GD, zip, opcache

### 3. Node Container
**Image:** `node:20-alpine`
**Port:** 5173 (Vite dev server)
**Purpose:**
- Runs Vite development server
- Provides Hot Module Replacement (HMR)
- Builds production assets
- Manages npm dependencies

**Volume Mounts:**
- `./` → `/var/www` (source code)

### 4. MySQL Container
**Image:** `mysql:8.0`
**Port:** 3306
**Purpose:**
- Relational database
- Stores application data
- Handles transactions

**Volume Mounts:**
- `mysql-data` → `/var/lib/mysql` (persistent data)

**Environment Variables:**
- `MYSQL_DATABASE`: primehub
- `MYSQL_USER`: primehub_user
- `MYSQL_PASSWORD`: secret

### 5. Redis Container
**Image:** `redis:alpine`
**Port:** 6379
**Purpose:**
- Cache storage
- Session storage
- Queue backend
- Real-time data

**Volume Mounts:**
- `redis-data` → `/data` (persistent data)

### 6. Queue Worker Container
**Image:** Same as App container
**Purpose:**
- Processes background jobs
- Handles QR code generation
- Manages long-running tasks
- Email sending

**Command:** `php artisan queue:work --tries=3`

## Data Flow

### 1. HTTP Request Flow
```
Browser
  ↓ HTTP Request
Nginx (Port 80)
  ↓ FastCGI
App Container (PHP-FPM)
  ↓ Query
MySQL/Redis
  ↓ Response
App Container
  ↓ Response
Nginx
  ↓ HTTP Response
Browser
```

### 2. Vite Dev Server Flow (Development)
```
Browser
  ↓ Request /resources/js/...
Nginx
  ↓ Proxy to :5173
Node Container (Vite)
  ↓ Serve Module
Browser
  ↑ HMR WebSocket
Node Container
```

### 3. Queue Job Flow
```
App Container
  ↓ Dispatch Job
Redis (Queue)
  ↓ Job Data
Queue Worker Container
  ↓ Process Job
MySQL/Redis/External Service
  ↓ Update Status
Redis (Queue)
```

## Network Communication

### Internal Communication (Docker Network)
- `app` → `db:3306` (MySQL)
- `app` → `redis:6379` (Redis)
- `queue` → `db:3306` (MySQL)
- `queue` → `redis:6379` (Redis)
- `nginx` → `app:9000` (PHP-FPM)
- `nginx` → `node:5173` (Vite Proxy)

### External Communication (Host)
- `localhost:80` → `nginx:80`
- `localhost:3306` → `db:3306`
- `localhost:6379` → `redis:6379`
- `localhost:5173` → `node:5173`

## Volume Management

### Named Volumes (Persistent)
```
mysql-data/      # Database files
├── ibdata1
├── mysql/
├── primehub/
└── ...

redis-data/      # Redis snapshots
├── dump.rdb
└── appendonly.aof
```

### Bind Mounts (Development)
```
Project Root → /var/www
├── app/
├── resources/
├── storage/
├── public/
└── ...
```

## Environment Flow

### Development
```
.env.docker → .env
      ↓
docker-compose.yml
      ↓
Containers with ENV vars
      ↓
Laravel reads config
```

### Production
```
.env.production → .env
      ↓
docker-compose.production.yml
      ↓
Containers with ENV vars
      ↓
Laravel reads config (cached)
```

## Scaling Possibilities

### Horizontal Scaling
```
                    ┌──────────┐
                    │  Nginx   │
                    │  (Load   │
                    │ Balancer)│
                    └────┬─────┘
                         │
          ┌──────────────┼──────────────┐
          │              │              │
     ┌────▼───┐     ┌────▼───┐    ┌────▼───┐
     │ App 1  │     │ App 2  │    │ App 3  │
     └────┬───┘     └────┬───┘    └────┬───┘
          │              │              │
          └──────────────┼──────────────┘
                         │
                    ┌────▼───┐
                    │ MySQL  │
                    │ Redis  │
                    └────────┘
```

### Queue Worker Scaling
```yaml
queue:
  deploy:
    replicas: 3  # Run 3 queue workers
```

## Performance Optimization

### Nginx Layer
- Static file caching
- Gzip compression
- Connection pooling
- FastCGI caching

### Application Layer
- OPcache for PHP bytecode
- Redis for session/cache
- Query optimization
- Eager loading

### Database Layer
- Connection pooling
- Query caching
- Index optimization
- Read replicas (advanced)

## Monitoring Points

### Health Checks
```bash
# App container
docker-compose exec app php artisan inspire

# Database
docker-compose exec db mysqladmin ping

# Redis
docker-compose exec redis redis-cli ping

# Queue worker
docker-compose logs queue | grep "Processing"
```

### Log Locations
```
Nginx:        docker-compose logs nginx
App:          docker-compose logs app
             + storage/logs/laravel.log
Queue:        docker-compose logs queue
MySQL:        docker-compose logs db
Redis:        docker-compose logs redis
```

## Security Layers

### Network Isolation
- Containers communicate via internal network
- Only necessary ports exposed to host
- Firewall rules on host

### Data Protection
- Environment variables for secrets
- Volume encryption (optional)
- SSL/TLS for external communication
- Redis password protection (production)

### Process Isolation
- Each service in separate container
- Limited user permissions
- Read-only mounts where possible
- Resource limits (CPU/Memory)

## Backup Strategy

### Database Backups
```bash
# Manual backup
docker-compose exec db mysqldump primehub > backup.sql

# Automated (production)
# Backup container runs daily backups
```

### Application Backups
```bash
# Storage directory
tar -czf storage-backup.tar.gz storage/

# Database + Storage
./backup-script.sh
```

## Development vs Production

| Feature | Development | Production |
|---------|-------------|------------|
| Debug Mode | ON | OFF |
| Vite | Dev Server (HMR) | Built Assets |
| Cache | Off | Redis |
| Queue Worker | Manual/Auto | Supervisor |
| Volumes | Bind Mounts | Named Volumes |
| Build | Development | Multi-stage (optimized) |
| Logging | Verbose | Error Level |
| SSL | Optional | Required |

---

This architecture provides:
- ✅ Scalability
- ✅ Maintainability
- ✅ Security
- ✅ Performance
- ✅ Easy Development
- ✅ Production-Ready
