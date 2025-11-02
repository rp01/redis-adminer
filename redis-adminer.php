<?php

/**
 * Redis Adminer - A simple Redis Admineristration tool
 * Uses pure PHP socket connection (no extension required)
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

class RedisClient
{
    private $socket = null;
    private $connected = false;
    private $error = '';

    public function connect($host = '127.0.0.1', $port = 6379, $password = '', $database = 0)
    {
        try {
            $this->socket = @fsockopen($host, $port, $errno, $errstr, 2.5);

            if (!$this->socket) {
                $this->error = "Connection failed: $errstr ($errno)";
                return false;
            }

            stream_set_timeout($this->socket, 2);

            if (!empty($password)) {
                $response = $this->command('AUTH', $password);
                if ($response !== 'OK') {
                    $this->error = 'Authentication failed';
                    return false;
                }
            }

            if ($database > 0) {
                $response = $this->command('SELECT', $database);
                if ($response !== 'OK') {
                    $this->error = 'Database selection failed';
                    return false;
                }
            }

            $this->connected = true;
            return true;
        } catch (Exception $e) {
            $this->error = 'Connection failed: ' . $e->getMessage();
            return false;
        }
    }

    public function isConnected()
    {
        return $this->connected;
    }

    public function getError()
    {
        return $this->error;
    }

    private function command(...$args)
    {
        if (!$this->socket) {
            return null;
        }

        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        fwrite($this->socket, $cmd);
        return $this->readResponse();
    }

    private function readResponse()
    {
        $line = fgets($this->socket);
        if ($line === false) {
            return null;
        }

        $type = $line[0];
        $line = rtrim($line, "\r\n");

        switch ($type) {
            case '+': // Simple string
                return substr($line, 1);

            case '-': // Error
                return null;

            case ':': // Integer
                return (int)substr($line, 1);

            case '$': // Bulk string
                $length = (int)substr($line, 1);
                if ($length === -1) {
                    return null;
                }
                $data = fread($this->socket, $length + 2);
                return substr($data, 0, -2);

            case '*': // Array
                $count = (int)substr($line, 1);
                if ($count === -1) {
                    return null;
                }
                $result = [];
                for ($i = 0; $i < $count; $i++) {
                    $result[] = $this->readResponse();
                }
                return $result;

            default:
                return null;
        }
    }

    public function getInfo()
    {
        $response = $this->command('INFO');
        if (!$response) {
            return [];
        }

        $info = [];
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $info[$parts[0]] = $parts[1];
            }
        }
        return $info;
    }

    public function getKeys($pattern = '*')
    {
        $keys = $this->command('KEYS', $pattern);
        return is_array($keys) ? $keys : [];
    }

    public function getKeyType($key)
    {
        return $this->command('TYPE', $key);
    }

    public function getTTL($key)
    {
        return $this->command('TTL', $key);
    }

    public function getValue($key)
    {
        $type = $this->getKeyType($key);

        switch (strtolower($type)) {
            case 'string':
                return $this->command('GET', $key);

            case 'list':
                return $this->command('LRANGE', $key, 0, -1);

            case 'set':
                return $this->command('SMEMBERS', $key);

            case 'zset':
                $values = $this->command('ZRANGE', $key, 0, -1, 'WITHSCORES');
                if (!$values) return [];
                $result = [];
                for ($i = 0; $i < count($values); $i += 2) {
                    $result[$values[$i]] = $values[$i + 1];
                }
                return $result;

            case 'hash':
                $values = $this->command('HGETALL', $key);
                if (!$values) return [];
                $result = [];
                for ($i = 0; $i < count($values); $i += 2) {
                    $result[$values[$i]] = $values[$i + 1];
                }
                return $result;

            default:
                return null;
        }
    }

    public function deleteKey($key)
    {
        return $this->command('DEL', $key);
    }

    public function setString($key, $value, $ttl = null)
    {
        if ($ttl) {
            return $this->command('SETEX', $key, $ttl, $value);
        }
        return $this->command('SET', $key, $value);
    }

    public function setExpire($key, $ttl)
    {
        return $this->command('EXPIRE', $key, $ttl);
    }

    public function flushDB()
    {
        return $this->command('FLUSHDB');
    }

    public function dbSize()
    {
        return $this->command('DBSIZE');
    }

    public function __destruct()
    {
        if ($this->socket) {
            fclose($this->socket);
        }
    }
}

$redis = new RedisClient();

// Handle connection
if (isset($_POST['connect'])) {
    $host = $_POST['host'] ?? '127.0.0.1';
    $port = (int)($_POST['port'] ?? 6379);
    $password = $_POST['password'] ?? '';
    $database = (int)($_POST['database'] ?? 0);

    if ($redis->connect($host, $port, $password, $database)) {
        $_SESSION['redis_connected'] = true;
        $_SESSION['redis_host'] = $host;
        $_SESSION['redis_port'] = $port;
        $_SESSION['redis_password'] = $password;
        $_SESSION['redis_database'] = $database;
    }
} elseif (isset($_SESSION['redis_connected'])) {
    $redis->connect(
        $_SESSION['redis_host'],
        $_SESSION['redis_port'],
        $_SESSION['redis_password'],
        $_SESSION['redis_database']
    );
}

// AJAX handler for viewing keys
if (isset($_GET['ajax']) && $_GET['ajax'] === 'view' && isset($_GET['key']) && $redis->isConnected()) {
    $key = $_GET['key'];
    $value = $redis->getValue($key);
    header('Content-Type: application/json');
    echo json_encode(['value' => $value]);
    exit;
}

// Handle actions
if ($redis->isConnected()) {
    if (isset($_POST['delete_key'])) {
        $redis->deleteKey($_POST['delete_key']);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (isset($_POST['add_string'])) {
        $key = $_POST['new_key'];
        $value = $_POST['new_value'];
        $ttl = !empty($_POST['new_ttl']) ? (int)$_POST['new_ttl'] : null;
        $redis->setString($key, $value, $ttl);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (isset($_POST['set_expire'])) {
        $redis->setExpire($_POST['expire_key'], (int)$_POST['expire_ttl']);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (isset($_POST['flush_db'])) {
        $redis->flushDB();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (isset($_GET['disconnect'])) {
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$searchPattern = $_GET['search'] ?? '*';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redis Adminer</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: #dc382d;
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 24px;
            font-weight: 600;
        }

        .content {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }

        input[type="text"],
        input[type="password"],
        input[type="number"],
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        textarea {
            min-height: 100px;
            font-family: monospace;
        }

        button,
        .btn {
            background: #dc382d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        button:hover,
        .btn:hover {
            background: #c12e24;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-danger {
            background: #e74c3c;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .info-box {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .info-box h3 {
            margin-bottom: 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }

        .info-item {
            padding: 10px;
            background: white;
            border-radius: 4px;
        }

        .info-item strong {
            display: block;
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .key-type {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-string {
            background: #d4edda;
            color: #155724;
        }

        .type-list {
            background: #cce5ff;
            color: #004085;
        }

        .type-set {
            background: #fff3cd;
            color: #856404;
        }

        .type-zset {
            background: #f8d7da;
            color: #721c24;
        }

        .type-hash {
            background: #e2e3e5;
            color: #383d41;
        }

        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-bar input {
            flex: 1;
        }

        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .close {
            cursor: pointer;
            font-size: 24px;
            color: #999;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>🔴 Redis Adminer</h1>
            <?php if ($redis->isConnected()): ?>
                <a href="?disconnect=1" class="btn btn-secondary">Disconnect</a>
            <?php endif; ?>
        </div>

        <div class="content">
            <?php if (!$redis->isConnected()): ?>
                <!-- Connection Form -->
                <?php if ($redis->getError()): ?>
                    <div class="error"><?= htmlspecialchars($redis->getError()) ?></div>
                <?php endif; ?>

                <form method="post">
                    <div class="form-group">
                        <label>Host:</label>
                        <input type="text" name="host" value="127.0.0.1" required>
                    </div>

                    <div class="form-group">
                        <label>Port:</label>
                        <input type="number" name="port" value="6379" required>
                    </div>

                    <div class="form-group">
                        <label>Password (optional):</label>
                        <input type="password" name="password">
                    </div>

                    <div class="form-group">
                        <label>Database:</label>
                        <input type="number" name="database" value="0" min="0" max="15">
                    </div>

                    <button type="submit" name="connect">Connect</button>
                </form>
            <?php else: ?>
                <!-- Redis Info -->
                <?php
                $info = $redis->getInfo();
                $dbSize = $redis->dbSize();
                ?>

                <div class="info-box">
                    <h3>Server Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <strong>Version</strong>
                            <?= htmlspecialchars($info['redis_version'] ?? 'N/A') ?>
                        </div>
                        <div class="info-item">
                            <strong>Connected Clients</strong>
                            <?= htmlspecialchars($info['connected_clients'] ?? 'N/A') ?>
                        </div>
                        <div class="info-item">
                            <strong>Used Memory</strong>
                            <?= htmlspecialchars($info['used_memory_human'] ?? 'N/A') ?>
                        </div>
                        <div class="info-item">
                            <strong>Total Keys</strong>
                            <?= number_format($dbSize) ?>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="actions">
                    <button onclick="showModal('addKeyModal')">+ Add Key</button>
                    <button onclick="if(confirm('Are you sure you want to flush the entire database?')) document.getElementById('flushForm').submit();" class="btn-danger">
                        Flush Database
                    </button>
                </div>

                <form id="flushForm" method="post" style="display:none;">
                    <input type="hidden" name="flush_db" value="1">
                </form>

                <!-- Search -->
                <form class="search-bar" method="get">
                    <input type="text" name="search" placeholder="Search keys (use * for wildcard)" value="<?= htmlspecialchars($searchPattern) ?>">
                    <button type="submit">Search</button>
                </form>

                <!-- Keys Table -->
                <?php
                $keys = $redis->getKeys($searchPattern);
                $keys = array_slice($keys, 0, 100);

                if (empty($keys)): ?>
                    <p>No keys found.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Type</th>
                                <th>TTL</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keys as $key):
                                $type = $redis->getKeyType($key);
                                $ttl = $redis->getTTL($key);
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($key) ?></strong></td>
                                    <td><span class="key-type type-<?= strtolower($type) ?>"><?= htmlspecialchars($type) ?></span></td>
                                    <td><?= $ttl === -1 ? 'Never' : ($ttl === -2 ? 'Expired' : $ttl . 's') ?></td>
                                    <td>
                                        <button onclick="viewKey('<?= htmlspecialchars($key, ENT_QUOTES) ?>')" class="btn-small btn-secondary">View</button>
                                        <button onclick="setExpire('<?= htmlspecialchars($key, ENT_QUOTES) ?>')" class="btn-small btn-secondary">Set TTL</button>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this key?');">
                                            <input type="hidden" name="delete_key" value="<?= htmlspecialchars($key) ?>">
                                            <button type="submit" class="btn-small btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (count($keys) >= 100): ?>
                        <p style="margin-top: 10px; color: #666;">Showing first 100 results. Use search to narrow down.</p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Key Modal -->
    <div id="addKeyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Key</h2>
                <span class="close" onclick="hideModal('addKeyModal')">&times;</span>
            </div>
            <form method="post">
                <div class="form-group">
                    <label>Key:</label>
                    <input type="text" name="new_key" required>
                </div>
                <div class="form-group">
                    <label>Value:</label>
                    <textarea name="new_value" required></textarea>
                </div>
                <div class="form-group">
                    <label>TTL (seconds, optional):</label>
                    <input type="number" name="new_ttl" min="0">
                </div>
                <button type="submit" name="add_string">Add Key</button>
            </form>
        </div>
    </div>

    <!-- View Key Modal -->
    <div id="viewKeyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="viewKeyTitle">Key Value</h2>
                <span class="close" onclick="hideModal('viewKeyModal')">&times;</span>
            </div>
            <div id="viewKeyContent"></div>
        </div>
    </div>

    <!-- Set Expire Modal -->
    <div id="expireModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Set TTL</h2>
                <span class="close" onclick="hideModal('expireModal')">&times;</span>
            </div>
            <form method="post">
                <input type="hidden" name="expire_key" id="expire_key">
                <div class="form-group">
                    <label>TTL (seconds):</label>
                    <input type="number" name="expire_ttl" min="0" required>
                </div>
                <button type="submit" name="set_expire">Set TTL</button>
            </form>
        </div>
    </div>

    <script>
        function showModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function hideModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function viewKey(key) {
            fetch('?ajax=view&key=' + encodeURIComponent(key))
                .then(r => r.json())
                .then(data => {
                    document.getElementById('viewKeyTitle').textContent = 'Key: ' + key;
                    let content = '<pre>' + JSON.stringify(data.value, null, 2) + '</pre>';
                    document.getElementById('viewKeyContent').innerHTML = content;
                    showModal('viewKeyModal');
                })
                .catch(err => {
                    alert('Error loading key: ' + err.message);
                });
        }

        function setExpire(key) {
            document.getElementById('expire_key').value = key;
            showModal('expireModal');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>

</html>
