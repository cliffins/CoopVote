<?php
/** Connection policy shared by db(), diagnostics and non-destructive tests. */
declare(strict_types=1);
require_once __DIR__.'/../config/runtime.php';

function database_certificate_file(string $path,string $kind): string
{
    if(!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$path)||!@is_file($path)||!@is_readable($path))
        config_failure('DB_TLS_FILE_UNREADABLE','The database '.$kind.' must be a readable absolute file path on this server. Check PHP-FPM permissions and open_basedir; do not copy workstation paths.');
    $content=@file_get_contents($path);
    if($content===false)config_failure('DB_TLS_FILE_UNREADABLE','PHP cannot read the configured database '.$kind.'.');
    if($kind==='client key'){
        if(!@openssl_pkey_get_private($content))config_failure('DB_TLS_PEM_INVALID','The client key must be a valid unencrypted PEM private key.');
    }else{
        if(str_contains($content,'PRIVATE KEY')||!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',$content,$matches))config_failure('DB_TLS_PEM_INVALID','The database '.$kind.' must contain PEM certificates, not a key or downloaded HTML page.');
        foreach($matches[0] as $pem)if(!@openssl_x509_read($pem))config_failure('DB_TLS_PEM_INVALID','The database '.$kind.' contains an invalid PEM certificate.');
    }
    return $path;
}
function database_pdo_options(array $config): array
{
    if(!extension_loaded('pdo_mysql'))config_failure('DB_DRIVER_MISSING','Enable pdo_mysql for both PHP-FPM and the PHP CLI.');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>10];
    if (($config['socket'] ?? '') !== '') {
        if ($config['ca'] !== '' || $config['cert'] !== '' || $config['key'] !== '') config_failure('DB_CONFIG_INVALID','Choose either a local Unix socket or TCP TLS certificates, not both.');
        // PDO opens the socket itself. PHP file checks may be denied by
        // open_basedir even when the MySQL driver can use the local socket.
        return $options;
    }
    if($config['ca']===''){
        if($config['tls_required']||$config['cert']!==''||$config['key']!=='')config_failure('DB_TLS_REQUIRED','Set COOPVOTE_DB_SSL_CA or db_ssl_ca to a trusted database CA bundle supplied or confirmed by the hosting provider.');
        return $options;
    }
    if(!extension_loaded('openssl')||!extension_loaded('mysqlnd')||!defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'))config_failure('DB_TLS_DRIVER_UNSUPPORTED','Verified database TLS requires pdo_mysql with mysqlnd and OpenSSL.');
    $options[PDO::MYSQL_ATTR_SSL_CA]=database_certificate_file($config['ca'],'CA bundle');
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;
    if(($config['cert']==='')!==($config['key']===''))config_failure('DB_TLS_CLIENT_CONFIG_INVALID','For mutual TLS, configure both the client certificate and client key.');
    if($config['cert']!==''){
        $options[PDO::MYSQL_ATTR_SSL_CERT]=database_certificate_file($config['cert'],'client certificate');
        $options[PDO::MYSQL_ATTR_SSL_KEY]=database_certificate_file($config['key'],'client key');
    }
    return $options;
}
function database_assert_tls(string $version,string $cipher): void
{
    if(!in_array($version,['TLSv1.2','TLSv1.3'],true)||$cipher==='')config_failure('DB_TLS_NEGOTIATION_FAILED','The session did not negotiate TLS 1.2 or 1.3 with a cipher. Ask the provider to enforce TLS 1.2+ and REQUIRE SSL for this account. No application queries were allowed.');
}
function database_assert_encryption(array $inventory): void
{
    if((int)($inventory['total']??0)<1)config_failure('DB_ENCRYPTION_UNVERIFIABLE','No database tables are visible. Check the selected database and metadata privileges; encryption cannot be verified.');
    if((int)($inventory['unprotected']??-1)!==0)config_failure('DB_ENCRYPTION_REQUIRED','Application tables lack verified native InnoDB encryption. The provider must configure MariaDB encryption and managed AES-256 keys. No schema changes were made.');
}
function database_verify_connection(PDO $connection,array $config): void
{
    if($config['tls_required']||$config['ca']!==''){
        $tls=$connection->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version','Ssl_cipher')")->fetchAll(PDO::FETCH_KEY_PAIR);
        database_assert_tls((string)($tls['Ssl_version']??''),(string)($tls['Ssl_cipher']??''));
    }
    if($config['encryption_required']){
        try{
            $version=(string)$connection->query('SELECT VERSION()')->fetchColumn();
            if(stripos($version,'MariaDB')===false)config_failure('DB_ENCRYPTION_UNSUPPORTED','This verifier requires MariaDB INNODB_TABLESPACES_ENCRYPTION. MySQL uses different encryption metadata and is not certified by this implementation.');
            $query=$connection->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(COALESCE(t.ENGINE,'') <> 'InnoDB'
                OR REPLACE(UPPER(COALESCE(t.CREATE_OPTIONS,'')),'`','') NOT LIKE '%ENCRYPTED=YES%'
                OR COALESCE(e.ENCRYPTION_SCHEME,0) <> 1 OR COALESCE(e.CURRENT_KEY_VERSION,0) < 1),0) AS unprotected
                FROM information_schema.TABLES t LEFT JOIN information_schema.INNODB_TABLESPACES_ENCRYPTION e
                ON e.NAME=CONCAT(t.TABLE_SCHEMA,'/',t.TABLE_NAME)
                WHERE t.TABLE_SCHEMA=? AND t.TABLE_TYPE='BASE TABLE'");
            $query->execute([$config['name']]);database_assert_encryption($query->fetch()?:[]);
        }catch(PDOException){config_failure('DB_ENCRYPTION_UNVERIFIABLE','MariaDB encryption metadata is unavailable or permission was denied. The provider must supply supported metadata access (normally PROCESS privilege) or a separately reviewed solution.');}
    }
}
function database_connection_error(PDOException $error): DatabaseConfigurationException
{
    if (preg_match('/SSL|TLS|certificate/i', $error->getMessage())) {
        return new DatabaseConfigurationException('DB_TLS_HANDSHAKE_FAILED', 'TLS handshake failed. Verify the CA chain, certificate expiry, hostname/IP SAN match and any client-certificate requirement with the provider.');
    }
    [$reason,$message]=match((int)($error->errorInfo[1]??0)){
        1045=>['DB_AUTH_FAILED','Database authentication failed. Check deployment credentials and host grants.'],
        1049=>['DB_NOT_FOUND','The configured database was not found. Confirm the hosting database name; do not reimport over existing data.'],
        2026=>['DB_TLS_HANDSHAKE_FAILED','TLS handshake failed. Verify CA chain, certificate expiry, hostname/IP SAN match and any client-certificate requirement with the provider.'],
        default=>['DB_CONNECTION_FAILED','Database connection failed. Check TCP host/port, service availability, grants and TLS certificate/hostname configuration.'],
    };
    return new DatabaseConfigurationException($reason,$message);
}
function database_connect(array $config): PDO
{
    $options=database_pdo_options($config);
    $dsn=($config['socket']??'')!==''
        ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4',$config['socket'],$config['name'])
        : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$config['host'],$config['port'],$config['name']);
    try{$connection=@new PDO($dsn,$config['user'],$config['password'],$options);}
    catch(PDOException $e){throw database_connection_error($e);}
    database_verify_connection($connection,$config);
    return $connection;
}
