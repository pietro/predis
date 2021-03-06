<?php
require_once '../lib/Predis.php';

if (I_AM_AWARE_OF_THE_DESTRUCTIVE_POWER_OF_THIS_TEST_SUITE !== true) {
    exit('Please set the I_AM_AWARE_OF_THE_DESTRUCTIVE_POWER_OF_THIS_TEST_SUITE constant to TRUE if you want to proceed.');
}

if (!function_exists('array_union')) {
    function array_union(Array $a, Array $b) { 
        return array_merge($a, array_diff($b, $a));
    }
}

class RC {
    const SERVER_HOST      = '127.0.0.1';
    const SERVER_PORT      = 6379;
    const DEFAULT_DATABASE = 15;

    const WIPE_OUT         = 1;
    const EXCEPTION_WRONG_TYPE     = 'Operation against a key holding the wrong kind of value';
    const EXCEPTION_NO_SUCH_KEY    = 'no such key';
    const EXCEPTION_OUT_OF_RANGE   = 'index out of range';
    const EXCEPTION_INVALID_DB_IDX = 'invalid DB index';
    const EXCEPTION_EXEC_NO_MULTI  = 'EXEC without MULTI';

    private static $_connection;

    private static function createConnection() {
        $serverProfile = Predis\RedisServerProfile::get('dev');
        $connection = new Predis\Client(array('host' => RC::SERVER_HOST, 'port' => RC::SERVER_PORT), $serverProfile);
        $connection->connect();
        $connection->selectDatabase(RC::DEFAULT_DATABASE);
        return $connection;
    }

    public static function getConnection() {
        if (self::$_connection === null || !self::$_connection->isConnected()) {
            self::$_connection = self::createConnection();
        }
        return self::$_connection;
    }

    public static function resetConnection() {
        if (self::$_connection !== null && self::$_connection->isConnected()) {
            self::$_connection->disconnect();
            self::$_connection = self::createConnection();
        }
    }

    public static function helperForBlockingPops($op) {
        // TODO: I admit that this helper is kinda lame and it does not run 
        //       in a separate process to properly test BLPOP/BRPOP
        $redisUri = sprintf('redis://%s:%d/?database=%d', RC::SERVER_HOST, RC::SERVER_PORT, RC::DEFAULT_DATABASE);
        $handle = popen('php', 'w');
        fwrite($handle, "<?php
        require '../lib/Predis.php';
        \$redis = Predis\Client::create('$redisUri');
        \$redis->rpush('{$op}1', 'a');
        \$redis->rpush('{$op}2', 'b');
        \$redis->rpush('{$op}3', 'c');
        \$redis->rpush('{$op}1', 'd');
        ?>");
        pclose($handle);
    }

    public static function getArrayOfNumbers() {
        return array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9);
    }

    public static function getKeyValueArray() {
        return array(
            'foo'      => 'bar', 
            'hoge'     => 'piyo', 
            'foofoo'   => 'barbar', 
        );
    }

    public static function getNamespacedKeyValueArray() {
        return array(
            'metavar:foo'      => 'bar', 
            'metavar:hoge'     => 'piyo', 
            'metavar:foofoo'   => 'barbar', 
        );
    }

    public static function getZSetArray() {
        return array(
            'a' => -10, 'b' => 0, 'c' => 10, 'd' => 20, 'e' => 20, 'f' => 30
        );
    }

    public static function sameValuesInArrays($arrayA, $arrayB) {
        if (count($arrayA) != count($arrayB)) {
            return false;
        }
        return count(array_diff($arrayA, $arrayB)) == 0;
    }

    public static function testForServerException($testcaseInstance, $expectedMessage, $wrapFunction) {
        $thrownException = null;
        try {
            $wrapFunction($testcaseInstance);
        }
        catch (Predis\ServerException $exception) {
            $thrownException = $exception;
        }
        $testcaseInstance->assertType('Predis\ServerException', $thrownException);
        $testcaseInstance->assertEquals($expectedMessage, $thrownException->getMessage());
    }

    public static function pushTailAndReturn(Predis\Client $client, $keyName, Array $values, $wipeOut = 0) {
        if ($wipeOut == true) {
            $client->delete($keyName);
        }
        foreach ($values as $value) {
            $client->pushTail($keyName, $value);
        }
        return $values;
    }

    public static function setAddAndReturn(Predis\Client $client, $keyName, Array $values, $wipeOut = 0) {
        if ($wipeOut == true) {
            $client->delete($keyName);
        }
        foreach ($values as $value) {
            $client->setAdd($keyName, $value);
        }
        return $values;
    }

    public static function zsetAddAndReturn(Predis\Client $client, $keyName, Array $values, $wipeOut = 0) {
        // $values: array(SCORE => VALUE, ...);
        if ($wipeOut == true) {
            $client->delete($keyName);
        }
        foreach ($values as $value => $score) {
            $client->zsetAdd($keyName, $score, $value);
        }
        return $values;
    }
}
?>
