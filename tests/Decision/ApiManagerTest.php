<?php

namespace Flagship\Decision;

use Exception;
use Flagship\Config\DecisionApiConfig;
use Flagship\Enum\FlagshipConstant;
use Flagship\Enum\FlagshipStatus;
use Flagship\Model\HttpResponse;
use Flagship\Utils\ConfigManager;
use Flagship\Utils\Container;
use Flagship\Utils\HttpClient;
use Flagship\Visitor\VisitorDelegate;
use PHPUnit\Framework\TestCase;

class ApiManagerTest extends TestCase
{
    public function testConstruct()
    {
        $httpClient = new HttpClient();
        $config = new DecisionApiConfig();
        $apiManager = new ApiManager($httpClient, $config);
        $this->assertSame($httpClient, $apiManager->getHttpClient());
        $this->assertSame($config, $apiManager->getConfig());
        $this->assertFalse($apiManager->getIsPanicMode());
        $apiManager->setIsPanicMode(true);
        $this->assertTrue($apiManager->getIsPanicMode());
    }

    public function testGetCampaignModifications()
    {
        $httpClientMock = $this->getMockForAbstractClass(
            'Flagship\Utils\HttpClientInterface',
            ['post'],
            "",
            false
        );

        $trackingManager = $this->getMockForAbstractClass(
            'Flagship\Api\TrackingManagerAbstract',
            ['sendConsentHit'],
            "",
            false
        );
        $modificationValue1 = [
            "background" => "bleu ciel",
            "btnColor" => "#EE3300",
            "borderColor" => null, //test modification null
            'isVip' => false, //test modification false
            'firstConnect' => true
        ];
        $modificationValue2 = [
            "key" => "variation 2",
            "key2" => 1,
            "key3" => 3,
            "key4" => 4,
            "key5" => '' //test modification empty
        ];
        $modificationValue3 = [
            'key' => 'variation 3',
            'key2' => 3
        ];

        $mergeModification = array_merge($modificationValue1, $modificationValue2);

        $campaigns = [
            [
                "id" => "c1e3t1nvfu1ncqfcdco0",
                "variationGroupId" => "c1e3t1nvfu1ncqfcdcp0",
                "variation" => [
                    "id" => "c1e3t1nvfu1ncqfcdcq0",
                    "modifications" => [
                        "type" => "FLAG",
                        "value" => $modificationValue1
                    ],
                    "reference" => false]
            ],
            [
                "id" => "c20j8bk3fk9hdphqtd1g",
                "variationGroupId" => "c20j8bk3fk9hdphqtd2g",
                "variation" => [
                    "id" => "c20j9lgbcahhf2mvhbf0",
                    "modifications" => [
                        "type" => "JSON",
                        "value" => $modificationValue2
                    ],
                    "reference" => true
                ]
            ],
            [
                "id" => "c20j8bksdfk9hdphqtd1g",
                "variationGroupId" => "c2sf8bk3fk9hdphqtd2g",
                "variation" => [
                    "id" => "c20j9lrfcahhf2mvhbf0",
                    "modifications" => [
                        "type" => "JSON",
                        "value" => $modificationValue3
                    ],
                    "reference" => true
                ]
            ]
        ];

        $visitorId = "visitorId";
        $body = [
            "visitorId" => $visitorId,
            "campaigns" => $campaigns
        ];

        $httpPost = $httpClientMock->expects($this->exactly(2))
            ->method('post')
            ->willReturn(new HttpResponse(204, $body));

        $config = new DecisionApiConfig();
        $manager = new ApiManager($httpClientMock, $config);

        $statusCallback = function ($status) {
            // test status change
            $this->assertSame(FlagshipStatus::READY, $status);
        };

        $manager->setStatusChangedCallback($statusCallback);
        $configManager = (new ConfigManager())->setConfig($config)->setTrackingManager($trackingManager);

        $visitor = new VisitorDelegate(new Container(), $configManager, $visitorId, false, [], true);

        $postData = [
            "visitorId" => $visitor->getVisitorId(),
            "anonymousId" => $visitor->getAnonymousId(),
            "trigger_hit" => false,
            "context" => count($visitor->getContext()) > 0 ? $visitor->getContext() : null,
            "visitor_consent" => $visitor->hasConsented()
        ];

        $url = FlagshipConstant::BASE_API_URL . '/' . $config->getEnvId() . '/' . FlagshipConstant::URL_CAMPAIGNS;

        $query = [
            FlagshipConstant::EXPOSE_ALL_KEYS => "true",
        ];

        $httpPost->withConsecutive(
            [
                $url, [FlagshipConstant::EXPOSE_ALL_KEYS => "true"], $postData
            ],
            [
                $url, [FlagshipConstant::EXPOSE_ALL_KEYS => "true"], [
                "visitorId" => $visitor->getVisitorId(),
                "anonymousId" => $visitor->getAnonymousId(),
                "trigger_hit" => false,
                "context" => count($visitor->getContext()) > 0 ? $visitor->getContext() : null,
                "visitor_consent" => false
                ]
            ]
        );


        $modifications = $manager->getCampaignModifications($visitor);

        //Test duplicate keys are overwritten
        $this->assertCount(count($mergeModification), $modifications);

        $this->assertSame($modificationValue2['key3'], $modifications[7]->getValue());
        $this->assertSame($mergeModification['background'], $modifications[0]->getValue());

        //Test campaignId
        $this->assertSame($campaigns[0]['id'], $modifications[2]->getCampaignId());

        //Test Variation group
        $this->assertSame($campaigns[2]['variationGroupId'], $modifications[5]->getVariationGroupId());

        //Test Variation
        $this->assertSame($campaigns[2]['variation']['id'], $modifications[6]->getVariationId());

        //Test reference
        $this->assertSame($campaigns[2]['variation']['reference'], $modifications[6]->getIsReference());

        // Test with consent = false
        $visitor->setConsent(false);
        $manager->getCampaignModifications($visitor);
    }

    public function testGetCampaignModificationsWithPanicMode()
    {
        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface', ['post'], "", false);

        $visitorId = "visitorId";
        $body = [
            "visitorId" => $visitorId,
            "campaigns" => [],
            "panic" => true
        ];

        $httpClientMock->method('post')->willReturn(new HttpResponse(204, $body));

        $config = new DecisionApiConfig();
        $manager = new ApiManager($httpClientMock, $config);

        $statusCallback = function ($status) {
            echo $status;
        };

        $manager->setStatusChangedCallback($statusCallback);

        $this->assertFalse($manager->getIsPanicMode());
        $configManager = (new ConfigManager())->setConfig($config);

        $visitor = new VisitorDelegate(new Container(), $configManager, $visitorId, false, [], true);

        //Test Change Status to FlagshipStatus::READY_PANIC_ON
        $this->expectOutputString((string)FlagshipStatus::READY_PANIC_ON);
        $modifications = $manager->getCampaignModifications($visitor);

        $this->assertTrue($manager->getIsPanicMode());

        $this->assertSame([], $modifications);
    }

    public function testGetCampaignModificationsWithSomeFailed()
    {
        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface', ['post'], "", false);

        $modificationValue = [
            "background" => "bleu ciel",
            "btnColor" => "#EE3300",
            "borderColor" => null,
            'isVip' => false,
            'firstConnect' => true,
            '' => 'hello world' //Test with invalid key
        ];


        $campaigns = [
            [
                "id" => "c1e3t1nvfu1ncqfcdco0",
                "variationGroupId" => "c1e3t1nvfu1ncqfcdcp0",
                "variation" => [
                    "id" => "c1e3t1nvfu1ncqfcdcq0",
                    "modifications" => [ //Test modification without Value
                        "type" => "FLAG",
                    ],
                    "reference" => false]
            ],
            [
                "id" => "c20j8bk3fk9hdphqtd1g",
                "variationGroupId" => "c20j8bk3fk9hdphqtd2g",
                "variation" => [ //Test Variation without modification
                    "id" => "c20j9lgbcahhf2mvhbf0",
                    "reference" => true
                ]
            ],
            [ // Test Campaign without variation
                "id" => "c20j8bksdfk9hdphqtd1g",
                "variationGroupId" => "c2sf8bk3fk9hdphqtd2g",

            ],
            [
                "id" => "c20j8bksdfk9hdphqtd1g",
                "variationGroupId" => "c2sf8bk3fk9hdphqtd2g",
                "variation" => [
                    "id" => "c20j9lrfcahhf2mvhbf0",
                    "modifications" => [
                        "type" => "JSON",
                        "value" => $modificationValue
                    ],
                    "reference" => true
                ]
            ]
        ];

        $visitorId = "visitorId";
        $body = [
            "visitorId" => $visitorId,
            "campaigns" => $campaigns
        ];

        $httpClientMock->method('post')->willReturn(new HttpResponse(204, $body));

        $config = new DecisionApiConfig();
        $manager = new ApiManager($httpClientMock, $config);
        $configManager = (new ConfigManager())->setConfig($config);

        $visitor = new VisitorDelegate(new Container(), $configManager, $visitorId, false, [], true);

        $modifications = $manager->getCampaignModifications($visitor);

        $this->assertCount(count($modificationValue) - 1, $modifications);
    }

    public function testGetCampaignThrowException()
    {
        //Mock logManger
        $logManagerStub = $this->getMockForAbstractClass(
            'Psr\Log\LoggerInterface',
            ['error'],
            '',
            false
        );

        //Mock class Curl
        $httpClientMock = $this->getMockForAbstractClass(
            'Flagship\Utils\HttpClientInterface',
            ['post'],
            '',
            false
        );

        //Mock Track
        $trackerManager = $this->getMockForAbstractClass(
            'Flagship\Api\TrackingManagerAbstract',
            ['sendHit'],
            '',
            false
        );

        //Mock method curl->post to throw Exception
        $flagshipSdk = FlagshipConstant::FLAGSHIP_SDK;
        $errorMessage = "{'message': 'Forbidden'}";
        $httpClientMock->method('post')
            ->willThrowException(new Exception($errorMessage, 403));

        $config = new DecisionApiConfig("env_id", "api_key");


        $config->setLogManager($logManagerStub);
        $configManager = new ConfigManager();
        $configManager->setConfig($config)->setTrackingManager($trackerManager);

        $apiManager = new ApiManager($httpClientMock, $config);

        $visitor = new VisitorDelegate(new Container(), $configManager, 'visitor_id', false, ['age' => 15], true);

        $logManagerStub->expects($this->once())->method('error')
            ->withConsecutive(
                [ $errorMessage]
            );

        $value = $apiManager->getCampaigns($visitor);

        $this->assertNull($value);
    }
}
