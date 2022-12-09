<?php

namespace Flagship\Api;

use Flagship\Enum\FlagshipConstant;
use Flagship\Hit\Activate;
use Flagship\Hit\ActivateBatch;
use Flagship\Hit\HitAbstract;
use Flagship\Hit\HitBatch;

class NoBatchingContinuousCachingStrategy extends BatchingCachingStrategyAbstract
{
    protected $cacheHitKeys = [];

    public function addHit(HitAbstract $hit)
    {
        $header = [
            FlagshipConstant::HEADER_CONTENT_TYPE => FlagshipConstant::HEADER_APPLICATION_JSON
        ];

        $requestBody = $hit->toApiKeys();
        $now = $this->getNow();
        $url = FlagshipConstant::HIT_EVENT_URL;

        try {
            $this->httpClient->setTimeout($this->config->getTimeout());
            $this->httpClient->setHeaders($header);
            $this->httpClient->post($url, [], $requestBody);

            $this->logDebugSprintf($this->config, FlagshipConstant::TRACKING_MANAGER,
                FlagshipConstant::HIT_SENT_SUCCESS, [
                    FlagshipConstant::SEND_HIT,
                    $this->getLogFormat(null, $url, $requestBody, $header, $this->getNow() - $now)]);

        } catch (\Exception $exception) {

            $hitKey = $this->generateHitKey($hit->getVisitorId());
            $hit->setKey($hitKey);
            $this->cacheHitKeys[]= $hitKey;
            $this->cacheHit([$hit]);

            $this->logErrorSprintf($this->config, FlagshipConstant::TRACKING_MANAGER,
                FlagshipConstant::TRACKING_MANAGER_ERROR, [FlagshipConstant::SEND_HIT,
                    $this->getLogFormat($exception->getMessage(), $url, $requestBody, $header, $this->getNow() - $now)]);
        }
    }

    public function activateFlag(Activate $hit)
    {
        $headers = $this->getActivateHeaders();

        $activateBatch = new ActivateBatch($this->config, [$hit]);

        $requestBody = $activateBatch->toArray();

        $url = FlagshipConstant::BASE_API_URL . '/' . FlagshipConstant::URL_ACTIVATE_MODIFICATION;

        $now = $this->getNow();

        try {
            $this->httpClient->setHeaders($headers);
            $this->httpClient->setTimeout($this->config->getTimeout());

            $this->httpClient->post($url, [], $requestBody);

            $this->logDebugSprintf($this->config, FlagshipConstant::TRACKING_MANAGER,
                FlagshipConstant::HIT_SENT_SUCCESS, [
                    FlagshipConstant::SEND_ACTIVATE,
                    $this->getLogFormat(null, $url, $requestBody, $headers, $this->getNow() - $now)]);

        } catch (\Exception $exception) {

            $hitKey = $this->generateHitKey($hit->getVisitorId());
            $hit->setKey($hitKey);
            $this->cacheHitKeys[]= $hitKey;
            $this->cacheHit([$hit]);

            $this->logErrorSprintf($this->config, FlagshipConstant::TRACKING_MANAGER,
                FlagshipConstant::TRACKING_MANAGER_ERROR, [FlagshipConstant::SEND_ACTIVATE,
                    $this->getLogFormat($exception->getMessage(), $url, $requestBody, $headers, $this->getNow() - $now)]);
        }
    }

    protected function notConsent($visitorId)
    {
        $keysToFlush = $this->commonNotConsent($visitorId);
        $mergedQueue = array_merge($keysToFlush, $this->cacheHitKeys);
        if (!count($mergedQueue)){
            return;
        }
        $this->flushHits($mergedQueue);
        $this->cacheHitKeys = [];
    }

}