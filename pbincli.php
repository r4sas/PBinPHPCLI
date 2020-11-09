#!/usr/bin/env php
<?php
/**
 * PBinPHPCLI - PrivateBin PHP CLI
 *
 * That script is CLI (command line) tool for sending pastes to PrivateBin
 * instances with v2 API support.
 *
 * TODO: add work with command line arguments using getopt.
 *
 * Copyright (c) 2020 R4SAS. Code is covered by MIT license.
 */

/* ------------------------------------------------------------------------- */
/* ------------------------ Default variable values ------------------------ */

// PrivateBin instance URL
$url = 'https://paste.i2pd.xyz/';

// Paste password
$password = '';

// Use ZLIB compression (zlib or none)
$compression = 'zlib';

// Print debug output (True or False)
$debug = False;

/* --------------------- Don't touch anything below! ----------------------- */
/* ------------------------------------------------------------------------- */

// Paste data -- testing
$paste = array(
  'paste' => 'text content of the paste'
);

/* --- Check if requirements are met --- */

if (version_compare(PHP_VERSION, '7.0.0', '<')) {
  exit("PHP 7+ is required!");
}

if (function_exists('\bcmul') === false) {
  exit("That tool requires BCMath extension!");
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

  if (is_string($string) === false) {
    exit('Argument $string must be a string.');
  }

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


/* --- Encryption parameters --- */
$CIPHER_ITER_COUNT  = 100000;
$CIPHER_SALT_BYTES  = 8;
$CIPHER_BLOCK_BITS  = 256;
$CIPHER_BLOCK_BYTES = $CIPHER_BLOCK_BITS / 8; // 32 bytes
$CIPHER_TAG_BITS    = $CIPHER_BLOCK_BITS / 2; // 128 bits
$CIPHER_TAG_BYTES   = $CIPHER_TAG_BITS / 8;   // 16 bytes
$CIPHER_STRONG      = True;


/* --- Main code --- */
if (substr($url, -1) !== '/') {
  // URL must ends with slash!
  $url .= '/';
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
$key = openssl_pbkdf2($pass, $salt, $CIPHER_BLOCK_BYTES, $CIPHER_ITER_COUNT, 'sha256');

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
  'plaintext',
  0,
  0
);
$authdata = json_encode($adata, JSON_UNESCAPED_SLASHES);

if($compression == 'zlib') {
  $zlib_def = deflate_init(ZLIB_ENCODING_RAW);
  $pastedata = deflate_add($zlib_def, json_encode($paste), ZLIB_FINISH);
} else {
  $pastedata = json_encode($paste);
}

$cipherText = openssl_encrypt($pastedata, 'aes-256-gcm', $key, $options=OPENSSL_RAW_DATA, $iv, $tag, $authdata, $CIPHER_TAG_BYTES);
$fulldata = array(
  'adata' => $adata,
  'ct' => base64_encode($cipherText . $tag),
  'meta' => array(
    'expire' => '5min'
  ),
  'v' => 2
);

$data = json_encode($fulldata, JSON_UNESCAPED_SLASHES);

if($debug) {
  echo "Passhash:\t"   . $passhash . PHP_EOL;
  echo "PBKDF2 Key:\t" . base64_encode($key) . PHP_EOL;
  echo "Paste Data:\t" . print_r($pastedata, true) . PHP_EOL;
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
  exit("Received incorrect response code (" . $header[1] . "). Check if you correctly set instance URL." . PHP_EOL);
}

if($debug) {
  echo "Resp Code:\t" . $header[1] . PHP_EOL;
  echo "Response:\t" . print_r($result, true) . PHP_EOL;
}

$resp = json_decode($result, True);
if($resp['status'] === 0) {
  exit("Successfully sent! Link: " . $url . "?" . $resp['id'] . "#" . $passhash . PHP_EOL);
} else {
  exit("Error: " . $resp['message'] . PHP_EOL);
}
?>
