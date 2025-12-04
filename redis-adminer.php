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

            if (! $this->socket) {
                $this->error = "Connection failed: $errstr ($errno)";

                return false;
            }

            stream_set_timeout($this->socket, 2);

            if (! empty($password)) {
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
        if (! $this->socket) {
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
                return (int) substr($line, 1);

            case '$': // Bulk string
                $length = (int) substr($line, 1);
                if ($length === -1) {
                    return null;
                }
                $data = fread($this->socket, $length + 2);

                return substr($data, 0, -2);

            case '*': // Array
                $count = (int) substr($line, 1);
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
        if (! $response) {
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
                if (! $values) {
                    return [];
                }
                $result = [];
                for ($i = 0; $i < count($values); $i += 2) {
                    $result[$values[$i]] = $values[$i + 1];
                }

                return $result;

            case 'hash':
                $values = $this->command('HGETALL', $key);
                if (! $values) {
                    return [];
                }
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

    public function selectDatabase($database)
    {
        if ($database >= 0) {
            $response = $this->command('SELECT', $database);
            if ($response === 'OK') {
                return true;
            }
        }

        return false;
    }

    public function __destruct()
    {
        if ($this->socket) {
            fclose($this->socket);
        }
    }
}

$redis = new RedisClient;

// Handle connection
if (isset($_POST['connect'])) {
    $host = $_POST['host'] ?? '127.0.0.1';
    $port = (int) ($_POST['port'] ?? 6379);
    $password = $_POST['password'] ?? '';
    $database = (int) ($_POST['database'] ?? 0);

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
    if (isset($_POST['switch_db'])) {
        $newDb = (int) $_POST['switch_db'];
        if ($redis->selectDatabase($newDb)) {
            $_SESSION['redis_database'] = $newDb;
        }
        // Build query params - preserve search but remove key (since it may not exist in new DB)
        $queryParams = [];
        if (isset($_GET['search'])) {
            $queryParams['search'] = $_GET['search'];
        }
        $queryString = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';
        header('Location: ' . $_SERVER['PHP_SELF'] . $queryString);
        exit;
    }

    if (isset($_POST['bulk_delete']) && isset($_POST['selected_keys']) && is_array($_POST['selected_keys'])) {
        $deletedCount = 0;
        foreach ($_POST['selected_keys'] as $key) {
            if ($redis->deleteKey($key)) {
                $deletedCount++;
            }
        }
        $url = $_SERVER['PHP_SELF'];
        if (isset($_GET['search'])) {
            $url .= '?search=' . urlencode($_GET['search']);
        }
        header('Location: ' . $url);
        exit;
    }

    if (isset($_POST['delete_key'])) {
        $redis->deleteKey($_POST['delete_key']);
        $url = $_SERVER['PHP_SELF'];
        if (isset($_GET['search'])) {
            $url .= '?search=' . urlencode($_GET['search']);
        }
        header('Location: ' . $url);
        exit;
    }

    if (isset($_POST['add_string'])) {
        $key = $_POST['edit_key'] ?? $_POST['new_key'];
        $value = $_POST['new_value'];
        $ttl = ! empty($_POST['new_ttl']) ? (int) $_POST['new_ttl'] : null;
        $redis->setString($key, $value, $ttl);
        $redirectUrl = $_SERVER['PHP_SELF'];
        if (isset($_POST['edit_key'])) {
            $redirectUrl .= '?key=' . urlencode($key);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if (isset($_POST['set_expire'])) {
        $key = $_POST['expire_key'];
        $redis->setExpire($key, (int) $_POST['expire_ttl']);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?key=' . urlencode($key));
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

// Function to build hierarchical tree from keys
function buildKeyTree($keys)
{
    $tree = [];
    foreach ($keys as $key) {
        $parts = explode(':', $key);
        $current = &$tree;
        foreach ($parts as $i => $part) {
            if (! isset($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }
        // Mark as leaf node (actual key)
        $current['__key__'] = $key;
    }

    return $tree;
}

// Function to get Tailwind classes for key type
function getKeyTypeClasses($type)
{
    $typeMap = [
        'string' => 'bg-green-100 text-green-800',
        'list' => 'bg-blue-100 text-blue-800',
        'set' => 'bg-yellow-100 text-yellow-800',
        'zset' => 'bg-red-100 text-red-800',
        'hash' => 'bg-gray-100 text-gray-800',
    ];

    return $typeMap[strtolower($type)] ?? 'bg-gray-100 text-gray-800';
}

$searchPattern = $_GET['search'] ?? '*';
$viewMode = $_GET['view'] ?? 'table'; // 'table' or 'hierarchy'
$currentDb = $_SESSION['redis_database'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redis Adminer</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-3 font-sans">
    <div class="max-w-[98%] mx-auto bg-white rounded-lg shadow-lg overflow-hidden">
        <div class="bg-red-600 text-white px-4 py-3 flex justify-between items-center">
            <h1 class="text-2xl font-semibold">🔴 Redis Adminer</h1>
            <?php if ($redis->isConnected()) { ?>
                <a href="?disconnect=1" class="bg-gray-600 hover:bg-gray-700 text-white px-5 py-2 rounded no-underline inline-block">Disconnect</a>
            <?php } ?>
        </div>

        <div class="p-4">
            <?php if (! $redis->isConnected()) { ?>
                <!-- Connection Form -->
                <?php if ($redis->getError()) { ?>
                    <div class="bg-red-100 text-red-800 p-4 rounded mb-5"><?= htmlspecialchars($redis->getError()) ?></div>
                <?php } ?>

                <form method="post">
                    <div class="mb-4">
                        <label class="block mb-1 font-medium text-gray-700">Host:</label>
                        <input type="text" name="host" value="127.0.0.1" required class="w-full p-2.5 border border-gray-300 rounded text-sm">
                    </div>

                    <div class="mb-4">
                        <label class="block mb-1 font-medium text-gray-700">Port:</label>
                        <input type="number" name="port" value="6379" required class="w-full p-2.5 border border-gray-300 rounded text-sm">
                    </div>

                    <div class="mb-4">
                        <label class="block mb-1 font-medium text-gray-700">Password (optional):</label>
                        <input type="password" name="password" class="w-full p-2.5 border border-gray-300 rounded text-sm">
                    </div>

                    <div class="mb-4">
                        <label class="block mb-1 font-medium text-gray-700">Database:</label>
                        <input type="number" name="database" value="0" min="0" max="15" class="w-full p-2.5 border border-gray-300 rounded text-sm">
                    </div>

                    <button type="submit" name="connect" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded cursor-pointer text-sm border-none">Connect</button>
                </form>
            <?php } else {
                $info = $redis->getInfo();
                $dbSize = $redis->dbSize();
                $keys = $redis->getKeys($searchPattern);
                $keys = array_slice($keys, 0, 100);
                $selectedKey = $_GET['key'] ?? null;

                // Validate selected key exists before rendering HTML
                if ($selectedKey && $redis->isConnected()) {
                    $type = $redis->getKeyType($selectedKey);
                    // Check if key exists (type will be 'none' or null if key doesn't exist)
                    if (empty($type) || strtolower($type) === 'none') {
                        // Key doesn't exist - clear it from URL and redirect
                        $url = $_SERVER['PHP_SELF'];
                        if (isset($_GET['search'])) {
                            $url .= '?search=' . urlencode($_GET['search']);
                        }
                        header('Location: ' . $url);
                        exit;
                    }
                }
            ?>
                <!-- Dashboard Layout -->
                <div class="flex h-[calc(100vh-180px)] gap-4">
                    <!-- Left Sidebar -->
                    <div class="w-1/4 flex flex-col border-r border-gray-200 pr-4">
                        <!-- Database Selector -->
                        <div class="mb-4 pb-4 border-b border-gray-200">
                            <label class="block mb-2 font-semibold text-gray-700">Database:</label>
                            <form method="post" class="mb-2">
                                <select name="switch_db" onchange="this.form.submit()" class="w-full p-2 border border-gray-300 rounded text-sm bg-white">
                                    <?php for ($i = 0; $i <= 15; $i++) { ?>
                                        <option value="<?= $i ?>" <?= $currentDb == $i ? 'selected' : '' ?>>
                                            DB <?= $i ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </form>
                            <div class="text-xs text-gray-600 mt-2">
                                <div>Total Keys: <strong><?= number_format($dbSize) ?></strong></div>
                                <div>Version: <?= htmlspecialchars($info['redis_version'] ?? 'N/A') ?></div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="mb-4 pb-4 border-b border-gray-200">
                            <button onclick="showCreateForm()" class="w-full bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm mb-2">+ Create Key</button>
                            <button id="bulkDeleteBtn" onclick="bulkDelete()" class="w-full bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded text-sm mb-2 hidden" disabled>
                                Delete Selected (<span id="selectedCount">0</span>)
                            </button>
                            <button onclick="if(confirm('Are you sure you want to flush the entire database?')) document.getElementById('flushForm').submit();" class="w-full bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded text-sm">
                                Flush Database
                            </button>
                        </div>

                        <form id="flushForm" method="post" class="hidden">
                            <input type="hidden" name="flush_db" value="1">
                        </form>

                        <!-- Search -->
                        <form class="mb-4" method="get">
                            <input type="text" name="search" placeholder="Search keys..." value="<?= htmlspecialchars($searchPattern) ?>" class="w-full p-2 border border-gray-300 rounded text-sm">
                        </form>

                        <!-- Keys List -->
                        <div class="flex-1 overflow-y-auto">
                            <?php if (empty($keys)) { ?>
                                <p class="text-gray-500 text-sm">No keys found.</p>
                            <?php } else { ?>
                                <div class="mb-2 pb-2 border-b border-gray-200">
                                    <label class="flex items-center cursor-pointer">
                                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll()" class="mr-2 w-4 h-4 text-red-600 border-gray-300 rounded focus:ring-red-500">
                                        <span class="text-sm font-semibold text-gray-700">Select All</span>
                                    </label>
                                </div>
                                <form id="bulkDeleteForm" method="post" class="hidden">
                                    <div id="selectedKeysInputs"></div>
                                    <?php if (isset($_GET['search'])) { ?>
                                        <input type="hidden" name="preserve_search" value="<?= htmlspecialchars($_GET['search']) ?>">
                                    <?php } ?>
                                </form>
                                <div class="space-y-1">
                                    <?php foreach ($keys as $key) {
                                        $type = $redis->getKeyType($key);
                                        $isSelected = $selectedKey === $key;
                                    ?>
                                        <div class="p-2 rounded hover:bg-gray-100 <?= $isSelected ? 'bg-red-50 border border-red-200' : '' ?>">
                                            <div class="flex items-center gap-2">
                                                <input type="checkbox"
                                                    name="key_checkbox"
                                                    value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                                                    onchange="updateBulkDeleteButton()"
                                                    onclick="event.stopPropagation()"
                                                    class="w-4 h-4 text-red-600 border-gray-300 rounded focus:ring-red-500 flex-shrink-0">
                                                <div onclick="selectKey('<?= htmlspecialchars($key, ENT_QUOTES) ?>')" class="flex items-center justify-between flex-1 cursor-pointer">
                                                    <span class="text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($key) ?></span>
                                                    <span class="<?= getKeyTypeClasses($type) ?> inline-block px-2 py-0.5 rounded text-xs font-semibold uppercase ml-2"><?= htmlspecialchars($type) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php } ?>
                                </div>
                                <?php if (count($keys) >= 100) { ?>
                                    <p class="mt-2 text-xs text-gray-500">Showing first 100 results</p>
                                <?php } ?>
                            <?php } ?>
                        </div>
                    </div>

                    <!-- Right Panel - Details -->
                    <div class="flex-1 flex flex-col">
                        <div id="detailsPanel" class="flex-1 overflow-y-auto">
                            <?php if ($selectedKey && $redis->isConnected()) {
                                $type = $redis->getKeyType($selectedKey);
                                $ttl = $redis->getTTL($selectedKey);
                                $value = $redis->getValue($selectedKey);
                                // Handle null values
                                $displayValue = $value ?? '';
                                if (is_array($displayValue)) {
                                    $displayValue = json_encode($displayValue, JSON_PRETTY_PRINT);
                                }
                            ?>
                                <div class="bg-white rounded-lg border border-gray-200 p-4">
                                    <div class="flex items-center justify-between mb-4 pb-4 border-b border-gray-200">
                                        <div>
                                            <h2 class="text-xl font-semibold text-gray-800 mb-1"><?= htmlspecialchars($selectedKey) ?></h2>
                                            <div class="flex items-center gap-3 text-sm text-gray-600">
                                                <span class="<?= getKeyTypeClasses($type) ?> inline-block px-2 py-0.5 rounded text-xs font-semibold uppercase"><?= htmlspecialchars($type) ?></span>
                                                <span>TTL: <?= $ttl === -1 ? 'Never' : ($ttl === -2 ? 'Expired' : $ttl . 's') ?></span>
                                            </div>
                                        </div>
                                        <div class="flex gap-2">
                                            <button onclick="showEditForm('<?= htmlspecialchars($selectedKey, ENT_QUOTES) ?>')" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded text-sm">Edit</button>
                                            <button onclick="showExpireForm('<?= htmlspecialchars($selectedKey, ENT_QUOTES) ?>')" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded text-sm">Set TTL</button>
                                            <form method="post" class="inline" onsubmit="return confirm('Delete this key?');">
                                                <input type="hidden" name="delete_key" value="<?= htmlspecialchars($selectedKey) ?>">
                                                <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded text-sm">Delete</button>
                                            </form>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <h3 class="text-sm font-semibold text-gray-700 mb-2">Value:</h3>
                                        <pre class="bg-gray-50 p-4 rounded border border-gray-200 overflow-x-auto text-sm"><?= htmlspecialchars($displayValue) ?></pre>
                                    </div>
                                </div>
                            <?php } else { ?>
                                <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                                    <div class="text-gray-400 mb-4">
                                        <svg class="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                    </div>
                                    <h3 class="text-lg font-semibold text-gray-600 mb-2">Select a key to view details</h3>
                                    <p class="text-sm text-gray-500">Click on any key from the left sidebar to view and edit its details</p>
                                </div>
                            <?php } ?>
                        </div>

                        <!-- Create/Edit Form Panel -->
                        <div id="formPanel" class="hidden bg-white rounded-lg border border-gray-200 p-4 mt-4">
                            <div id="formContent"></div>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>

    <script>
        function selectKey(key) {
            const url = new URL(window.location);
            url.searchParams.set('key', key);
            window.location.href = url.toString();
        }

        function showCreateForm() {
            const formPanel = document.getElementById('formPanel');
            const formContent = document.getElementById('formContent');
            const detailsPanel = document.getElementById('detailsPanel');

            formContent.innerHTML = `
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">Create New Key</h2>
                    <button onclick="hideForm()" class="text-gray-500 hover:text-gray-700">✕</button>
                </div>
                <form method="post">
                    <div class="mb-4">
                        <label class="block mb-1 font-medium text-gray-700">Key:</label>
                        <input type="text" name="new_key" required class="w-full p-2.5 border border-gray-300 rounded text-sm">
                    </div>
                    <div class="mb-4">
                        <label class="block mb-1 font-medium text-gray-700">Value:</label>
                        <textarea name="new_value" required class="w-full p-2.5 border border-gray-300 rounded text-sm min-h-[200px] font-mono"></textarea>
                    </div>
                    <div class="mb-4">
                        <label class="block mb-1 font-medium text-gray-700">TTL (seconds, optional):</label>
                        <input type="number" name="new_ttl" min="0" class="w-full p-2.5 border border-gray-300 rounded text-sm">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" name="add_string" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded text-sm">Create Key</button>
                        <button type="button" onclick="hideForm()" class="bg-gray-500 hover:bg-gray-600 text-white px-5 py-2.5 rounded text-sm">Cancel</button>
                    </div>
                </form>
            `;
            formPanel.classList.remove('hidden');
            detailsPanel.classList.add('hidden');
        }

        function showEditForm(key) {
            fetch('?ajax=view&key=' + encodeURIComponent(key))
                .then(r => r.json())
                .then(data => {
                    const formPanel = document.getElementById('formPanel');
                    const formContent = document.getElementById('formContent');
                    const detailsPanel = document.getElementById('detailsPanel');

                    const value = typeof data.value === 'object' ? JSON.stringify(data.value, null, 2) : data.value;

                    formContent.innerHTML = `
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-semibold">Edit Key: ${escapeHtml(key)}</h2>
                            <button onclick="hideForm()" class="text-gray-500 hover:text-gray-700">✕</button>
                        </div>
                        <form method="post">
                            <input type="hidden" name="edit_key" value="${escapeHtml(key)}">
                            <div class="mb-4">
                                <label class="block mb-1 font-medium text-gray-700">Value:</label>
                                <textarea name="new_value" required class="w-full p-2.5 border border-gray-300 rounded text-sm min-h-[200px] font-mono">${escapeHtml(value)}</textarea>
                            </div>
                            <div class="mb-4">
                                <label class="block mb-1 font-medium text-gray-700">TTL (seconds, optional):</label>
                                <input type="number" name="new_ttl" min="0" class="w-full p-2.5 border border-gray-300 rounded text-sm" placeholder="Leave empty to keep current TTL">
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" name="add_string" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded text-sm">Update Key</button>
                                <button type="button" onclick="hideForm()" class="bg-gray-500 hover:bg-gray-600 text-white px-5 py-2.5 rounded text-sm">Cancel</button>
                            </div>
                        </form>
                    `;
                    formPanel.classList.remove('hidden');
                    detailsPanel.classList.add('hidden');
                })
                .catch(err => {
                    alert('Error loading key: ' + err.message);
                });
        }

        function showExpireForm(key) {
            const formPanel = document.getElementById('formPanel');
            const formContent = document.getElementById('formContent');
            const detailsPanel = document.getElementById('detailsPanel');

            formContent.innerHTML = `
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">Set TTL for: ${escapeHtml(key)}</h2>
                    <button onclick="hideForm()" class="text-gray-500 hover:text-gray-700">✕</button>
                </div>
                <form method="post">
                    <input type="hidden" name="expire_key" value="${escapeHtml(key)}">
                    <div class="mb-4">
                        <label class="block mb-1 font-medium text-gray-700">TTL (seconds):</label>
                        <input type="number" name="expire_ttl" min="0" required class="w-full p-2.5 border border-gray-300 rounded text-sm" placeholder="Enter TTL in seconds">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" name="set_expire" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded text-sm">Set TTL</button>
                        <button type="button" onclick="hideForm()" class="bg-gray-500 hover:bg-gray-600 text-white px-5 py-2.5 rounded text-sm">Cancel</button>
                    </div>
                </form>
            `;
            formPanel.classList.remove('hidden');
            detailsPanel.classList.add('hidden');
        }

        function hideForm() {
            const formPanel = document.getElementById('formPanel');
            const detailsPanel = document.getElementById('detailsPanel');
            formPanel.classList.add('hidden');
            detailsPanel.classList.remove('hidden');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('input[name="key_checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            updateBulkDeleteButton();
        }

        function updateBulkDeleteButton() {
            const checkboxes = document.querySelectorAll('input[name="key_checkbox"]:checked');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            const selectedCount = document.getElementById('selectedCount');
            const count = checkboxes.length;

            if (count > 0) {
                bulkDeleteBtn.classList.remove('hidden');
                bulkDeleteBtn.disabled = false;
                selectedCount.textContent = count;
            } else {
                bulkDeleteBtn.classList.add('hidden');
                bulkDeleteBtn.disabled = true;
            }

            // Update select all checkbox state
            const selectAll = document.getElementById('selectAll');
            const allCheckboxes = document.querySelectorAll('input[name="key_checkbox"]');
            if (allCheckboxes.length > 0) {
                selectAll.checked = count === allCheckboxes.length;
            }
        }

        function bulkDelete() {
            const checkboxes = document.querySelectorAll('input[name="key_checkbox"]:checked');
            const count = checkboxes.length;

            if (count === 0) {
                return;
            }

            const keys = Array.from(checkboxes).map(cb => cb.value);
            const keysList = keys.slice(0, 5).join(', ') + (keys.length > 5 ? ` and ${keys.length - 5} more` : '');

            const message = `Are you sure you want to delete ${count} key(s)?\n\nKeys to delete:\n${keysList}\n\nThis action cannot be undone!`;

            if (confirm(message)) {
                const form = document.getElementById('bulkDeleteForm');
                const inputsContainer = document.getElementById('selectedKeysInputs');
                inputsContainer.innerHTML = '';

                keys.forEach(key => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_keys[]';
                    input.value = key;
                    inputsContainer.appendChild(input);
                });

                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'bulk_delete';
                submitInput.value = '1';
                inputsContainer.appendChild(submitInput);

                form.submit();
            }
        }
    </script>
</body>

</html>
