<?php
/** Public display identity; only active administrators may change it.
 * The small raster image is validated before database storage, with optional
 * GD re-encoding. It is displayed only in an image element, never as HTML.
 * No file path, executable markup, or private settings are exposed publicly.
 */
declare(strict_types=1);
require_once __DIR__.'/../../includes/validation.php';
require_once __DIR__.'/../../includes/branding.php';
function handle_branding(): never
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $s=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $s->execute(['site_branding']);
        $data=json_decode($s->fetchColumn() ?: 'null',true) ?: ['text'=>'CoopVote','logo'=>null];
        $data['coopName']=coop_setting('coop_name',branding_defaults()['coopName']);
        json_out($data);
    }
    require_method('PUT');$me=require_admin();require_json_request();
    $text=input('text');$logo=input('logo');
    if (!is_string($text) || blank_form_value($text) || mb_strlen(trim_form_text($text))>40) {
        json_error('Enter a logo name with 1 to 40 characters.',422);
    }
    $text=trim_form_text($text);
    $coopName=input('coopName',coop_setting('coop_name',branding_defaults()['coopName']));
    if (!is_string($coopName) || blank_form_value($coopName) || mb_strlen(trim_form_text($coopName))>150) {
        json_error('Enter a cooperative name with 1 to 150 characters.',422);
    }
    $coopName=trim_form_text($coopName);
    if ($logo!==null) {
        if (!is_string($logo) || strlen($logo)>65000 || !preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#D',$logo,$m)) {
            json_error('Choose a PNG, JPEG or WebP logo.',422);
        }
        $bytes=base64_decode($m[2],true);$info=$bytes!==false?@getimagesizefromstring($bytes):false;
        if (!$info || $info[0]>512 || $info[1]>512 || !in_array($info['mime'],['image/png','image/jpeg','image/webp'],true)) {
            json_error('Use a valid logo image no larger than 512 by 512 pixels.',422);
        }
        $clean=$bytes;$mime=$info['mime'];
        if (function_exists('imagewebp') && function_exists('imagecreatefromstring')) {
            $image=@imagecreatefromstring($bytes);
            if (!$image) json_error('The logo image could not be read.',422);
            ob_start();imagewebp($image,null,85);$clean=ob_get_clean();imagedestroy($image);
            $mime='image/webp';
        }
        if (!$clean || strlen($clean)>45000) json_error('The logo is too detailed. Choose a simpler image.',422);
        $logo='data:'.$mime.';base64,'.base64_encode($clean);
    }
    $data=['text'=>$text,'logo'=>$logo,'coopName'=>$coopName];
    db_transaction(static function(PDO $pdo) use($data):void {
        branding_store($pdo,$data);
    });
    audit((int)$me['user_id'],'branding.updated','settings',null,'Updated the system logo and name');
    json_out($data);
}
