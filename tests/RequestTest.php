<?php
namespace tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Workerman\Http\Client;
use Workerman\Psr7\Response;
use Workerman\Psr7\MultipartStream;
use Workerman\Timer;
use function Workerman\Psr7\build_query;

class RequestTest extends TestCase
{

    /**
     * Test GET request with callbacks
     */
    public function testGetRequestWithCallbacks()
    {
        $successCalled = false;
        $errorCalled = false;
        $http = new Client();
        $data = ['k1' => 'v1', 'k2' => 'v2'];
        $http->get('http://127.0.0.1:7171/get?' . http_build_query($data), function ($response) use (&$successCalled, $data) {
            $successCalled = true;
            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals(json_encode($data), $response->getBody());
        }, function ($exception) use (&$errorCalled) {
            $errorCalled = true;
        });
        for ($i = 0; $i < 10; $i++) {
            if ($successCalled || $errorCalled) {
                break;
            }
            Timer::sleep(0.1);
        }
        $this->assertTrue($successCalled);
        $this->assertFalse($errorCalled);

    }

    public function testException()
    {
        $successCalled = false;
        $errorCalled = false;
        $http = new Client();
        $http->get('http://127.0.0.1:7171/exception', function ($response) use (&$successCalled) {
            $successCalled = true;
        }, function ($exception) use (&$errorCalled) {
            $errorCalled = true;
        });
        for ($i = 0; $i < 10; $i++) {
            if ($successCalled || $errorCalled) {
                break;
            }
            Timer::sleep(0.1);
        }
        $this->assertFalse($successCalled);
        $this->assertTrue($errorCalled);
    }

    public function testBadAddressException()
    {
        $successCalled = false;
        $errorCalled = false;
        $http = new Client();
        $http->get(':bad_address/exception', function ($response) use (&$successCalled) {
            $successCalled = true;
        }, function ($exception) use (&$errorCalled) {
            $errorCalled = true;
        });
        for ($i = 0; $i < 10; $i++) {
            if ($successCalled || $errorCalled) {
                break;
            }
            Timer::sleep(0.1);
        }
        $this->assertFalse($successCalled);
        $this->assertTrue($errorCalled);
    }

    /**
     * Test POST request with callbacks
     */
    public function testPostRequestWithCallbacks()
    {
        $successCalled = false;
        $errorCalled = false;
        $http = new Client();

        $postData = ['key1' => 'value1', 'key2' => 'value2'];

        $http->post('http://127.0.0.1:7171/post', $postData, function ($response) use (&$successCalled, $postData) {
            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals(200, $response->getStatusCode());

            $body = json_decode($response->getBody(), true);
            $this->assertEquals($postData, $body);
            $successCalled = true;

        }, function ($exception) use (&$errorCalled) {
            $errorCalled = true;
        });

        for ($i = 0; $i < 10; $i++) {
            if ($successCalled || $errorCalled) {
                break;
            }
            Timer::sleep(0.1);
        }
        $this->assertTrue($successCalled);
        $this->assertFalse($errorCalled);
    }

    /**
     * Test custom request with options
     */
    public function testCustomRequestWithOptions()
    {
        $successCalled = false;
        $errorCalled = false;
        $http = new Client();

        $options = [
            'method' => 'POST',
            'version' => '1.1',
            'headers' => ['Connection' => 'keep-alive'],
            'data' => ['key1' => 'value1', 'key2' => 'value2'],
            'success' => function ($response) use (&$successCalled) {
                $this->assertInstanceOf(Response::class, $response);
                $this->assertEquals(200, $response->getStatusCode());
                $successCalled = true;
            },
            'error' => function ($exception) use (&$errorCalled) {
                $errorCalled = true;
            }
        ];

        $http->request('http://127.0.0.1:7171/post', $options);

        for ($i = 0; $i < 10; $i++) {
            if ($successCalled || $errorCalled) {
                break;
            }
            Timer::sleep(0.1);
        }
        $this->assertTrue($successCalled);
        $this->assertFalse($errorCalled);
    }

    /**
     * Test file upload
     */
    public function testFileUpload()
    {
        $successCalled = false;
        $errorCalled = false;
        $http = new Client();

        $multipart = new MultipartStream([
            [
                'name' => 'file',
                'contents' => fopen(__FILE__, 'r'),
                'filename' => 'test.php'
            ],
            [
                'name' => 'json',
                'contents' => json_encode(['a' => 1, 'b' => 2])
            ]
        ]);

        $boundary = $multipart->getBoundary();

        $options = [
            'method' => 'POST',
            'version' => '1.1',
            'headers' => [
                'Connection' => 'keep-alive',
                'Content-Type' => "multipart/form-data; boundary=$boundary",
                'Content-Length' => $multipart->getSize()
            ],
            'data' => $multipart,
            'success' => function ($response) use (&$successCalled) {
                $this->assertInstanceOf(Response::class, $response);
                $this->assertEquals(200, $response->getStatusCode());
                $this->assertEquals(md5_file(__FILE__) . ' {"json":"{\"a\":1,\"b\":2}"}', $response->getBody());
                $successCalled = true;
            },
            'error' => function ($exception) use (&$errorCalled) {
                $errorCalled = true;
            }
        ];

        $http->request('http://127.0.0.1:7171/upload', $options);

        for ($i = 0; $i < 10; $i++) {
            if ($successCalled || $errorCalled) {
                break;
            }
            Timer::sleep(0.1);
        }
        $this->assertTrue($successCalled);
        $this->assertFalse($errorCalled);

    }


    /**
     * Test progress stream handling
     */
    public function testProgressStreamHandling()
    {
        $successCalled = false;
        $http = new Client();

        $options = [
            'method' => 'GET',
            'progress' => function ($buffer) use (&$progressCalled) {
                static $i = 0;
                $this->assertEquals((string)$i++, $buffer);
                if ($i == 10) {
                    $progressCalled = true;
                }
            },
            'success' => function ($response) use (&$successCalled) {
                $this->assertInstanceOf(Response::class, $response);
                $this->assertEquals(200, $response->getStatusCode());
                $successCalled = true;
            }
        ];

        $http->request('http://127.0.0.1:7171/stream', $options);

        for ($i = 0; $i < 20; $i++) {
            if ($successCalled) {
                break;
            }
            Timer::sleep(0.1);
        }
        $this->assertTrue($successCalled);
    }

    /**
     * Test synchronous GET request
     */
    public function testSynchronousGetRequest()
    {
        $http = new Client();
        $data = ['k1' => 'v1', 'k2' => 'v2'];
        $response = $http->get('http://127.0.0.1:7171/get?' . http_build_query($data));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(json_encode($data), $response->getBody());

    }

    /**
     * Test synchronous POST request
     */
    public function testSynchronousPostRequest()
    {
        $http = new Client();

        $postData = ['key1' => 'value1', 'key2' => 'value2'];

        $response = $http->post('http://127.0.0.1:7171/post', $postData);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertEquals($postData, $body);
    }

    /**
     * Test synchronous custom request
     */
    public function testSynchronousCustomRequest()
    {
        $http = new Client();

        $options = [
            'method' => 'POST',
            'version' => '1.1',
            'headers' => ['Connection' => 'keep-alive'],
            'data' => ['key1' => 'value1', 'key2' => 'value2'],
        ];

        $response = $http->request('http://127.0.0.1:7171/post', $options);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertEquals($options['data'], $body);
    }

    public function testSynchronousException()
    {
        $throw = false;
        try {
            $http = new Client();
            $options = [
                'method' => 'POST',
                'version' => '1.1',
                'data' => ['key1' => 'value1', 'key2' => 'value2'],
            ];
            $http->request('http://127.0.0.1:7171/exception', $options);
        } catch (Throwable $e) {
            $throw = true;
            $this->assertInstanceOf(RuntimeException::class, $e);
        }

        $this->assertTrue($throw);

    }

    public function testSynchronousBadAddressException()
    {
        $throw = false;
        try {
            $http = new Client();
            $options = [
                'method' => 'POST',
                'version' => '1.1',
                'data' => ['key1' => 'value1', 'key2' => 'value2'],
            ];
            $http->request(':bad_address/exception', $options);
        } catch (Throwable $e) {
            $throw = true;
            $this->assertInstanceOf(RuntimeException::class, $e);
        }

        $this->assertTrue($throw);

    }

}
