<?php
declare(strict_types=1);
/** Canonical address comparison prevents alternate IPv6 spelling bypasses. */
function normalized_ip(string $ip): ?string
{
    if (!filter_var($ip,FILTER_VALIDATE_IP)) return null;
    $packed=inet_pton($ip);
    if (strlen($packed)===16 && substr($packed,0,12)===str_repeat("\0",10)."\xff\xff") return inet_ntop(substr($packed,12));
    return inet_ntop($packed);
}
