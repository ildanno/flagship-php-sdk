<?php

namespace Flagship\Api;

require_once __DIR__ . "/Round.php";
require_once __DIR__ . "/../Assets/Round.php";

use Exception;
use Flagship\Config\DecisionApiConfig;
use Flagship\Enum\EventCategory;
use Flagship\Enum\FlagshipConstant;
use Flagship\Enum\HitCacheFields;
use Flagship\Flag\FlagMetadata;
use Flagship\Hit\Activate;
use Flagship\Hit\ActivateBatch;
use Flagship\Hit\Event;
use Flagship\Hit\HitAbstract;
use Flagship\Hit\HitBatch;
use Flagship\Hit\Page;
use Flagship\Hit\Screen;
use Flagship\Model\ExposedFlag;
use Flagship\Model\ExposedVisitor;
use Flagship\Traits\LogTrait;
use PHPUnit\Framework\TestCase;

class BatchingOnFailedCachingStrategyTest extends TestCase
{
    use LogTrait;

    public function testGeneralMethods()
    {
        $config = new DecisionApiConfig("envId", "apiKey");

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $strategy = new BatchingOnFailedCachingStrategy($config, $httpClientMock);

        $page = new Page("http://localhost");
        $key = "page-key";

        $strategy->hydrateHitsPoolQueue($key, $page);
        $this->assertSame([$key => $page], $strategy->getHitsPoolQueue());

        $activate = new Activate("varGroupId", "varID");
        $activateKey = "activate-key";
        $strategy->hydrateActivatePoolQueue($activateKey, $activate);
        $this->assertSame([$activateKey => $activate], $strategy->getActivatePoolQueue());

        //Test getNow method
        $this->assertEquals(0, $strategy->getNow());

        //Test getActivateHeaders
        $activateHeaders = [
            FlagshipConstant::HEADER_X_API_KEY => $config->getApiKey(),
            FlagshipConstant::HEADER_X_SDK_VERSION => FlagshipConstant::SDK_VERSION,
            FlagshipConstant::HEADER_CONTENT_TYPE => FlagshipConstant::HEADER_APPLICATION_JSON,
            FlagshipConstant::HEADER_X_SDK_CLIENT => FlagshipConstant::SDK_LANGUAGE,
        ];
        $this->assertSame($activateHeaders, $strategy->getActivateHeaders());

        //Test generateHitKey method
        $visitorId = "visitorId";
        if (method_exists($this, "assertMatchesRegularExpression")) {
            $this->assertMatchesRegularExpression("/^$visitorId:/", $strategy->generateHitKey($visitorId));
        } else {
            $this->assertRegExp("/^$visitorId:/", $strategy->generateHitKey($visitorId));
        }
    }

    public function testAddHit()
    {
        $config = new DecisionApiConfig();
        $visitorId = "visitorId";
        $newVisitor = "newVisitor";

        $page3Key = "$visitorId:b1b48180-0d72-410d-8e9b-44ee90dfafc6";
        $activate3Key = "$visitorId:51d18dbf-53ba-4aec-9bff-0d295c1d5d02";

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["cacheHit","flushHits","flushAllHits"]
        );

        $strategy->expects($this->never())->method("cacheHit");

        $strategy->expects($this->never())
            ->method("flushAllHits");

        $strategy->expects($this->once())
            ->method("flushHits")->with([$page3Key, $activate3Key]);


        $page = new Page("http://localhost");
        $page->setConfig($config)->setVisitorId($visitorId);

        $strategy->addHit($page);

        $page2 = new Page("http://localhost");
        $page2->setConfig($config)->setVisitorId($newVisitor);

        $strategy->addHit($page2);

        $contentPage3 = [
            'pageUrl' => 'page1',
            'visitorId' => $visitorId,
            'ds' => 'APP',
            'type' => 'PAGEVIEW',
            'anonymousId' => null,
            'userIP' => null,
            'pageResolution' => null,
            'locale' => null,
            'sessionNumber' => null,
            'key' => $page3Key,
            'createdAt' => 1676542078047,
        ];

        $page3 = HitAbstract::hydrate(Event::getClassName(), $contentPage3);

        $page3->setConfig($config);

        $strategy->hydrateHitsPoolQueue($page3Key, $page3);

        $activate = new Activate("varGrId", "varId");
        $activate->setConfig($config)->setVisitorId($visitorId);

        $strategy->activateFlag($activate);

        $activate2 = new Activate("varGrId", "varId");
        $activate2->setConfig($config)->setVisitorId($newVisitor);
        $strategy->activateFlag($activate2);

        $contentActivate = [
            'variationGroupId' => 'cagt08da51hg0787cns0',
            'variationId' => 'cagt08da51hg0787cnt0',
            'visitorId' => $visitorId,
            'ds' => 'APP',
            'type' => 'ACTIVATE',
            'anonymousId' => null,
            'userIP' => null,
            'pageResolution' => null,
            'locale' => null,
            'sessionNumber' => null,
            'key' => $activate3Key,
            'createdAt' => 1676542078044,
        ];

        $activate3 = HitAbstract::hydrate(Activate::getClassName(), $contentActivate);
        $activate3->setConfig($config);

        $strategy->hydrateActivatePoolQueue($activate3Key, $activate3);

        $this->assertContains($page, $strategy->getHitsPoolQueue());
        $this->assertContains($page2, $strategy->getHitsPoolQueue());
        $this->assertCount(3, $strategy->getHitsPoolQueue());
        $this->assertContains($activate, $strategy->getActivatePoolQueue());
        $this->assertContains($activate2, $strategy->getActivatePoolQueue());
        $this->assertCount(3, $strategy->getActivatePoolQueue());

        // Test consent true
        $consentHit1 = new Event(EventCategory::USER_ENGAGEMENT, FlagshipConstant::FS_CONSENT);
        $consentHit1->setLabel(FlagshipConstant::SDK_LANGUAGE . ":" . "true");
        $consentHit1->setConfig($config);
        $consentHit1->setVisitorId($visitorId);

        $strategy->addHit($consentHit1);

        $this->assertContains($page, $strategy->getHitsPoolQueue());
        $this->assertContains($page2, $strategy->getHitsPoolQueue());
        $this->assertContains($consentHit1, $strategy->getHitsPoolQueue());
        $this->assertCount(4, $strategy->getHitsPoolQueue());
        $this->assertContains($activate, $strategy->getActivatePoolQueue());
        $this->assertContains($activate2, $strategy->getActivatePoolQueue());
        $this->assertCount(3, $strategy->getActivatePoolQueue());

        // Test consent false
        $consentHit = new Event(EventCategory::USER_ENGAGEMENT, FlagshipConstant::FS_CONSENT);
        $consentHit->setLabel(FlagshipConstant::SDK_LANGUAGE . ":" . "false");
        $consentHit->setConfig($config);
        $consentHit->setVisitorId($visitorId);

        $strategy->addHit($consentHit);

        $this->assertNotContains($page, $strategy->getHitsPoolQueue());
        $this->assertContains($page2, $strategy->getHitsPoolQueue());
        $this->assertContains($consentHit1, $strategy->getHitsPoolQueue());
        $this->assertContains($consentHit, $strategy->getHitsPoolQueue());

        $this->assertCount(3, $strategy->getHitsPoolQueue());
        $this->assertNotContains($activate, $strategy->getActivatePoolQueue());
        $this->assertContains($activate2, $strategy->getActivatePoolQueue());
        $this->assertCount(1, $strategy->getActivatePoolQueue());

//        //Test consent hit false when no hits for visitorId exist HitsPoolQueue
        $strategy->addHit($consentHit);

        $this->assertCount(4, $strategy->getHitsPoolQueue());
        $this->assertNotContains($activate, $strategy->getActivatePoolQueue());
        $this->assertContains($activate2, $strategy->getActivatePoolQueue());
        $this->assertCount(1, $strategy->getActivatePoolQueue());
    }


    public function testSendActivateHit()
    {
        $config = new DecisionApiConfig();
        $visitorId = "visitorId";

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $url = FlagshipConstant::BASE_API_URL . '/' . FlagshipConstant::URL_ACTIVATE_MODIFICATION;

        $activate = new Activate("varGrId", "VarId");
        $activate->setConfig($config)->setVisitorId($visitorId);

        $activate2 = new Activate("varGrId", "VarId");
        $activate2->setConfig($config)->setVisitorId($visitorId);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["flushHits","logDebugSprintf","cacheHit"]
        );

        $strategy->activateFlag($activate);
        $strategy->activateFlag($activate2);

        $activate3Key = "$visitorId:51d18dbf-53ba-4aec-9bff-0d295c1d5d02";

        $contentActivate = [
            'variationGroupId' => 'cagt08da51hg0787cns0',
            'variationId' => 'cagt08da51hg0787cnt0',
            'visitorId' => $visitorId,
            'ds' => 'APP',
            'type' => 'ACTIVATE',
            'anonymousId' => null,
            'userIP' => null,
            'pageResolution' => null,
            'locale' => null,
            'sessionNumber' => null,
            'key' => $activate3Key,
            'createdAt' => 1676542078044,
        ];

        $activate3 = HitAbstract::hydrate(Activate::getClassName(), $contentActivate);
        $activate3->setConfig($config);

        $strategy->hydrateActivatePoolQueue($activate3Key, $activate3);

        $activateBatch = new ActivateBatch($config, $strategy->getActivatePoolQueue());

        $requestBody = $activateBatch->toApiKeys();

        $httpClientMock->expects($this->once())->method("post")
            ->with($url, [], $requestBody);

        $headers = $strategy->getActivateHeaders();

        $strategy
            ->expects($this->exactly(1))
            ->method("flushHits")
            ->with([$activate3Key]);

        $logMessage = $this->getLogFormat(
            null,
            $url,
            $requestBody,
            $headers,
            0
        );

        $strategy->expects($this->once())->method("logDebugSprintf")
            ->with(
                $config,
                FlagshipConstant::TRACKING_MANAGER,
                FlagshipConstant::HIT_SENT_SUCCESS,
                [FlagshipConstant::SEND_ACTIVATE, $logMessage ]
            );

        $this->assertCount(3, $strategy->getActivatePoolQueue());

        $strategy->sendBatch();

        $this->assertCount(0, $strategy->getActivatePoolQueue());
    }

    public function testOnUserExposed()
    {
        $config = new DecisionApiConfig();
        $visitorId = "visitorId";
        $context = ["key" => "value"];


        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $url = FlagshipConstant::BASE_API_URL . '/' . FlagshipConstant::URL_ACTIVATE_MODIFICATION;

        $variationGroupId1 = "variationGroupId";
        $variationId1 = "variationId";
        $campaignId1 = "campaignId";
        $flagKey1 = "key1";
        $flagValue1 = "value1";
        $flagDefaultValue1 = "defaultValue1";

        $activate = new Activate($variationGroupId1, $variationId1);

        $flagMetadata1 = new FlagMetadata($campaignId1, $variationGroupId1, $variationId1, false, "ab", null);

        $activate->setConfig($config)
            ->setVisitorId($visitorId)
            ->setVisitorContext($context)
            ->setFlagKey($flagKey1)
            ->setFlagValue($flagValue1)
            ->setFlagDefaultValue($flagDefaultValue1)
            ->setFlagMetadata($flagMetadata1);

        $variationGroupId2 = "variationGroupId2";
        $variationId2 = "variationId2";
        $campaignId2 = "campaignId2";
        $flagKey2 = "key2";
        $flagValue2 = "value2";
        $flagDefaultValue2 = "defaultValue2";

        $flagMetadata2 = new FlagMetadata($campaignId2, $variationGroupId2, $variationId2, false, "ab", null);

        $activate2 = new Activate($variationGroupId2, $variationId2);
        $activate2->setConfig($config)
            ->setVisitorId($visitorId)
            ->setVisitorContext($context)
            ->setFlagKey($flagKey2)
            ->setFlagValue($flagValue2)
            ->setFlagDefaultValue($flagDefaultValue2)
            ->setFlagMetadata($flagMetadata2);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["flushHits","logDebugSprintf","cacheHit", "logErrorSprintf"]
        );

        $strategy->activateFlag($activate);
        $strategy->activateFlag($activate2);

        $activateBatch = new ActivateBatch($config, $strategy->getActivatePoolQueue());

        $requestBody = $activateBatch->toApiKeys();

        $httpClientMock->expects($this->once())->method("post")
            ->with($url, [], $requestBody);

        $check1 = false;
        $check2 = false;
        $count = 0;

        $config->setOnVisitorExposed(function (
            ExposedVisitor $exposedUser,
            ExposedFlag    $exposedFlag
        )
 use (
            $visitorId,
            $context,
            &$check1,
            &$check2,
            &$count,
            $flagKey1,
            $flagValue1,
            $flagMetadata1,
            $flagDefaultValue1,
            $flagKey2,
            $flagValue2,
            $flagMetadata2,
            $flagDefaultValue2
        ) {
            $count++;
            if ($count === 1) {
                $check1 = $exposedUser->getId() === $visitorId &&
                    $exposedUser->getAnonymousId() === null &&
                    $exposedUser->getContext() === $context &&
                $exposedFlag->getValue() === $flagValue1 &&
                $exposedFlag->getKey() === $flagKey1 &&
                $exposedFlag->getMetadata() === $flagMetadata1 &&
                $exposedFlag->getDefaultValue() === $flagDefaultValue1;
            } else {
                $check2 = $exposedUser->getId() === $visitorId &&
                    $exposedUser->getAnonymousId() === null &&
                    $exposedUser->getContext() === $context &&
                    $exposedFlag->getValue() === $flagValue2 &&
                    $exposedFlag->getKey() === $flagKey2 &&
                    $exposedFlag->getMetadata() === $flagMetadata2 &&
                $exposedFlag->getDefaultValue() === $flagDefaultValue2;
            }
        });


        $this->assertCount(2, $strategy->getActivatePoolQueue());


        $strategy->sendBatch();

        $this->assertSame(2, $count);
        $this->assertCount(0, $strategy->getActivatePoolQueue());
        $this->assertTrue($check1);
        $this->assertTrue($check2);
    }

    public function testOnUserExposedError()
    {
        $config = new DecisionApiConfig();
        $visitorId = "visitorId";
        $context = ["key" => "value"];

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $url = FlagshipConstant::BASE_API_URL . '/' . FlagshipConstant::URL_ACTIVATE_MODIFICATION;

        $variationGroupId1 = "variationGroupId";
        $variationId1 = "variationId";
        $campaignId1 = "campaignId";
        $flagKey1 = "key1";
        $flagValue1 = "value1";

        $activate = new Activate($variationGroupId1, $variationId1);

        $flagMetadata1 = new FlagMetadata($campaignId1, $variationGroupId1, $variationId1, false, "ab", null);

        $activate->setConfig($config)
            ->setVisitorId($visitorId)
            ->setVisitorContext($context)
            ->setFlagKey($flagKey1)
            ->setFlagValue($flagValue1)
            ->setFlagMetadata($flagMetadata1);

        $variationGroupId2 = "variationGroupId2";
        $variationId2 = "variationId2";
        $campaignId2 = "campaignId2";
        $flagKey2 = "key2";
        $flagValue2 = "value2";

        $flagMetadata2 = new FlagMetadata($campaignId2, $variationGroupId2, $variationId2, false, "ab", null);

        $activate2 = new Activate($variationGroupId2, $variationId2);
        $activate2->setConfig($config)
            ->setVisitorId($visitorId)
            ->setVisitorContext($context)
            ->setFlagKey($flagKey2)
            ->setFlagValue($flagValue2)
            ->setFlagMetadata($flagMetadata2);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["flushHits","logDebugSprintf","cacheHit", "logErrorSprintf"]
        );

        $strategy->activateFlag($activate);
        $strategy->activateFlag($activate2);

        $activateBatch = new ActivateBatch($config, $strategy->getActivatePoolQueue());

        $requestBody = $activateBatch->toApiKeys();

        $httpClientMock->expects($this->once())->method("post")
            ->with($url, [], $requestBody);

        $check1 = false;
        $check2 = false;
        $count = 0;


        $config->setOnVisitorExposed(function (ExposedVisitor $exposedUser, ExposedFlag $exposedFlag)
 use (&$count) {
            $exceptionMessage = "Message error";
            $count++;
            throw new Exception($exceptionMessage);
        });

        $strategy->expects($this->exactly(2))
            ->method("logErrorSprintf");

        $this->assertCount(2, $strategy->getActivatePoolQueue());

        $strategy->sendBatch();

        $this->assertSame(2, $count);
        $this->assertCount(0, $strategy->getActivatePoolQueue());

        $strategy->activateFlag($activate2);
    }
    public function testSendActivateHitFailed()
    {
        $config = new DecisionApiConfig();
        $visitorId = "visitorId";

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $url = FlagshipConstant::BASE_API_URL . '/' . FlagshipConstant::URL_ACTIVATE_MODIFICATION;

        $activate = new Activate("varGrId", "VarId");
        $activate->setConfig($config)->setVisitorId($visitorId);

        $activate2 = new Activate("varGrId", "VarId");
        $activate2->setConfig($config)->setVisitorId($visitorId);


        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["flushHits","logErrorSprintf","cacheHit"]
        );

        $strategy->activateFlag($activate);
        $strategy->activateFlag($activate2);

        $activateBatch = new ActivateBatch($config, $strategy->getActivatePoolQueue());

        $requestBody = $activateBatch->toApiKeys();

        $exception = new Exception("activate error");
        $httpClientMock->expects($this->once())->method("post")
            ->with($url, [], $requestBody)->willThrowException($exception);

        $strategy
            ->expects($this->never())
            ->method("flushHits");

        $strategy
            ->expects($this->once())
            ->method("cacheHit")
            ->with($this->countOf(2));

        $logMessage = $this->getLogFormat(
            $exception->getMessage(),
            $url,
            $requestBody,
            $strategy->getActivateHeaders(),
            0
        );

        $strategy->expects($this->once())->method("logErrorSprintf")
            ->with(
                $config,
                FlagshipConstant::TRACKING_MANAGER,
                FlagshipConstant::TRACKING_MANAGER_ERROR,
                [FlagshipConstant::SEND_ACTIVATE, $logMessage ]
            );

        $this->assertCount(2, $strategy->getActivatePoolQueue());

        $strategy->sendBatch();

        $this->assertCount(2, $strategy->getActivatePoolQueue());
    }


    public function testSendBatch()
    {
        $config = new DecisionApiConfig();
        $visitorId = "visitorId";
        //Mock class Curl
        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $url = FlagshipConstant::HIT_EVENT_URL;

        $page = new Page("https://myurl.com");
        $page->setConfig($config)->setVisitorId($visitorId);

        $screen = new Screen("home");
        $screen->setConfig($config)->setVisitorId($visitorId);


        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["flushHits","logDebugSprintf","cacheHit","flushAllHits"]
        );

        $strategy->addHit($page);
        $strategy->addHit($screen);

        $page3Key = "$visitorId:b1b48180-0d72-410d-8e9b-44ee90dfafc6";
        $contentPage3 = [
            'pageUrl' => 'page1',
            'visitorId' => $visitorId,
            'ds' => 'APP',
            'type' => 'PAGEVIEW',
            'anonymousId' => null,
            'userIP' => null,
            'pageResolution' => null,
            'locale' => null,
            'sessionNumber' => null,
            'key' => $page3Key,
            'createdAt' => 1676542078047,
        ];

        $page3 = HitAbstract::hydrate(Event::getClassName(), $contentPage3);

        $page3->setConfig($config);

        $strategy->hydrateHitsPoolQueue($page3Key, $page3);

        $batchHit = new HitBatch($config, $strategy->getHitsPoolQueue());
        $batchHit->setConfig($config);

        $requestBody = $batchHit->toApiKeys();

        $httpClientMock->expects($this->once())->method("post")
            ->with($url, [], $requestBody);

        $headers = [FlagshipConstant::HEADER_CONTENT_TYPE => FlagshipConstant::HEADER_APPLICATION_JSON];

        $httpClientMock->expects($this->once())->method('setHeaders')->with($headers);
        $httpClientMock->expects($this->once())->method("setTimeout")->with($config->getTimeout());

        $strategy
            ->expects($this->exactly(1))
            ->method("flushHits")->with([$page3Key]);

        $strategy
            ->expects($this->never())
            ->method("flushAllHits");

        $strategy
            ->expects($this->never())
            ->method("cacheHit")
            ->with([]);

        $logMessage = $this->getLogFormat(
            null,
            $url,
            $requestBody,
            $headers,
            0
        );

        $strategy->expects($this->once())->method("logDebugSprintf")
            ->with(
                $config,
                FlagshipConstant::TRACKING_MANAGER,
                FlagshipConstant::HIT_SENT_SUCCESS,
                [FlagshipConstant::SEND_BATCH, $logMessage ]
            );

        $this->assertCount(3, $strategy->getHitsPoolQueue());
        $strategy->sendBatch();
        $this->assertCount(0, $strategy->getHitsPoolQueue());
    }

    public function testSendBatchFailed()
    {
        $config = new DecisionApiConfig();
        $visitorId = "visitorId";

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $url = FlagshipConstant::HIT_EVENT_URL;

        $page = new Page("https://myurl.com");
        $page->setConfig($config)->setVisitorId($visitorId);

        $screen = new Screen("home");
        $screen->setConfig($config)->setVisitorId($visitorId);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["flushHits","logErrorSprintf","cacheHit","flushAllHits"]
        );

        $strategy->addHit($page);
        $strategy->addHit($screen);

        $batchHit = new HitBatch($config, $strategy->getHitsPoolQueue());
        $batchHit->setConfig($config);

        $requestBody = $batchHit->toApiKeys();

        $exception = new Exception("batch error");
        $httpClientMock->expects($this->once())->method("post")
            ->with($url, [], $requestBody)->willThrowException($exception);

        $headers = [FlagshipConstant::HEADER_CONTENT_TYPE => FlagshipConstant::HEADER_APPLICATION_JSON];

        $strategy
            ->expects($this->never())
            ->method("flushHits");

        $strategy
            ->expects($this->once())
            ->method("cacheHit")->with($this->countOf(2));

        $strategy
            ->expects($this->never())
            ->method("flushAllHits");

        $logMessage = $this->getLogFormat(
            $exception->getMessage(),
            $url,
            $requestBody,
            $headers,
            0
        );

        $strategy->expects($this->once())->method("logErrorSprintf")
            ->with(
                $config,
                FlagshipConstant::TRACKING_MANAGER,
                FlagshipConstant::TRACKING_MANAGER_ERROR,
                [FlagshipConstant::SEND_BATCH, $logMessage ]
            );

        $this->assertCount(2, $strategy->getHitsPoolQueue());
        $strategy->sendBatch();
        $this->assertCount(2, $strategy->getHitsPoolQueue());
    }

    public function testSendBatchWithExpiredHit()
    {
        $config = new DecisionApiConfig();
        $visitorId = "visitorId";

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $url = FlagshipConstant::HIT_EVENT_URL;

        \Flagship\Assets\Round::$returnValue = FlagshipConstant::DEFAULT_HIT_CACHE_TIME_MS;
        $page = new Page("https://myurl.com");
        $page->setConfig($config)->setVisitorId($visitorId);

        \Flagship\Assets\Round::$returnValue = 0;
        $screen = new Screen("home");
        $screen->setConfig($config)->setVisitorId($visitorId);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["flushHits","logErrorSprintf","cacheHit", "flushAllHits"]
        );

        $strategy->addHit($page);
        $strategy->addHit($screen);

        $hits = [];
        foreach ($strategy->getHitsPoolQueue() as $key => $item) {
            if ($item instanceof Page) {
                $hits[$key] = $item;
            }
        }

        $batchHit = new HitBatch($config, $hits);
        $batchHit->setConfig($config);

        $requestBody = $batchHit->toApiKeys();

        $httpClientMock->expects($this->once())->method("post")
            ->with($url, [], $requestBody);

        $strategy
            ->expects($this->exactly(0))
            ->method("flushHits");

        $strategy
            ->expects($this->never())
            ->method("cacheHit");

        $strategy
            ->expects($this->never())
            ->method("flushAllHits");

        $this->assertCount(2, $strategy->getHitsPoolQueue());
        Round::$returnValue = FlagshipConstant::DEFAULT_HIT_CACHE_TIME_MS;
        $strategy->sendBatch();
    }


    public function testFlushHits()
    {
        $config = new DecisionApiConfig();

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $hitCacheImplementationMock = $this->getMockForAbstractClass("Flagship\Cache\IHitCacheImplementation");

        $config->setHitCacheImplementation($hitCacheImplementationMock);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["logDebugSprintf","cacheHit"]
        );

        $keyToRemove = ["key1","key2","key3"];

        $strategy->expects($this->once())->method("logDebugSprintf")
            ->with(
                $config,
                FlagshipConstant::PROCESS_CACHE,
                FlagshipConstant::HIT_DATA_FLUSHED,
                [$keyToRemove]
            );

        $hitCacheImplementationMock->expects($this->exactly(1))
            ->method("flushHits")->with($keyToRemove);

        $strategy->flushHits($keyToRemove);

        $config->setHitCacheImplementation(null);
        $strategy->flushHits($keyToRemove);
    }

    public function testFlushHitsFailed()
    {
        $config = new DecisionApiConfig();

        $httpClientMock = $this->getMockForAbstractClass(
            'Flagship\Utils\HttpClientInterface',
            ['post'],
            '',
            false
        );

        $hitCacheImplementationMock = $this->getMockForAbstractClass(
            "Flagship\Cache\IHitCacheImplementation",
            ["flushHits"],
            '',
            false
        );

        $config->setHitCacheImplementation($hitCacheImplementationMock);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["logErrorSprintf"]
        );

        $keyToRemove = ["key1","key2","key3"];

        $exception = new Exception("flushHits error");

        $strategy->expects($this->once())->method("logErrorSprintf")
            ->with(
                $config,
                FlagshipConstant::PROCESS_CACHE,
                FlagshipConstant::HIT_CACHE_ERROR,
                ["flushHits", $exception->getMessage()]
            );

        $hitCacheImplementationMock->expects($this->exactly(1))
            ->method("flushHits")->with($keyToRemove)
            ->willThrowException($exception);

        $strategy->flushHits($keyToRemove);
    }


    public function testFlushAllHits()
    {
        $config = new DecisionApiConfig();

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $hitCacheImplementationMock = $this->getMockForAbstractClass("Flagship\Cache\IHitCacheImplementation");

        $config->setHitCacheImplementation($hitCacheImplementationMock);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["logDebugSprintf"]
        );

        $strategy->expects($this->once())->method("logDebugSprintf")
            ->with(
                $config,
                FlagshipConstant::PROCESS_CACHE,
                FlagshipConstant::ALL_HITS_FLUSHED
            );

        $hitCacheImplementationMock->expects($this->exactly(1))
            ->method("flushAllHits");

        $strategy->flushAllHits();

        $config->setHitCacheImplementation(null);
        $strategy->flushAllHits();
    }

    public function testFlushAllHitsFailed()
    {
        $config = new DecisionApiConfig();

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $hitCacheImplementationMock = $this->getMockForAbstractClass("Flagship\Cache\IHitCacheImplementation");

        $config->setHitCacheImplementation($hitCacheImplementationMock);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["logErrorSprintf"]
        );

        $exception = new Exception("flushHits error");

        $strategy->expects($this->exactly(1))->method('logErrorSprintf')
            ->with(
                $config,
                FlagshipConstant::PROCESS_CACHE,
                FlagshipConstant::HIT_CACHE_ERROR,
                ["flushAllHits", $exception->getMessage()]
            );

        $hitCacheImplementationMock->expects($this->exactly(1))
            ->method("flushAllHits")->willThrowException($exception);

        $strategy->flushAllHits();
    }

    public function testCacheHit()
    {
        $config = new DecisionApiConfig();

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $hitCacheImplementationMock = $this->getMockForAbstractClass("Flagship\Cache\IHitCacheImplementation");

        $config->setHitCacheImplementation($hitCacheImplementationMock);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["logDebugSprintf"]
        );

        $visitorId = "visitorId";
        $key = "$visitorId:key";
        $activate = new Activate("varGrid", "varId");
        $activate->setVisitorId($visitorId)->setConfig($config);
        $activate->setKey($key);

        $hitData = [
            HitCacheFields::VERSION => 1,
            HitCacheFields::DATA => [
                HitCacheFields::VISITOR_ID => $activate->getVisitorId(),
                HitCacheFields::ANONYMOUS_ID => $activate->getAnonymousId(),
                HitCacheFields::TYPE => $activate->getType(),
                HitCacheFields::CONTENT => $activate->toArray(),
                HitCacheFields::TIME => 0
            ]
        ];

        $data = [];
        $data[$key] = $hitData;

        $strategy->expects($this->once())->method("logDebugSprintf")
            ->with(
                $config,
                FlagshipConstant::PROCESS_CACHE,
                FlagshipConstant::HIT_CACHE_SAVED,
                [$data]
            );

        $hitCacheImplementationMock->expects($this->exactly(1))
            ->method("cacheHit")->with($data);

        $strategy->cacheHit([$activate]);

        $config->setHitCacheImplementation(null);
        $strategy->cacheHit([$activate]);
    }

    public function testCacheHitFailed()
    {
        $config = new DecisionApiConfig();

        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface');

        $hitCacheImplementationMock = $this->getMockForAbstractClass("Flagship\Cache\IHitCacheImplementation");

        $config->setHitCacheImplementation($hitCacheImplementationMock);

        $strategy = $this->getMockForAbstractClass(
            "Flagship\Api\BatchingOnFailedCachingStrategy",
            [$config, $httpClientMock],
            "",
            true,
            true,
            true,
            ["logErrorSprintf"]
        );

        $visitorId = "visitorId";
        $key = "$visitorId:key";
        $activate = new Activate("varGrid", "varId");
        $activate->setVisitorId($visitorId)->setConfig($config);
        $activate->setKey($key);

        $hitData = [
            HitCacheFields::VERSION => 1,
            HitCacheFields::DATA => [
                HitCacheFields::VISITOR_ID => $activate->getVisitorId(),
                HitCacheFields::ANONYMOUS_ID => $activate->getAnonymousId(),
                HitCacheFields::TYPE => $activate->getType(),
                HitCacheFields::CONTENT => $activate->toArray(),
                HitCacheFields::TIME => 0
            ]
        ];

        $data = [];
        $data[$key] = $hitData;

        $exception = new Exception("Cache error");

        $strategy->expects($this->once())->method("logErrorSprintf")
            ->with(
                $config,
                FlagshipConstant::PROCESS_CACHE,
                FlagshipConstant::HIT_CACHE_ERROR,
                ["cacheHit", $exception->getMessage()]
            );

        $hitCacheImplementationMock->expects($this->exactly(1))
            ->method("cacheHit")->with($data)->willThrowException($exception);

        $strategy->cacheHit([$activate]);
    }
}
