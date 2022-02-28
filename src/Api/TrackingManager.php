<?php

namespace Flagship\Api;

use Exception;
use Flagship\Config\FlagshipConfig;
use Flagship\Enum\EventCategory;
use Flagship\Enum\FlagshipConstant;
use Flagship\Enum\HitType;
use Flagship\Hit\HitAbstract;
use Flagship\Model\FlagDTO;
use Flagship\Traits\BuildApiTrait;
use Flagship\Traits\LogTrait;
use Flagship\Visitor\VisitorAbstract;

/**
 * Class TrackingManager
 * @package Flagship\Api
 */
class TrackingManager extends TrackingManagerAbstract
{
    use LogTrait;
    use BuildApiTrait;

    protected function buildActivateBody($postData, $visitorId, $anonymousId)
    {
        if ($visitorId && $anonymousId) {
            $postData[FlagshipConstant::VISITOR_ID_API_ITEM] = $visitorId ;
            $postData[FlagshipConstant::ANONYMOUS_ID] = $anonymousId;
        } else {
            $postData[FlagshipConstant::VISITOR_ID_API_ITEM] = $visitorId ?: $anonymousId;
            $postData[FlagshipConstant::ANONYMOUS_ID] = null;
        }
        return $postData;
    }
    /**
     * @inheritDoc
     */
    public function sendActive(VisitorAbstract $visitor, FlagDTO $modification)
    {
        try {
            $headers = $this->buildHeader($visitor->getConfig()->getApiKey());
            $this->httpClient->setHeaders($headers);
            $this->httpClient->setTimeout($visitor->getConfig()->getTimeout() / 1000);
            $url = $this->buildDecisionApiUrl(FlagshipConstant::URL_ACTIVATE_MODIFICATION);
            $postData = [
                FlagshipConstant::VISITOR_ID_API_ITEM => $visitor->getVisitorId(),
                FlagshipConstant::VARIATION_ID_API_ITEM => $modification->getVariationId(),
                FlagshipConstant::VARIATION_GROUP_ID_API_ITEM => $modification->getVariationGroupId(),
                FlagshipConstant::CUSTOMER_ENV_ID_API_ITEM => $visitor->getConfig()->getEnvId()
            ];

            $postData = $this->buildActivateBody(
                $postData,
                $visitor->getVisitorId(),
                $visitor->getAnonymousId()
            );

            $bodyArg = escapeshellarg(json_encode($postData));
            $headersArg = escapeshellarg(json_encode($headers));
            $timeoutArg = $visitor->getConfig()->getTimeout();
            $args = " --url=$url";
            $args .= " --body=$bodyArg";
            $args .= " --header=$headersArg";
            $args .= " --timeout=$timeoutArg";

            shell_exec("php " . __DIR__ . "/MakeRequest.php $args 2>&1 | tee -a ./mylog 2>/dev/null >/dev/null &");

//            $response = $this->httpClient->post($url, [], $postData);
            return true;
        } catch (Exception $exception) {
            $this->logError($visitor->getConfig(), $exception->getMessage(), [FlagshipConstant::TAG => __FUNCTION__]);
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function sendHit(HitAbstract $hit)
    {
        try {
            $headers = $this->buildHeader($hit->getConfig()->getApiKey());
            $this->httpClient->setHeaders($headers);
            $this->httpClient->setTimeout($hit->getConfig()->getTimeOut() / 1000);
            $url = FlagshipConstant::HIT_API_URL;
            $this->httpClient->post($url, [], $hit->toArray());
        } catch (Exception $exception) {
            $this->logError($hit->getConfig(), $exception->getMessage(), [FlagshipConstant::TAG => __FUNCTION__]);
        }
    }

    public function sendConsentHit(VisitorAbstract $visitor, FlagshipConfig $config)
    {
        try {
            $headers = $this->buildHeader($config->getApiKey());
            $this->httpClient->setHeaders($headers);
            $this->httpClient->setTimeout($config->getTimeOut() / 1000);
            $url = FlagshipConstant::HIT_CONSENT_URL;
            $postBody = [
                FlagshipConstant::T_API_ITEM => HitType::EVENT,
                FlagshipConstant::EVENT_LABEL_API_ITEM =>
                    FlagshipConstant::SDK_LANGUAGE . ":" . ($visitor->hasConsented() ? "true" : "false"),
                FlagshipConstant::EVENT_ACTION_API_ITEM => "fs_content",
                FlagshipConstant::EVENT_CATEGORY_API_ITEM => EventCategory::USER_ENGAGEMENT,
                FlagshipConstant::CUSTOMER_ENV_ID_API_ITEM => $config->getEnvId(),
                FlagshipConstant::DS_API_ITEM => FlagshipConstant::SDK_APP
            ];

            if ($visitor->getVisitorId() && $visitor->getAnonymousId()) {
                $postBody[FlagshipConstant::VISITOR_ID_API_ITEM] = $visitor->getAnonymousId();
                $postBody[FlagshipConstant::CUSTOMER_UID] = $visitor->getVisitorId();
            } else {
                $postBody[FlagshipConstant::VISITOR_ID_API_ITEM] =
                    $visitor->getVisitorId() ?: $visitor->getAnonymousId();
                $postBody[FlagshipConstant::CUSTOMER_UID] = null;
            }
            $this->httpClient->post($url, [], $postBody);
        } catch (Exception $exception) {
            $this->logError($config, $exception->getMessage(), [FlagshipConstant::TAG => __FUNCTION__]);
        }
    }
}
