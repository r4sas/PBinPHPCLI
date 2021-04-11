#!/usr/bin/env php
<?php
/**
 * PBinPHPCLI - PrivateBin CLI on PHP
 *
 * That script is CLI (command line) tool for sending pastes to PrivateBin
 * instances with v2 API support.
 *
 * Copyright (c) 2020 R4SAS. Code is covered by MIT license.
 */

/* ------------------------------------------------------------------------- */
/* ------------------------ Default variable values ------------------------ */

// PrivateBin instance URL
$url = 'https://paste.i2pd.xyz/';

// Compression mode ("zlib", "none")
$compression = 'zlib';

// Paste password
$password = '';

// Paste format ("plaintext", "syntaxhighlighting", "markdown")
$formatter = 'plaintext';

// Paste expire ("5min", "10min", "1hour", "1day", "1week",
//               "1month", "1year", "never")
$expire = '10min';

// Enable discussion (true, false)
$discussion = false;

// Burn paste after reading (true, false)
$burn = false;

// Debug output (true, false)
$debug = false;

/* --------------------- Don't touch anything below! ----------------------- */
/* ------------------------------------------------------------------------- */


/* --- Check if requirements are met --- */

if (version_compare(PHP_VERSION, '7.0.0', '<')) {
  exit("PHP 7+ is required!" . PHP_EOL);
}

if (function_exists('\bcmul') === false) {
  exit("That tool requires BCMath extension!" . PHP_EOL);
}

/**
 * base58 encoding function.
 * Taken from https://github.com/stephen-hill/base58php and uses bcmath.
 * @param string $string
 * @return string
 */
function b58(string $string)
{
  $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
  $base = strlen($alphabet);

  if (strlen($string) === 0) {
    return '';
  }

  $bytes = array_values(unpack('C*', $string));
  $decimal = $bytes[0];

  for ($i = 1, $l = count($bytes); $i < $l; $i++) {
    $decimal = bcmul($decimal, 256);
    $decimal = bcadd($decimal, $bytes[$i]);
  }

  $output = '';
  while ($decimal >= $base) {
    $div = bcdiv($decimal, $base, 0);
    $mod = (int) bcmod($decimal, $base);
    $output .= $alphabet[$mod];
    $decimal = $div;
  }

  if ($decimal > 0) {
    $output .= $alphabet[$decimal];
  }

  $output = strrev($output);

  foreach ($bytes as $byte) {
    if ($byte === 0) {
      $output = $alphabet[0] . $output;
      continue;
    }
    break;
  }
  return (string) $output;
}

/* --- Help --- */
function help() {
  echo <<<EOL
PBinPHPCLI - PrivateBin CLI on PHP

Available options:
1. Used with required input
  -t "text"      - text in quotes, ignored if used stdin
  -f "file.ext"  - include attachment to paste using pointed filename
  -p "password"  - password for encrypting paste
  -E "1day"      - paste lifetime
  -F "plaintext" - paste formatter on webpage in reading mode
  -c "zlib"      - compression mode
  -s "https://paste.i2pd.xyz/"
                 - PrivateBin instance address

2. Used as switches
  -b             - Bypass hardcoded values in "-E" and "-F" options
  -B             - Burn after reading
  -D             - Enable discussion in paste
  -d             - Enable debug output

3. Use text input from stdin
  -T             - input text when no "-t" flag used or text input done
                   with pipe. Can be skipped if "-t" used or when file with
                   "-f" specified.
EOL;
  exit(PHP_EOL);
}

/* --- Encryption parameters --- */
$CIPHER_ITER_COUNT  = 100000;
$CIPHER_SALT_BYTES  = 8;
$CIPHER_BLOCK_BITS  = 256;
$CIPHER_BLOCK_BYTES = $CIPHER_BLOCK_BITS / 8; // 32 bytes
$CIPHER_TAG_BITS    = $CIPHER_BLOCK_BITS / 2; // 128 bits
$CIPHER_TAG_BYTES   = $CIPHER_TAG_BITS / 8;   // 16 bytes
$CIPHER_STRONG      = true;

/* --- Main code --- */
// Get arguments from command line
$shortopts = 'bt:Tf:p:E:BDF:c:s:dh';
$opts = getopt($shortopts);

if(empty($opts) || isset($opts['h'])) {
  help();
}

$bypass = false;
$textinputed = false;
$paste = array();

// Bypass mode check
if(isset($opts['b'])) $bypass = true;


foreach($opts as $opt => $value) {
  switch($opt) {
    case 't':
      if(isset($opts['T']) || isset($paste['paste'])) continue 2;
      $paste = array_merge($paste, ["paste" => $value]);
      continue 2;
    case 'T':
      $input_data = stream_get_contents(STDIN);
      $paste = array_merge($paste, ["paste" => $input_data]);
      continue 2;
    case 'f':
      $file = file_get_contents($value);
      if ($file) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $file);
        if (!$mime) {
          $mime = "application/octet-stream";
        }
        $data = "data:" . $mime . ";base64," . base64_encode($file);
        $name = basename($value);
        $paste = array_merge($paste, ["attachment" => $data,
                                      "attachment_name" => $name]);
      } else {
        exit("Unable open file " . $value . PHP_EOL);
      }
      continue 2;
    case 'p':
      $password = $value;
      continue;
    case 'E':
      if (in_array($value, ["5min", "10min", "1hour", "1day", "1week",
                            "1month", "1year", "never"]) || $bypass) {
        $expire = $value;
      }
      continue 2;
    case 'B':
      if($discussion) exit("You can't mess discussion and burn flags!" .
                            PHP_EOL);
      $burn = true;
      continue 2;
    case 'D':
      if($burn) exit("You can't mess discussion and burn flags!" . PHP_EOL);
      $discussion = true;
      continue 2;
    case 'F':
      if(in_array($value, ["plaintext", "syntaxhighlighting", "markdown"])
         || $bypass) {
        $formatter = $value;
      }
      continue 2;
    case 'c':
      if(in_array($value, ["zlib", "none"])) {
        $compression = $value;
      }
      continue 2;
    case 's':
      $url = $value;
      if (substr($url, -1) !== '/') {
        // URL must ends with slash!
        $url .= '/';
      }
      continue2 ;
    case 'd':
      $debug = true;
      continue 2;
  }
}

if(!isset($paste['paste']) && !isset($paste['attachment'])) {
  exit("Nothing to send!" . PHP_EOL);
}

$passbytes = openssl_random_pseudo_bytes($CIPHER_BLOCK_BYTES, $CIPHER_STRONG);
$passhash = b58($passbytes);

if(!empty($password)) {
  $pass = $passbytes . $password;
} else {
  $pass = $passbytes;
}

$iv = openssl_random_pseudo_bytes($CIPHER_TAG_BYTES);
$salt = openssl_random_pseudo_bytes($CIPHER_SALT_BYTES);
$key = openssl_pbkdf2($pass, $salt, $CIPHER_BLOCK_BYTES,
                      $CIPHER_ITER_COUNT, 'sha256');

$adata = array(
  array(
    base64_encode($iv),
    base64_encode($salt),
    $CIPHER_ITER_COUNT,
    $CIPHER_BLOCK_BITS,
    $CIPHER_TAG_BITS,
    'aes',
    'gcm',
    $compression,
  ),
  $formatter,
  (int) $discussion,
  (int) $burn
);
$authdata = json_encode($adata, JSON_UNESCAPED_SLASHES);

if($compression == 'zlib') {
  $zlib_def = deflate_init(ZLIB_ENCODING_RAW);
  $pastedata = deflate_add($zlib_def, json_encode($paste), ZLIB_FINISH);
} else {
  $pastedata = json_encode($paste);
}

$cipherText = openssl_encrypt($pastedata, 'aes-256-gcm', $key,
                              $options=OPENSSL_RAW_DATA, $iv, $tag,
                              $authdata, $CIPHER_TAG_BYTES);
$fulldata = array(
  'adata' => $adata,
  'ct' => base64_encode($cipherText . $tag),
  'meta' => array(
    'expire' => $expire
  ),
  'v' => 2
);

$data = json_encode($fulldata, JSON_UNESCAPED_SLASHES);

if($debug) {
  echo "Passhash:\t"   . $passhash . PHP_EOL;
  echo "PBKDF2 Key:\t" . base64_encode($key) . PHP_EOL;
  echo "Paste Data:\t" . print_r($paste, true);
  //echo "Paste Data:\t" . print_r($pastedata, true) . PHP_EOL;
  echo "Auth Data:\t"  . print_r($authdata, true) . PHP_EOL;
  echo "CipherText:\t" . base64_encode($cipherText) . PHP_EOL;
  echo "CipherTag:\t"  . base64_encode($tag) . PHP_EOL;
  echo "Prep Data:\t"  . print_r($data, true) . PHP_EOL;
}

$options = array(
  'http' => array(
    'method'  => 'POST',
    'content' => $data,
    'header'  => "Content-Type: application/json\r\n" .
                 "Accept: application/json\r\n" .
                 "X-Requested-With: JSONHttpRequest\r\n"
  )
);

/* --- Send data to server --- */
$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

/* --- Check response code --- */
preg_match('{HTTP\/\S*\s(\d{3})}', $http_response_header[0], $header);
if ($header[1] !== "200") {
  exit("Received incorrect response code (" . $header[1] . "). " .
    "Check if you correctly set instance URL." . PHP_EOL);
}

if($debug) {
  echo "Resp Code:\t" . $header[1] . PHP_EOL;
  echo "Response:\t" . print_r($result, true) . PHP_EOL;
}

$resp = json_decode($result, True);
if($resp['status'] === 0) {
  exit("-- Successfully sent! --" . PHP_EOL .
    "Paste Link:\t" . $url . "?" . $resp['id'] . "#" . $passhash . PHP_EOL .
    "Detele Link:\t" . $url . "?pasteid=" . $resp['id'] . "&deletetoken=" .
    $resp['deletetoken'] . PHP_EOL);
} else {
  exit("Error: " . $resp['message'] . PHP_EOL);
}
?>
