<?php

namespace Flagship\Decision;

use Exception;
use Flagship\FlagshipConfig;
use Flagship\Model\HttpResponse;
use Flagship\Utils\HttpClient;
use Flagship\Visitor;
use PHPUnit\Framework\TestCase;

class ApiManagerTest extends TestCase
{
    public function testConstruct()
    {
        $httpClient = new HttpClient();
        $apiManager = new ApiManager($httpClient);
        $this->assertSame($httpClient, $apiManager->getHttpClient());
    }

    public function testGetModifications()
    {
        $httpClientMock = $this->getMockForAbstractClass('Flagship\Utils\HttpClientInterface', ['post'], "", false);
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

        $manager = new ApiManager($httpClientMock);

        $modifications = $manager->getModifications($campaigns);


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
    }

    public function testGetModificationsWithSomeFailed()
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

        $manager = new ApiManager($httpClientMock);

        $modifications = $manager->getModifications($campaigns);

        $this->assertCount(count($modificationValue) - 1, $modifications);
    }

    public function testGetCampaigns()
    {
        $httpClientMock = $this->getMockForAbstractClass(
            'Flagship\Utils\HttpClientInterface',
            ['post'],
            '',
            false
        );

        $visitorId = 'visitor_id';

        $campaigns = [
            [
                "id" => "c1e3t1nvfu1ncqfcdco0",
                "variationGroupId" => "c1e3t1nvfu1ncqfcdcp0",
                "variation" => [
                    "id" => "c1e3t1nvfu1ncqfcdcq0",
                    "modifications" => [
                        "type" => "JSON",
                        "value" => [
                            "btnColor" => "green"
                        ]
                    ],
                    "reference" => false]
            ]
        ];
        $body = [
            "visitorId" => $visitorId,
            "campaigns" => $campaigns
        ];

        $result = new HttpResponse(200, $body);

        $httpClientMock->method('post')
            ->willReturn($result);
        $config = new FlagshipConfig("env_id", "api_key");

        $manager = new ApiManager($httpClientMock);

        $visitor = new Visitor($config, $visitorId, ['age' => 15]);

        $value = $manager->getCampaigns($visitor);
        $this->assertSame($campaigns, $value);
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
        ;

        //Mock method curl->post to throw Exception
        $errorMessage = '{"message": "Forbidden"}';
        $httpClientMock->method('post')
            ->willThrowException(new Exception($errorMessage, 403));

        $config = new FlagshipConfig("env_id", "api_key");
        $logManagerStub->expects($this->once())->method('error')->withConsecutive(
            [$errorMessage]
        );

        $config->setLogManager($logManagerStub);

        $apiManager = new ApiManager($httpClientMock);

        $visitor = new Visitor($config, 'visitor_id', ['age' => 15]);
        $value = $apiManager->getCampaigns($visitor);

        $this->assertSame([], $value);
    }
}
