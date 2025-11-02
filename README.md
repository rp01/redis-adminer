# Redis Adminer 🔴

A lightweight, single-file PHP application for managing Redis databases through a web interface. Inspired by Adminer's simplicity, this tool provides a clean and intuitive way to browse, manage, and manipulate your Redis data.


## ✨ Features

- **🔌 Easy Connection Management** - Connect to local or remote Redis instances with password authentication
- **📊 Server Dashboard** - View Redis server info, memory usage, connected clients, and database statistics
- **🔍 Key Search & Filtering** - Search keys using Redis pattern matching with wildcard support
- **👁️ Multi-type Data Viewer** - View and inspect all Redis data types:
  - Strings
  - Lists
  - Sets
  - Sorted Sets (ZSets)
  - Hashes
- **➕ Key Management** - Add new string keys with optional TTL (Time To Live)
- **⏱️ TTL Control** - Set or update expiration times for existing keys
- **🗑️ Delete Operations** - Remove individual keys or flush entire databases
- **🎨 Modern UI** - Clean, responsive interface that works on desktop and mobile
- **🚀 Zero Dependencies** - No Redis PHP extension required - uses pure PHP socket connections

## 📋 Requirements

- **PHP 7.4 or higher**
- **Redis Server** (any version)
- **Web server** (Apache, Nginx, or PHP built-in server)

**No Redis PHP extension needed!** This application uses native PHP socket connections.

## 🚀 Installation

### Quick Start

1. **Download the file:**
   ```bash
   wget https://raw.githubusercontent.com/rp01/redis-adminerer/main/redis-adminerer.php
   # or
   curl -O https://raw.githubusercontent.com/rp01/redis-adminerer/main/redis-adminerer.php
   ```

2. **Place it in your web server directory:**
   ```bash
   # For Apache
   sudo cp redis-adminerer.php /var/www/html/
   
   # For Nginx
   sudo cp redis-adminerer.php /usr/share/nginx/html/
   ```

3. **Set proper permissions:**
   ```bash
   chmod 644 redis-adminerer.php
   ```

4. **Access via browser:**
   ```
   http://localhost/redis-adminerer.php
   ```

### Using PHP Built-in Server

Perfect for development or local use:

```bash
php -S localhost:8080 redis-adminerer.php
```

Then open `http://localhost:8080` in your browser.

## 🔧 Configuration

### Default Connection Settings

- **Host:** `127.0.0.1`
- **Port:** `6379`
- **Password:** *(empty)*
- **Database:** `0`

### Connecting to Redis

1. Open the application in your browser
2. Enter your Redis server credentials
3. Click "Connect"

### Remote Redis Connection

To connect to a remote Redis server:

```
Host: your-redis-server.com
Port: 6379
Password: your-redis-password
Database: 0
```

### Redis with Authentication

If your Redis server requires a password (set in `redis.conf`):

```conf
# redis.conf
requirepass your-strong-password
```

Enter this password in the "Password" field when connecting.

## 📖 Usage Guide

### Browsing Keys

- After connecting, you'll see a list of all keys in the selected database
- Keys are displayed with their type, TTL, and available actions
- Use the search bar to filter keys using patterns (e.g., `user:*`, `session:*`)

### Viewing Key Values

Click the **"View"** button next to any key to see its value. The viewer automatically formats:
- JSON data
- Arrays and objects
- Multi-line strings

### Adding New Keys

1. Click the **"+ Add Key"** button
2. Enter the key name
3. Enter the value (for string type)
4. Optionally set a TTL (Time To Live) in seconds
5. Click **"Add Key"**

### Setting TTL (Expiration)

1. Click **"Set TTL"** next to any key
2. Enter the expiration time in seconds
3. Click **"Set TTL"**

**TTL Values:**
- `-1`: Key never expires (persistent)
- `-2`: Key has expired (will be deleted)
- `Positive number`: Seconds until expiration

### Deleting Keys

- Click the **"Delete"** button next to a key
- Confirm the deletion in the popup dialog

### Flushing Database

⚠️ **Warning:** This will delete ALL keys in the current database!

1. Click **"Flush Database"**
2. Confirm the action

## 🔒 Security Considerations

### Production Deployment

**Important:** This tool provides full access to your Redis database. Follow these security practices:

1. **Use Authentication:**
   ```bash
   # In redis.conf
   requirepass your-strong-password
   ```

2. **Restrict Access:**
   - Place behind a firewall
   - Use HTTP authentication:
   ```apache
   # .htaccess
   AuthType Basic
   AuthName "Redis Admin"
   AuthUserFile /path/to/.htpasswd
   Require valid-user
   ```

3. **Use HTTPS:**
   ```nginx
   server {
       listen 443 ssl;
       ssl_certificate /path/to/cert.pem;
       ssl_certificate_key /path/to/key.pem;
       # ... rest of config
   }
   ```

4. **Limit by IP:**
   ```apache
   # Apache
   <Files "redis-adminerer.php">
       Order Deny,Allow
       Deny from all
       Allow from 192.168.1.0/24
   </Files>
   ```
   
   ```nginx
   # Nginx
   location ~ redis-adminerer\.php$ {
       allow 192.168.1.0/24;
       deny all;
   }
   ```

5. **Remove from Production:**
   - Only use this tool in development/staging environments
   - Delete the file when not in use

## 🏗️ Architecture

### How It Works

This application implements the **Redis Serialization Protocol (RESP)** from scratch using PHP's native `fsockopen()` function:

```php
// Connection
$socket = fsockopen('127.0.0.1', 6379);

// Send command
fwrite($socket, "*2\r\n$3\r\nGET\r\n$6\r\nmykey\r\n");

// Read response
$response = fgets($socket);
```

### Supported Data Types

| Type | Redis Type | Operations |
|------|-----------|------------|
| String | `REDIS_STRING` | GET, SET, SETEX |
| List | `REDIS_LIST` | LRANGE |
| Set | `REDIS_SET` | SMEMBERS |
| Sorted Set | `REDIS_ZSET` | ZRANGE with scores |
| Hash | `REDIS_HASH` | HGETALL |

### File Structure

```
redis-adminerer.php
├── RedisClient Class
│   ├── Connection management
│   ├── RESP protocol implementation
│   ├── Command execution
│   └── Response parsing
├── Session handling
├── Action handlers (add, delete, update)
├── HTML interface
└── JavaScript for modals & AJAX
```

## 🎨 Screenshots

### Connection Screen
Clean and simple connection form with all necessary options.

### Dashboard
View server information and database statistics at a glance.

### Key Browser
Browse, search, and manage your Redis keys with ease.

### Data Viewer
Inspect key values with automatic formatting and syntax highlighting.

## 🤝 Contributing

Contributions are welcome! Here's how you can help:

1. **Fork the repository**
2. **Create a feature branch:**
   ```bash
   git checkout -b feature/amazing-feature
   ```
3. **Commit your changes:**
   ```bash
   git commit -m 'Add some amazing feature'
   ```
4. **Push to the branch:**
   ```bash
   git push origin feature/amazing-feature
   ```
5. **Open a Pull Request**

### Development Guidelines

- Maintain the single-file architecture
- Follow PSR-12 coding standards
- Test with multiple Redis versions
- Update documentation for new features
- Keep dependencies at zero

## 🐛 Troubleshooting

### Connection Failed

**Error:** `Connection failed: Connection refused (111)`

**Solutions:**
- Ensure Redis server is running: `redis-cli ping`
- Check if Redis is listening on the correct port: `netstat -an | grep 6379`
- Verify firewall rules allow connections
- Check `bind` directive in `redis.conf`

### Authentication Failed

**Error:** `Authentication failed`

**Solutions:**
- Verify password in `redis.conf`
- Test password with redis-cli: `redis-cli -a your-password ping`
- Ensure no special characters need escaping

### No Keys Displayed

**Possible causes:**
- Empty database (DBSIZE returns 0)
- Wrong database selected
- Pattern doesn't match any keys
- Keys have expired

### Session Issues

If experiencing session problems:

```bash
# Check PHP session directory
ls -la /var/lib/php/sessions/

# Ensure proper permissions
sudo chmod 1733 /var/lib/php/sessions/
```

## 📚 Redis Resources

- [Redis Official Documentation](https://redis.io/documentation)
- [Redis Commands Reference](https://redis.io/commands)
- [Redis Best Practices](https://redis.io/topics/best-practices)
- [RESP Protocol Specification](https://redis.io/topics/protocol)

## 📝 Changelog

### Version 1.0.0 (2024)
- Initial release
- Pure PHP implementation (no extensions required)
- Support for all major Redis data types
- Modern responsive UI
- Key management (add, delete, view)
- TTL management
- Database flush functionality
- Search and pattern matching

## 📄 License

This project is licensed under the MIT License - see below for details:

```
MIT License

Copyright (c) 2024

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## 🙏 Acknowledgments

- Inspired by [Adminer](https://www.adminer.org/) - Database management in a single file
- Redis logo and branding by [Redis Ltd.](https://redis.io/)
- Built with ❤️ for the Redis community

## 📧 Support

- **Issues:** [GitHub Issues](https://github.com/rp01/redis-adminerer/issues)
- **Discussions:** [GitHub Discussions](https://github.com/rp01/redis-adminerer/discussions)
- **Email:** your-email@example.com

## ⭐ Star History

If you find this project useful, please consider giving it a star on GitHub!

---

**Made with ❤️ by developers, for developers**
