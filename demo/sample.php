<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Flagship\Flagship;
use Flagship\Hit\Event;
use Flagship\Enum\EventCategory;

//Start Flagship SDK with the environment id and api key available in your account settings.
Flagship::start("ENV_ID", "API_KEY");

//Once Flagship SDK status is READY you can instantiate a flagship visitor.
$visitor = Flagship::newVisitor("visitor_123")
    ->withContext(["qa_report" => true,'is_php'=> true]) //Set visitor attributes so he will be targeted by your campaigns.
    ->build();

//fetchFlag will call the Flagship decision api and return the campaigns assignations according to the targeting.
$visitor->fetchFlags();

//Once fetchFlag is done, the visitor instance will contain all the assigned campaigns variation flags key/values.
$flag = $visitor->getFlag('qa_report_var', 'default_title');

echo "value :" . $flag->getValue() . PHP_EOL;

//You can display the title to the user in your app. Once displayed don't forget to call the method userExposed() so it be counted in the reporting.
//$flag->userExposed();

//Later if you want to validate an objective when a user click on the feature:
$visitor->sendHit(new Event(EventCategory::USER_ENGAGEMENT, "feature_click"));

Flagship::close();
