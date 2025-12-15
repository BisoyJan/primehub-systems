# Setup & Configuration Documentation

This directory contains technical setup guides for server configuration and feature enablement.

---

## 📄 Documents

### [PHP_EXTENSIONS_SETUP.md](PHP_EXTENSIONS_SETUP.md)
**PHP Extensions for QR Code Generation**

Complete guide to enabling required PHP extensions for QR code functionality.

**Topics Covered:**
- ✅ **Required Extensions:** GD (image generation) and ZIP (archive creation)
- ✅ **Production Setup:** Ubuntu/Debian and CentOS/RHEL instructions
- ✅ **Windows Setup:** XAMPP, Laragon, and manual PHP configurations
- ✅ **Verification:** Checking if extensions are enabled
- ✅ **Troubleshooting:** Common issues and solutions
- ✅ **Alternative Solutions:** If extensions can't be installed

**Best For:**
- Production server setup
- Enabling QR code features
- Resolving extension-related errors
- Windows development environment

**Requirements:**
- `gd` extension - PNG image generation
- `zip` extension - ZIP archive creation
- PHP 8.2 or higher

---

### [QR_CODE_ZIP_GENERATION_SETUP_GUIDE.MD](QR_CODE_ZIP_GENERATION_SETUP_GUIDE.MD)
**QR Code Feature Setup**

*(Note: This file is currently empty. It should contain setup instructions for the QR code generation feature.)*

**Planned Topics:**
- QR code generation for PC specs
- QR code generation for stations
- Bulk ZIP download functionality
- Job queue configuration
- Storage management

---

## 🔗 Related Documentation

### In Project Root
- **[../../README.md](../../README.md)** - Project overview
- **[../../.github/copilot-instructions.md](../../.github/copilot-instructions.md)** - Project architecture

### In Guides Folder
- **[../guides/LOCAL_SETUP_GUIDE.md](../guides/LOCAL_SETUP_GUIDE.md)** - Complete local environment setup
- **[../guides/README.md](../guides/README.md)** - Deployment guides index

---

## 🎯 Quick Reference

### Required PHP Extensions

| Extension | Purpose | Required For |
|-----------|---------|--------------|
| **gd** | Image manipulation | QR code PNG generation |
| **zip** | Archive creation | Bulk QR code downloads |
| pdo_mysql | Database | Core functionality |
| mbstring | String handling | Core functionality |
| redis | Caching/Queue | Performance |

### Verification Commands

```bash
# Check if extensions are enabled
php -m | grep -i gd
php -m | grep -i zip

# Check PHP version
php -v

# View PHP configuration
php -i | grep -i gd
php -i | grep -i zip
```

### Production Checklist

- [ ] PHP 8.2+ installed
- [ ] GD extension enabled
- [ ] ZIP extension enabled
- [ ] Web server restarted (Apache/Nginx)
- [ ] PHP-FPM restarted (if applicable)
- [ ] Extensions verified with `php -m`
- [ ] Test QR code generation
- [ ] Test ZIP download

---

## 🚀 Setup Instructions

### For Production Servers

#### Ubuntu/Debian
```bash
# Install extensions
sudo apt update
sudo apt install php-gd php-zip

# Restart services
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx  # or apache2
```

#### CentOS/RHEL
```bash
# Install extensions
sudo yum install php-gd php-pecl-zip

# Restart services
sudo systemctl restart php-fpm
sudo systemctl restart nginx  # or httpd
```

See **[PHP_EXTENSIONS_SETUP.md](PHP_EXTENSIONS_SETUP.md)** for detailed instructions.

### For Windows Development

#### XAMPP
1. Open `php.ini` (C:\xampp\php\php.ini)
2. Uncomment: `extension=gd` and `extension=zip`
3. Restart Apache

#### Laragon
1. Extensions usually enabled by default
2. Verify with `php -m`

See **[PHP_EXTENSIONS_SETUP.md](PHP_EXTENSIONS_SETUP.md)** for detailed instructions.

---

## 🔧 Common Tasks

### Enabling PHP Extensions

**On Linux:**
```bash
# Install
sudo apt install php-gd php-zip

# Verify
php -m | grep gd
php -m | grep zip

# Restart
sudo systemctl restart php8.2-fpm
```

**On Windows:**
```ini
; In php.ini, uncomment:
extension=gd
extension=zip
```

### Testing QR Code Feature

1. Navigate to PC Specs page
2. Select records
3. Click "Generate QR Codes"
4. Verify ZIP download works

### Troubleshooting Extension Errors

**Error:** "Call to undefined function imagecreatefrompng()"
- **Solution:** Enable GD extension

**Error:** "Class ZipArchive not found"
- **Solution:** Enable ZIP extension

**Error:** Extensions not loading
- **Solution:** Check `php.ini` path with `php --ini`

See **[PHP_EXTENSIONS_SETUP.md](PHP_EXTENSIONS_SETUP.md)** for complete troubleshooting.

---

## 📊 Feature Dependencies

### QR Code Generation
**Dependencies:**
- GD extension (required)
- endroid/qr-code package (installed via Composer)
- Storage directory writable

**Used By:**
- PC Spec QR codes
- Station QR codes
- Bulk QR code downloads

**Job Classes:**
- `GenerateAllPcSpecQRCodesZip.php`
- `GenerateSelectedPcSpecQRCodesZip.php`
- `GenerateAllStationQRCodesZip.php`
- `GenerateSelectedStationQRCodesZip.php`

### Storage Requirements
- Temp storage for QR code generation
- Public storage for downloadable ZIPs
- Queue worker for background processing

---

## 🎓 Learning Path

### For DevOps/System Admins
1. **[PHP_EXTENSIONS_SETUP.md](PHP_EXTENSIONS_SETUP.md)**
   - Learn required extensions
   - Production server setup
   - Verification steps

2. **[../guides/LOCAL_SETUP_GUIDE.md](../guides/LOCAL_SETUP_GUIDE.md)**
   - Complete environment setup
   - All prerequisites
   - Configuration files

3. Test QR code feature
   - Generate single QR code
   - Generate bulk ZIP
   - Verify job queue

### For Developers
1. Review extension requirements
2. Enable on local machine
3. Test QR code generation locally
4. Review job classes in `app/Jobs/`

---

## 📝 Configuration Files

### php.ini
```ini
; Required extensions
extension=gd
extension=zip

; Recommended settings
memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 10M
max_execution_time = 300
```

### .env (Laravel)
```env
# Queue configuration (for QR generation jobs)
QUEUE_CONNECTION=redis

# Storage configuration
FILESYSTEM_DISK=public
```

---

## 🧪 Testing Checklist

### Extension Verification
- [ ] Run `php -m | grep gd` → Shows "gd"
- [ ] Run `php -m | grep zip` → Shows "zip"
- [ ] Run `php -i | grep GD` → Shows GD version info

### Feature Testing
- [ ] Generate single QR code → PNG created
- [ ] Generate bulk ZIP → ZIP downloads
- [ ] Job queue processes → No errors in logs
- [ ] Storage cleanup → Old files removed

### Production Testing
- [ ] Test on production server
- [ ] Verify web server user permissions
- [ ] Check storage directory writable
- [ ] Monitor job queue processing

---

## 💡 Pro Tips

### Performance
- Use Redis for queue (faster than database)
- Enable OPcache for PHP performance
- Monitor job queue processing time

### Storage Management
- Implement cleanup for old QR codes
- Set up storage monitoring
- Use symbolic links for public storage

### Security
- Restrict direct access to QR code files (if sensitive)
- Validate input for QR code generation
- Rate limit bulk generation requests

---

## 🔐 Security Considerations

### File Storage
- QR codes stored in non-web-accessible directory
- Downloaded via controller (authentication check)
- Temporary files cleaned up regularly

### Input Validation
- Validate PC spec/station IDs
- Limit bulk generation quantity
- Authentication required for all operations

---

## 🆘 Getting Help

### Extension Issues
→ See **[PHP_EXTENSIONS_SETUP.md](PHP_EXTENSIONS_SETUP.md)** troubleshooting section

### General Setup
→ See **[../guides/LOCAL_SETUP_GUIDE.md](../guides/LOCAL_SETUP_GUIDE.md)**

### QR Code Feature Issues
1. Check PHP extensions enabled
2. Verify job queue running
3. Check storage permissions
4. Review logs in `storage/logs/`

---

## 📚 External Resources

- **GD Extension:** https://www.php.net/manual/en/book.image.php
- **ZIP Extension:** https://www.php.net/manual/en/book.zip.php
- **endroid/qr-code:** https://github.com/endroid/qr-code
- **Laravel Queues:** https://laravel.com/docs/queues

---

**Need Help?**
- PHP extensions → [PHP_EXTENSIONS_SETUP.md](PHP_EXTENSIONS_SETUP.md)
- Full setup → [../guides/LOCAL_SETUP_GUIDE.md](../guides/LOCAL_SETUP_GUIDE.md)
- Production deployment → [../guides/README.md](../guides/README.md)

---

*Last updated: December 15, 2025*
