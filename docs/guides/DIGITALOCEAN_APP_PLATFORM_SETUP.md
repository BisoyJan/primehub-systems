# DigitalOcean App Platform Deployment - Step by Step

Quick guide for deploying PrimeHub Systems to DigitalOcean App Platform.

## 📋 Prerequisites Checklist

- [x] DigitalOcean account (you have this ✓)
- [ ] GitHub account with access to `BisoyJan/primehub-systems`
- [ ] Configuration files committed to repository

---

## Step 1: Prepare Your Repository

### 1.1 Generate APP_KEY

Run this locally to generate your application key:

```bash
php artisan key:generate --show
```

**Copy the output** (looks like: `base64:xyz123abc...`) - you'll need this in Step 3.

### 1.2 Commit Configuration Files

The required files are already created:
- `.do/app.yaml` - App Platform configuration
- `.do/deploy.template.yaml` - Deployment template

**Commit and push these files:**

```bash
git add .do/
git commit -m "Add DigitalOcean App Platform configuration"
git push origin deploy
```

---

## Step 2: Create App in DigitalOcean

### 2.1 Navigate to App Platform

1. Go to [DigitalOcean Console](https://cloud.digitalocean.com/)
2. Click **Create** (top right)
3. Select **Apps**

### 2.2 Connect GitHub Repository

1. Choose **GitHub** as source
2. Click **Manage Access**
3. Authorize DigitalOcean to access your GitHub account
4. Select **Only select repositories**
5. Choose `BisoyJan/primehub-systems`
6. Click **Install & Authorize**

### 2.3 Configure Repository

1. **Repository**: Select `BisoyJan/primehub-systems`
2. **Branch**: Choose `deploy`
3. **Source Directory**: Leave as `/` (root)
4. Check **Autodeploy**: Yes (deploys automatically on git push)
5. Click **Next**

### 2.4 Review Resources

App Platform will detect your `.do/app.yaml` configuration and show:

**Components:**
- ✅ **web** (Basic XS - $12/mo)
  - PHP application with Nginx
  - Runs Laravel app
  
- ✅ **worker** (Basic XXS - $6/mo)
  - Background queue worker
  - Processes QR code generation jobs
  
- ✅ **db** (Basic - $15/mo)
  - Managed MySQL 8 database
  - Automatic backups included

**Total Cost: ~$33/month**

Click **Next**

### 2.5 Environment Variables

App Platform will show most environment variables are already configured from `app.yaml`.

**Important:** The `APP_KEY` needs to be set manually (it's marked as SECRET).

Click **Next** (we'll add APP_KEY in Step 3)

### 2.6 App Info

1. **App Name**: `primehub-systems` (or customize)
2. **Region**: Select closest to your users:
   - `sgp1` - Singapore (Asia)
   - `nyc3` - New York (US East)
   - `sfo3` - San Francisco (US West)
   - `fra1` - Frankfurt (Europe)
3. Click **Next**

### 2.7 Review & Create

1. Review all settings
2. **Estimated cost**: ~$33/month
3. Click **Create Resources**

⏳ **Initial deployment takes 5-10 minutes**

You'll see:
- Building web component...
- Building worker component...
- Creating database...
- Deploying application...

---

## Step 3: Configure APP_KEY

### 3.1 Add APP_KEY Environment Variable

1. Once deployment starts, go to **Settings** tab
2. Click **App-Level Environment Variables**
3. Click **Edit**
4. Add new variable:
   - **Key**: `APP_KEY`
   - **Value**: Paste the key you generated in Step 1.1
   - **Encrypt**: ✅ Check this box
5. Click **Save**

### 3.2 Trigger Rebuild

Since we added a required environment variable:

1. Go to **Actions** menu (top right)
2. Click **Force Rebuild and Deploy**
3. Confirm the rebuild

⏳ Wait 5-8 minutes for rebuild to complete

---

## Step 4: Run Database Migrations

### 4.1 Access Console

Once deployment is complete:

1. Go to your app dashboard
2. Click **Console** tab
3. Select **web** component from dropdown
4. Click **Launch Console**

A web-based terminal will open.

### 4.2 Run Migrations

In the console, run:

```bash
php artisan migrate --force
```

Expected output:
```
Running migrations...
✓ 2024_01_01_000000_create_users_table
✓ 2024_01_02_000000_create_pc_specs_table
...
Migration completed successfully!
```

### 4.3 Seed Database (Optional)

If you want sample data:

```bash
php artisan db:seed --force
```

### 4.4 Create Admin User

```bash
php artisan tinker
```

Then paste:

```php
$user = new App\Models\User();
$user->name = 'Admin';
$user->email = 'admin@yourdomain.com';
$user->password = bcrypt('ChangeThisPassword123!');
$user->save();
echo "Admin user created!\n";
exit
```

Press Enter, then type `exit` to leave tinker.

---

## Step 5: Access Your Application

### 5.1 Get App URL

1. Go to your app dashboard
2. You'll see your app URL at the top (looks like: `https://primehub-systems-xxxxx.ondigitalocean.app`)
3. Click the URL to open your application

### 5.2 Test Application

1. Visit your app URL
2. You should see the login page
3. Login with the admin credentials you created
4. Test key features:
   - Dashboard loads
   - PC specs management
   - QR code generation (this uses the queue worker)

---

## Step 6: Configure Custom Domain (Optional)

### 6.1 Add Domain

1. In App Platform dashboard, go to **Settings** → **Domains**
2. Click **Add Domain**
3. Enter your domain: `yourdomain.com`
4. Click **Add Domain**

### 6.2 Update DNS Records

DigitalOcean will show you DNS records to add:

**For root domain (yourdomain.com):**
- Type: `A`
- Host: `@`
- Value: (IP address provided)

**For www subdomain:**
- Type: `CNAME`
- Host: `www`
- Value: (CNAME provided)

### 6.3 Wait for Verification

- DNS propagation takes 5-60 minutes
- SSL certificate is automatically provisioned
- App Platform shows "Active" when ready

### 6.4 Update APP_URL

1. Go to **Settings** → **App-Level Environment Variables**
2. Edit `APP_URL` to match your domain
3. Save and rebuild

---

## Step 7: Enable Automatic Deployments

Already configured! ✅

Every time you push to `deploy` branch:
1. DigitalOcean detects the push
2. Automatically builds new version
3. Deploys with zero downtime
4. Rolls back automatically if deployment fails

### Test it:

```bash
# Make a small change
echo "# Updated $(date)" >> README.md
git add README.md
git commit -m "Test auto-deploy"
git push origin deploy
```

Watch the deployment in App Platform dashboard!

---

## 📊 Monitoring & Logs

### View Logs

1. Go to **Runtime Logs** tab
2. Select component (web or worker)
3. View real-time logs

### View Metrics

1. Go to **Insights** tab
2. See:
   - Request rate
   - Response time
   - CPU usage
   - Memory usage

### View Database

1. Go to **Databases** in main DigitalOcean menu
2. Click your database
3. View:
   - Connection details
   - Metrics
   - Backups
   - Users

---

## 🔧 Common Tasks

### Clear Application Cache

```bash
# In Console
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
```

### Restart Queue Worker

1. Go to **Settings** → **Components**
2. Find **worker** component
3. Click **⋮** menu → **Force Rebuild**

Or trigger via code:
```bash
php artisan queue:restart
```

### View Failed Jobs

```bash
php artisan queue:failed
```

### Retry Failed Jobs

```bash
php artisan queue:retry all
```

### Check Database Connection

```bash
php artisan tinker
```

Then:
```php
DB::connection()->getPdo();
echo "Database connected!\n";
exit
```

### Update Environment Variables

1. **Settings** → **App-Level Environment Variables**
2. Edit variables
3. **Save**
4. Rebuild required for changes to take effect

---

## 💰 Cost Breakdown

**Monthly Costs:**
- Web component (Basic XS): $12
- Worker component (Basic XXS): $6
- MySQL database (Basic): $15
- **Total**: ~$33/month

**Included:**
- ✅ Automatic SSL certificates
- ✅ Daily database backups (7 days retention)
- ✅ DDoS protection
- ✅ CDN for static assets
- ✅ Automatic scaling (vertical)
- ✅ Health checks & auto-restart

**Cost Optimization Tips:**
- Start with these sizes, scale up if needed
- Database backups are free (7 days)
- Monitor usage in Insights tab
- Can downgrade/upgrade components anytime

---

## 🚨 Troubleshooting

### Deployment Failed

**Check build logs:**
1. Go to **Activity** tab
2. Click failed deployment
3. View **Build Logs**
4. Look for error messages

**Common issues:**
- Missing `APP_KEY` → Add in environment variables
- Composer dependencies → Check `composer.json`
- NPM build errors → Check Node.js version compatibility

### 500 Internal Server Error

**Check runtime logs:**
1. **Runtime Logs** tab
2. Select **web** component
3. Look for PHP errors

**Common fixes:**
```bash
# Clear caches
php artisan config:clear
php artisan cache:clear

# Check APP_KEY is set
php artisan tinker
config('app.key')  # Should not be null
```

### Database Connection Failed

**Verify database is running:**
1. **Databases** in main menu
2. Check status is "Active"

**Check environment variables:**
1. Settings → Components → web
2. Verify `DB_*` variables are set
3. They should auto-populate from `${db.*}`

### Queue Jobs Not Processing

**Check worker status:**
1. **Components** → **worker**
2. Status should be "Active"

**View worker logs:**
1. **Runtime Logs** → Select **worker**
2. Should see: "Processing job..."

**Restart worker:**
1. Components → worker → Force Rebuild

### Site is Slow

**Check metrics:**
1. **Insights** tab
2. Look at CPU/Memory usage

**If high usage:**
- Scale up component size
- Settings → Components → Edit → Increase size
- Basic S ($24/mo) for web component

**Optimize application:**
```bash
# Enable caching
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 🔒 Security Best Practices

✅ **Already configured:**
- SSL/HTTPS automatic
- Environment variables encrypted
- Database access restricted
- Regular security updates

**Additional recommendations:**

1. **Use strong passwords** for admin accounts

2. **Enable 2FA** on DigitalOcean account
   - Account → Settings → Security

3. **Monitor activity**
   - Check **Activity** tab regularly
   - Review deployment logs

4. **Regular backups**
   - Database backed up daily (automatic)
   - Can trigger manual backup anytime

5. **Keep dependencies updated**
   ```bash
   composer update
   npm update
   ```

6. **Monitor logs** for suspicious activity
   - Runtime Logs tab
   - Set up alerts if needed

---

## 📈 Scaling Your Application

### Vertical Scaling (Increase Resources)

**Web Component:**
- Basic XS (512MB RAM) → $12/mo ← **Current**
- Basic S (1GB RAM) → $24/mo
- Basic M (2GB RAM) → $48/mo

**Worker Component:**
- Basic XXS (256MB) → $6/mo ← **Current**
- Basic XS (512MB) → $12/mo

### Horizontal Scaling (More Instances)

1. Settings → Components → web
2. **Instance Count**: Change from 1 to 2+
3. Load balancing is automatic
4. Cost multiplies by instance count

### When to Scale?

**Monitor these metrics:**
- CPU usage > 70% consistently
- Memory usage > 80%
- Response time > 1 second
- Queue jobs backing up

---

## 🎯 Next Steps

Now that your app is deployed:

1. ✅ **Test all features** thoroughly
2. ✅ **Set up monitoring** alerts
3. ✅ **Configure custom domain** (if needed)
4. ✅ **Create user accounts** for your team
5. ✅ **Set up regular database exports** for extra safety
6. ✅ **Document any custom configurations**

### Recommended Monitoring

Set up external monitoring (optional but recommended):
- [UptimeRobot](https://uptimerobot.com/) - Free uptime monitoring
- [Sentry](https://sentry.io/) - Error tracking (free tier available)

---

## 📚 Additional Resources

- [DigitalOcean App Platform Docs](https://docs.digitalocean.com/products/app-platform/)
- [Laravel Deployment Best Practices](https://laravel.com/docs/deployment)
- [App Platform Pricing](https://www.digitalocean.com/pricing/app-platform)

---

## ✅ Deployment Checklist

Use this checklist to track your progress:

- [ ] Step 1: Generated APP_KEY
- [ ] Step 1: Committed `.do/` files to repository
- [ ] Step 2: Connected GitHub repository
- [ ] Step 2: Created app in App Platform
- [ ] Step 3: Added APP_KEY environment variable
- [ ] Step 3: Triggered rebuild
- [ ] Step 4: Ran database migrations
- [ ] Step 4: Created admin user
- [ ] Step 5: Accessed application successfully
- [ ] Step 5: Tested key features
- [ ] Step 6: Configured custom domain (optional)
- [ ] Step 7: Tested automatic deployments

---

**🎉 Congratulations!** Your Laravel + React app is now live on DigitalOcean!

**Need help?** 
- Check the Troubleshooting section above
- View logs in Runtime Logs tab
- Contact DigitalOcean support (24/7 available)
