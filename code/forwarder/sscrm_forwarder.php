#!/usr/bin/php5 -q
<?
/**
 * Place this 
 */
define('ERROR_ADDRESS', 'ingo@silverstripe.com');

$url = $_SERVER['argv'][1];

if($url) {
    $emailData = file_get_contents("php://stdin");
    if(!sendPostRequest($url, "Message=" . urlencode($emailData))) {
        mail(ERROR_ADDRESS, "Couldn't post to URL $url in forwarder.php","");
    }

} else {
    mail(ERROR_ADDRESS, "No URL given to forwarder.php","");
}



/**
 * Post the given data to the given URL
 * The data must already be encoded into a string
  */
function sendPostRequest($url, $data) {
    $urlParts = parse_url($url);
    if(!$urlParts) return false;
    if(!$urlParts['port']) $urlParts['port'] = 80;

     $fp = fsockopen( $urlParts['host'], $urlParts['port'], $errno, $error );
     if( !$fp ) return false;

     $length = strlen( $data );

     $send =  "POST $urlParts[path] HTTP/1.1\r\n";
     $send .= "Host: $urlParts[host]\r\n";
     $send .= "User-Agent: SilverStripe Incoming Email Handler\r\n";
     $send .= "Connection: Close\r\n";
     $send .= "Content-Type: application/x-www-form-urlencoded\r\n";
     $send .= "Content-Length: $length\r\n\r\n";

     $send .= "$data\r\n";

     $response = '';

     fwrite($fp, $send);

     while (!feof($fp)) {
          $response .= fgets($fp, 128);
     }
     fclose($fp);

     if(ereg("^HTTP/1.1 2", $response) && strpos($response, "\r\n\r\nOK") !== false) return true;
     else {
         mail(ERROR_ADDRESS, "Forum post response", $response);
         return false;
     }
}
?>