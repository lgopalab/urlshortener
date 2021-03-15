<?php
namespace UrlShortner\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;
use mysqli;
use DateTime;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use UrlShortner\Exceptions\CustomException;

class URLController
{
    private static $mysqli = null;
    private Request $request;

    /**
     * URLController constructor.
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
        if(is_null(self::$mysqli)) self::$mysqli = DatabaseController::initialize();
    }

    /**
     * @param $urlHook
     * @return array|bool
     * @throws CustomException
     * @throws HttpNotFoundException
     */
    public function getURLDetails($urlHook)
    {
        $redirectDetails = MemcachedController::getMemCache($this->getMemcacheKeyName($urlHook));

        //If not found in memcache fetch it from DB
        if(!$redirectDetails){
            $redirectDetails = array();
            $sql = "SELECT id, original_url, shortened_url, creation_date, expiration_date FROM links WHERE BINARY shortened_url=?";
            $stmt = self::$mysqli->prepare($sql);
            if(!$stmt) throw new CustomException($this->request,"Error occurred while preparing statement");
            $stmt->bind_param("s",$urlHook);
            if($stmt->execute()){
                $stmt->store_result();
                if($stmt->num_rows == 1){
                    $stmt->bind_result($id, $original_url, $shortened_url, $creation_date, $expiration_date);
                    $stmt->fetch();
                    $redirectDetails["id"] = (int)$id;
                    $redirectDetails["original_url"] = $original_url;
                    $redirectDetails["shortened_url"] = $shortened_url;
                    $redirectDetails["creation_date"] = $creation_date;
                    $redirectDetails["expiration_date"] = $expiration_date;

                    MemcachedController::setMemCache($this->getMemcacheKeyName($urlHook),$redirectDetails,3600);
                }else{
                    throw new HttpNotFoundException($this->request,"Invalid URL");
                }
            }else{
                throw new CustomException($this->request, "Error occurred while statement execution");
            }
        }

        return $redirectDetails;
    }

    /**
     * @param $urlHook
     */
    public function getURLStats($urlHook){

        $urlStats = array("visits"=>0,"data"=>array());
        global $logger;
        try{
            $sql = "SELECT id,visits FROM links WHERE BINARY shortened_url=?";
            $stmt = self::$mysqli->prepare($sql);
            if(!$stmt) {
                // Doing nothing
            }
            $stmt->bind_param("s",$urlHook);
            if($stmt->execute()){
                $stmt->store_result();
                if($stmt->num_rows == 1){
                    $stmt->bind_result($id,$visits);
                    $stmt->fetch();
                    $urlStats["visits"] = (int)$visits;
                }
            }
            $id = (int)$id;

            $statsSql = "SELECT from_addr, browser_info, referrer, os_info FROM link_stats WHERE BINARY link_id=? LIMIT 100";
            $statsStmt = self::$mysqli->prepare($statsSql);
            if(!$statsStmt) {
                // Doing nothing
            }
            $statsStmt->bind_param("i",$id);
            if($statsStmt->execute()){
                $statsStmt->store_result();
                if($statsStmt->num_rows > 0){
                    $statsStmt->bind_result($fromAddr, $browserInfo, $referrer, $osInfo);
                    while ($statsStmt->fetch()) {

                        $urlStats["data"][] = array("from_addr"=>$fromAddr, "browser_info"=>$browserInfo,"referrer"=>$referrer,"os_info"=>$osInfo);
                    }
                }
            }
        }catch(Exception $e){
            // Doing nothing
        }
        return $urlStats;
    }

    /**
     * @param $urlHook
     * @return string
     */
    private function getMemcacheKeyName($urlHook)
    {
        return "URL_SHORTNER_".$urlHook;
    }

    /**
     * @param Request $request
     * @param $linkId
     * @throws CustomException
     */
    public function addURLStats(Request $request, $linkId)
    {
        $headerDetails = array();
        $headerDetails["browser_info"] = $request->getHeaderLine('User-Agent');
        $headerDetails["referer"] = $request->getHeaderLine('Referer');

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        $sql = "INSERT INTO link_stats (link_id, from_addr, browser_info, referrer, os_info) VALUES (?, ?, ?, ?, ?)";
        $stmt = self::$mysqli->prepare($sql);
        if(!$stmt) throw new Exception("Error occurred while preparing statement. ".$stmt->error);
        $browser = $this->getBrowserName($headerDetails["browser_info"]);
        $os = $this->getOS($headerDetails["browser_info"]);
        $stmt->bind_param("issss", $linkId, $ip, $browser, $headerDetails["referer"], $os);
        if($stmt->execute()){
            $stmt->close();
        } else {
            throw new CustomException($this->request,"Error occurred while statement execution. ".$stmt->error);
        }

        $visitedUpdateSQL = "UPDATE links SET visits = visits + 1 WHERE id = ?";
        $visitedUpdateStmt = self::$mysqli->prepare($visitedUpdateSQL);
        if(!$visitedUpdateStmt) throw new CustomException($this->request,"Error occurred while preparing statement. ".$visitedUpdateStmt->error);
        $visitedUpdateStmt->bind_param("i", $linkId);
        if($visitedUpdateStmt->execute()){
            $visitedUpdateStmt->close();
        } else {
            throw new Exception("Error occurred while statement execution. ".$visitedUpdateStmt->error);
        }
    }

    /**
     * @param $url
     */
    public function getValidRedirectURL(&$url)
    {
        if(!(strpos($url, "https://") !== false || strpos($url, "http://") !== false)){
            $url = "http://".$url;
        }
    }

    /**
     * @param string|null $customHook
     * @param int $length
     * @return false|string
     * @throws CustomException
     */
    public function getValidHook(string $customHook = null, int $length = 8)
    {
        $hook = "";
        if(isset($customHook)){
            if(strlen($customHook)<$length) throw new Exception("Invalid parameter custom_hook");
            $hook = substr($customHook, 0, $length);
        }else{
            $hook = substr(md5(uniqid(mt_rand(), true)), 0, $length);
        }

        if($this->checkIfHookExists($hook)){
            throw new Exception("Invalid parameter custom_hook");
        }

        return $hook;
    }

    /**
     * @param $hook
     * @return bool
     * @throws CustomException
     */
    public function checkIfHookExists($hook)
    {

        $sql = "SELECT id FROM links WHERE BINARY shortened_url=?";
        $stmt = self::$mysqli->prepare($sql);
        if(!$stmt) throw new CustomException($this->request,"Error occurred while preparing statement");
        $stmt->bind_param("s",$hook);
        if($stmt->execute()){
            $stmt->store_result();
            if($stmt->num_rows > 0){
                return true;
            }else{
                return false;
            }
        }else{
            throw new CustomException($this->request, "Error occurred while statement execution");
        }
        return false;
    }

    /**
     * @param $url
     * @return bool
     * @throws CustomException
     */
    private function checkIfURLExists($url)
    {

        $sql = "SELECT id FROM links WHERE BINARY original_url=?";
        $stmt = self::$mysqli->prepare($sql);
        if(!$stmt) throw new CustomException($this->request,"Error occurred while preparing statement");
        $stmt->bind_param("s",$url);
        if($stmt->execute()){
            $stmt->store_result();
            if($stmt->num_rows > 0){
                return true;
            }else{
                return false;
            }
        }else{
            throw new CustomException($this->request, "Error occurred while statement execution");
        }
        return false;
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    public function createNewLink($data)
    {
        $returnArray = array();
        $errorCount = 0;
        $processedCount = 0;

        $sql = "INSERT INTO links (original_url, shortened_url, expiration_date, visits) VALUES (?, ?, ?, 0)";
        $stmt = self::$mysqli->prepare($sql);
        if(!$stmt) throw new Exception("Error occurred while preparing statement. ".$stmt->error);
        $stmt->bind_param("sss", $urlInfo, $hook, $expirationDate);

        $checkURLSql = "SELECT id FROM links WHERE BINARY original_url=?";
        $checkURLStmt = self::$mysqli->prepare($checkURLSql);
        if(!$checkURLStmt) throw new Exception("Error occurred while preparing statement. ".$stmt->error);
        $checkURLStmt->bind_param("s",$urlInfo);

        foreach ($data as $linkData){
            try{
                $urlInfo = $this->isURLValid($linkData["url"]);
                $hook = isset($linkData["custom_hook"])? $this->getValidHook($linkData["custom_hook"]):$this->getValidHook();
                $expirationDate = isset($linkData["expiration_date"])? $this->isExpirationDateValid($linkData["expiration_date"]):null;

                if($checkURLStmt->execute()){
                    $checkURLStmt->store_result();
                    if($checkURLStmt->num_rows > 0){
                        throw new Exception("Invalid parameter url");
                    }
                }

                if (!$stmt->execute()) throw new Exception("Error occurred while statement execution. ".$stmt->error, 500);
                $stmt->free_result();

                $this->getHookBasedUrl($hook);

                $returnArray[] = array("url"=>$this->getHookBasedUrl($hook));
                $processedCount++;

            }catch(Exception $e){
                if(strpos($e->getMessage(),"Invalid parameter") !== false)
                    $returnArray[] = array("statusCode"=>400,"error"=>array("type"=>"INVALID_PARAMETER","message"=>$e->getMessage()));
                else if(strpos($e->getMessage(),"Required parameter") !== false)
                    $returnArray[] = array("statusCode"=>400,"error"=>array("type"=>"REQUIRED_PARAMETER","message"=>$e->getMessage()));
                else if(strpos($e->getMessage(),"statement") !== false)
                    $returnArray[] = array("statusCode"=>500,"error"=>array("type"=>"SERVER_ERROR","message"=>$e->getMessage()));
                else
                    $returnArray[] = array("statusCode"=>500,"error"=>array("type"=>"SERVER_ERROR","message"=>$e->getMessage()));

                $errorCount++;
            }
        }

        return array("data"=>$returnArray,"processedCount"=>$processedCount,"errorCount"=>$errorCount);
    }

    /**
     * @param $date
     * @return string|null
     * @throws Exception
     */
    private function isExpirationDateValid($date)
    {
        if(isset($date)){
            if (!($date = DateTime::createFromFormat('Y-m-d H:i:s', $date))) throw new Exception("Invalid parameter expiration_date");
            if(time()-strtotime($date->format('Y-m-d H:i:s'))>0){
                throw new Exception("Invalid parameter expiration_date");
            }
            return $date->format('Y-m-d H:i:s');
        }else{
            return null;
        }
    }

    /**
     * @param $url
     * @return mixed
     * @throws CustomException
     */
    private function isURLValid($url)
    {
        if(!is_null($url)){
            $urlInfo = parse_url($url);
            if ($urlInfo === false || empty($urlInfo["scheme"]) || empty($urlInfo["host"])) throw new Exception("Invalid parameter url - Not well formed");
            else $urlInfo = $url;
            if (!filter_var($urlInfo, FILTER_VALIDATE_URL)) {
                throw new Exception("Invalid parameter url - Filter match fail");
            }
            if($this->checkIfURLExists($urlInfo))throw new Exception("Invalid parameter url - Already Exists");
            if(!$this->checkIfURLReachable($urlInfo))throw new Exception("Invalid parameter url - Not reachable");
            return $urlInfo;
        }else{
            throw new Exception("Required parameter url");
        }
    }

    /**
     * @param $url
     * @return bool
     */
    private function checkIfURLReachable($url){
        $c=curl_init();
        curl_setopt($c,CURLOPT_URL,$url);
        curl_setopt($c,CURLOPT_HEADER,1);//get the header
        curl_setopt($c,CURLOPT_NOBODY,1);//and *only* get the header
        curl_setopt($c,CURLOPT_RETURNTRANSFER,1);//get the response as a string from curl_exec(), rather than echoing it
        curl_setopt($c,CURLOPT_FRESH_CONNECT,1);//don't use a cached version of the url
        if(!curl_exec($c)){
            return false;
        }else{
            return true;
        }
    }

    /**
     * @param $hook
     * @return array
     */
    public function removeURL($hook)
    {
        $returnArray = array();
        $errorCount = 0;
        $processedCount = 0;
        try{
            if($this->checkIfHookExists($hook)){
                $sql = "DELETE FROM links WHERE shortened_url=?";
                $stmt = self::$mysqli->prepare($sql);
                if(!$stmt) throw new Exception("Error occurred while preparing statement. ".$stmt->error);
                $stmt->bind_param("s", $hook);
                if($stmt->execute()){
                    if($stmt->affected_rows > 0){
                        MemcachedController::getMemCache($this->getMemcacheKeyName($hook));
                        $returnArray = array("url"=>$this->getHookBasedUrl($hook));
                        $processedCount++;
                    }else{
                        throw new Exception("No links were removed.");
                    }
                }
            }else{
                throw new HttpNotFoundException($this->request);
            }

        }catch(Exception $e){
            if(strpos($e->getMessage(),"Invalid parameter") !== false)
                $returnArray[] = array("statusCode"=>400,"error"=>array("type"=>"INVALID_PARAMETER","message"=>$e->getMessage()));
            else if(strpos($e->getMessage(),"statement") !== false)
                $returnArray[] = array("statusCode"=>500,"error"=>array("type"=>"SERVER_ERROR","message"=>$e->getMessage()));
            else
                $returnArray[] = array("statusCode"=>500,"error"=>array("type"=>"SERVER_ERROR","message"=>$e->getMessage()));
            $errorCount++;
        }

        return array("data"=>$returnArray,"processedCount"=>$processedCount,"errorCount"=>$errorCount);
    }

    /**
     * @param $hook
     * @return string
     */
    private function getHookBasedUrl($hook)
    {
        $base = empty($_SERVER['HTTPS'])?"http://":"https://";
        $base = $base.$_SERVER["SERVER_NAME"]."/";
        return $base.$hook;
    }

    /**
     * @param $userAgent
     * @return string
     */
    private function getBrowserName($userAgent)
    {
        if (strpos($userAgent, 'Opera') || strpos($userAgent, 'OPR/')) return 'Opera';
        elseif (strpos($userAgent, 'Edge')) return 'Edge';
        elseif (strpos($userAgent, 'Chrome')) return 'Chrome';
        elseif (strpos($userAgent, 'Safari')) return 'Safari';
        elseif (strpos($userAgent, 'Firefox')) return 'Firefox';
        elseif (strpos($userAgent, 'MSIE') || strpos($userAgent, 'Trident/7')) return 'Internet Explorer';

        return 'Other';
    }

    /**
     * @param null $user_agent
     * @return string
     */
    private function getOS($user_agent = null)
    {
        if(!isset($user_agent) && isset($_SERVER['HTTP_USER_AGENT'])) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
        }

        // https://stackoverflow.com/questions/18070154/get-operating-system-info-with-php
        $os_array = [
            'windows nt 10'                              =>  'Windows 10',
            'windows nt 6.3'                             =>  'Windows 8.1',
            'windows nt 6.2'                             =>  'Windows 8',
            'windows nt 6.1|windows nt 7.0'              =>  'Windows 7',
            'windows nt 6.0'                             =>  'Windows Vista',
            'windows nt 5.2'                             =>  'Windows Server 2003/XP x64',
            'windows nt 5.1'                             =>  'Windows XP',
            'windows xp'                                 =>  'Windows XP',
            'windows nt 5.0|windows nt5.1|windows 2000'  =>  'Windows 2000',
            'windows me'                                 =>  'Windows ME',
            'windows nt 4.0|winnt4.0'                    =>  'Windows NT',
            'windows ce'                                 =>  'Windows CE',
            'windows 98|win98'                           =>  'Windows 98',
            'windows 95|win95'                           =>  'Windows 95',
            'win16'                                      =>  'Windows 3.11',
            'mac os x 10.1[^0-9]'                        =>  'Mac OS X Puma',
            'macintosh|mac os x'                         =>  'Mac OS X',
            'mac_powerpc'                                =>  'Mac OS 9',
            'linux'                                      =>  'Linux',
            'ubuntu'                                     =>  'Linux - Ubuntu',
            'iphone'                                     =>  'iPhone',
            'ipod'                                       =>  'iPod',
            'ipad'                                       =>  'iPad',
            'android'                                    =>  'Android',
            'blackberry'                                 =>  'BlackBerry',
            'webos'                                      =>  'Mobile',

            '(media center pc).([0-9]{1,2}\.[0-9]{1,2})'=>'Windows Media Center',
            '(win)([0-9]{1,2}\.[0-9x]{1,2})'=>'Windows',
            '(win)([0-9]{2})'=>'Windows',
            '(windows)([0-9x]{2})'=>'Windows',

            // Doesn't seem like these are necessary...not totally sure though..
            //'(winnt)([0-9]{1,2}\.[0-9]{1,2}){0,1}'=>'Windows NT',
            //'(windows nt)(([0-9]{1,2}\.[0-9]{1,2}){0,1})'=>'Windows NT', // fix by bg

            'Win 9x 4.90'=>'Windows ME',
            '(windows)([0-9]{1,2}\.[0-9]{1,2})'=>'Windows',
            'win32'=>'Windows',
            '(java)([0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,2})'=>'Java',
            '(Solaris)([0-9]{1,2}\.[0-9x]{1,2}){0,1}'=>'Solaris',
            'dos x86'=>'DOS',
            'Mac OS X'=>'Mac OS X',
            'Mac_PowerPC'=>'Macintosh PowerPC',
            '(mac|Macintosh)'=>'Mac OS',
            '(sunos)([0-9]{1,2}\.[0-9]{1,2}){0,1}'=>'SunOS',
            '(beos)([0-9]{1,2}\.[0-9]{1,2}){0,1}'=>'BeOS',
            '(risc os)([0-9]{1,2}\.[0-9]{1,2})'=>'RISC OS',
            'unix'=>'Unix',
            'os/2'=>'OS/2',
            'freebsd'=>'FreeBSD',
            'openbsd'=>'OpenBSD',
            'netbsd'=>'NetBSD',
            'irix'=>'IRIX',
            'plan9'=>'Plan9',
            'osf'=>'OSF',
            'aix'=>'AIX',
            'GNU Hurd'=>'GNU Hurd',
            '(fedora)'=>'Linux - Fedora',
            '(kubuntu)'=>'Linux - Kubuntu',
            '(ubuntu)'=>'Linux - Ubuntu',
            '(debian)'=>'Linux - Debian',
            '(CentOS)'=>'Linux - CentOS',
            '(Mandriva).([0-9]{1,3}(\.[0-9]{1,3})?(\.[0-9]{1,3})?)'=>'Linux - Mandriva',
            '(SUSE).([0-9]{1,3}(\.[0-9]{1,3})?(\.[0-9]{1,3})?)'=>'Linux - SUSE',
            '(Dropline)'=>'Linux - Slackware (Dropline GNOME)',
            '(ASPLinux)'=>'Linux - ASPLinux',
            '(Red Hat)'=>'Linux - Red Hat',
            // Loads of Linux machines will be detected as unix.
            // Actually, all of the linux machines I've checked have the 'X11' in the User Agent.
            //'X11'=>'Unix',
            '(linux)'=>'Linux',
            '(amigaos)([0-9]{1,2}\.[0-9]{1,2})'=>'AmigaOS',
            'amiga-aweb'=>'AmigaOS',
            'amiga'=>'Amiga',
            'AvantGo'=>'PalmOS',
            //'(Linux)([0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}(rel\.[0-9]{1,2}){0,1}-([0-9]{1,2}) i([0-9]{1})86){1}'=>'Linux',
            //'(Linux)([0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}(rel\.[0-9]{1,2}){0,1} i([0-9]{1}86)){1}'=>'Linux',
            //'(Linux)([0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}(rel\.[0-9]{1,2}){0,1})'=>'Linux',
            '[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}'=>'Linux',
            '(webtv)/([0-9]{1,2}\.[0-9]{1,2})'=>'WebTV',
            'Dreamcast'=>'Dreamcast OS',
            'GetRight'=>'Windows',
            'go!zilla'=>'Windows',
            'gozilla'=>'Windows',
            'gulliver'=>'Windows',
            'ia archiver'=>'Windows',
            'NetPositive'=>'Windows',
            'mass downloader'=>'Windows',
            'microsoft'=>'Windows',
            'offline explorer'=>'Windows',
            'teleport'=>'Windows',
            'web downloader'=>'Windows',
            'webcapture'=>'Windows',
            'webcollage'=>'Windows',
            'webcopier'=>'Windows',
            'webstripper'=>'Windows',
            'webzip'=>'Windows',
            'wget'=>'Windows',
            'Java'=>'Unknown',
            'flashget'=>'Windows',

            // delete next line if the script show not the right OS
            //'(PHP)/([0-9]{1,2}.[0-9]{1,2})'=>'PHP',
            'MS FrontPage'=>'Windows',
            '(msproxy)/([0-9]{1,2}.[0-9]{1,2})'=>'Windows',
            '(msie)([0-9]{1,2}.[0-9]{1,2})'=>'Windows',
            'libwww-perl'=>'Unix',
            'UP.Browser'=>'Windows CE',
            'NetAnts'=>'Windows',
        ];

        // https://github.com/ahmad-sa3d/php-useragent/blob/master/core/user_agent.php
        $arch_regex = '/\b(x86_64|x86-64|Win64|WOW64|x64|ia64|amd64|ppc64|sparc64|IRIX64)\b/ix';
        $arch = preg_match($arch_regex, $user_agent) ? '64' : '32';

        foreach ($os_array as $regex => $value) {
            if (preg_match('{\b('.$regex.')\b}i', $user_agent)) {
                return $value.' x'.$arch;
            }
        }

        return 'Unknown';
    }
}