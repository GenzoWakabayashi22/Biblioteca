# ✅ DEPLOYMENT CHECKLIST - Fix HTTP 500 Error

## Pre-Deployment Verification
- [ ] PR merged to main branch
- [ ] Latest code pulled on production server
- [ ] Backup of current system created

## Deployment Steps

### Step 1: Configuration Setup
```bash
cd /path/to/biblioteca
cp .env.example .env
```

### Step 2: Database Credentials
Edit `.env` file with production credentials:
```bash
nano .env
```

Required changes:
- [ ] `DB_HOST` - Set to your database host (usually 'localhost')
- [ ] `DB_USERNAME` - Set to your database username
- [ ] `DB_PASSWORD` - Set to your database password
- [ ] `DB_DATABASE` - Set to your database name

### Step 3: Security
```bash
chmod 600 .env
```

### Step 4: Verification
```bash
php setup.php
```

Expected output:
- [ ] ✅ File .env exists
- [ ] ✅ Database connection successful
- [ ] ✅ All required tables present

### Step 5: Test Login
- [ ] Open browser: https://biblioteca.loggiakilwinning.com
- [ ] Attempt login
- [ ] Verify no HTTP 500 error
- [ ] Verify successful login and redirect to dashboard

## Post-Deployment Verification

### Functional Tests
- [ ] Login page loads without errors
- [ ] Login with valid credentials succeeds
- [ ] Dashboard displays correctly
- [ ] Rate limiting working (check logs)
- [ ] CSRF protection active

### Log Checks
```bash
# Check for errors
tail -50 /path/to/biblioteca/error_log

# Check web server logs
tail -50 /var/log/apache2/error.log  # or nginx
```

Expected: No critical errors related to .env or database connection

### Security Verification
- [ ] `.env` file has 600 permissions (not world-readable)
- [ ] `.env` not accessible via browser
- [ ] No credentials visible in error messages
- [ ] HTTPS enabled (if production)

## Rollback Plan (if needed)

If issues occur:
```bash
# 1. Check logs
tail -100 /path/to/biblioteca/error_log

# 2. Verify .env credentials
cat .env

# 3. Test database connection manually
mysql -u your_username -p your_database

# 4. If all fails, restore from backup
```

## Common Issues & Solutions

### Issue: "Connessione fallita"
**Solution**: Verify database credentials in .env are correct

### Issue: "Tabelle mancanti"
**Solution**: Run database migrations or import schema

### Issue: "Permission denied"
**Solution**: Check file permissions
```bash
chmod 600 .env
chmod 755 setup.php
```

## Success Criteria ✅

All of the following must be true:
- [ ] HTTP 500 error resolved
- [ ] Login page accessible
- [ ] Users can login successfully
- [ ] Dashboard loads without errors
- [ ] No critical errors in logs
- [ ] Rate limiting functional
- [ ] Security headers present

## Support Contacts

- System Administrator: [Contact details]
- Database Administrator: [Contact details]
- Repository: https://github.com/GenzoWakabayashi22/Biblioteca

## Documentation References

- **QUICKFIX.txt** - Quick reference for immediate fixes
- **SETUP.md** - Detailed deployment guide
- **README.md** - System overview and troubleshooting

---

**Deployment Date**: _______________
**Deployed By**: _______________
**Status**: [ ] Success [ ] Issues [ ] Rolled Back
**Notes**: _________________________________
