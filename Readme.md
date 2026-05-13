# Fast Upload Transfer

**Version: 2.2.0** | [Changelog](#changelog)

A high-performance, secure file transfer solution optimized for local networks (LAN/WiFi). Designed for transferring large files (5GB - 50GB) without USB cables.

> **AI-DLC**: This project uses the [AI-Driven Development Life Cycle (AI-DLC)](https://github.com/awslabs/aidlc-workflows) v0.1.8 workflow. Rules are in `.aidlc/`. Start any development task with: `"Using AI-DLC, ..."`.

## Features

### Performance
- **16MB Chunked Uploads** - Optimal chunk size for LAN throughput
- **12 Parallel Connections** - Saturate gigabit network bandwidth
- **Auto-Resume** - Recover from connection drops automatically
- **Pause/Resume** - Stop and continue uploads anytime
- **X-Accel-Redirect** - nginx kernel sendfile for zero-copy downloads

### Security
- **Input Validation** - All inputs sanitized and validated
- **Extended File Type Blocking** - Blocks PHP, executables, scripts, and SVG
- **Magic Bytes Validation** - First-chunk binary signature check (PE, ELF, Mach-O, PHP, shell)
- **Path Traversal Protection** - Trailing-separator hardened path checks
- **Content Security Policy** - Strict CSP on all HTML responses and nginx headers
- **Hardened CORS** - Origin validated against allowlist (no reflected `Origin` header)
- **Rate Limiting** - Per-IP connection and request limits at nginx edge + PHP layer
- **Security Logging** - All events logged for audit
- **XSS/CSRF Headers** - Security headers on all responses
- **Permissions-Policy** - Disables geolocation, microphone, camera, payment APIs
- **Cross-Origin-Opener-Policy / Cross-Origin-Resource-Policy** headers
- **HSTS-ready** - Header pre-configured, enable when TLS is in place

### User Experience
- **Drag & Drop** - Drop files directly onto the page
- **File Browser / Viewer** - View, stream, and download uploaded files
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

### Docker (Recommended — nginx + PHP-FPM)

```bash
git clone https://github.com/Bootloader1z/u.git
cd u
docker compose up -d
# Access at http://localhost:8080
```

Both containers run non-root, read-only rootfs, all Linux capabilities dropped, `no-new-privileges`, pids/mem/cpu/ulimits enforced, tmpfs-only writable paths.

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
define('BLOCKED_EXTENSIONS', ['php', 'exe', ...]);  // Blocked types
```

## Security Features

| Feature | Description |
|---------|-------------|
| Input Sanitization | All filenames and IDs validated |
| Extended Extension Blocking | PHP, PHTML, PHAR, executables, scripts, SVG |
| Magic Bytes Check | First-chunk binary signature validation (PE/ELF/Mach-O/PHP/shell) |
| Path Traversal | Trailing-separator hardened directory traversal prevention |
| Hardened CORS | Origin validated against allowlist, no reflected headers |
| Content Security Policy | Strict CSP on HTML and API responses |
| Rate Limiting | Per-IP nginx edge limits + PHP application layer |
| Security Headers | X-Frame-Options, X-XSS-Protection, Permissions-Policy, COOP, CORP |
| Audit Logging | All uploads and security events logged to security.log |
| HSTS-ready | Header pre-configured, enable when TLS is in place |

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
├── viewer.html      # File browser and media viewer
├── js.js            # Upload logic (chunked, parallel)
├── viewer.js        # File viewer module
├── css.css          # Responsive styles (mobile/tablet/desktop)
├── viewer.css       # Viewer styles
├── upload.php       # Secure server handler
├── stream.php       # Media streaming handler
├── .htaccess        # Apache security config
├── .aidlc/          # AI-DLC workflow rules (v0.1.8)
├── .kiro/steering/  # Kiro AI-DLC steering file
├── docker/          # Docker configuration files
│   ├── nginx.conf
│   ├── default.conf
│   ├── security.conf
│   ├── php.ini
│   ├── php-fpm.conf
│   └── Dockerfile.nginx
├── docker-compose.yml
├── Dockerfile
├── s/               # Uploaded files (90-day retention)
├── chunks/          # Temp chunks (7-day cleanup)
├── rate_limits/     # Rate limit data (auto-cleanup)
├── security.log     # Security audit log
└── vendor/          # Composer dependencies (Carbon)
```

## Changelog

### v2.2.0 (2026-05-13)

**Security hardening and new features (AI-DLC guided):**

- **Magic bytes validation** — First chunk checked for PE (EXE/DLL), ELF, Mach-O, PHP `<?php`, and shell shebang signatures; upload rejected regardless of extension
- **Extended blocked extensions** — Added `php7`, `php8`, `pht`, `phtm`, `shtml`, `exe`, `bat`, `cmd`, `com`, `msi`, `vbs`, `vbe`, `jse`, `wsf`, `wsh`, `ps1`, `ps2`, `psc1`, `psc2`, `sh`, `bash`, `zsh`, `csh`, `ksh`, `py`, `pyc`, `pyo`, `rb`, `pl`, `cgi`, `asp`, `aspx`, `ashx`, `asmx`, `axd`, `jsp`, `jspx`, `cfm`, `cfml`, `svg`
- **Hardened CORS** — `Access-Control-Allow-Origin` now validates against `ALLOWED_ORIGINS` allowlist; no longer reflects arbitrary `Origin` header
- **Content Security Policy** — Strict CSP added to all HTML responses (meta tag) and nginx `security.conf`; restricts scripts, styles, fonts, connections to `'self'` + CDN
- **Hardened path traversal** — `handleFastDownload()` now uses trailing-separator comparison (consistent with GET download and `stream.php`)
- **Hardened `getClientIP()`** — Trusts only `REMOTE_ADDR` and `X-Real-IP` (set by nginx); no longer blindly trusts `X-Forwarded-For` chain which is spoofable
- **`isValidUploadId()` type safety** — Added `is_string()` check before regex; uses named constant `UPLOAD_ID_PATTERN`
- **Stricter filename sanitization** — Unicode-aware regex (`\p{L}\p{N}`), uses `MAX_FILENAME_LENGTH` constant, trims whitespace before empty check
- **`file_exists` check in GET download** — Added missing 404 guard after path validation
- **Security headers** — Added `Content-Security-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` to PHP responses
- **`.htaccess` hardening** — Added `Options -ExecCGI`, blocked `logs/` and `docker/` directories, added PHP flags (`expose_php Off`, `display_errors Off`, `allow_url_fopen Off`), added all new security headers
- **AI-DLC integration** — Added `.aidlc/` rules directory (v0.1.8) and `.kiro/steering/ai-dlc.md` steering file

### v2.1.1 (2026-05-11)

**Docker ready and secured system:**
- Full Docker stack: nginx + PHP-FPM (multi-stage, Alpine) via docker-compose
- X-Accel-Redirect hand-off: nginx streams files via kernel sendfile, PHP workers are released immediately
- Both containers run non-root, read-only rootfs, all Linux capabilities dropped, no-new-privileges, pids/mem/cpu/ulimits enforced, tmpfs-only writable paths
- Per-IP connection + request rate limits at the edge, verb whitelist (GET/POST/OPTIONS/HEAD)
- PHP hardening: disable_functions, open_basedir sandbox, allow_url_fopen/include off, expose_php off, cgi.fix_pathinfo=0, clear_env, opcache + JIT enabled
- Apache variant preserved as Dockerfile.apache for rollback
- Rollback-safe: X-Accel-Redirect is gated on SERVER_SOFTWARE, so the same PHP code still works under Apache
- Hardened path-traversal check (trailing-separator compare) in stream.php and upload.php download branch
- Client IP correctly forwarded from nginx to PHP for rate limiting and security log
- Comprehensive image extension coverage (HEIC/HEIF, AVIF, JXL, RAW formats, macOS/Windows/Linux native types, PSD, etc.)

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
