<?php
define('I_AM_AWARE_OF_THE_DESTRUCTIVE_POWER_OF_THIS_TEST_SUITE', false);

require_once 'PHPUnit/Framework.php';
require_once 'PredisShared.php';

class RedisCommandTestSuite extends PHPUnit_Framework_TestCase {
    public $redis;

    // TODO: instead of an boolean assertion against the return value 
    //       of RC::sameValuesInArrays, we should extend PHPUnit with 
    //       a new assertion, e.g. $this->assertSameValues();
    // TODO: an option to skip certain tests such as testFlushDatabases
    //       should be provided.
    // TODO: missing test with float values for a few commands

    protected function setUp() { 
        $this->redis = RC::getConnection();
        $this->redis->flushDatabase();
    }

    protected function tearDown() { 
    }

    protected function onNotSuccessfulTest($exception) {
        // drops and reconnect to a redis server on uncaught exceptions
        RC::resetConnection();
        parent::onNotSuccessfulTest($exception);
    }


    /* miscellaneous commands */

    function testPing() {
        $this->assertTrue($this->redis->ping());
    }

    function testEcho() {
        $string = 'This is an echo test!';
        $this->assertEquals($string, $this->redis->echo($string));
    }

    function testQuit() {
        $this->redis->quit();
        $this->assertFalse($this->redis->isConnected());
    }

    function testMultiExec() {
        // NOTE: due to a limitation in the current implementation of Predis\Client, 
        //       the replies returned by Predis\Command\Exec are not parsed by their 
        //       respective Predis\Command::parseResponse methods. If you need that 
        //       kind of behaviour, you should use an instance of Predis\MultiExecBlock.
        $this->assertTrue($this->redis->multi());
        $this->assertType('Predis\ResponseQueued', $this->redis->ping());
        $this->assertType('Predis\ResponseQueued', $this->redis->echo('hello'));
        $this->assertType('Predis\ResponseQueued', $this->redis->echo('redis'));
        $this->assertEquals(array('PONG', 'hello', 'redis'), $this->redis->exec());

        $this->assertTrue($this->redis->multi());
        $this->assertEquals(array(), $this->redis->exec());

        // should throw an exception when trying to EXEC without having previously issued MULTI
        RC::testForServerException($this, RC::EXCEPTION_EXEC_NO_MULTI, function($test) {
            $test->redis->exec();
        });
    }

    function testDiscard() {
        $this->assertTrue($this->redis->multi());
        $this->assertType('Predis\ResponseQueued', $this->redis->set('foo', 'bar'));
        $this->assertType('Predis\ResponseQueued', $this->redis->set('hoge', 'piyo'));
        $this->assertEquals(true, $this->redis->discard());

        // should throw an exception when trying to EXEC after a DISCARD
        RC::testForServerException($this, RC::EXCEPTION_EXEC_NO_MULTI, function($test) {
            $test->redis->exec();
        });

        $this->assertFalse($this->redis->exists('foo'));
        $this->assertFalse($this->redis->exists('hoge'));
    }

    /* commands operating on string values */

    function testSet() {
        $this->assertTrue($this->redis->set('foo', 'bar'));
        $this->assertEquals('bar', $this->redis->get('foo'));
    }

    function testGet() {
        $this->redis->set('foo', 'bar');

        $this->assertEquals('bar', $this->redis->get('foo'));
        $this->assertNull($this->redis->get('fooDoesNotExist'));

        // should throw an exception when trying to do a GET on non-string types
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->pushTail('metavars', 'foo');
            $test->redis->get('metavars');
        });
    }

    function testExists() {
        $this->redis->set('foo', 'bar');

        $this->assertTrue($this->redis->exists('foo'));
        $this->assertFalse($this->redis->exists('key_does_not_exist'));
    }

    function testSetPreserve() {
        $multi = RC::getKeyValueArray();

        $this->assertTrue($this->redis->setPreserve('foo', 'bar'));
        $this->assertFalse($this->redis->setPreserve('foo', 'rab'));
        $this->assertEquals('bar', $this->redis->get('foo'));
    }

    function testMultipleSetAndGet() {
        $multi = RC::getKeyValueArray();

        // key=>value pairs via array instance
        $this->assertTrue($this->redis->setMultiple($multi));
        $multiRet = $this->redis->getMultiple(array_keys($multi));
        $this->assertEquals($multi, array_combine(array_keys($multi), array_values($multiRet)));

        // key=>value pairs via function arguments
        $this->assertTrue($this->redis->setMultiple('a', 1, 'b', 2, 'c', 3));
        $this->assertEquals(array(1, 2, 3), $this->redis->getMultiple('a', 'b', 'c'));
    }

    function testSetMultiplePreserve() {
        $multi    = RC::getKeyValueArray();
        $newpair  = array('hogehoge' => 'piyopiyo');
        $hijacked = array('foo' => 'baz', 'hoge' => 'fuga');

        // successful set
        $expectedResult = array_merge($multi, $newpair);
        $this->redis->setMultiple($multi);
        $this->assertTrue($this->redis->setMultiplePreserve($newpair));
        $this->assertEquals(
            array_values($expectedResult), 
            $this->redis->getMultiple(array_keys($expectedResult))
        );

        $this->redis->flushDatabase();

        // unsuccessful set
        $expectedResult = array_merge($multi, array('hogehoge' => null));
        $this->redis->setMultiple($multi);
        $this->assertFalse($this->redis->setMultiplePreserve(array_merge($newpair, $hijacked)));
        $this->assertEquals(
            array_values($expectedResult), 
            $this->redis->getMultiple(array_keys($expectedResult))
        );
    }

    function testGetSet() {
        $this->assertNull($this->redis->getSet('foo', 'bar'));
        $this->assertEquals('bar', $this->redis->getSet('foo', 'barbar'));
        $this->assertEquals('barbar', $this->redis->getSet('foo', 'baz'));
    }

    function testIncrementAndIncrementBy() {
        // test subsequent increment commands
        $this->assertEquals(1, $this->redis->increment('foo'));
        $this->assertEquals(2, $this->redis->increment('foo'));

        // test subsequent incrementBy commands
        $this->assertEquals(22, $this->redis->incrementBy('foo', 20));
        $this->assertEquals(10, $this->redis->incrementBy('foo', -12));
        $this->assertEquals(-100, $this->redis->incrementBy('foo', -110));
    }

    function testDecrementAndDecrementBy() {
        // test subsequent decrement commands
        $this->assertEquals(-1, $this->redis->decrement('foo'));
        $this->assertEquals(-2, $this->redis->decrement('foo'));

        // test subsequent decrementBy commands
        $this->assertEquals(-22, $this->redis->decrementBy('foo', 20));
        $this->assertEquals(-10, $this->redis->decrementBy('foo', -12));
        $this->assertEquals(100, $this->redis->decrementBy('foo', -110));
    }

    function testDelete() {
        $this->redis->set('foo', 'bar');
        $this->assertTrue($this->redis->delete('foo'));
        $this->assertFalse($this->redis->exists('foo'));
        $this->assertFalse($this->redis->delete('foo'));
    }

    function testType() {
        $this->assertEquals('none', $this->redis->type('fooDoesNotExist'));

        $this->redis->set('fooString', 'bar');
        $this->assertEquals('string', $this->redis->type('fooString'));

        $this->redis->pushTail('fooList', 'bar');
        $this->assertEquals('list', $this->redis->type('fooList'));

        $this->redis->setAdd('fooSet', 'bar');
        $this->assertEquals('set', $this->redis->type('fooSet'));

        $this->redis->zsetAdd('fooZSet', 'bar', 0);
        $this->assertEquals('zset', $this->redis->type('fooZSet'));
    }

    function testAppend() {
        $this->redis->set('foo', 'bar');
        $this->assertEquals(5, $this->redis->append('foo', '__'));
        $this->assertEquals(8, $this->redis->append('foo', 'bar'));
        $this->assertEquals('bar__bar', $this->redis->get('foo'));

        $this->assertEquals(4, $this->redis->append('hoge', 'piyo'));
        $this->assertEquals('piyo', $this->redis->get('hoge'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->pushTail('metavars', 'foo');
            $test->redis->append('metavars', 'bar');
        });
    }

    function testSubstr() {
        $this->redis->set('var', 'foobar');
        $this->assertEquals('foo', $this->redis->substr('var', 0, 2));
        $this->assertEquals('bar', $this->redis->substr('var', 3, 5));
        $this->assertEquals('bar', $this->redis->substr('var', -3, -1));

        $this->assertNull($this->redis->substr('var', 5, 0));

        $this->redis->set('numeric', 123456789);
        $this->assertEquals(12345, $this->redis->substr('numeric', 0, 4));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->pushTail('metavars', 'foo');
            $test->redis->substr('metavars', 0, 3);
        });
    }

    /* commands operating on the key space */

    function testKeys() {
        $keyValsNs     = RC::getNamespacedKeyValueArray();
        $keyValsOthers = array('aaa' => 1, 'aba' => 2, 'aca' => 3);
        $allKeyVals    = array_merge($keyValsNs, $keyValsOthers);

        $this->redis->setMultiple($allKeyVals);

        $this->assertEquals(array(), $this->redis->keys('nokeys:*'));

        $keysFromRedis = $this->redis->keys('metavar:*');
        $this->assertEquals(array(), array_diff(array_keys($keyValsNs), $keysFromRedis));

        $keysFromRedis = $this->redis->keys('*');
        $this->assertEquals(array(), array_diff(array_keys($allKeyVals), $keysFromRedis));

        $keysFromRedis = $this->redis->keys('a?a');
        $this->assertEquals(array(), array_diff(array_keys($keyValsOthers), $keysFromRedis));
    }

    function testRandomKey() {
        $keyvals = RC::getKeyValueArray();

        $this->assertNull($this->redis->randomKey());

        $this->redis->setMultiple($keyvals);
        $this->assertTrue(in_array($this->redis->randomKey(), array_keys($keyvals)));
    }

    function testRename() {
        $this->redis->setMultiple(array('foo' => 'bar', 'foofoo' => 'barbar'));

        // rename existing keys
        $this->assertTrue($this->redis->rename('foo', 'foofoo'));
        $this->assertFalse($this->redis->exists('foo'));
        $this->assertEquals('bar', $this->redis->get('foofoo'));

        // should throw an excepion then trying to rename non-existing keys
        RC::testForServerException($this, RC::EXCEPTION_NO_SUCH_KEY, function($test) {
            $test->redis->rename('hoge', 'hogehoge');
        });
    }

    function testRenamePreserve() {
        $this->redis->setMultiple(array('foo' => 'bar', 'hoge' => 'piyo', 'hogehoge' => 'piyopiyo'));

        $this->assertTrue($this->redis->renamePreserve('foo', 'foofoo'));
        $this->assertFalse($this->redis->exists('foo'));
        $this->assertEquals('bar', $this->redis->get('foofoo'));

        $this->assertFalse($this->redis->renamePreserve('hoge', 'hogehoge'));
        $this->assertTrue($this->redis->exists('hoge'));

        // should throw an excepion then trying to rename non-existing keys
        RC::testForServerException($this, RC::EXCEPTION_NO_SUCH_KEY, function($test) {
            $test->redis->renamePreserve('fuga', 'baz');
        });
    }

    function testExpirationAndTTL() {
        $this->redis->set('foo', 'bar');

        // check for key expiration
        $this->assertTrue($this->redis->expire('foo', 1));
        $this->assertEquals(1, $this->redis->ttl('foo'));
        $this->assertTrue($this->redis->exists('foo'));
        sleep(2);
        $this->assertFalse($this->redis->exists('foo'));
        $this->assertEquals(-1, $this->redis->ttl('foo'));

        // check for consistent TTL values
        $this->redis->set('foo', 'bar');
        $this->assertTrue($this->redis->expire('foo', 100));
        sleep(3);
        $this->assertEquals(97, $this->redis->ttl('foo'));

        // delete key on negative TTL
        $this->redis->set('foo', 'bar');
        $this->assertTrue($this->redis->expire('foo', -100));
        $this->assertFalse($this->redis->exists('foo'));
        $this->assertEquals(-1, $this->redis->ttl('foo'));
    }

    function testDatabaseSize() {
        // TODO: is this really OK?
        $this->assertEquals(0, $this->redis->databaseSize());
        $this->redis->setMultiple(RC::getKeyValueArray());
        $this->assertGreaterThan(0, $this->redis->databaseSize());
    }


    /* commands operating on lists */

    function testPushTail() {
        // NOTE: List push operations return the list length since Redis commit 520b5a3
        $this->assertEquals(1, $this->redis->pushTail('metavars', 'foo'));
        $this->assertTrue($this->redis->exists('metavars'));
        $this->assertEquals(2, $this->redis->pushTail('metavars', 'hoge'));

        // should throw an exception when trying to do a RPUSH on non-list types
        // should throw an exception when trying to do a LPUSH on non-list types
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->pushTail('foo', 'bar');
        });
    }

    function testPushHead() {
        // NOTE: List push operations return the list length since Redis commit 520b5a3
        $this->assertEquals(1, $this->redis->pushHead('metavars', 'foo'));
        $this->assertTrue($this->redis->exists('metavars'));
        $this->assertEquals(2, $this->redis->pushHead('metavars', 'hoge'));

        // should throw an exception when trying to do a LPUSH on non-list types
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->pushHead('foo', 'bar');
        });
    }

    function testListLength() {
        $this->assertEquals(1, $this->redis->pushTail('metavars', 'foo'));
        $this->assertEquals(2, $this->redis->pushTail('metavars', 'hoge'));
        $this->assertEquals(2, $this->redis->listLength('metavars'));

        $this->assertEquals(0, $this->redis->listLength('doesnotexist'));

        // should throw an exception when trying to do a LLEN on non-list types
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->listLength('foo');
        });
    }

    function testListRange() {
        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', RC::getArrayOfNumbers());

        $this->assertEquals(
            array_slice($numbers, 0, 4),
            $this->redis->listRange('numbers', 0, 3)
        );
        $this->assertEquals(
            array_slice($numbers, 4, 5),
            $this->redis->listRange('numbers', 4, 8)
        );
        $this->assertEquals(
            array_slice($numbers, 0, 1),
            $this->redis->listRange('numbers', 0, 0)
        );
        $this->assertEquals(
            array(),
            $this->redis->listRange('numbers', 1, 0)
        );
        $this->assertEquals(
            $numbers,
            $this->redis->listRange('numbers', 0, -1)
        );
        $this->assertEquals(
            array(5),
            $this->redis->listRange('numbers', 5, -5)
        );
        $this->assertEquals(
            array(),
            $this->redis->listRange('numbers', 7, -5)
        );
        $this->assertEquals(
            array_slice($numbers, -5, -1),
            $this->redis->listRange('numbers', -5, -2)
        );
        $this->assertEquals(
            $numbers,
            $this->redis->listRange('numbers', -100, 100)
        );

        $this->assertNull($this->redis->listRange('keyDoesNotExist', 0, 1));

        // should throw an exception when trying to do a LRANGE on non-list types
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->listRange('foo', 0, -1);
        });
    }

    function testListTrim() {
        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', RC::getArrayOfNumbers());
        $this->assertTrue($this->redis->listTrim('numbers', 0, 2));
        $this->assertEquals(
            array_slice($numbers, 0, 3), 
            $this->redis->listRange('numbers', 0, -1)
        );

        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', RC::getArrayOfNumbers(), RC::WIPE_OUT);
        $this->assertTrue($this->redis->listTrim('numbers', 5, 9));
        $this->assertEquals(
            array_slice($numbers, 5, 5), 
            $this->redis->listRange('numbers', 0, -1)
        );

        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', RC::getArrayOfNumbers(), RC::WIPE_OUT);
        $this->assertTrue($this->redis->listTrim('numbers', 0, -6));
        $this->assertEquals(
            array_slice($numbers, 0, -5), 
            $this->redis->listRange('numbers', 0, -1)
        );

        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', RC::getArrayOfNumbers(), RC::WIPE_OUT);
        $this->assertTrue($this->redis->listTrim('numbers', -5, -3));
        $this->assertEquals(
            array_slice($numbers, 5, 3), 
            $this->redis->listRange('numbers', 0, -1)
        );

        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', RC::getArrayOfNumbers(), RC::WIPE_OUT);
        $this->assertTrue($this->redis->listTrim('numbers', -100, 100));
        $this->assertEquals(
            $numbers, 
            $this->redis->listRange('numbers', 0, -1)
        );

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->listTrim('foo', 0, 1);
        });
    }

    function testListIndex() {
        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', RC::getArrayOfNumbers());

        $this->assertEquals(0, $this->redis->listIndex('numbers', 0));
        $this->assertEquals(5, $this->redis->listIndex('numbers', 5));
        $this->assertEquals(9, $this->redis->listIndex('numbers', 9));
        $this->assertNull($this->redis->listIndex('numbers', 100));

        $this->assertEquals(0, $this->redis->listIndex('numbers', -0));
        $this->assertEquals(9, $this->redis->listIndex('numbers', -1));
        $this->assertEquals(7, $this->redis->listIndex('numbers', -3));
        $this->assertNull($this->redis->listIndex('numbers', -100));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->listIndex('foo', 0);
        });
    }

    function testListSet() {
        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', RC::getArrayOfNumbers());

        $this->assertTrue($this->redis->listSet('numbers', 5, -5));
        $this->assertEquals(-5, $this->redis->listIndex('numbers', 5));

        RC::testForServerException($this, RC::EXCEPTION_OUT_OF_RANGE, function($test) {
            $test->redis->listSet('numbers', 99, 99);
        });

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->listSet('foo', 0, 0);
        });
    }

    function testListRemove() {
        $mixed = array(0, '_', 2, '_', 4, '_', 6, '_');

        RC::pushTailAndReturn($this->redis, 'mixed', $mixed);
        $this->assertEquals(2, $this->redis->listRemove('mixed', 2, '_'));
        $this->assertEquals(array(0, 2, 4, '_', 6, '_'), $this->redis->listRange('mixed', 0, -1));

        RC::pushTailAndReturn($this->redis, 'mixed', $mixed, RC::WIPE_OUT);
        $this->assertEquals(4, $this->redis->listRemove('mixed', 0, '_'));
        $this->assertEquals(array(0, 2, 4, 6), $this->redis->listRange('mixed', 0, -1));

        RC::pushTailAndReturn($this->redis, 'mixed', $mixed, RC::WIPE_OUT);
        $this->assertEquals(2, $this->redis->listRemove('mixed', -2, '_'));
        $this->assertEquals(array(0, '_', 2, '_', 4, 6), $this->redis->listRange('mixed', 0, -1));

        RC::pushTailAndReturn($this->redis, 'mixed', $mixed, RC::WIPE_OUT);
        $this->assertEquals(0, $this->redis->listRemove('mixed', 2, '|'));
        $this->assertEquals($mixed, $this->redis->listRange('mixed', 0, -1));

        $this->assertEquals(0, $this->redis->listRemove('listDoesNotExist', 2, '_'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->listRemove('foo', 0, 0);
        });
    }

    function testListPopFirst() {
        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', array(0, 1, 2, 3, 4));

        $this->assertEquals(0, $this->redis->popFirst('numbers'));
        $this->assertEquals(1, $this->redis->popFirst('numbers'));
        $this->assertEquals(2, $this->redis->popFirst('numbers'));

        $this->assertEquals(array(3, 4), $this->redis->listRange('numbers', 0, -1));

        $this->redis->popFirst('numbers');
        $this->redis->popFirst('numbers');
        $this->assertNull($this->redis->popFirst('numbers'));

        $this->assertNull($this->redis->popFirst('numbers'));

        $this->assertNull($this->redis->popFirst('listDoesNotExist'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->popFirst('foo');
        });
    }

    function testListPopLast() {
        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', array(0, 1, 2, 3, 4));

        $this->assertEquals(4, $this->redis->popLast('numbers'));
        $this->assertEquals(3, $this->redis->popLast('numbers'));
        $this->assertEquals(2, $this->redis->popLast('numbers'));

        $this->assertEquals(array(0, 1), $this->redis->listRange('numbers', 0, -1));

        $this->redis->popLast('numbers');
        $this->redis->popLast('numbers');
        $this->assertNull($this->redis->popLast('numbers'));

        $this->assertNull($this->redis->popLast('numbers'));

        $this->assertNull($this->redis->popLast('listDoesNotExist'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->popLast('foo');
        });
    }


    function testListPopLastPushHead() {
        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', array(0, 1, 2));
        $this->assertEquals(0, $this->redis->listLength('temporary'));
        $this->assertEquals(2, $this->redis->listPopLastPushHead('numbers', 'temporary'));
        $this->assertEquals(1, $this->redis->listPopLastPushHead('numbers', 'temporary'));
        $this->assertEquals(0, $this->redis->listPopLastPushHead('numbers', 'temporary'));
        $this->assertEquals(0, $this->redis->listLength('numbers'));
        $this->assertEquals(3, $this->redis->listLength('temporary'));
        $this->assertNull($this->redis->listRange('numbers', 0, -1));
        $this->assertEquals($numbers, $this->redis->listRange('temporary', 0, -1));

        $numbers = RC::pushTailAndReturn($this->redis, 'numbers', array(0, 1, 2));
        $this->redis->listPopLastPushHead('numbers', 'numbers');
        $this->redis->listPopLastPushHead('numbers', 'numbers');
        $this->redis->listPopLastPushHead('numbers', 'numbers');
        $this->assertEquals($numbers, $this->redis->listRange('numbers', 0, -1));

        $this->assertEquals(null, $this->redis->listPopLastPushHead('listDoesNotExist1', 'listDoesNotExist2'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->listPopLastPushHead('foo', 'hoge');
        });

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->listPopLastPushHead('temporary', 'foo');
        });
    }

    function testListBlockingPopFirst() {
        // TODO: this test does not cover all the aspects of BLPOP/BRPOP as it 
        //       does not run with a concurrent client pushing items on lists. 
        RC::helperForBlockingPops('blpop');

        // BLPOP on one key
        $start = time();
        $item = $this->redis->blpop('blpop3', 5);
        $this->assertEquals((float)(time() - $start), 0, '', 1);
        $this->assertEquals($item, array('blpop3', 'c'));

        // BLPOP on more than one key
        $poppedItems = array();
        while ($item = $this->redis->blpop('blpop1', 'blpop2', 1)) {
            $poppedItems[] = $item;
        }
        $this->assertEquals(
            array(array('blpop1', 'a'), array('blpop1', 'd'), array('blpop2', 'b')),
            $poppedItems
        );

        // check if BLPOP timeouts as expected on empty lists
        $start = time();
        $this->redis->blpop('blpop4', 2);
        $this->assertEquals((float)(time() - $start), 2, '', 1);
    }

    function testListBlockingPopLast() {
        // TODO: this test does not cover all the aspects of BLPOP/BRPOP as it 
        //       does not run with a concurrent client pushing items on lists. 
        RC::helperForBlockingPops('brpop');

        // BRPOP on one key
        $start = time();
        $item = $this->redis->brpop('brpop3', 5);
        $this->assertEquals((float)(time() - $start), 0, '', 1);
        $this->assertEquals($item, array('brpop3', 'c'));

        // BRPOP on more than one key
        $poppedItems = array();
        while ($item = $this->redis->brpop('brpop1', 'brpop2', 1)) {
            $poppedItems[] = $item;
        }
        $this->assertEquals(
            array(array('brpop1', 'd'), array('brpop1', 'a'), array('brpop2', 'b')),
            $poppedItems
        );

        // check if BRPOP timeouts as expected on empty lists
        $start = time();
        $this->redis->brpop('brpop4', 2);
        $this->assertEquals((float)(time() - $start), 2, '', 1);
    }


    /* commands operating on sets */

    function testSetAdd() {
        $this->assertTrue($this->redis->setAdd('set', 0));
        $this->assertTrue($this->redis->setAdd('set', 1));
        $this->assertFalse($this->redis->setAdd('set', 0));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->setAdd('foo', 0);
        });
    }

    function testSetRemove() {
        $set = RC::setAddAndReturn($this->redis, 'set', array(0, 1, 2, 3, 4));

        $this->assertTrue($this->redis->setRemove('set', 0));
        $this->assertTrue($this->redis->setRemove('set', 4));
        $this->assertFalse($this->redis->setRemove('set', 10));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->setRemove('foo', 0);
        });
    }

    function testSetPop() {
        $set = RC::setAddAndReturn($this->redis, 'set', array(0, 1, 2, 3, 4));

        $this->assertTrue(in_array($this->redis->setPop('set'), $set));

        $this->assertNull($this->redis->setPop('setDoesNotExist'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->setPop('foo');
        });
    }

    function testSetMove() {
        $setA = RC::setAddAndReturn($this->redis, 'setA', array(0, 1, 2, 3, 4, 5));
        $setB = RC::setAddAndReturn($this->redis, 'setB', array(5, 6, 7, 8, 9, 10));

        $this->assertTrue($this->redis->setMove('setA', 'setB', 0));
        $this->assertFalse($this->redis->setRemove('setA', 0));
        $this->assertTrue($this->redis->setRemove('setB', 0));

        $this->assertTrue($this->redis->setMove('setA', 'setB', 5));
        $this->assertFalse($this->redis->setMove('setA', 'setB', 100));

        // wrong type
        $this->redis->set('foo', 'bar');
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setMove('foo', 'setB', 5);
        });
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setMove('setA', 'foo', 5);
        });
    }

    function testSetCardinality() {
        RC::setAddAndReturn($this->redis, 'setA', array(0, 1, 2, 3, 4, 5));

        $this->assertEquals(6, $this->redis->setCardinality('setA'));

        // empty set
        $this->redis->setAdd('setB', 0);
        $this->redis->setPop('setB');
        $this->assertEquals(0, $this->redis->setCardinality('setB'));

        // non-existing set
        $this->assertEquals(0, $this->redis->setCardinality('setDoesNotExist'));

        // wrong type
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->setCardinality('foo');
        });
    }

    function testSetIsMember() {
        RC::setAddAndReturn($this->redis, 'set', array(0, 1, 2, 3, 4, 5));

        $this->assertTrue($this->redis->setIsMember('set', 3));
        $this->assertFalse($this->redis->setIsMember('set', 100));

        $this->assertFalse($this->redis->setIsMember('setDoesNotExist', 0));

        // wrong type
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->setIsMember('foo', 0);
        });
    }

    function testSetMembers() {
        $set = RC::setAddAndReturn($this->redis, 'set', array(0, 1, 2, 3, 4, 5, 6));

        $this->assertTrue(RC::sameValuesInArrays($set, $this->redis->setMembers('set')));

        $this->assertNull($this->redis->setMembers('setDoesNotExist'));

        // wrong type
        $this->redis->set('foo', 'bar');
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setMembers('foo');
        });
    }

    function testSetIntersection() {
        $setA = RC::setAddAndReturn($this->redis, 'setA', array(0, 1, 2, 3, 4, 5, 6));
        $setB = RC::setAddAndReturn($this->redis, 'setB', array(1, 3, 4, 6, 9, 10));

        $this->assertTrue(RC::sameValuesInArrays(
            $setA, 
            $this->redis->setIntersection('setA')
        ));

        $this->assertTrue(RC::sameValuesInArrays(
            array_intersect($setA, $setB), 
            $this->redis->setIntersection('setA', 'setB')
        ));

        // TODO: should nil really be considered an empty set?
        $this->assertNull($this->redis->setIntersection('setA', 'setDoesNotExist'));

        // wrong type
        $this->redis->set('foo', 'bar');
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setIntersection('foo');
        });
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setIntersection('setA', 'foo');
        });
    }

    function testSetIntersectionStore() {
        $setA = RC::setAddAndReturn($this->redis, 'setA', array(0, 1, 2, 3, 4, 5, 6));
        $setB = RC::setAddAndReturn($this->redis, 'setB', array(1, 3, 4, 6, 9, 10));

        $this->assertEquals(count($setA), $this->redis->setIntersectionStore('setC', 'setA'));
        $this->assertTrue(RC::sameValuesInArrays(
            $setA, 
            $this->redis->setMembers('setC')
        ));

        $this->redis->delete('setC');
        $this->assertEquals(4, $this->redis->setIntersectionStore('setC', 'setA', 'setB'));
        $this->assertTrue(RC::sameValuesInArrays(
            array(1, 3, 4, 6), 
            $this->redis->setMembers('setC')
        ));

        $this->redis->delete('setC');
        $this->assertNull($this->redis->setIntersection('setC', 'setDoesNotExist'));
        $this->assertFalse($this->redis->exists('setC'));

        // existing keys are replaced by SINTERSTORE
        $this->redis->set('foo', 'bar');
        $this->assertEquals(count($setA), $this->redis->setIntersectionStore('foo', 'setA'));

        // wrong type
        $this->redis->set('foo', 'bar');
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setIntersectionStore('setA', 'foo');
        });
    }

    function testSetUnion() {
        $setA = RC::setAddAndReturn($this->redis, 'setA', array(0, 1, 2, 3, 4, 5, 6));
        $setB = RC::setAddAndReturn($this->redis, 'setB', array(1, 3, 4, 6, 9, 10));

        $this->assertTrue(RC::sameValuesInArrays(
            $setA, 
            $this->redis->setUnion('setA')
        ));

        $this->assertTrue(RC::sameValuesInArrays(
            array_union($setA, $setB), 
            $this->redis->setUnion('setA', 'setB')
        ));

        $this->assertTrue(RC::sameValuesInArrays(
            $setA, 
            $this->redis->setUnion('setA', 'setDoesNotExist')
        ));

        // wrong type
        $this->redis->set('foo', 'bar');
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setUnion('foo');
        });
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setUnion('setA', 'foo');
        });
    }

    function testSetUnionStore() {
        $setA = RC::setAddAndReturn($this->redis, 'setA', array(0, 1, 2, 3, 4, 5, 6));
        $setB = RC::setAddAndReturn($this->redis, 'setB', array(1, 3, 4, 6, 9, 10));

        $this->assertEquals(count($setA), $this->redis->setUnionStore('setC', 'setA'));
        $this->assertTrue(RC::sameValuesInArrays(
            $setA, 
            $this->redis->setMembers('setC')
        ));

        $this->redis->delete('setC');
        $this->assertEquals(9, $this->redis->setUnionStore('setC', 'setA', 'setB'));
        $this->assertTrue(RC::sameValuesInArrays(
            array_union($setA, $setB), 
            $this->redis->setMembers('setC')
        ));

        // non-existing keys are considered empty sets
        $this->redis->delete('setC');
        $this->assertEquals(0, $this->redis->setUnionStore('setC', 'setDoesNotExist'));
        $this->assertFalse($this->redis->exists('setC'));
        $this->assertEquals(0, $this->redis->setCardinality('setC'));

        // existing keys are replaced by SUNIONSTORE
        $this->redis->set('foo', 'bar');
        $this->assertEquals(count($setA), $this->redis->setUnionStore('foo', 'setA'));

        // wrong type
        $this->redis->set('foo', 'bar');
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setUnionStore('setA', 'foo');
        });
    }

    function testSetDifference() {
        $setA = RC::setAddAndReturn($this->redis, 'setA', array(0, 1, 2, 3, 4, 5, 6));
        $setB = RC::setAddAndReturn($this->redis, 'setB', array(1, 3, 4, 6, 9, 10));

        $this->assertTrue(RC::sameValuesInArrays(
            $setA, 
            $this->redis->setDifference('setA')
        ));

        $this->assertTrue(RC::sameValuesInArrays(
            array_diff($setA, $setB), 
            $this->redis->setDifference('setA', 'setB')
        ));

        $this->assertTrue(RC::sameValuesInArrays(
            $setA, 
            $this->redis->setDifference('setA', 'setDoesNotExist')
        ));

        // wrong type
        $this->redis->set('foo', 'bar');
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setDifference('foo');
        });
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setDifference('setA', 'foo');
        });
    }

    function testSetDifferenceStore() {
        $setA = RC::setAddAndReturn($this->redis, 'setA', array(0, 1, 2, 3, 4, 5, 6));
        $setB = RC::setAddAndReturn($this->redis, 'setB', array(1, 3, 4, 6, 9, 10));

        $this->assertEquals(count($setA), $this->redis->setDifferenceStore('setC', 'setA'));
        $this->assertTrue(RC::sameValuesInArrays(
            $setA, 
            $this->redis->setMembers('setC')
        ));

        $this->redis->delete('setC');
        $this->assertEquals(3, $this->redis->setDifferenceStore('setC', 'setA', 'setB'));
        $this->assertTrue(RC::sameValuesInArrays(
            array_diff($setA, $setB), 
            $this->redis->setMembers('setC')
        ));

        // non-existing keys are considered empty sets
        $this->redis->delete('setC');
        $this->assertEquals(0, $this->redis->setDifferenceStore('setC', 'setDoesNotExist'));
        $this->assertFalse($this->redis->exists('setC'));
        $this->assertEquals(0, $this->redis->setCardinality('setC'));

        // existing keys are replaced by SDIFFSTORE
        $this->redis->set('foo', 'bar');
        $this->assertEquals(count($setA), $this->redis->setDifferenceStore('foo', 'setA'));

        // wrong type
        $this->redis->set('foo', 'bar');
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setDifferenceStore('setA', 'foo');
        });
    }

    function testRandomMember() {
        $set = RC::setAddAndReturn($this->redis, 'set', array(0, 1, 2, 3, 4, 5, 6));

        $this->assertTrue(in_array($this->redis->setRandomMember('set'), $set));

        $this->assertNull($this->redis->setRandomMember('setDoesNotExist'));

        // wrong type
        $this->redis->set('foo', 'bar');
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->setRandomMember('foo');
        });
    }


    /* commands operating on sorted sets */

    function testZsetAdd() {
        $this->assertTrue($this->redis->zsetAdd('zset', 0, 'a'));
        $this->assertTrue($this->redis->zsetAdd('zset', 1, 'b'));

        $this->assertTrue($this->redis->zsetAdd('zset', -1, 'c'));

        // TODO: floats?
        //$this->assertTrue($this->redis->zsetAdd('zset', -1.0, 'c'));
        //$this->assertTrue($this->redis->zsetAdd('zset', -1.0, 'c'));

        $this->assertFalse($this->redis->zsetAdd('zset', 2, 'b'));
        $this->assertFalse($this->redis->zsetAdd('zset', -2, 'b'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->zsetAdd('foo', 0, 'a');
        });
    }

    function testZsetIncrementBy() {
        $this->assertEquals(1, $this->redis->zsetIncrementBy('zsetDoesNotExist', 1, 'foo'));
        $this->assertEquals('zset', $this->redis->type('zsetDoesNotExist'));

        RC::zsetAddAndReturn($this->redis, 'zset', RC::getZSetArray());
        $this->assertEquals(-5, $this->redis->zsetIncrementBy('zset', 5, 'a'));
        $this->assertEquals(1, $this->redis->zsetIncrementBy('zset', 1, 'b'));
        $this->assertEquals(10, $this->redis->zsetIncrementBy('zset', 0, 'c'));
        $this->assertEquals(0, $this->redis->zsetIncrementBy('zset', -20, 'd'));
        $this->assertEquals(2, $this->redis->zsetIncrementBy('zset', 2, 'd'));
        $this->assertEquals(-10, $this->redis->zsetIncrementBy('zset', -30, 'e'));
        $this->assertEquals(1, $this->redis->zsetIncrementBy('zset', 1, 'x'));

        // wrong type
        $this->redis->set('foo', 'bar');
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->zsetIncrementBy('foo', 1, 'a');
        });
    }

    function testZsetRemove() {
        RC::zsetAddAndReturn($this->redis, 'zset', RC::getZSetArray());
        
        $this->assertTrue($this->redis->zsetRemove('zset', 'a'));
        $this->assertFalse($this->redis->zsetRemove('zset', 'x'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->zsetRemove('foo', 'bar');
        });
    }

    function testZsetRange() {
        $zset = RC::zsetAddAndReturn($this->redis, 'zset', RC::getZSetArray());

        $this->assertEquals(
            array_slice(array_keys($zset), 0, 4), 
            $this->redis->zsetRange('zset', 0, 3)
        );

        $this->assertEquals(
            array_slice(array_keys($zset), 0, 1), 
            $this->redis->zsetRange('zset', 0, 0)
        );

        $this->assertEquals(
            array(), 
            $this->redis->zsetRange('zset', 1, 0)
        );

        $this->assertEquals(
            array_values(array_keys($zset)), 
            $this->redis->zsetRange('zset', 0, -1)
        );

        $this->assertEquals(
            array_slice(array_keys($zset), 3, 1), 
            $this->redis->zsetRange('zset', 3, -3)
        );

        $this->assertEquals(
            array(), 
            $this->redis->zsetRange('zset', 5, -3)
        );

        $this->assertEquals(
            array_slice(array_keys($zset), -5, -1), 
            $this->redis->zsetRange('zset', -5, -2)
        );

        $this->assertEquals(
            array_values(array_keys($zset)), 
            $this->redis->zsetRange('zset', -100, 100)
        );

        $this->assertEquals(
            array_values(array_keys($zset)), 
            $this->redis->zsetRange('zset', -100, 100)
        );

        $this->assertEquals(
            array(array('a', -10), array('b', 0), array('c', 10)), 
            $this->redis->zsetRange('zset', 0, 2, 'withscores')
        );

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->zsetRange('foo', 0, -1);
        });
    }

    function testZsetReverseRange() {
        $zset = RC::zsetAddAndReturn($this->redis, 'zset', RC::getZSetArray());

        $this->assertEquals(
            array_slice(array_reverse(array_keys($zset)), 0, 4), 
            $this->redis->zsetReverseRange('zset', 0, 3)
        );

        $this->assertEquals(
            array_slice(array_reverse(array_keys($zset)), 0, 1), 
            $this->redis->zsetReverseRange('zset', 0, 0)
        );

        $this->assertEquals(
            array(), 
            $this->redis->zsetReverseRange('zset', 1, 0)
        );

        $this->assertEquals(
            array_values(array_reverse(array_keys($zset))), 
            $this->redis->zsetReverseRange('zset', 0, -1)
        );

        $this->assertEquals(
            array_slice(array_reverse(array_keys($zset)), 3, 1), 
            $this->redis->zsetReverseRange('zset', 3, -3)
        );

        $this->assertEquals(
            array(), 
            $this->redis->zsetReverseRange('zset', 5, -3)
        );

        $this->assertEquals(
            array_slice(array_reverse(array_keys($zset)), -5, -1), 
            $this->redis->zsetReverseRange('zset', -5, -2)
        );

        $this->assertEquals(
            array_values(array_reverse(array_keys($zset))), 
            $this->redis->zsetReverseRange('zset', -100, 100)
        );

        $this->assertEquals(
            array(array('f', 30), array('e', 20), array('d', 20)), 
            $this->redis->zsetReverseRange('zset', 0, 2, 'withscores')
        );

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->zsetReverseRange('foo', 0, -1);
        });
    }

    function testZsetRangeByScore() {
        $zset = RC::zsetAddAndReturn($this->redis, 'zset', RC::getZSetArray());

        $this->assertEquals(
            array('a'), 
            $this->redis->zsetRangeByScore('zset', -10, -10)
        );

        $this->assertEquals(
            array('c', 'd', 'e', 'f'), 
            $this->redis->zsetRangeByScore('zset', 10, 30)
        );

        $this->assertEquals(
            array('d', 'e'), 
            $this->redis->zsetRangeByScore('zset', 20, 20)
        );

        $this->assertEquals(
            array(), 
            $this->redis->zsetRangeByScore('zset', 30, 0)
        );

        $this->assertEquals(
            array(array('c', 10), array('d', 20), array('e', 20)), 
            $this->redis->zsetRangeByScore('zset', 10, 20, 'withscores')
        );

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->zsetRangeByScore('foo', 0, 0);
        });
    }

    function testZsetCount() {
        $zset = RC::zsetAddAndReturn($this->redis, 'zset', RC::getZSetArray());

        $this->assertEquals(0, $this->redis->zsetCount('zset', 50, 100));
        $this->assertEquals(6, $this->redis->zsetCount('zset', -100, 100));
        $this->assertEquals(3, $this->redis->zsetCount('zset', 10, 20));
        $this->assertEquals(2, $this->redis->zsetCount('zset', "(10", 20));
        $this->assertEquals(1, $this->redis->zsetCount('zset', 10, "(20"));
        $this->assertEquals(0, $this->redis->zsetCount('zset', "(10", "(20"));
        $this->assertEquals(3, $this->redis->zsetCount('zset', "(0", "(30"));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->zsetCount('foo', 0, 0);
        });
    }

    function testZsetCardinality() {
        $zset = RC::zsetAddAndReturn($this->redis, 'zset', RC::getZSetArray());

        $this->assertEquals(count($zset), $this->redis->zsetCardinality('zset'));
        
        $this->redis->zsetRemove('zset', 'a');
        $this->assertEquals(count($zset) - 1, $this->redis->zsetCardinality('zset'));

        // empty zset
        $this->redis->zsetAdd('zsetB', 0, 'a');
        $this->redis->zsetRemove('zsetB', 'a');
        $this->assertEquals(0, $this->redis->zsetCardinality('setB'));

        // non-existing zset
        $this->assertEquals(0, $this->redis->zsetCardinality('zsetDoesNotExist'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->zsetCardinality('foo');
        });
    }

    function testZsetScore() {
        $zset = RC::zsetAddAndReturn($this->redis, 'zset', RC::getZSetArray());

        $this->assertEquals(-10, $this->redis->zsetScore('zset', 'a'));
        $this->assertEquals(10, $this->redis->zsetScore('zset', 'c'));
        $this->assertEquals(20, $this->redis->zsetScore('zset', 'e'));

        $this->assertNull($this->redis->zsetScore('zset', 'x'));
        $this->assertNull($this->redis->zsetScore('zsetDoesNotExist', 'a'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->zsetScore('foo', 'bar');
        });
    }

    function testZsetRemoveRangeByScore() {
        $zset = RC::zsetAddAndReturn($this->redis, 'zset', RC::getZSetArray());

        $this->assertEquals(2, $this->redis->zsetRemoveRangeByScore('zset', -10, 0));
        $this->assertEquals(
            array('c', 'd', 'e', 'f'), 
            $this->redis->zsetRange('zset', 0, -1)
        );

        $this->assertEquals(1, $this->redis->zsetRemoveRangeByScore('zset', 10, 10));
        $this->assertEquals(
            array('d', 'e', 'f'), 
            $this->redis->zsetRange('zset', 0, -1)
        );

        $this->assertEquals(0, $this->redis->zsetRemoveRangeByScore('zset', 100, 100));

        $this->assertEquals(3, $this->redis->zsetRemoveRangeByScore('zset', 0, 100));
        $this->assertEquals(0, $this->redis->zsetRemoveRangeByScore('zset', 0, 100));

        $this->assertEquals(0, $this->redis->zsetRemoveRangeByScore('zsetDoesNotExist', 0, 100));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->zsetRemoveRangeByScore('foo', 0, 0);
        });
    }

    function testZrank() {
        $zset = RC::zsetAddAndReturn($this->redis, 'zset', RC::getZSetArray());

        $this->assertEquals(0, $this->redis->zsetRank('zset', 'a'));
        $this->assertEquals(1, $this->redis->zsetRank('zset', 'b'));
        $this->assertEquals(4, $this->redis->zsetRank('zset', 'e'));

        $this->redis->zsetRemove('zset', 'd');
        $this->assertEquals(3, $this->redis->zsetRank('zset', 'e'));

        $this->assertNull($this->redis->zsetRank('zset', 'x'));

        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->zsetRank('foo', 'a');
        });
    }

    /* multiple databases handling commands */

    function testSelectDatabase() {
        $this->assertTrue($this->redis->selectDatabase(0));
        $this->assertTrue($this->redis->selectDatabase(RC::DEFAULT_DATABASE));

        RC::testForServerException($this, RC::EXCEPTION_INVALID_DB_IDX, function($test) {
            $test->redis->selectDatabase(32);
        });

        RC::testForServerException($this, RC::EXCEPTION_INVALID_DB_IDX, function($test) {
            $test->redis->selectDatabase(-1);
        });
    }

    function testMove() {
        // TODO: This test sucks big time. Period.
        $otherDb = 5;
        $this->redis->set('foo', 'bar');

        $this->redis->selectDatabase($otherDb);
        $this->redis->flushDatabase();
        $this->redis->selectDatabase(RC::DEFAULT_DATABASE);

        $this->assertTrue($this->redis->move('foo', $otherDb));
        $this->assertFalse($this->redis->move('foo', $otherDb));
        $this->assertFalse($this->redis->move('keyDoesNotExist', $otherDb));

        $this->redis->set('hoge', 'piyo');
        // TODO: shouldn't Redis send an EXCEPTION_INVALID_DB_IDX instead of EXCEPTION_OUT_OF_RANGE?
        RC::testForServerException($this, RC::EXCEPTION_OUT_OF_RANGE, function($test) {
            $test->redis->move('hoge', 32);
        });
    }

    function testFlushDatabase() {
        $this->assertTrue($this->redis->flushDatabase());
    }


    /* sorting */

    function testSort() {
        $unorderedList = RC::pushTailAndReturn($this->redis, 'unordered', array(2, 100, 3, 1, 30, 10));

        // without parameters
        $this->assertEquals(array(1, 2, 3, 10, 30, 100), $this->redis->sort('unordered'));

        // with parameter ASC/DESC
        $this->assertEquals(
            array(100, 30, 10, 3, 2, 1), 
            $this->redis->sort('unordered', array(
                'sort' => 'desc'
            ))
        );

        // with parameter LIMIT
        $this->assertEquals(
            array(1, 2, 3), 
            $this->redis->sort('unordered', array(
                'limit' => array(0, 3)
            ))
        );
        $this->assertEquals(
            array(10, 30), 
            $this->redis->sort('unordered', array(
                'limit' => array(3, 2)
            ))
        );

        // with parameter ALPHA
        $this->assertEquals(
            array(1, 10, 100, 2, 3, 30), 
            $this->redis->sort('unordered', array(
                'alpha' => true
            ))
        );

        // with combined parameters
        $this->assertEquals(
            array(30, 10, 3, 2), 
            $this->redis->sort('unordered', array(
                'alpha' => false, 
                'sort'  => 'desc', 
                'limit' => array(1, 4)
            ))
        );

        // with parameter ALPHA
        $this->assertEquals(
            array(1, 10, 100, 2, 3, 30), 
            $this->redis->sort('unordered', array(
                'alpha' => true
            ))
        );

        // with parameter STORE
        $this->assertEquals(
            count($unorderedList), 
            $this->redis->sort('unordered', array(
                'store' => 'ordered'
            ))
        );
        $this->assertEquals(array(1, 2, 3, 10, 30, 100),  $this->redis->listRange('ordered', 0, -1));

        // with parameter GET
        $this->redis->pushTail('uids', 1003);
        $this->redis->pushTail('uids', 1001);
        $this->redis->pushTail('uids', 1002);
        $this->redis->pushTail('uids', 1000);
        $sortget = array(
            'uid:1000' => 'foo',  'uid:1001' => 'bar', 
            'uid:1002' => 'hoge', 'uid:1003' => 'piyo'
        );
        $this->redis->setMultiple($sortget);
        $this->assertEquals(
            array_values($sortget), 
            $this->redis->sort('uids', array('get' => 'uid:*'))
        );

        // wrong type
        RC::testForServerException($this, RC::EXCEPTION_WRONG_TYPE, function($test) {
            $test->redis->set('foo', 'bar');
            $test->redis->sort('foo');
        });
    }

    /* remote server control commands */

    function testInfo() {
        $serverInfo = $this->redis->info();

        $this->assertType('array', $serverInfo);
        $this->assertNotNull($serverInfo['redis_version']);
        $this->assertGreaterThan(0, $serverInfo['uptime_in_seconds']);
        $this->assertGreaterThan(0, $serverInfo['total_connections_received']);
    }

    function testSlaveOf() {
        $masterHost = 'www.google.com';
        $masterPort = 80;

        $this->assertTrue($this->redis->slaveOf($masterHost, $masterPort));
        $serverInfo = $this->redis->info();
        $this->assertEquals('slave', $serverInfo['role']);
        $this->assertEquals($masterHost, $serverInfo['master_host']);
        $this->assertEquals($masterPort, $serverInfo['master_port']);

        // slave of NO ONE, the implicit way
        $this->assertTrue($this->redis->slaveOf());
        $serverInfo = $this->redis->info();
        $this->assertEquals('master', $serverInfo['role']);

        // slave of NO ONE, the explicit way
        $this->assertTrue($this->redis->slaveOf('NO ONE'));
    }


    /* persistence control commands */

    function testSave() {
        $this->assertTrue($this->redis->save());
    }

    function testBackgroundSave() {
        $this->assertTrue($this->redis->backgroundSave());
    }

    function testBackgroundRewriteAppendOnlyFile() {
        $this->assertTrue($this->redis->backgroundRewriteAppendOnlyFile());
    }

    function testLastSave() {
        $this->assertGreaterThan(0, $this->redis->lastSave());
    }

    function testShutdown() {
        // TODO: seriously, we even test shutdown?
        /*
        $this->assertNull($this->redis->shutdown());
        sleep(1);
        $this->assertFalse($this->redis->isConnected());
        */
    }
}
?>
