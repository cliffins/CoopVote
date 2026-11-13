<?php
/** Deployment configuration. Credentials belong in private files or environment variables. */
declare(strict_types=1);

final class DatabaseConfigurationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $operatorMessage) { parent::__construct($operatorMessage); }
}
function config_failure(string $reason, string $message): never { throw new DatabaseConfigurationException($reason,$message); }

function deployment_settings(): array
{
    static $settings;
    if (isset($settings)) return $settings;
    $path=getenv('COOPVOTE_CONFIG_FILE');
    if ($path!==false && $path!=='') {
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$path) || !@is_file($path) || !@is_readable($path))
            config_failure('CONFIG_FILE_UNREADABLE','COOPVOTE_CONFIG_FILE must name a readable absolute PHP configuration file. Check PHP permissions and open_basedir.');
    } else {
        $path=__DIR__.'/security-local.php';
        if (!is_file($path)) return $settings=[];
    }
    ob_start();
    try { $value=require $path; $output=ob_get_clean(); }
    catch(Throwable) { ob_end_clean(); config_failure('CONFIG_FILE_INVALID','The private configuration file could not be loaded. Check PHP syntax and permissions.'); }
    if($output!=='')config_failure('CONFIG_FILE_INVALID','The private configuration file must return settings without producing output. Remove any BOM, HTML or echo statements.');
    if(!is_array($value)) config_failure('CONFIG_FILE_INVALID','The private configuration file must return an array.');
    return $settings=$value;
}
function deployment_value(string $variable,string $key,mixed $default=null): mixed
{
    $value=getenv($variable);
    return $value!==false ? $value : (deployment_settings()[$key]??$default);
}

/** Pure resolver: tests supply configuration without reading real secrets. */
function database_settings(array $settings,callable $env,string $sapi=PHP_SAPI): array
{
    $read=static function(string $variable,string $key,mixed $default=null)use($settings,$env):mixed {
        $value=$env($variable);return $value!==false ? $value : (array_key_exists($key,$settings)?$settings[$key]:$default);
    };
    $environment=$read('COOPVOTE_ENV','environment','production');
    if(!in_array($environment,['production','local','test'],true))config_failure('CONFIG_ENV_INVALID','COOPVOTE_ENV must be production, local, or test.');
    $name=$env('COOPVOTE_DB'); // Legacy isolated-suite override.
    if($name===false)$name=$read('COOPVOTE_DB_NAME','db_name');
    $host=$read('COOPVOTE_DB_HOST','db_host','127.0.0.1');
    $port=$read('COOPVOTE_DB_PORT','db_port',3306);
    $socket=$read('COOPVOTE_DB_SOCKET','db_socket','');
    if(!is_string($socket)||($socket!==''&&(!str_starts_with($socket,'/')||preg_match('/[;\x00-\x1f]/',$socket))))config_failure('DB_CONFIG_INVALID','Database socket must be an absolute path without DSN delimiters.');
    $user=$read('COOPVOTE_DB_USER','db_user');$password=$read('COOPVOTE_DB_PASSWORD','db_password');
    if(!is_string($name)||!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,63}$/D',$name))config_failure('DB_CONFIG_INVALID','Set a valid COOPVOTE_DB_NAME (or db_name).');
    if(!is_string($host)||(!filter_var($host,FILTER_VALIDATE_IP)&&!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/D',$host)))config_failure('DB_CONFIG_INVALID','Set a TCP database host; DSN fragments and socket paths are not allowed.');
    if($socket===''&&strtolower($host)==='localhost'&&PHP_OS_FAMILY!=='Windows')config_failure('DB_CONFIG_INVALID','Use an explicit Unix socket or a certificate-matching TCP hostname or IP.');
    if((!is_int($port)&&!is_string($port))||!ctype_digit((string)$port)||(int)$port<1||(int)$port>65535)config_failure('DB_CONFIG_INVALID','COOPVOTE_DB_PORT must be between 1 and 65535.');
    if(!is_string($user)||trim($user)===''||str_contains($user,"\0"))config_failure('DB_CONFIG_INVALID','Set COOPVOTE_DB_USER (or db_user) for this deployment.');
    if(!is_string($password)||($environment==='production'&&$password===''))config_failure('DB_CONFIG_INVALID','Set COOPVOTE_DB_PASSWORD (or db_password); production requires a nonempty password.');
    $fixture=$environment==='test'&&$name==='coopvote_test'&&in_array($host,['127.0.0.1','localhost','::1'],true)&&in_array($sapi,['cli','cli-server'],true);
    if($environment==='test'&&!$fixture)config_failure('CONFIG_TEST_SCOPE_INVALID','Test mode is limited to local coopvote_test under CLI or the isolated PHP test server.');
    $encryption=$read('COOPVOTE_DB_ENCRYPTION_REQUIRED','db_encryption_required',true);
    $encryption=filter_var($encryption,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    if($encryption===null)config_failure('DB_CONFIG_INVALID','Database encryption requirement must be a boolean.');
    if(!$fixture&&!$encryption&&$socket==='')config_failure('DB_CONFIG_INVALID','Disabling mandatory table encryption is limited to an explicitly configured local Unix socket.');
    $result=['environment'=>$environment,'host'=>$host,'port'=>(int)$port,'socket'=>$socket,'name'=>$name,'user'=>$user,'password'=>$password,
        'tls_required'=>!$fixture&&$socket==='','encryption_required'=>!$fixture&&$encryption];
    foreach(['ca'=>'db_ssl_ca','cert'=>'db_ssl_cert','key'=>'db_ssl_key'] as $short=>$key){
        $value=$read('COOPVOTE_DB_SSL_'.strtoupper($short),$key,'');
        if(!is_string($value))config_failure('DB_CONFIG_INVALID','Database certificate settings must be file paths.');
        $result[$short]=$value;
    }
    return $result;
}
