<?php

// Runs as root before PHP-FPM; never logs certificate contents or credentials.
$source = getenv('MYSQL_ATTR_SSL_CA');
$destination = $argv[1] ?? '/var/www/html/storage/app/private/mysql-ca.pem';
if (! $source || ! is_readable($source)) {
    fwrite(STDERR, "MySQL CA file is missing or unreadable. Add mysql-ca.pem under Render Secret Files and set MYSQL_ATTR_SSL_CA=/etc/secrets/mysql-ca.pem.\n");
    exit(1);
}
$pem = file_get_contents($source);
if ($pem === false || ! preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $match)
    || ! ($certificate = openssl_x509_read($match[0]))) {
    fwrite(STDERR, "MySQL CA file is invalid. Paste the complete downloaded Aiven ca.pem contents into Render Secret Files, including BEGIN and END CERTIFICATE lines.\n");
    exit(1);
}
$details = openssl_x509_parse($certificate);
if (! $details || $details['validTo_time_t'] < time() || $details['validFrom_time_t'] > time()) {
    fwrite(STDERR, "MySQL CA certificate is expired or not yet valid. Download the current CA from Aiven.\n");
    exit(1);
}
// Preserve the full CA bundle. Normalize Windows line endings and a possible UTF-8 BOM.
$pem = str_replace("\r\n", "\n", preg_replace('/^\xEF\xBB\xBF/', '', $pem));
if (file_put_contents($destination, $pem) === false) {
    fwrite(STDERR, "Could not prepare the private MySQL CA file.\n");
    exit(1);
}
echo "MySQL CA certificate validated.\n";
