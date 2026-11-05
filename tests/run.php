<?php
/**
 * Cache / TOS behaviour tests — no network, no PHPUnit (the library supports PHP ≥ 5.3).
 *
 *     php tests/run.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../loader_METno.php';

/**
 * METno with a scripted HTTP layer and a controllable clock
 */
class FakeMETno extends METno {
    static public $now       = 1700000000;
    static public $responses = array();
    static public $requests  = array();
    static public $jitter    = 0;

    protected function now() {
        return self::$now;
    }

    protected function jitter() {
        return self::$jitter;
    }

    protected function httpGet($url, $ifModifiedSince = null) {
        self::$requests[] = array('url' => $url, 'ims' => $ifModifiedSince);
        if (empty(self::$responses)) {
            throw new RuntimeException('unexpected request to ' . $url);
        }
        $r = array_shift(self::$responses);
        return $r + array('code' => 200, 'body' => '', 'headers' => array(), 'error' => '');
    }

    public function fetchXml() {
        return $this->getForecastXML();
    }

    public function runGarbageCollection() {
        $this->collectGarbage();
    }

    public function cacheFile() {
        return $this->getCacheFileName();
    }

    public function getApiParameters() {
        return $this->apiParameters;
    }
}

$failures = 0;
$tests    = 0;

function check($cond, $label) {
    global $failures, $tests;
    $tests++;
    if (!$cond) {
        $failures++;
        echo "FAIL: $label\n";
    }
}

function xmlBody($marker = 'a') {
    return '<?xml version="1.0" encoding="UTF-8"?><weatherdata><product class="pointData">'
        . '<time datatype="forecast" from="2026-11-05T12:00:00Z" to="2026-11-05T12:00:00Z">'
        . '<location altitude="1992" latitude="48.9432" longitude="19.5906"><temperature id="TTT" unit="celsius" value="1.5"/>'
        . '<!--' . $marker . '--></location></time></product></weatherdata>';
}

function ok($body = null, array $headers = array()) {
    return array('code' => 200, 'body' => $body === null ? xmlBody() : $body, 'headers' => $headers);
}

function httpDate($ts) {
    return gmdate('D, d M Y H:i:s', $ts) . ' GMT';
}

function rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), array('.', '..')) as $f) {
        is_dir("$dir/$f") ? rrmdir("$dir/$f") : unlink("$dir/$f");
    }
    rmdir($dir);
}

/** Fresh cache dir + clean fake state for each test */
function reset_state() {
    static $dir = null;
    if ($dir !== null) rrmdir($dir);
    $dir = sys_get_temp_dir() . '/metno-test-' . getmypid() . '-' . mt_rand() . '/';
    mkdir($dir);
    METnoFactory::setCacheDir($dir);
    METnoFactory::setErrorBackoff(600);
    METnoFactory::setMaxStale(86400);
    FakeMETno::$now       = 1700000000;
    FakeMETno::$responses = array();
    FakeMETno::$requests  = array();
    FakeMETno::$jitter    = 0;
    return $dir;
}

function test($name, $fn) {
    reset_state();
    try {
        $fn();
    } catch (Exception $e) {
        check(false, "$name threw " . get_class($e) . ': ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Coordinates
// ---------------------------------------------------------------------------

test('coordinate truncation', function () {
    $cases = array(
        array(48.94329, '48.9432'),
        array('48.94329', '48.9432'),
        array(48.943299999, '48.9432'),
        array(48.99999995, '48.9999'), // truncated, not rounded up to 49
        array(-19.59069, '-19.5906'),
        array(19.5, '19.5'),
        array(50, '50'),
        array(0.00001, '0'),
        array(-0.00001, '0'),
        array(-0.5, '-0.5'),
        array(179.99999, '179.9999'),
    );
    foreach ($cases as $c) {
        $got = METnoFactory::truncateCoordinate($c[0]);
        check($got === $c[1], "truncateCoordinate(" . var_export($c[0], true) . ") = $got, expected {$c[1]}");
    }
});

test('request URL uses truncated coordinates and integer altitude', function () {
    $m = new FakeMETno(48.943299, 19.590699, 1992.4);
    check($m->getApiParameters() === 'lat=48.9432&lon=19.5906&altitude=1992', 'params: ' . $m->getApiParameters());
    $m = new FakeMETno(48.9, 19.5);
    check($m->getApiParameters() === 'lat=48.9&lon=19.5', 'no altitude: ' . $m->getApiParameters());
    $m = new FakeMETno(48.9, 19.5, null);
    check($m->getApiParameters() === 'lat=48.9&lon=19.5', 'null altitude: ' . $m->getApiParameters());
    $m = new FakeMETno(48.9, 19.5, '0');
    check($m->getApiParameters() === 'lat=48.9&lon=19.5&altitude=0', 'zero altitude kept: ' . $m->getApiParameters());
});

test('nearby positions that truncate the same share one cache entry', function () {
    FakeMETno::$responses = array(ok());
    $a = new FakeMETno(48.94321, 19.59061);
    $b = new FakeMETno(48.94329, 19.59069);
    check($a->fetchXml() !== false, 'first fetch');
    check($b->fetchXml() !== false, 'second served');
    check(count(FakeMETno::$requests) === 1, 'one request, got ' . count(FakeMETno::$requests));
});

test('Nordic region detection', function () {
    check(METnoFactory::isNordic(59.91, 10.75), 'Oslo');
    check(METnoFactory::isNordic(69.65, 18.96), 'Tromsø');
    check(METnoFactory::isNordic(60.17, 24.94), 'Helsinki');
    check(METnoFactory::isNordic(55.68, 12.57), 'Copenhagen');
    check(!METnoFactory::isNordic(48.94, 19.59), 'Chopok is not Nordic');
    check(!METnoFactory::isNordic(52.52, 13.40), 'Berlin is not Nordic');
    check(METnoFactory::getMinCacheTime(48.94, 19.59) === 10800, 'world = 3 h');
    check(METnoFactory::getMinCacheTime(59.91, 10.75) === 1800, 'Nordic = 30 min');
});

test('minimum cache times cannot be lowered', function () {
    check(METnoFactory::setMinCacheTimes(600, 10800) === false, 'nordic below minimum rejected');
    check(METnoFactory::setMinCacheTimes(1800, 3600) === false, 'world below minimum rejected');
    check(METnoFactory::getMinCacheTime(48.94, 19.59) === 10800, 'unchanged');
    check(METnoFactory::setMinCacheTimes(3600, 21600) === true, 'raising accepted');
    check(METnoFactory::getMinCacheTime(48.94, 19.59) === 21600, 'raised');
    METnoFactory::setMinCacheTimes(1800, 10800);
});

// ---------------------------------------------------------------------------
// Freshness
// ---------------------------------------------------------------------------

test('rest of world: no request for 3 h, then revalidation with If-Modified-Since', function () {
    $lm = 'Thu, 05 Nov 2026 10:00:00 GMT';
    FakeMETno::$responses = array(ok(null, array('last-modified' => $lm, 'expires' => httpDate(FakeMETno::$now + 1800))));
    $m = new FakeMETno(48.9432, 19.5906, 1992);
    check($m->fetchXml() !== false, 'first fetch ok');

    FakeMETno::$now += 10799;
    $m = new FakeMETno(48.9432, 19.5906, 1992);
    check($m->fetchXml() !== false, 'served from cache before 3 h');
    check(count(FakeMETno::$requests) === 1, 'no second request before 3 h (Expires 30 min ignored as shorter)');

    FakeMETno::$now += 1;
    FakeMETno::$responses = array(array('code' => 304, 'headers' => array('expires' => httpDate(FakeMETno::$now + 600))));
    $m = new FakeMETno(48.9432, 19.5906, 1992);
    check($m->fetchXml() !== false, '304 serves cached xml');
    check(count(FakeMETno::$requests) === 2, 'revalidated after 3 h');
    check(FakeMETno::$requests[1]['ims'] === $lm, 'If-Modified-Since = previous Last-Modified');

    FakeMETno::$now += 10799;
    $m = new FakeMETno(48.9432, 19.5906, 1992);
    check($m->fetchXml() !== false, 'served after 304');
    check(count(FakeMETno::$requests) === 2, '304 extended freshness by 3 h');
});

test('Nordic: 30 min minimum', function () {
    FakeMETno::$responses = array(ok(), ok());
    $m = new FakeMETno(59.91, 10.75);
    $m->fetchXml();
    FakeMETno::$now += 1799;
    $m = new FakeMETno(59.91, 10.75);
    $m->fetchXml();
    check(count(FakeMETno::$requests) === 1, 'cached before 30 min');
    FakeMETno::$now += 1;
    $m = new FakeMETno(59.91, 10.75);
    $m->fetchXml();
    check(count(FakeMETno::$requests) === 2, 'refetched at 30 min');
    check(FakeMETno::$requests[1]['ims'] === null, 'no If-Modified-Since without Last-Modified');
});

test('a later Expires header wins over the minimum', function () {
    FakeMETno::$responses = array(ok(null, array('expires' => httpDate(FakeMETno::$now + 20000))), ok());
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    FakeMETno::$now += 19999;
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    check(count(FakeMETno::$requests) === 1, 'Expires honoured');
    FakeMETno::$now += 1;
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    check(count(FakeMETno::$requests) === 2, 'refetched at Expires');
});

test('jitter extends the lifetime', function () {
    FakeMETno::$jitter    = 300;
    FakeMETno::$responses = array(ok(), ok());
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    FakeMETno::$now += 10800 + 299;
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    check(count(FakeMETno::$requests) === 1, 'still fresh inside jitter');
});

test('200 replaces the cached body', function () {
    FakeMETno::$responses = array(ok(xmlBody('old')), ok(xmlBody('new')));
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    FakeMETno::$now += 10800;
    $m = new FakeMETno(48.9432, 19.5906);
    $xml = $m->fetchXml();
    check($xml !== false && strpos($xml->asXML(), 'new') !== false, 'new body served');
    check(strpos(file_get_contents($m->cacheFile()), 'new') !== false, 'new body cached');
});

// ---------------------------------------------------------------------------
// Failures
// ---------------------------------------------------------------------------

test('failure with cached data: stale served, backoff respected', function () {
    FakeMETno::$responses = array(ok());
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();

    FakeMETno::$now += 10800;
    FakeMETno::$responses = array(array('code' => 502, 'body' => 'Bad Gateway'));
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() !== false, 'stale served on 502');
    check($m->isSuccess() && $m->isStale(), 'success + stale');

    FakeMETno::$now += 599;
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() !== false && $m->isStale(), 'stale during backoff');
    check(count(FakeMETno::$requests) === 2, 'no request during backoff');

    FakeMETno::$now += 1;
    FakeMETno::$responses = array(ok());
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() !== false && !$m->isStale(), 'fresh again after backoff');
    check(count(FakeMETno::$requests) === 3, 'retried after backoff');
});

test('truncated 200 is a failure and is never cached', function () {
    FakeMETno::$responses = array(ok(substr(xmlBody(), 0, 120)));
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() === false && $m->isError(), 'truncated body rejected');
    check(strpos((string) @file_get_contents($m->cacheFile()), '<weatherdata') === false, 'not cached');
});

test('200 without forecast data is a failure', function () {
    FakeMETno::$responses = array(ok('<?xml version="1.0"?><weatherdata/>'));
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() === false, 'empty weatherdata rejected');
});

test('failure without data: error, and no request during backoff', function () {
    FakeMETno::$responses = array(array('code' => 0, 'error' => 'Operation timed out'));
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() === false && $m->isError(), 'error');
    FakeMETno::$now += 300;
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() === false && $m->isError(), 'still error during backoff');
    check(count(FakeMETno::$requests) === 1, 'no request during backoff');
    FakeMETno::$now += 300;
    FakeMETno::$responses = array(ok());
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() !== false, 'recovers after backoff');
});

test('429 Retry-After longer than the backoff wins', function () {
    FakeMETno::$responses = array(array('code' => 429, 'headers' => array('retry-after' => '3600')));
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    FakeMETno::$now += 3599;
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    check(count(FakeMETno::$requests) === 1, 'no request before Retry-After');
    FakeMETno::$now += 1;
    FakeMETno::$responses = array(ok());
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() !== false, 'retried after Retry-After');
});

test('429 Retry-After as HTTP date', function () {
    FakeMETno::$responses = array(array('code' => 429, 'headers' => array('retry-after' => httpDate(FakeMETno::$now + 1200))));
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    FakeMETno::$now += 1199;
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    check(count(FakeMETno::$requests) === 1, 'no request before Retry-After date');
});

test('stale data older than maxStale is not served', function () {
    FakeMETno::$responses = array(ok());
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    FakeMETno::$now += 86401;
    FakeMETno::$responses = array(array('code' => 503));
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() === false && $m->isError(), 'too old → error');
});

test('maxStale 0 disables stale serving', function () {
    METnoFactory::setMaxStale(0);
    FakeMETno::$responses = array(ok(), array('code' => 503));
    $m = new FakeMETno(48.9432, 19.5906);
    $m->fetchXml();
    FakeMETno::$now += 10800;
    $m = new FakeMETno(48.9432, 19.5906);
    check($m->fetchXml() === false, 'no stale');
});

test('a corrupt cache file is refetched', function () {
    $m = new FakeMETno(48.9432, 19.5906);
    file_put_contents($m->cacheFile(), "garbage without newline");
    FakeMETno::$responses = array(ok());
    check($m->fetchXml() !== false, 'refetched');
    check(count(FakeMETno::$requests) === 1, 'one request');
});

// ---------------------------------------------------------------------------
// Garbage collection
// ---------------------------------------------------------------------------

test('garbage collection removes old entries, temp files and legacy day folders', function () {
    $dir = reset_state();
    mkdir($dir . '20261104');
    file_put_contents($dir . '20261104/lat=1&lon=2-10.xml', 'x');
    mkdir($dir . 'keepme');
    file_put_contents($dir . 'metno-old.cache', 'x');
    touch($dir . 'metno-old.cache', FakeMETno::$now - 86400 * 3);
    file_put_contents($dir . 'metno-new.cache', 'x');
    touch($dir . 'metno-new.cache', FakeMETno::$now - 3600);
    file_put_contents($dir . 'tmp-metno-abc', 'x');
    touch($dir . 'tmp-metno-abc', FakeMETno::$now - 7200);

    $m = new FakeMETno(48.9432, 19.5906);
    $m->runGarbageCollection();

    check(!is_dir($dir . '20261104'), 'legacy day folder removed');
    check(is_dir($dir . 'keepme'), 'other folders kept');
    check(!file_exists($dir . 'metno-old.cache'), 'old entry removed');
    check(file_exists($dir . 'metno-new.cache'), 'recent entry kept');
    check(!file_exists($dir . 'tmp-metno-abc'), 'temp leftover removed');
});

reset_state();
echo $failures === 0 ? "OK ($tests checks)\n" : "$failures of $tests checks FAILED\n";
exit($failures === 0 ? 0 : 1);
