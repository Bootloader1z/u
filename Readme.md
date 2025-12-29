# Fast Upload Transfer

**Version: 2.0.0** | [Changelog](#changelog)

A high-performance, secure file transfer solution optimized for local networks (LAN/WiFi). Designed for transferring large files (5GB - 50GB) without USB cables.

## Features

### Performance
- **16MB Chunked Uploads** - Optimal chunk size for LAN throughput
- **12 Parallel Connections** - Saturate gigabit network bandwidth
- **Auto-Resume** - Recover from connection drops automatically
- **Pause/Resume** - Stop and continue uploads anytime

### Security
- **Input Validation** - All inputs sanitized and validated
- **File Type Blocking** - Prevents PHP/executable uploads
- **Path Traversal Protection** - Secure filename handling
- **Rate Limiting** - Optional DDoS protection (disabled for LAN)
- **Security Logging** - All events logged for audit
- **XSS/CSRF Headers** - Security headers on all responses

### User Experience
- **Drag & Drop** - Drop files directly onto the page
- **File Browser** - View and download uploaded files
- **Responsive UI** - Works on mobile, tablet, and desktop
- **Real-time Progress** - Speed, ETA, and chunk status
- **90-Day Retention** - Auto-cleanup of old files

## Supported Transfers

| From | To | Speed |
|------|-----|-------|
| PC | PC | Up to 1 Gbps |
| Mobile | PC | WiFi speed |
| Tablet | PC | WiFi speed |

## Requirements

- PHP 7.4+ with Composer
- Web server (Apache/Nginx/XAMPP/Laragon)
- Modern browser (Chrome, Firefox, Safari, Edge)

## Installation

### Linux/macOS

```bash
# Clone repository
git clone https://github.com/Bootloader1z/u.git
cd u

# Install dependencies
composer install

# Set permissions
chmod 755 s/ chunks/ rate_limits/
```

### Windows Setup (XAMPP)

1. **Install XAMPP**
   - Download from [apachefriends.org](https://www.apachefriends.org/)
   - Install with PHP and Apache selected

2. **Clone/Download Repository**
   ```cmd
   cd C:\xampp\htdocs
   git clone https://github.com/Bootloader1z/u.git
   ```
   Or download ZIP and extract to `C:\xampp\htdocs\u`

3. **Install Composer** (if not installed)
   - Download from [getcomposer.org](https://getcomposer.org/Composer-Setup.exe)
   - Run installer, select PHP path: `C:\xampp\php\php.exe`

4. **Install Dependencies**
   ```cmd
   cd C:\xampp\htdocs\u
   composer install
   ```

5. **Configure PHP** - Edit `C:\xampp\php\php.ini`:
   ```ini
   post_max_size = 64M
   upload_max_filesize = 64M
   max_execution_time = 0
   memory_limit = 512M
   ```

6. **Start Apache** via XAMPP Control Panel

7. **Access** at `http://localhost/u`

### Windows Setup (Laragon)

1. **Install Laragon**
   - Download from [laragon.org](https://laragon.org/download/)
   - Full version recommended (includes PHP, Apache, Composer)

2. **Clone Repository**
   ```cmd
   cd C:\laragon\www
   git clone https://github.com/Bootloader1z/u.git
   ```

3. **Install Dependencies**
   ```cmd
   cd C:\laragon\www\u
   composer install
   ```

4. **Configure PHP** - Edit `C:\laragon\bin\php\php-8.x\php.ini`:
   ```ini
   post_max_size = 64M
   upload_max_filesize = 64M
   max_execution_time = 0
   memory_limit = 512M
   ```

5. **Start Laragon** and access at `http://u.test` or `http://localhost/u`

### Windows Configuration Files

| File | Location (XAMPP) | Location (Laragon) |
|------|------------------|-------------------|
| php.ini | `C:\xampp\php\php.ini` | `C:\laragon\bin\php\php-8.x\php.ini` |
| httpd.conf | `C:\xampp\apache\conf\httpd.conf` | `C:\laragon\bin\apache\apache-2.x\conf\httpd.conf` |
| .htaccess | `C:\xampp\htdocs\u\.htaccess` | `C:\laragon\www\u\.htaccess` |

## PHP Configuration

Add to `php.ini` or `.htaccess`:

```ini
post_max_size = 64M
upload_max_filesize = 64M
max_execution_time = 0
memory_limit = 512M
```

## Configuration Options

### JavaScript (`js.js`)
```javascript
const CHUNK_SIZE = 16 * 1024 * 1024;  // 16MB per chunk
const PARALLEL_CHUNKS = 12;            // Simultaneous uploads
const MAX_RETRIES = 15;                // Retry attempts
const CONNECTION_TIMEOUT = 120000;     // 2 min timeout
```

### PHP (`upload.php`)
```php
define('MAX_FILE_SIZE', 50 * 1024 * 1024 * 1024); // 50GB
define('RETENTION_DAYS', 90);                      // File retention
define('ENABLE_RATE_LIMIT', false);                // For LAN use
define('BLOCKED_EXTENSIONS', ['php', 'phtml', ...]); // Blocked types
```

## Security Features

| Feature | Description |
|---------|-------------|
| Input Sanitization | All filenames and IDs validated |
| Extension Blocking | PHP, PHTML, PHAR files blocked |
| Path Traversal | Directory traversal prevented |
| Rate Limiting | Optional request throttling |
| Security Headers | X-Frame-Options, X-XSS-Protection |
| Audit Logging | All uploads logged to security.log |

## Responsive Design

The UI adapts to different screen sizes:

| Device | Layout |
|--------|--------|
| Mobile (<576px) | Stacked buttons, card-style table rows |
| Tablet (577-768px) | 2x2 button grid, compact table |
| Laptop (769-1024px) | Standard layout |
| Desktop (1025px+) | Full-width layout |

## File Structure

```
├── index.html       # Main UI with drag-drop
├── js.js            # Upload logic (chunked, parallel)
├── file-browser.js  # File browser modal (separate for performance)
├── css.css          # Responsive styles (mobile/tablet/desktop)
├── upload.php       # Secure server handler
├── .htaccess        # Apache security config
├── s/               # Uploaded files (90-day retention)
├── chunks/          # Temp chunks (7-day cleanup)
├── rate_limits/     # Rate limit data (auto-cleanup)
├── security.log     # Security audit log
└── vendor/          # Composer dependencies (Carbon)
```

## Changelog

### v2.0.0 (2025-12-30)

**Chunked Upload System:**
- 16MB chunked uploads for large files (5GB - 50GB)
- 12 parallel connections - saturate gigabit LAN
- Pause/Resume functionality
- Auto-resume on connection drop
- 90-day file retention with auto-cleanup

**Security Hardening:**
- Input validation on all parameters
- File extension blocking (PHP, PHTML, etc.)
- Path traversal protection
- Security headers (X-Frame-Options, X-XSS-Protection)
- Audit logging to security.log
- Optional rate limiting

**LAN Optimizations:**
- Optimized chunk size for LAN throughput
- Fast retry delay (1 second)
- 2-minute connection timeout per chunk

**New Features:**
- Drag & drop file upload
- File browser modal (separate JS module for lighter rendering)
- File preview before upload
- Responsive mobile/tablet UI (CSS breakpoints)
- Real-time speed and ETA display
- Custom scrollable modal (no Bootstrap conflicts)

### v1.1.0 (2024)
- Date-based folder organization
- Multiple file upload support
- Basic progress tracking

### v1.0.0 (Initial)
- Basic single-file upload
- Simple progress bar

## License

MIT License

## Author

Bootloader1z
