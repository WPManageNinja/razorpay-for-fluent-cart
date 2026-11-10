<?php

namespace Tests\Integration;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\GlobalPaymentHandler;
use lucatume\WPBrowser\TestCase\WPTestCase;
use RazorpayFluentCart\RazorpayGateway;

class RazorpayTestConnectionTest extends WPTestCase
{
    /** @var array<int, array{url:string, method:string, auth:string}> */
    private array $calls = [];

    private array $reply = [200, ['entity' => 'collection', 'count' => 0, 'items' => []]];

    private $httpMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->httpMock = function ($preempt, $args, $url) {
            if (strpos($url, 'api.razorpay.com') === false) {
                return $preempt;
            }
            $this->calls[] = [
                'url'    => $url,
                'method' => (string) ($args['method'] ?? ''),
                'auth'   => (string) ($args['headers']['Authorization'] ?? ''),
            ];
            [$status, $body] = $this->reply;

            return [
                'headers'  => [],
                'body'     => wp_json_encode($body),
                'response' => ['code' => $status, 'message' => ''],
                'cookies'  => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $this->httpMock, 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', $this->httpMock, 10);
        parent::tearDown();
    }

    private function gateway(array $keys): RazorpayGateway
    {
        $gateway = new RazorpayGateway();
        foreach ($keys as $field => $value) {
            $gateway->settings->settings[$field] = $value;
        }

        return $gateway;
    }

    public function test_core_shows_the_button_for_razorpay(): void
    {
        $this->assertTrue(GlobalPaymentHandler::canTestConnection(new RazorpayGateway()));
    }

    public function test_accepted_live_keys_with_the_decrypted_secret(): void
    {
        $result = $this->gateway([
            'live_api_key'    => 'rzp_live_suite',
            'live_key_secret' => Helper::encryptKey('live-secret'),
        ])->testConnection('live');

        $this->assertSame(['message' => 'Razorpay accepted the live API keys.'], $result);
        $this->assertCount(1, $this->calls);
        $this->assertSame('https://api.razorpay.com/v1/payments?count=1', $this->calls[0]['url']);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('Basic ' . base64_encode('rzp_live_suite:live-secret'), $this->calls[0]['auth']);
    }

    public function test_accepted_test_keys(): void
    {
        $result = $this->gateway([
            'test_api_key'    => 'rzp_test_suite',
            'test_key_secret' => Helper::encryptKey('test-secret'),
        ])->testConnection('test');

        $this->assertSame(['message' => 'Razorpay accepted the test API keys.'], $result);
        $this->assertSame('Basic ' . base64_encode('rzp_test_suite:test-secret'), $this->calls[0]['auth']);
    }

    /**
     * @dataProvider missingKeys
     */
    public function test_missing_keys_make_no_call(string $mode, array $keys, string $message): void
    {
        $result = $this->gateway($keys)->testConnection($mode);

        $this->assertWPError($result);
        $this->assertSame('missing_credentials', $result->get_error_code());
        $this->assertSame($message, $result->get_error_message());
        $this->assertSame([], $this->calls);
    }

    public static function missingKeys(): array
    {
        return [
            'live, no secret' => ['live', ['live_api_key' => 'rzp_live_suite', 'live_key_secret' => ''], 'No live API keys are saved.'],
            'live, no key id' => ['live', ['live_api_key' => '', 'live_key_secret' => 'secret'], 'No live API keys are saved.'],
            'test, only live keys saved' => ['test', [
                'test_api_key' => '', 'test_key_secret' => '',
                'live_api_key' => 'rzp_live_suite', 'live_key_secret' => 'secret',
            ], 'No test API keys are saved.'],
        ];
    }

    public function test_api_rejection_carries_razorpay_message(): void
    {
        $this->reply = [401, ['error' => [
            'code'        => 'BAD_REQUEST_ERROR',
            'description' => 'The api key provided is invalid',
        ]]];

        $result = $this->gateway([
            'test_api_key'    => 'rzp_test_bad',
            'test_key_secret' => 'wrong',
        ])->testConnection('test');

        $this->assertWPError($result);
        $this->assertSame('razorpay_api_error', $result->get_error_code());
        $this->assertSame('The api key provided is invalid', $result->get_error_message());
    }

    public function test_api_rejection_without_a_description_names_the_status(): void
    {
        $this->reply = [500, []];

        $result = $this->gateway([
            'test_api_key'    => 'rzp_test_suite',
            'test_key_secret' => 'secret',
        ])->testConnection('test');

        $this->assertWPError($result);
        $this->assertSame('Razorpay answered HTTP 500.', $result->get_error_message());
    }

    /**
     * @dataProvider mismatchedKeys
     */
    public function test_key_id_from_the_other_mode_makes_no_call(string $mode, array $keys, string $message): void
    {
        $result = $this->gateway($keys)->testConnection($mode);

        $this->assertWPError($result);
        $this->assertSame('mode_mismatch', $result->get_error_code());
        $this->assertSame($message, $result->get_error_message());
        $this->assertSame([], $this->calls);
    }

    public static function mismatchedKeys(): array
    {
        return [
            'test key in live field' => ['live', ['live_api_key' => 'rzp_test_suite', 'live_key_secret' => 'secret'], 'The live API key does not start with rzp_live_.'],
            'live key in test field' => ['test', ['test_api_key' => 'rzp_live_suite', 'test_key_secret' => 'secret'], 'The test API key does not start with rzp_test_.'],
        ];
    }
}
