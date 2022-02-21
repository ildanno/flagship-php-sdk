<?php

namespace Flagship\Visitor;

use Flagship\Config\DecisionApiConfig;
use Flagship\Enum\FlagshipConstant;
use Flagship\Enum\FlagshipStatus;
use Flagship\Flag\FlagMetadata;
use Flagship\Hit\Page;
use Flagship\Utils\ConfigManager;
use Flagship\Utils\Container;
use PHPUnit\Framework\TestCase;

class PanicStrategyTest extends TestCase
{
    public function testMethods()
    {
        $apiManagerStub = $this->getMockForAbstractClass(
            'Flagship\Decision\DecisionManagerAbstract',
            [],
            'ApiManagerInterface',
            false,
            true,
            true,
            ['getCampaignModifications', 'getConfig']
        );

        $logManagerStub = $this->getMockForAbstractClass(
            'Psr\Log\LoggerInterface',
            [],
            "",
            true,
            true,
            true,
            ['error']
        );

        $trackerManager = $this->getMockForAbstractClass(
            'Flagship\Api\TrackingManagerAbstract',
            ['sendConsentHit'],
            '',
            false
        );

        $config = new DecisionApiConfig('envId', 'apiKey');
        $config->setLogManager($logManagerStub);

        $logMessageBuild = function ($functionName) {
            $flagshipSdk = FlagshipConstant::FLAGSHIP_SDK;
            return ["[$flagshipSdk] " . sprintf(
                FlagshipConstant::METHOD_DEACTIVATED_ERROR,
                $functionName,
                FlagshipStatus::getStatusName(FlagshipStatus::READY_PANIC_ON)
            ),
            [FlagshipConstant::TAG => $functionName]];
        };

        $logMessageBuildConsent = function ($functionName) {
            $flagshipSdk = FlagshipConstant::FLAGSHIP_SDK;
            return [
                "[$flagshipSdk] " . sprintf(
                    FlagshipConstant::METHOD_DEACTIVATED_SEND_CONSENT_ERROR,
                    FlagshipStatus::getStatusName(FlagshipStatus::READY_PANIC_ON)
                ),
                [FlagshipConstant::TAG => $functionName]];
        };

        $logManagerStub->expects($this->exactly(11))->method('error')
            ->withConsecutive(
                $logMessageBuild('updateContext'),
                $logMessageBuild('updateContextCollection'),
                $logMessageBuild('clearContext'),
                $logMessageBuild('getModification'),
                $logMessageBuild('getModificationInfo'),
                $logMessageBuild('activateModification'),
                $logMessageBuild('sendHit'),
                $logMessageBuildConsent('setConsent'),
                $logMessageBuild('getFlagValue'),
                $logMessageBuild('userExposed'),
                $logMessageBuild('getFlagMetadata')
            );

        $apiManagerStub->expects($this->once())->method('getCampaignModifications');

        $configManager = (new ConfigManager())->setConfig($config);
        $configManager->setDecisionManager($apiManagerStub)->setTrackingManager($trackerManager);

        $visitor = new VisitorDelegate(new Container(), $configManager, "visitorId", false, [], true);

        $panicStrategy = new PanicStrategy($visitor);

        //Test updateContext
        $key = "key";
        $value = "value";
        $panicStrategy->updateContext($key, $value);

        //Test updateContextCollection
        $panicStrategy->updateContextCollection([]);

        //Test clearContext
        $panicStrategy->clearContext();

        //Test synchronizedModifications
        $panicStrategy->synchronizeModifications();

        //Test getModification
        $defaultValue = "defaultValue";
        $valueOutput = $panicStrategy->getModification('key', $defaultValue);

        $this->assertSame($valueOutput, $defaultValue);

        //Test getModificationInfo
        $valueOutput = $panicStrategy->getModificationInfo('key');
        $this->assertNull($valueOutput);

        //Test activateModification
        $panicStrategy->activateModification('key');

        //Test sendHit
        $panicStrategy->sendHit(new Page('http://localhost'));

        //Test setConsent
        $panicStrategy->setConsent(true);
        $this->assertSame(true, $visitor->hasConsented());

        //Test getFlagValue
        $value = $panicStrategy->getFlagValue('key', true);
        $this->assertEquals(true, $value);

        //Test userExposed
        $panicStrategy->userExposed('key', true, null);

        //Test getFlagMetadata
        $panicStrategy->getFlagMetadata('key', FlagMetadata::getEmpty(), true);
    }
}
