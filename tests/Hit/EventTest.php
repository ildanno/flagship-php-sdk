<?php

namespace Flagship\Hit;

use Flagship\Enum\EventCategory;
use Flagship\Enum\FlagshipConstant;
use Flagship\Enum\HitType;
use Flagship\FlagshipConfig;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testConstruct()
    {

        $visitorId = "visitorId";
        $envId = "envId";

        $eventAction = "eventAction";
        $eventCategory = EventCategory::USER_ENGAGEMENT;
        $eventLabel = "eventLabel";
        $eventValue = 458;

        $eventArray = [
            FlagshipConstant::VISITOR_ID_API_ITEM => $visitorId,
            FlagshipConstant::DS_API_ITEM => FlagshipConstant::SDK_APP,
            FlagshipConstant::CUSTOMER_ENV_ID_API_ITEM => $envId,
            FlagshipConstant::T_API_ITEM => HitType::EVENT,
            FlagshipConstant::EVENT_CATEGORY_API_ITEM => $eventCategory,
            FlagshipConstant::EVENT_ACTION_API_ITEM => $eventAction,

        //            FlagshipConstant::EVENT_LABEL_API_ITEM=>$eventLabel,
        //            FlagshipConstant::EVENT_VALUE_API_ITEM =>$eventValue
        ];

        $event = new Event($eventCategory, $eventAction);
        $config = new FlagshipConfig();
        $config->setEnvId($envId);
        $event->setConfig($config)
            ->setVisitorId($visitorId)
            ->setDs(FlagshipConstant::SDK_APP);

        $this->assertSame($eventArray, $event->toArray());

        $event->setEventLabel($eventLabel);
        $eventArray[FlagshipConstant::EVENT_LABEL_API_ITEM] = $eventLabel;

        $this->assertSame($eventArray, $event->toArray());

        $event->setEventValue($eventValue);
        $eventArray[FlagshipConstant::EVENT_VALUE_API_ITEM] = $eventValue;

        $this->assertSame($eventArray, $event->toArray());

        $logManagerMock = $this->getMockForAbstractClass(
            'Psr\Log\LoggerInterface',
            [],
            "",
            true,
            true,
            true,
            ['error']
        );

        $event->getConfig()->setLogManager($logManagerMock);

        $flagshipSdk = FlagshipConstant::FLAGSHIP_SDK;
        $errorMessage = function ($itemName, $typeName) use ($flagshipSdk) {

            return "[$flagshipSdk] " . sprintf(FlagshipConstant::TYPE_ERROR, $itemName, $typeName);
        };

        $logManagerMock->expects($this->exactly(4))->method('error')
            ->withConsecutive(
                ["[$flagshipSdk] " . sprintf(Event::CATEGORY_ERROR, 'category')],
                [$errorMessage('action', 'string')],
                [$errorMessage('eventLabel', 'string')],
                [$errorMessage('eventValue', 'numeric')]
            );

        //Test category validation with empty
        $event->setCategory('');

        //Test category validation with no string
        $event->setAction(455);

        //Test label validation with no string
        $event->setEventLabel([]);

        //Test value validation with no numeric
        $event->setEventValue('abc');

        $this->assertSame($eventArray, $event->toArray());
    }

    public function testSetCategory()
    {
        $eventAction = 'action';
        $event = new Event(EventCategory::ACTION_TRACKING, $eventAction);
        $event->setConfig(new FlagshipConfig());
        $this->assertSame(EventCategory::ACTION_TRACKING, $event->getCategory());

        $event->setCategory(EventCategory::USER_ENGAGEMENT);

        $this->assertSame(EventCategory::USER_ENGAGEMENT, $event->getCategory());

        $event->setCategory("otherCat");

        $this->assertSame(EventCategory::USER_ENGAGEMENT, $event->getCategory());
    }

    public function testIsReady()
    {
        //Test isReady without require HitAbstract fields
        $eventCategory = EventCategory::USER_ENGAGEMENT;
        $eventAction = "eventAction";
        $event = new Event($eventCategory, $eventAction);

        $this->assertFalse($event->isReady());

        //Test with require HitAbstract fields and with null eventCategory
        $eventCategory = null;
        $eventAction = "eventAction";
        $event = new Event($eventCategory, $eventAction);
        $config = new FlagshipConfig("envId");
        $event->setConfig($config)
            ->setVisitorId('visitorId')
            ->setDs(FlagshipConstant::SDK_APP);

        $this->assertFalse($event->isReady());

        //Test isReady with require HitAbstract fields and  with empty eventAction
        $eventAction = "";
        $eventCategory = EventCategory::ACTION_TRACKING;
        $event = new Event($eventCategory, $eventAction);

        $event->setConfig($config)
            ->setVisitorId('visitorId')
            ->setDs(FlagshipConstant::SDK_APP);

        $this->assertFalse($event->isReady());

        $this->assertSame(Event::ERROR_MESSAGE, $event->getErrorMessage());

        //Test with require HitAbstract fields and require Transaction fields
        $eventCategory = EventCategory::ACTION_TRACKING;
        $eventAction = "ItemName";
        $event = new Event($eventCategory, $eventAction);
        $event->setConfig($config)
            ->setVisitorId('visitorId')
            ->setDs(FlagshipConstant::SDK_APP);

        $this->assertTrue($event->isReady());
    }
}
