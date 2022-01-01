<?php

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\Firefox\FirefoxOptions;
use Facebook\WebDriver\Firefox\FirefoxProfile;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\WebDriverCapabilityType;
use Facebook\WebDriver\WebDriver;
use Facebook\WebDriver\WebDriverBy;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Symfony\Component\Process\Process;

class PushBrowserTest extends \PHPUnit\Framework\TestCase
{
    private static $webServerProcess;
    private static $seleniumProcess;
    private static $portNumber = 8012;
    private static $localUrl;
    private static $seleniumServerUrl = 'http://localhost:4444';
    private static $tempPreferencesFolder = __DIR__ . '/temp';
    private static $vapidKeys = [
        'subject' => 'mailto: web-push-testing-service@example.com',
        'publicKey' => 'BA6jvk34k6YjElHQ6S0oZwmrsqHdCNajxcod6KJnI77Dagikfb--O_kYXcR2eflRz6l3PcI2r8fPCH3BElLQHDk',
        'privateKey' => '-3CdhFOqjzixgAbUSa0Zv9zi-dwDVmWO7672aBxSFPQ',
    ];
    /** @var RemoteWebDriver */
    private $driver;

    public static function setUpBeforeClass(): void
    {
        /* Define absolute path to static files of web-push-testing-service
         * Install service:
         *      sudo npm install -g web-push-testing-service
         * Export environment variable, e.g.:
         *      export PUSH_BROWSER_STATIC_FILES=/usr/lib/node_modules/web-push-testing-service/src/static
         */
        if (!getenv('PUSH_BROWSER_STATIC_FILES') || !file_exists(getenv('PUSH_BROWSER_STATIC_FILES'))) {
            self::markTestSkipped('Missing environment variable PUSH_BROWSER_STATIC_FILES with path to static test files.');
        }

        self::$localUrl = 'http://localhost:' . self::$portNumber;
        // Start up webserver for static pages
        self::$webServerProcess = new Process(['php', '--server=localhost:' . self::$portNumber, '--docroot=' . getenv('PUSH_BROWSER_STATIC_FILES')]);
        self::$webServerProcess->start();

        // Start selenium server
        self::$seleniumProcess = Process::fromShellCommandline('java -jar $SELENIUM_JAR_PATH');
        self::$seleniumProcess->start();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$webServerProcess && self::$webServerProcess->isRunning()) {
            self::$webServerProcess->stop();
        }

        if (self::$seleniumProcess && self::$seleniumProcess->isRunning()) {
            self::$seleniumProcess->stop();
        }

        if (file_exists(__DIR__ . '/static')) {
            exec('rm -rf ' . __DIR__ . '/static');
        }
    }

    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        exec('rm -rf ' . self::$tempPreferencesFolder);
    }

    private function getWebDriver(string $driverName): WebDriver
    {
        if ($driverName === 'chrome') {
            $desiredCapabilities = DesiredCapabilities::chrome();

            $tempPreferencesFolder = __DIR__ . '/temp';
            $tempPreferenceFile = $tempPreferencesFolder . '/Default/Preferences';
            exec('rm -rf ' . self::$tempPreferencesFolder);

            $chromePreferences = [
                'profile' => [
                    'content_settings' => [
                        'exceptions' => [
                            'notifications' => [
                                self::$localUrl . ',*' => [
                                    'setting' => 1
                                ]
                            ],
                        ],
                    ],
                ],
            ];
            mkdir($tempPreferencesFolder . '/Default', 0777, true);
            $this->assertNotFalse(
                file_put_contents($tempPreferenceFile, json_encode($chromePreferences, JSON_UNESCAPED_SLASHES)),
                'Unable to write temporary chrome preferences files'
            );

            $chromeOptions = new ChromeOptions();
            $chromeOptions->addArguments([
                "--user-data-dir=$tempPreferencesFolder/",
                "--disable-gpu",
                "--no-sandbox"
            ]);

            $desiredCapabilities->setCapability(ChromeOptions::CAPABILITY, $chromeOptions);
        } else if ($driverName === 'firefox') {
            $desiredCapabilities = DesiredCapabilities::firefox();

            // Add arguments via FirefoxOptions to start headless firefox
            $firefoxOptions = new FirefoxOptions();
            $firefoxOptions->setPreference('notification.prompt.testing', true);
            $firefoxOptions->setPreference('notification.prompt.testing.allow', true);
            $firefoxOptions->setPreference('permissions.default.desktop-notification', 1);
            $firefoxOptions->setPreference('dom.push.testing.ignorePermission', true);
            // test below
            $desiredCapabilities->setCapability(FirefoxOptions::CAPABILITY, $firefoxOptions);
        } else {
            $this->fail('Invalid driver requested');
        }

        $desiredCapabilities->setPlatform(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'Windows' : 'Linux');
        return RemoteWebDriver::create(self::$seleniumServerUrl, $desiredCapabilities);
    }

    private function subscribeToPush(): array
    {
        // Check push service is loaded
        $pushServiceLoaded = $this->driver->executeScript(
            'return window.PUSH_TESTING_SERVICE &&
              window.PUSH_TESTING_SERVICE.loaded;'
        );
        $this->assertTrue($pushServiceLoaded);

        $this->driver->executeScript(
            "navigator.permissions.query({name:'notifications'}).then(function(result) { logMessage(result.state); });"
        );

        // Start and subscribe to push service
        $this->driver->executeScript(
            'window.PUSH_TESTING_SERVICE.start();'
        );
        $this->driver->wait(60, 500)->until(
            function () {
                return $this->driver->executeScript(
                    "return (typeof window.PUSH_TESTING_SERVICE.swRegistered) !==
                        'undefined';"
                );
            }
        );
        $pushServiceLoaded = $this->driver->executeScript('return window.PUSH_TESTING_SERVICE.swRegistered;');
        $this->assertTrue($pushServiceLoaded);

        // Acquire subscription
        $this->driver->wait(60, 500)->until(
            function () {
                return $this->driver->executeScript(
                    "return (typeof window.PUSH_TESTING_SERVICE.subscription) !==
                        'undefined';"
                );
            }
        );

        return $this->driver->executeScript('return window.PUSH_TESTING_SERVICE.subscription;');
    }

    public function browserProvider(): array
    {
        return [
            ['chrome', 'aesgcm', ['VAPID' => self::$vapidKeys]],
            ['chrome', 'aes128gcm', ['VAPID' => self::$vapidKeys]],
            // ['chrome', 'aesgcm', []], // No longer supported on latest Chrome
            // ['chrome', 'aes128gcm', []], // No longer supported on latest Chrome
            // Subscribing to push notifications seems to be broken with current geckodriver:
            // https://github.com/mozilla/geckodriver/issues/1687
            // ['firefox', 'aesgcm', ['VAPID' => self::$vapidKeys]],
            // ['firefox', 'aes128gcm', ['VAPID' => self::$vapidKeys]],
        ];
    }

    /**
     * Selenium tests are flaky so add retries.
     */
    public function retryTest($retryCount, $test)
    {
        // just like above without checking the annotation
        for ($i = 0; $i < $retryCount; $i++) {
            try {
                $test();

                return;
            } catch (Exception $e) {
                // last one thrown below
            }
        }
        if (isset($e)) {
            throw $e;
        }
    }

    /**
     * @dataProvider browserProvider
     */
    public function testBrowserPush($browserName, $encodingType, $options): void
    {
        $this->retryTest(3, $this->createClosureTest($browserName, $encodingType, $options));
    }

    protected function createClosureTest($browserName, $encodingType, $options)
    {
        return function () use ($browserName, $encodingType, $options) {
            $this->driver = $this->getWebDriver($browserName);

            $webPush = new WebPush($options);
            $webPush->setAutomaticPadding(false);

            // Open push service test page
            $optionalArgs = $options ? '?vapidPublicKey='  . self::$vapidKeys['publicKey'] : '';
            $this->driver->get('http://localhost:' . self::$portNumber . $optionalArgs);

            $subscription = $this->subscribeToPush();

            if (isset($subscription['supportedContentEncodings'])) {
                $this->assertContains($encodingType, $subscription['supportedContentEncodings']);
            }

            $contentEncoding = $encodingType;
            $endpoint = $subscription['endpoint'];
            $keys = $subscription['keys'];
            $auth = $keys['auth'];
            $p256dh = $keys['p256dh'];

            $payload = 'hello';

            if (!in_array($contentEncoding, ['aesgcm', 'aes128gcm'])) {
                $this->expectException('ErrorException');
                $this->expectExceptionMessage('This content encoding ('.$contentEncoding.') is not supported.');
                $this->markTestIncomplete('Unsupported content encoding: '.$contentEncoding);
            }

            $subscription = new Subscription($endpoint, $p256dh, $auth, $contentEncoding);
            $report = $webPush->sendOneNotification($subscription, $payload);
            $this->assertInstanceOf(MessageSentReport::class, $report);
            $this->assertTrue($report->isSuccess());

            // Let browser idle before trying to acquire push messages
            sleep(15);

            // Acquire message
            $this->driver->wait(30, 500)->until(
                function () {
                    return $this->driver->executeScript(
                        'return window.PUSH_TESTING_SERVICE.receivedMessages.length > 0;'
                    );
                }
            );

            $messages = $this->driver->executeScript(
                'const messages = window.PUSH_TESTING_SERVICE.receivedMessages;
                window.PUSH_TESTING_SERVICE.receivedMessages = [];
                return messages;'
            );

            $this->assertCount(1, $messages);
            $this->assertEquals($payload, $messages[0]);
        };
    }
}
