<?php
/**
 * WposSocketControl is part of Wallace Point of Sale system (WPOS) API
 *
 * WposSocketControl is used to control the node.js websocket server
 *
 * WallacePOS is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3.0 of the License, or (at your option) any later version.
 *
 * WallacePOS is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details:
 * <https://www.gnu.org/licenses/lgpl.html>
 *
 * @package    wpos
 * @copyright  Copyright (c) 2014 WallaceIT. (https://wallaceit.com.au)
 * @link       https://wallacepos.com
 * @author     Michael B Wallace <micwallace@gmx.com>
 * @since      File available since 30/04/14 9:28 PM
 */
class WposSocketControl {

    private $isWindows = false;

    /**
     * WposSocketControl constructor.
     */
    function __construct(){
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Start the socket server
     * @param $result array Current result array
     * @return mixed API result array
     */
    public function startSocketServer($result=['error'=>'OK']){
		if ($this->isWindows) {
			pclose(popen('START "WPOS" node '.$_SERVER['DOCUMENT_ROOT'].$_SERVER['APP_ROOT'].'api/server.js','r'));
		} else {
            $args = $_SERVER['DOCUMENT_ROOT'].$_SERVER['APP_ROOT']."api/server.js > /dev/null &";
			exec("nodejs ".$args, $output, $res);
            // try the alternative command if nodejs fails
            if ($res>0)
                exec("node ".$args, $output, $res);
        }
        sleep(1); // Wait a bit to see if nodejs exits
		if ($this->getServerStat()===false){
            $result['error'] = "Failed to start the feed server! ".(isset($output)?json_encode($output):'');
        }
        return $result;
    }

    /**
     * Stop the socket server
     * @param $result array Current result array
     * @return mixed API result array
     */
    public function stopSocketServer($result=['error'=>'OK']){
		if ($this->isWindows) {
			exec('TASKKILL /F /FI "WindowTitle eq WPOS"', $output);
		} else {
			exec('kill `ps aux | grep "[n]odejs '.$_SERVER['DOCUMENT_ROOT'].'" | awk \'{print $2}\'`', $output);
		}
        if ($this->getServerStat()===true){
            $result['error'] = "Failed to stop the feed server! ".(isset($output)?json_encode($output):'');
        }
        return $result;
    }

    /**
     * Checks if the server is currently running
     * @param $result array Current result array
     * @return mixed API result array
     */
    public function isServerRunning($result=['error'=>'OK']){
        $result['data'] = ["status"=>$this->getServerStat()];
        return $result;
    }

    /**
     * Restart the server
     * @param $result array Current result array
     * @return mixed API result array
     */
    public function restartSocketServer($result=['error'=>'OK']){
        $result['data'] = true; // server currently running
        $result = $this->stopSocketServer($result);
        if ($result['error']=="OK"){ // successfully stopped server
            $result = $this->startSocketServer($result);
            if ($result['error']!=="OK"){
                $result['data'] = false;
            }
        }
        return $result;
    }

    /**
     * Checks if the server is running
     * @return bool
     */
    private function getServerStat(){
        // Prefer HTTP health check (Docker/dev friendly)
        if (function_exists('curl_init')) {
            foreach ([
                'http://node:8080/healthz',                // Docker service name
                'http://127.0.0.1:8080/healthz',          // Local fallback
            ] as $healthUrl) {
                $h = curl_init($healthUrl);
                curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($h, CURLOPT_CONNECTTIMEOUT, 1);
                curl_setopt($h, CURLOPT_TIMEOUT, 2);
                curl_exec($h);
                $code = curl_getinfo($h, CURLINFO_HTTP_CODE);
                curl_close($h);
                if ($code === 200) {
                    return true;
                }
            }
        }

        // Fallback to process inspection (legacy behavior)
        if ($this->isWindows) {
            $output = [];
            @exec('TASKLIST /NH /V /FI "WindowTitle eq WPOS"', $output );
            if (empty($output) || (isset($output[0]) && strpos($output[0], 'INFO')!==false)){
                return false;
            }
            return true;
        } else {
            $output = [];
            @exec('ps aux | grep -E "[n]ode(js)? '.$_SERVER['DOCUMENT_ROOT'].'"', $output);
            if (!empty($output) && isset($output[0]) && strpos($output[0], $_SERVER['DOCUMENT_ROOT'])!==false){
                return true;
            }
            return false;
        }
    }

}




