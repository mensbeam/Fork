<?php
declare(strict_types=1);
namespace MensBeam\Fork\Test;

ini_set('memory_limit', '2G');
ini_set('zend.assertions', '1');
ini_set('assert.exception', 'true');
error_reporting(\E_ALL);
define('CWD', dirname(__DIR__));
require_once CWD . '/vendor/autoload.php';

if (function_exists('xdebug_set_filter') && defined('XDEBUG_FILTER_CODE_COVERAGE')) {
    xdebug_set_filter(\XDEBUG_FILTER_CODE_COVERAGE, (defined('XDEBUG_PATH_INCLUDE')) ? \XDEBUG_PATH_INCLUDE : \XDEBUG_PATH_WHITELIST, [ CWD . '/lib/' ]);
}