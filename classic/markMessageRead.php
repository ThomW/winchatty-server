<?
require_once '../include/Global.php';
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

if (!isset($_REQUEST['id']))
   die('No id');

$username = false;
$password = false;

if (!isset($_SERVER['PHP_AUTH_USER'])) 
{
   header('WWW-Authenticate: Basic realm="WinChatty Server"');
   header('HTTP/1.0 401 Unauthorized');
   die('Unable to log in.');
}
else
{
   $username = $_SERVER['PHP_AUTH_USER'];
   $password = $_SERVER['PHP_AUTH_PW'];
}

try
{
   MessageParser()->markMessageAsRead($username, $password, intval($_REQUEST['id']));
}
catch (Exception $e)
{
   die('error_mark_failed');
}


die('ok');
