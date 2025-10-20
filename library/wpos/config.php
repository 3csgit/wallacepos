<?php
/*
 * WallacePOS API configuration file
 */
$wposConfig = [];

// Paths
$_SERVER['APP_ROOT'] = "/";
if (!isset($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = "/app"; // this is what dokku uses as docroot, for some reason it's not set
}
// load timezone config if available
// TODO: cache this somehow
$timezone = "Australia/Sydney";
if (file_exists($_SERVER['DOCUMENT_ROOT'].$_SERVER['APP_ROOT']."docs/.config.json")){
    $GLOBALS['config'] = json_decode(file_get_contents($_SERVER['DOCUMENT_ROOT'].$_SERVER['APP_ROOT']."docs/.config.json"));
    if (isset($GLOBALS['config']->timezone))
        $timezone = $GLOBALS['config']->timezone;
}
// Date & Time
ini_set('date.timezone', $timezone);

// Error handling (suppress direct display to keep JSON responses valid)
ini_set('display_errors', 'Off');
error_reporting(E_ERROR | E_WARNING | E_PARSE);

// Ensure a default JSON result container exists for handlers
if (!isset($GLOBALS['result']) || !is_array($GLOBALS['result'])) {
    $GLOBALS['result'] = [
        'errorCode' => 'OK',
        'error'     => 'OK',
        'warning'   => '',
        'data'      => ''
    ];
}

// Capture fatal errors and return JSON so XHRs don’t see a blank 500
register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $payload = [
            'errorCode' => 'phpfatal',
            'error'     => sprintf('FATAL: %s in %s on line %d', $err['message'], $err['file'], $err['line']),
            'data'      => ''
        ];
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode($payload);
    }
});

/**
 * Php error handler, sets & returns json result object
 * @param $errorno
 * @param $errstr
 * @param $errfile
 * @param $errline
 */
function errorHandler($errorno, $errstr, $errfile, $errline){
    global $result;
    if (!isset($result) || !is_array($result)) {
        $result = ['errorCode'=>'OK','error'=>'OK','warning'=>'','data'=>''];
    }

    $result['errorCode'] = "phperr";

    if ($result['error'] == "OK") $result['error'] = "";

    $result['error'] =  "ERROR: " . ": " . $errstr . " " . $errfile . " on line " . $errline . "\n";

    die(json_encode($result));
}

/**
 * Php warning handler
 * @param $errorno
 * @param $errstr
 * @param $errfile
 * @param $errline
 */
function warningHandler($errorno, $errstr, $errfile, $errline){
    global $result;
    if (!isset($result) || !is_array($result)) {
        $result = ['errorCode'=>'OK','error'=>'OK','warning'=>'','data'=>''];
    }
    if (!isset($result['warning'])) $result['warning'] = '';
    $result['warning'] .= "WARNING: " . $errstr . " " . $errfile . " on line " . $errline . "\n";
}

/**
 * Php exception handler, sets & returns json result object
 * @param Exception $ex
 */
function exceptionHandler(Throwable $ex){
    global $result;
    if (!isset($result) || !is_array($result)) {
        $result = ['errorCode'=>'OK','error'=>'OK','warning'=>'','data'=>''];
    }

    $result['errorCode'] = "phpexc";

    if ($result['error'] == "OK") $result['error'] = "";

    $result['error'] .= "EXCEPTION: " .$ex->getMessage() . "\nFile: " . $ex->getFile() . " line " . $ex->getLine();

    die(json_encode($result));
}




