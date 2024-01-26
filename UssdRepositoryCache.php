<?php

namespace App\Repositories;

use App\Jobs\AddMemberToDepartment;
use App\Jobs\CheckinChildToService;
use App\Jobs\CheckinMemberToService;
use App\Jobs\CheckoutChildFromService;
use App\Jobs\ConfirmSpouseInvite;
use App\Jobs\InitiateSTKPush;
use App\Jobs\RegisterChild;
use App\Jobs\RegisterChurchMember;
use App\Jobs\RemoveChildNominee;
use App\Jobs\SaveChildNominee;
use App\Jobs\SavePrayerRequest;
use App\Jobs\SendPaymentInstructions;
use App\Jobs\SendSMS;
use App\Models\BlockTransactionalMessage;
use App\Models\Child;
use App\Models\ChurchMember;
use App\Models\CollectionAccount;
use App\Models\CollectionTransaction;
use App\Models\Department;
use App\Models\Feedback;
use App\Models\FeedbackSubCategory;
use App\Models\PendingSpouseInvitation;
use App\Models\Pledge;
use App\Models\SMSCredential;
use Carbon\Carbon;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class UssdRepositoryCache
{
    public static function process($church, $ussdSession, $ussdString, $msisdn, $userExists)
    {
        if ($ussdString === null && $userExists == 1) {
            return self::showMenuRegistered($church, $ussdSession, $ussdString);
        } elseif ($ussdString === null && $userExists == 0) {
            return self::showMenu($church, $ussdSession, $ussdString);
        }
        $data = $ussdSession['action'];
        $dataType = json_decode($data, true);
        //check if the type is choice on input
        if (in_array('choice', $dataType)) {
            $options = $dataType['options'];
            if (array_key_exists($ussdString, $options)) {
                return self::{$options[$ussdString]}($church, $ussdSession, $ussdString, $msisdn);
            }
            $modified_text = str_replace("CON ", "", $dataType['text']);
            return "CON Invalid choice\n{$modified_text}";
        }
        //check if the type is choice on input
        if (in_array('fromDB', $dataType)) {
            $values = collect($dataType['values'])->toArray();
            $options = collect($dataType['options'])->toArray();
            if (array_key_exists($ussdString, $options)) {
                return self::{$options[$ussdString]}($church, $ussdSession, $ussdString, $msisdn);
            }
            if (!in_array($ussdString, $values)) {
                $modified_text = str_replace("CON ", "", $dataType['text']);
                return "CON Invalid choice\n{$modified_text}";
            }
        }
        $options = $dataType['options'];
        if (array_key_exists($ussdString, $options)) {
            return self::{$options[$ussdString]}($church, $ussdSession, $ussdString, $msisdn);
        }
        $function = $dataType['function'];
        return self::{$function}($church, $ussdSession, $ussdString, $msisdn);
    }

    //Displays  Menu

    /**
     * This menu is only for non-registered members
     */
    public static function showMenu($church, $ussdSession, $ussdString)
    {
        Redis::set($ussdSession['msisdn'] . 'menu', 'showMenu');
        $text = "CON Welcome to " . $church['church_name'] . "\n1. Register\n2. Make Payments\n3. Checkin\n4. Pledge\n5. Contact Us\n6. Prayer Request\n7. Today's Services\n8. Send Feedback";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'showMenu',
                'text' => $text,
                'options' => [
                    '1' => 'registerMember',
                    '2' => 'makePayments',
                    '3' => 'checkinVisitorOptions',
                    '4' => 'pledge',
                    '5' => 'contactUs',
                    '6' => 'enterName',
                    '7' => 'todayServices',
                    '8' => 'feedbackCategories',
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    /**
     * This menu is only for registered members
     */
    public static function showMenuRegistered($church, $ussdSession, $ussdString)
    {
        Redis::set($ussdSession['msisdn'] . 'menu', 'showMenuRegistered');
        $text = "CON Welcome to " . $church['church_name'] . "\n1. My Account\n2. Make Payments\n3. Pledge\n4. Checkin\n5. Today's Services\n6. Contact Us\n7. Prayer Request\n8. Send Feedback";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'showMenuRegistered',
                'text' => $text,
                'options' => [
                    '1' => 'myAccount',
                    '2' => 'makePayments',
                    '3' => 'pledge',
                    '4' => 'checkinOptions',
                    '5' => 'todayServices',
                    '6' => 'contactUs',
                    '7' => 'prayerRequest',
                    '8' => 'feedbackCategories',
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    /**
     * Checkin to services starts here
     */
//    public static function checkin($church, $ussdSession, $ussdString)
//    {
////        $adhoc = ServiceSchedule::whereHas('adhoc', function ($q) use ($church) {
////            $q->where('church_id', $church->id)->where('date', Carbon::today())->where('from_time', '<', Carbon::now())->where('to_time', '>', Carbon::now());
////        });
////        $services = ServiceSchedule::whereHas('permanent', function ($q) use ($church) {
////            $q->where('church_id', $church->id)
////                ->where('day_of_the_week', Carbon::today()->format('l'))->where('from_time', '<', Carbon::now())->where('to_time', '>', Carbon::now());
////        })->whereDate('created_at', Carbon::today())->union($adhoc)->get();
//        $services = json_decode(Redis::get($church['uuid'] . 'services'), true);
//        $text = "CON Select Service To Check-in\n";
//        $values = [];
//        foreach ($services as $service) {
//            $text .= $service['id'] . ". " . $service['service']['service_name'] . "\n";
//            $values[] = $service['id'];
//        }
//        $text .= "0. Cancel";
//        $data = [
//            'msisdn' => $ussdSession['msisdn'],
//            'session_id' => $ussdSession['session_id'],
//            'ussd_string' => $ussdString,
//            'action' => json_encode([
//                'type' => 'fromDB',
//                'name' => 'makePayments',
//                'function' => 'checkinMember',
//                'text' => $text,
//                'values' => $values,
//                'options' => [
//                    '0' => 'cancel'
//                ]
//            ]),
//        ];
//        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
//        return $text;
//    }
    public static function checkinOptions($church, $ussdSession, $ussdString)
    {
        $text = "CON Check-in options\n1. Checkin\n2. Checkin Child\n3. Checkout Child\n4. Add Child Trustee\n5. Remove Child Trustee\n";
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'checkin',
                'function' => 'checkinMember',
                'text' => $text,
                'options' => [
                    '1' => 'checkin',
                    '2' => 'checkinChildOptions',
                    '3' => 'checkoutChildOptions',
                    '4' => 'childNominee',
                    '5' => 'removeChildNominee',
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function checkinVisitorOptions($church, $ussdSession, $ussdString)
    {
        $text = "CON Check-in options\n1. Checkin\n2. Checkin Child\n3. Checkout Child\n4. Add Child Trustee\n5. Remove Child Trustee\n";
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'checkin',
                'function' => 'checkinMember',
                'text' => $text,
                'options' => [
                    '1' => 'checkinVisitor',
                    '2' => 'checkinChildOptions',
                    '3' => 'checkoutChildOptions',
                    '4' => 'childNominee',
                    '5' => 'removeChildNominee',
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function removeChildNominee($church, $ussdSession, $ussdString)
    {
        $text = "CON Enter trustee's phone number\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'removeNominee',
                'name' => 'removeChildNominee',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function removeNominee($church, $ussdSession, $ussdString)
    {
        $phoneStatus = validatePhone($ussdString);
        if (!$phoneStatus['isValid']){
            return "CON Enter a valid phone\n00. Home";
        }
        $data =
            [
                'nominee' => Redis::get($ussdSession['msisdn'] . 'trusteePhone'),
                'phone' => $ussdSession['msisdn'],
                'church' => $church['uuid'],
            ];
        RemoveChildNominee::dispatch($data)
            ->onQueue('remove-nominee')
            ->onConnection('beanstalkd-worker001');
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return "END Your child trustee is being removed. You will receive an sms shortly";
    }

    public static function childNominee($church, $ussdSession, $ussdString)
    {
        $text = "CON Enter trustee's phone number\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'addNominee',
                'name' => 'childNominee',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function addNominee($church, $ussdSession, $ussdString)
    {
        $phoneStatus = validatePhone($ussdString);
        if (!$phoneStatus['isValid']){
            return "CON Enter a valid phone\n00. Home";
        }
        $text = "CON Select gender\n1. Male\n2. Female\n";
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'addNominee',
                'text' => $text,
                'options' => [
                    '1' => 'enterIdOrPassport',
                    '2' => 'enterIdOrPassport',
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        Redis::set($ussdSession['msisdn'] . 'trusteePhone', $phoneStatus['phone']);
        return $text;
    }
    public static function enterIdOrPassport($church, $ussdSession, $ussdString)
    {
        if (!$ussdString){
            return "CON Select gender\n00. Home";
        }
        $text = "CON Enter ID/Passport number\n";
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'enterIdOrPassport',
                'function' => 'saveNominee',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['msisdn'] . 'gender', $ussdString);
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function saveNominee($church, $ussdSession, $ussdString)
    {
        if (!$ussdString){
            return "CON Enter ID/Passport number\n00. Home";
        }
        $data =
            [
                'nominee' => Redis::get($ussdSession['msisdn'] . 'trusteePhone'),
                'phone' => $ussdSession['msisdn'],
                'church' => $church['uuid'],
                'id' => $ussdString,
                'gender' => Redis::get($ussdSession['msisdn'] . 'gender'),
            ];
        SaveChildNominee::dispatch($data)
            ->onQueue('save-nominee')
            ->onConnection('beanstalkd-worker001');
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return "END Your child trustee is being saved. You will receive an sms shortly";
    }

    public static function checkin($church, $ussdSession, $ussdString)
    {
        $text = "CON Enter Service Code To Check-in\n";
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'checkin',
                'function' => 'checkinMember',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }

    public static function checkinChildOptions($church, $ussdSession, $ussdString){
        $text = "CON My Children:\n";
//        $children = Child::where('parent_phone',$ussdSession['msisdn'])->where('church_id',$church['id'])->get();
        $children = json_decode(Redis::get($ussdSession['msisdn'].'children'),true);
        if ($children == null || count($children) == 0){
            $children = [];
        }
        $values = [];
        $uuids = [];//to ensure a child is not duplicated
        for ($i = 0; $i < count($children); $i ++)
        {
            if (!in_array($children[$i]['uuid'],$uuids))
            {
                $text .= $i + 1 . ". " . $children[$i]['first_name'] . " " . $children[$i]['other_names'] . "\n";
                $values[] = $i + 1;
                $uuids[] = $children[$i]['uuid'];
            }
        }
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'fromDB',
                'name' => 'checkinChildOptions',
                'function' => 'checkinChildCode',
                'text' => $text,
                'values' => $values,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function checkinChildCode($church, $ussdSession, $ussdString)
    {
        $text = "CON Enter Service Code To Check-in\n";
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'checkin',
                'function' => 'checkinChild',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        $children = json_decode(Redis::get($ussdSession['msisdn'].'children'),true);
        $child = null;
        for ($i = 0; $i < count($children); $i ++)
        {
            if ($ussdString == $i+1){
                $child = $children[$i]['uuid'];
                break;
            }
        }
        Redis::set($ussdSession['msisdn'] . 'child', $child);
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function checkinChild($church, $ussdSession, $ussdString)
    {
        $services = json_decode(Redis::get($church['uuid'] . 'services'), true);
        $values = [];
        $serviceError = '';
        $schedule_id = null;
        $error = 0;
        foreach ($services as $service) {
            if ($service['code'] && $service['code']['code'] == $ussdString){
                if (Carbon::parse(now()->format('H:i'))->greaterThanOrEqualTo(Carbon::parse($service['service']['from_time'])->format('H:i')) &&
                    Carbon::parse(now()->format('H:i'))->lessThanOrEqualTo(Carbon::parse($service['service']['to_time'])->format('H:i'))){
                    $values[] = $service['code']['code'];
                    $schedule_id = $service['id'];
                }else{
                    $error = 1;
                    if (Carbon::parse(now()->format('H:i'))->lessThan(Carbon::parse($service['service']['from_time'])->format('H:i')))
                    {
                        $serviceError = "The service has not started";
                    }elseif ( Carbon::parse(now()->format('H:i'))->greaterThan(Carbon::parse($service['service']['to_time'])->format('H:i')))
                    {
                        $serviceError = "The service has ended";
                    }
                }
            }
        }
        if (!in_array($ussdString,$values))
        {
            if ($error == 0)
            {
                return "CON Wrong Code. Enter Service Code To Check-in .\n0. Cancel\n00. Home";
            }else
            {
                return "CON ".$serviceError.".\n0. Cancel\n00. Home";
            }
        }
        $data =
            [
                'type' => 1,
                'schedule' => $schedule_id,
                'phone' => $ussdSession['msisdn'],
                'church' => $church['uuid'],
                'children' => [Redis::get($ussdSession['msisdn'] . 'child')]
            ];
        CheckinChildToService::dispatch($data)
            ->onQueue('checkin-child')
            ->onConnection('beanstalkd-worker001');
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return "END Your child is being checked in. You will receive an sms shortly";
    }

    public static function checkoutChildOptions($church, $ussdSession, $ussdString){
        $text = "CON My Children:\n";
//        $children = Child::where('parent_phone',$ussdSession['msisdn'])->where('church_id',$church['id'])->get();
        $children = json_decode(Redis::get($ussdSession['msisdn'].'children'),true);
        if ($children == null || count($children) == 0){
            $children = [];
        }
        $values = [];
        $uuids = [];//to ensure a child is not duplicated
        for ($i = 0; $i < count($children); $i ++)
        {
            if (!in_array($children[$i]['uuid'],$uuids))
            {
                $text .= $i + 1 . ". " . $children[$i]['first_name'] . " " . $children[$i]['other_names'] . "\n";
                $values[] = $i + 1;
                $uuids[] = $children[$i]['uuid'];
            }
        }
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'fromDB',
                'name' => 'checkoutChildOptions',
                'function' => 'checkoutChildCode',
                'text' => $text,
                'values' => $values,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function checkoutChildCode($church, $ussdSession, $ussdString)
    {
        $text = "CON Enter Checkout Code\n";
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'checkin',
                'function' => 'checkoutChild',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        $children = json_decode(Redis::get($ussdSession['msisdn'].'children'),true);
        $child = null;
        for ($i = 0; $i < count($children); $i ++)
        {
            if ($ussdString == $i+1){
                $child = $children[$i]['uuid'];
                break;
            }
        }
        Redis::set($ussdSession['msisdn'] . 'child', $child);
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function checkoutChild($church, $ussdSession, $ussdString)
    {
        $data =
            [
                'code' => $ussdString,
                'phone' => $ussdSession['msisdn'],
                'church' => $church['uuid'],
                'child' => Redis::get($ussdSession['msisdn'] . 'child')
            ];
        CheckoutChildFromService::dispatch($data)
            ->onQueue('checkout-child')
            ->onConnection('beanstalkd-worker001');
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return "END Your child is being checked out. You will receive an sms shortly";
    }


    public static function checkinMember($church, $ussdSession, $ussdString)
    {
        $services = json_decode(Redis::get($church['uuid'] . 'services'), true);
        $values = [];
        $serviceError = '';
        $schedule_id = null;
        $error = 0;
        foreach ($services as $service) {
            if ($service['code'] && $service['code']['code'] == $ussdString){
                if (Carbon::parse(now()->format('H:i'))->greaterThanOrEqualTo(Carbon::parse($service['service']['from_time'])->format('H:i')) &&
                    Carbon::parse(now()->format('H:i'))->lessThanOrEqualTo(Carbon::parse($service['service']['to_time'])->format('H:i'))){
                    $values[] = $service['code']['code'];
                    $schedule_id = $service['id'];
                }else{
                    $error = 1;
                    if (Carbon::parse(now()->format('H:i'))->lessThan(Carbon::parse($service['service']['from_time'])->format('H:i'))){
                        $serviceError = "The service has not started";
                    }elseif ( Carbon::parse(now()->format('H:i'))->greaterThan(Carbon::parse($service['service']['to_time'])->format('H:i'))){
                        $serviceError = "The service has ended";
                    }
                }
            }
        }
        if (!in_array($ussdString,$values)){
            if ($error == 0){
                return "CON Wrong Code. Enter Service Code To Check-in .\n0. Cancel\n00. Home";
            }else{
                return "CON ".$serviceError.".\n0. Cancel\n00. Home";
            }
        }

        $data = [
            'schedule_id' => $schedule_id,
            'phone' => $ussdSession['msisdn'],
            'church' => $church['uuid'],
            'name' => null
        ];
        CheckinMemberToService::dispatch($data)->onQueue('checkin-member')->onConnection('beanstalkd-worker001');
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return "END You have successfully checked in to the service";

    }
    //checkin visitor
    public static function checkinVisitor($church, $ussdSession, $ussdString)
    {
        $text = "CON Enter Your Name\n";
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'checkinVisitor',
                'function' => 'checkinVisitorName',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function checkinVisitorName($church, $ussdSession, $ussdString)
    {
        if (!$ussdString) {
            return "CON Invalid. Enter your name.\n00. Home";
        }
        $text = "CON Enter Service Code To Check-in\n";
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'checkinVisitorName',
                'function' => 'checkinVisitorToService',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['msisdn'] . 'visitorName', $ussdString);
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }

    public static function checkinVisitorToService($church, $ussdSession, $ussdString)
    {
        if (!$ussdString || !is_numeric($ussdString)) {
            return "CON Invalid. Enter Service Code To Check-in.\n00. Home";
        }
        $services = json_decode(Redis::get($church['uuid'] . 'services'), true);
        $values = [];
        $serviceError = '';
        $schedule_id = null;
        $error = 0;
        foreach ($services as $service) {
            if ($service['code'] && $service['code']['code'] == $ussdString){
                if (Carbon::parse(now()->format('H:i'))->greaterThanOrEqualTo(Carbon::parse($service['service']['from_time'])->format('H:i')) &&
                    Carbon::parse(now()->format('H:i'))->lessThanOrEqualTo(Carbon::parse($service['service']['to_time'])->format('H:i'))){
                    $values[] = $service['code']['code'];
                    $schedule_id = $service['id'];
                }else{
                    $error = 1;
                    if (Carbon::parse(now()->format('H:i'))->lessThan(Carbon::parse($service['service']['from_time'])->format('H:i'))){
                        $serviceError = "The service has not started";
                    }elseif ( Carbon::parse(now()->format('H:i'))->greaterThan(Carbon::parse($service['service']['to_time'])->format('H:i'))){
                        $serviceError = "The service has ended";
                    }
                }
            }
        }
        if (!in_array($ussdString,$values)){
            if ($error == 0){
                return "CON Wrong Code. Enter Service Code To Check-in .\n0. Cancel\n00. Home";
            }else{
                return "CON ".$serviceError.".\n0. Cancel\n00. Home";
            }
        }

        $data = [
            'schedule_id' => $schedule_id,
            'church' => $church['uuid'],
            'phone' => $ussdSession['msisdn'],
            'name' => Redis::get($ussdSession['msisdn'] . 'visitorName')
        ];
        CheckinMemberToService::dispatch($data)->onQueue('checkin-member')->onConnection('beanstalkd-worker001');
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return "END You have successfully checked in to the service";

    }
    //my account
    public static function myAccount($church, $ussdSession,$ussdString)
    {
        $members = json_decode(Redis::get($church['uuid'].'churchMembers'),true);
        $member = null;
        foreach ($members as $object) {
            if ($object['primary_phone'] === $ussdSession['msisdn'] || $object['alternative_phone'] === $ussdSession['msisdn']) {
                $member = $object;
                break; // Exit the loop if the phone number is found
            }
        }
        if ($member['status'] == 0){
            $text = "CON Your membership is pending approval\n00. Home";
            return $text;
        }
        $text = "CON My Account.\n1. Profile\n2. My Departments\n3. Pledge Balance\n4. My Children\n5. Spouse\n6. Stop Messages\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'myAccount',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                    '1' => 'profile',
                    '2' => 'departments',
                    '3' => 'pledgeBalances',
                    '4' => 'children',
                    '5' => 'spouse',
                    '6' => 'stopNonTransactionalMessages'
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function stopNonTransactionalMessages($church, $ussdSession, $ussdString)
    {
        if (BlockTransactionalMessage::where('church_id',$church['id'])->where('phone',$ussdSession['msisdn'])->doesntExist()){
            BlockTransactionalMessage::create([
                'phone' => $ussdSession['msisdn'],
                'church_id' => $church['id']
            ]);
            $message = "You have successfully blocked non-transactional messages\n1. Unblock";
        }else{
            $message = "You have already blocked non-transactional messages.\n1. Unblock";
        }
        $text = $message."\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'stopNonTransactionalMessages',
                'text' => $text,
                'options' => [
                    '1' => 'unblockTransactionalMessages',
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;

    }
    public static function unblockTransactionalMessages($church, $ussdSession, $ussdString)
    {
        BlockTransactionalMessage::where([
            'phone' => $ussdSession['msisdn'],
            'church_id' => $church['id']
        ])->first()->forceDelete();
        $text = "You have successfully unblocked non-transactional messages.\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'unblockTransactionalMessages',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function spouse($church, $ussdSession, $ussdString)
    {
        $text = "CON My Spouse:\n";
        $members = json_decode(Redis::get($church['uuid'].'churchMembers'),true);
        $thisMember = null;
        foreach ($members as $object)
        {
            if ($object['primary_phone'] === $ussdSession['msisdn'] || $object['alternative_phone'] === $ussdSession['msisdn'])
            {
                $thisMember = $object;
                break; // Exit the loop if the phone number is found
            }
        }
        $thisMember = json_encode($thisMember);
        $thisMember = json_decode($thisMember,true);
        $spouse = null;
        if ($thisMember['spouse_id'])
        {
            foreach ($members as $spo)
            {
                if ($spo['id'] === $thisMember['spouse_id'])
                {
                    $spouse = $spo;
                    break;
                }
            }
        }

        if ($spouse)
        {
            $spouse = json_encode($spouse);
            $spouse = json_decode($spouse,true);
            $text .= "Name: ".$spouse['first_name']." ".$spouse['middle_name']." ".$spouse['other_names']."\nPhone: ".$spouse['primary_phone']."\n";
        }
        $text .= "1. Nominate Spouse\n2. Pending Invitations\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'spouse',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                    '1' => 'nominateSpouse',
                    '2' => 'pendingSpouse',
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;

    }
    public static function pendingSpouse($church, $ussdSession, $ussdString)
    {
        $text = "CON My Pending Invitations:\n";
        $members = json_decode(Redis::get($church['uuid'].'churchMembers'),true);
        $thisMember = null;
        foreach ($members as $object) {
            if ($object['primary_phone'] === $ussdSession['msisdn'] || $object['alternative_phone'] === $ussdSession['msisdn']) {
                $thisMember = $object;
                break; // Exit the loop if the phone number is found
            }
        }
        $thisMember = json_encode($thisMember);
        $thisMember = json_decode($thisMember,true);
        $inviter = null;
        foreach (PendingSpouseInvitation::where('church_id',$church['id'])->where('invitee',$thisMember['id'])->where('status',0)->get() as $pending){
            foreach ($members as $ob) {
                if ($ob['id'] === $pending->inviter) {
                    $inviter = $ob;
                    break; // Exit the loop if the phone number is found
                }
            }
            if ($inviter) {
                $inviter = json_encode($inviter);
                $inviter = json_decode($inviter, true);
                $text .=$pending->code."-". $inviter['first_name']." ".$inviter['other_names']."(".$inviter['primary_phone'].")\n";
            }
        }
        if ($inviter){
            $text .= "Enter the invite code to accept\n";
        }
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'spouse',
                'function' => 'confirmInvite',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function confirmInvite($church, $ussdSession, $ussdString)
    {
        $members = json_decode(Redis::get($church['uuid'].'churchMembers'),true);
        $thisMember = null;
        foreach ($members as $object) {
            if ($object['primary_phone'] === $ussdSession['msisdn'] || $object['alternative_phone'] === $ussdSession['msisdn']) {
                $thisMember = $object;
                break; // Exit the loop if the phone number is found
            }
        }
        $thisMember = json_encode($thisMember);
        $thisMember = json_decode($thisMember,true);
       $pending = PendingSpouseInvitation::where('code',$ussdString)->where('invitee',$thisMember['id'])->where('status',0)->first();
       if (!$pending){
           return "CON The code you entered does not exist\n00. Home";
       }
        $text = "CON Invite accepted successfully\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'spouse',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        $dat = [
            'pending' => $pending->id,
            'type' => 1
        ];
        ConfirmSpouseInvite::dispatch($dat)->onQueue('confirm-family')->onConnection('beanstalkd-worker001');
        return $text;

    }
    public static function nominateSpouse($church, $ussdSession, $ussdString)
    {
        $text = "CON Please enter spouse's Phone Number\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'confirmSpousePhone',
                'name' => 'nominateSpouse',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function confirmSpousePhone($church, $ussdSession, $ussdString)
    {
        $phoneStatus = validatePhone($ussdString);
        if ($phoneStatus['isValid']){
            $phone = $phoneStatus['phone'];
        }else{
            return "CON Please enter a valid phone number\n00. Home";
        }
        $members = json_decode(Redis::get($church['uuid'].'churchMembers'),true);
        $thisMember = null;
        foreach ($members as $object) {
            if ($object['primary_phone'] === $ussdSession['msisdn'] || $object['alternative_phone'] === $ussdSession['msisdn']) {
                $thisMember = $object;
                break; // Exit the loop if the phone number is found
            }
        }
        $spouse = null;
        $thisMember = json_encode($thisMember);
        $thisMember = json_decode($thisMember,true);
            foreach ($members as $spo) {
                if ($spo['primary_phone'] === $phone || $spo['alternative_phone'] === $phone) {
                    $spouse = $spo;
                    break;
                }
            }
        if (!$spouse){
            return "CON The spouse you entered is not a member of ".$church['church_name']. ". Please enter spouse's Phone Number\n00. Home";
        }
        $spouse = json_encode($spouse);
        $spouse = json_decode($spouse,true);
        if ($spouse['id'] == $thisMember['spouse_id']){
            return "CON The spouse is already linked to you\n00. Home";
        }
        if ($spouse['spouse_id'] && $spouse['spouse_id'] == $thisMember['id']){
            return "CON The spouse is already linked to you\n00. Home";
        }
        if ($spouse['spouse_id']){
            return "CON The spouse is already linked to another member\n00. Home";
        }
        if (PendingSpouseInvitation::where('church_id',$church['id'])->where('inviter',$thisMember['id'])->where('invitee',$spouse['id'])->where('status',0)->exists()){
            return "CON You have already invited this spouse.\n00. Home";
        }
        if (PendingSpouseInvitation::where('church_id',$church['id'])->where('inviter',$spouse['id'])->where('invitee',$thisMember['id'])->where('status',0)->exists()){
            return "CON You have been invited by this spouse. Check pending invitations and accept\n00. Home";
        }
        $text = "CON The spouse has been invited\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'spouse',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        $code = rand(1000,9999);
        if (PendingSpouseInvitation::where('church_id',$church['id'])->where('inviter',$thisMember['id'])->where('invitee',$spouse['id'])->where('code',$code)->where('status',0)->exists()){
            $code = rand(1000,9999);
        }
        PendingSpouseInvitation::create([
           'church_id' => $church['id'],
           'inviter' => $thisMember['id'],
           'invitee' => $spouse['id'],
            'code' => $code
        ]);
        return $text;

    }
    public static function children($church, $ussdSession, $ussdString)
    {
        $text = "CON My Children:\n";
//        $children = Child::where('parent_phone',$ussdSession['msisdn'])->where('church_id',$church['id'])->get();
        $children = json_decode(Redis::get($ussdSession['msisdn'].'children'),true);
        if ($children != null && count($children) != 0) {
            foreach ($children as $child) {
                $text .= $child['first_name'] . " " . $child['other_names'] . "\n";
            }
        }
        $members = json_decode(Redis::get($church['uuid'].'churchMembers'),true);
        $thisMember = null;
        foreach ($members as $object) {
            if ($object['primary_phone'] === $ussdSession['msisdn'] || $object['alternative_phone'] === $ussdSession['msisdn']) {
                $thisMember = $object;
                break; // Exit the loop if the phone number is found
            }
        }
        $thisMember = json_encode($thisMember);
        $thisMember = json_decode($thisMember,true);
        foreach ($members as $m) {
            if ($m['parent_id'] === $thisMember['id']) {
                $text .= $m['first_name']. " ". $m['other_names']."\n";
            }
        }
        $text .= "1. Register Child\n2. Child Phone(if member)\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'children',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                    '1' => 'registerChild',
                    '2' => 'registerChild18'
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;

    }
    public static function registerChild($church, $ussdSession, $ussdString)
    {
        $text = "CON Please enter child's Name\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertChildName',
                'name' => 'registerChild',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function registerChild18($church, $ussdSession, $ussdString)
    {
        $text = "CON Please enter child's phone number\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'childPhoneNumber',
                'name' => 'registerChild18',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function childPhoneNumber($church, $ussdSession, $ussdString)
    {
        $phoneStatus = validatePhone($ussdString);
        if (!$phoneStatus['isValid']){
            return "CON Please enter a valid phone number\n00. Home";
        }
        $phone = $phoneStatus['phone'];
        $members = json_decode(Redis::get($church['uuid'].'churchMembers',true));
        $child = null;
        foreach ($members as $object) {
            if ($object->primary_phone === $phone || $object->alternative_phone === $phone) {
                $child = $object;
                break; // Exit the loop if the phone number is found
            }
        }
        if (!$child){
            return "CON The child is not a registered member of ".$church['church_name']."\n00. Home";
        }
        $child = json_encode($child);
        $child = json_decode($child,true);
        $text = "CON You have been linked to your child. \n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'childPhoneNumber',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        $dat = [
            'child' => $child['id'],
            'phone' => $ussdSession['msisdn'],
            'type' => 2
        ];
        ConfirmSpouseInvite::dispatch($dat)->onQueue('confirm-family')->onConnection('beanstalkd-worker001');
        return $text;
    }
    public static function insertChildName($church, $ussdSession, $ussdString, $msisdn)
    {

        if (!$ussdString || is_numeric($ussdString) && $ussdString != 0) {
            return "CON Invalid. Name is required.\nPlease enter child's Name\n00. Home";
        }
        $text = "CON Please enter child's date of birth\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertChildDateOfBirth',
                'name' => 'insertChildName',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        //save first name in cache
        Redis::set($msisdn . 'child_name', $ussdString);

        return $text;
    }
    public static function insertChildOtherNames($church, $ussdSession, $ussdString, $msisdn)
    {
        if (!$ussdString || is_numeric($ussdString) && $ussdString != 0) {
            return "CON Invalid. Other Names is required.\nPlease enter child's Other Names\n00. Home";
        }
        $text = "CON Please enter child's date of birth\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertChildDateOfBirth',
                'name' => 'insertChildOtherNames',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        //save other name in cache
        Redis::set($msisdn . 'child_other_names', $ussdString);

        return $text;
    }
    public static function insertChildDateOfBirth($church, $ussdSession, $ussdString, $msisdn)
    {

        $date = null;
        $formats = ['d-m-Y','d.m.Y','d/m/Y'];
        $detectedFormat = null;
        if ($ussdString) {
            try {
                foreach ($formats as $format) {
                    $dateTime = DateTime::createFromFormat($format, $ussdString);
                    if ($dateTime && $dateTime->format($format) == $ussdString) {
                        $detectedFormat = $format;
                        break;
                    }
                }
                if (!$detectedFormat) {
                    return "CON Invalid date format\nPlease enter a date.\n00. Home";
                }
                $carbonDate = Carbon::createFromFormat($detectedFormat, $ussdString);
                    // Valid date
                    if (Carbon::today()->diffInYears(Carbon::parse($carbonDate)) >= 18) {
                        return "CON Children registered should be below 18 yrs\nPlease enter child's Date Of Birth\n00. Home";
                    }
                    $text = "CON Please select child's gender\n1. Male\n2. Female\n00. Home";

                    $data = [
                        'msisdn' => $ussdSession['msisdn'],
                        'session_id' => $ussdSession['session_id'],
                        'ussd_string' => $ussdString,
                        'action' => json_encode([
                            'type' => 'choice',
                            'name' => 'insertChildDateOfBirth',
                            'text' => $text,
                            'options' => [
                                '1' => 'insertChildGender',
                                '2' => 'insertChildGender',
                                '00' => Redis::get($ussdSession['msisdn'] . 'menu')
                            ]
                        ]),
                    ];
                    Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
                    //save first name in cache
                    Redis::set($msisdn . 'child_date_of_birth', $carbonDate->format('Y-m-d'));

                    return $text;

            } catch (Exception $e) {
                // Invalid date
                return "CON Invalid date format\nPlease enter a valid date.\n00. Home";
            }
        }else{
            return "CON Invalid date format\nPlease enter a valid date.\n00. Home";
        }

    }
    //insert gender
    public static function insertChildGender($church, $ussdSession, $ussdString, $msisdn)
    {
        //save other names in cache
        Redis::set($msisdn . 'child_gender', $ussdString);

        $name = Redis::get($msisdn . 'child_name');
        $gender = Redis::get($msisdn . 'child_gender');
        $date_of_birth = Redis::get($msisdn . 'child_date_of_birth');

        if ($gender == 1){
            $child_gender = "Male";
        }else{
            $child_gender = "Female";
        }
        $text = "CON Names: " . $name . "\nGender: " . $child_gender . "\nDate of Birth: " . $date_of_birth . "\n";

        $text .= "1. Confirm\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'insertChildGender',
                'text' => $text,
                'options' => [
                    '1' => 'confirmChild',
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function confirmChild($church, $ussdSession, $ussdString, $msisdn)
    {
        //save user details
        $name = Redis::get($msisdn . 'child_name');
        $gender = Redis::get($msisdn . 'child_gender');
        $date_of_birth = Redis::get($msisdn . 'child_date_of_birth');
        $name_parts = explode(" ", $name);

// Initialize the variables
        $first_name = '';
        $other_names = '';

// Check the number of parts
        if (count($name_parts) == 1) {
            // If there's only one part, consider it as the first name
            $first_name = $name_parts[0];
        } else  {
            // If there are two parts, consider the first part as the first name
            // and the second part as the last name
            $first_name = $name_parts[0];
            $other_names = end($name_parts);
        }
        $dataChild = [
            'church_id' => $church['id'],
            'first_name' => $first_name,
            'other_names' => $other_names,
            'gender' => $gender,
            'phone' => $ussdSession['msisdn'],
            'date_of_birth' => $date_of_birth,
        ];
        RegisterChild::dispatch($dataChild)
            ->onQueue('register_child')
            ->onConnection('beanstalkd-worker001');
        $text = "CON Your child is being registered.\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'confirmChild',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;

    }
    public static function pledgeBalances($church, $ussdSession,$ussdString)
    {
        $text = "CON My Pledges:\n";
        $pledges = Pledge::where('phone',$ussdSession['msisdn'])->where('church_id',$church['id'])->where('expected_completion_date','>=',today())->get();
        foreach ($pledges as $pledge){
            $account = CollectionAccount::find($pledge->collection_account_id);
            $total = CollectionTransaction::where('transaction_time', '>', $pledge->date_pledged)
                ->wherePhone($ussdSession['msisdn'])->whereCollectionAccountId($pledge->collection_account_id)->sum('amount');
            $balance = $pledge->amount_pledged - $total;
            $text .= $account->account.": ".$balance."\n";
        }

        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'pledgeBalances',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    //departments
    public static function departments($church, $ussdSession,$ussdString)
    {
        $departments = Department::where('church_id',$church['id'])->whereHas('members',function ($member) use ($ussdSession){
            $member->where('phone',$ussdSession['msisdn']);
        })->get();

        $text = "CON My Departments:\n";
        foreach ($departments as $department){
            $text .= $department->department_name."\n";
        }
        $text .= "Enter code to join a department\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'departments',
                'function' => 'joinDepartment',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function joinDepartment($church, $ussdSession, $ussdString)
    {
        if (Department::where('church_id',$church['id'])->where('code',$ussdString)->doesntExist()){
            return "CON Invalid. The code you entered does not exist.\nEnter code to join a department\n00. Home";
        }
        $text = "CON You will be added to the department shortly\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'joinDepartment',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        AddMemberToDepartment::dispatch(['church_id' => $church['id'],'code' => $ussdString,'phone' => $ussdSession['msisdn']])
            ->onQueue('add-member-to-department')
            ->onConnection('beanstalkd-worker001');
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    //profile
    public static function profile($church, $ussdSession,$ussdString)
    {
        $members = json_decode(Redis::get($church['uuid'].'churchMembers'),true);
        $member = null;
        foreach ($members as $object) {
            if ($object['primary_phone'] === $ussdSession['msisdn'] || $object['alternative_phone'] === $ussdSession['msisdn']) {
                $member = $object;
                break; // Exit the loop if the phone number is found
            }
        }

        $marital = '';
        if ($member && $member['marital_status']) {
            if ($member['marital_status'] == 1) {
                $marital = "Single";
            } elseif ($member['marital_status'] == 2) {
                $marital = "Married";
            } elseif ($member['marital_status'] == 3) {
                $marital = "Divorced";
            } elseif ($member['marital_status'] == 4) {
                $marital = "Widowed";
            }
        }
        $gender = $member['gender'] == 1 ? "Male" : ($member['gender'] == 2 ? 'Female' : '');
        if ($member){
            $text = "CON Name: ".$member['first_name']." ". $member['other_names']. "\nPhone: ".$member['primary_phone']. "\nPhysical Address: ".$member['physical_address']. "\nDate of Birth: ".$member['date_of_birth']. "\nGender: ".$gender. "\nMarital Status: ".$marital;
            $text .= "\n00. Home";
        }else{
            $text = "CON This feature is Coming soon\n00. Home";
        }
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'profile',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    /**
     * Pin section begins here
     */
    public static function changePin($church, $ussdSession, $ussdString)
    {
        $random = rand(1000, 9999);
        $smsSender = SMSCredential::whereChurchId($church['id'])->whereSendGeneralSms(true)->first();
        if (isset($smsSender)) {
            Redis::set($ussdSession['msisdn'] . 'pin_sent', $random);
            $d = [
                'type' => 0,
                'phone' => $ussdSession['msisdn'],
                'message' => $random,
                'sender' => $smsSender->id,
                'church_id' => $church['id']
            ];
            SendSMS::dispatch($d)
                ->onQueue('send-sms')
                ->onConnection('beanstalkd-worker001');
            // sendSMS($smsSender->sender_id,$smsSender->sms_api_key,$ussdSession->msisdn, $random);
        } else {
            return "END Service is unavailable, please try again later";
        }
        $text = "CON Please enter the pin sent on sms\n0. Cancel";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'pinChange',
                'name' => 'changePin',
                'text' => $text,
                'options' => [
                    '0' => 'cancel'
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    public static function pinChange($church, $ussdSession, $ussdString)
    {
        $pin = Redis::get($ussdSession['msisdn'] . 'pin_sent');
        if ($ussdString != $pin) {
            return "CON Invalid. You have entered the wrong pin.\nPlease enter the pin sent on sms\n0. Cancel";
        }
        $text = "CON Please enter your new pin\n0. Cancel";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'updatePin',
                'name' => 'pinChange',
                'text' => $text,
                'options' => [
                    '0' => 'cancel'
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    public static function updatePin($church, $ussdSession, $ussdString)
    {
        if (!$ussdString || !is_numeric($ussdString) && strlen($ussdString) != 4) {
            return "CON Invalid. Enter a valid pin.\nPlease enter new pin\n0. Cancel";
        }
        Redis::set($ussdSession['msisdn'], 'pin');
        $pin = Redis::get($ussdSession['msisdn'] . 'pin');
        ChurchMember::wherePrimaryPhone($ussdSession['msisdn'])->orWhere('alternative_phone', $ussdSession['msisdn'])->update([
            'pin' => Hash::make($pin)
        ]);
        self::clearCache($ussdSession['msisdn'], $ussdSession);

        return "END Your pin has been changed successfully";

    }

    /**
     * Member registration begins here
     */
    //Displays Register Menu
    public static function registerMember($church, $ussdSession, $ussdString)
    {
//        if (ChurchMember::where('primary_phone', $ussdSession['msisdn'])->exists()){
//            $member = ChurchMember::where('primary_phone', $ussdSession['msisdn'])->first();
//            $church = Church::find($member->church_id);
//            return "END You are already registered to " . $church->church_name;
//        }
        $text = "CON Please enter your Name\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertName',
                'name' => 'registerMember',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }

    //Insert FirstName Functionality
    public static function insertName($church, $ussdSession, $ussdString, $msisdn)
    {

        if (!$ussdString || is_numeric($ussdString) && $ussdString != 0) {
            return "CON Invalid. Name is required.\nPlease enter your Name\n00. Home";
        }
        $text = "CON Please enter your Physical Address \n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertPhysicalAddress',
                'name' => 'insertName',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        //save first name in cache
        Redis::set($msisdn . 'name', $ussdString);

        return $text;
    }

    public static function insertOtherNames($church, $ussdSession, $ussdString, $msisdn)
    {
        if (!$ussdString || is_numeric($ussdString) && $ussdString != 0) {
            return "CON Invalid. Other Names is required.\nPlease enter your Other Names\n00. Home";
        }
        $text = "CON Please enter your Physical Address \n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertPhysicalAddress',
                'name' => 'insertOtherNames',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        //save first name in cache
        Redis::set($msisdn . 'other_names', $ussdString);

        return $text;
    }

    public static function insertPhysicalAddress($church, $ussdSession, $ussdString, $msisdn)
    {
        if (!$ussdString || is_numeric($ussdString) && $ussdString != 0) {
            return "CON Invalid. Physical Address is required.\nPlease enter your Physical Address\n00. Home";
        }
        Redis::set($msisdn . 'physical_address', $ussdString);
        $text = "CON Please enter your Date Of Birth\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertDateOfBirth',
                'name' => 'insertPhysicalAddress',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }

    public static function insertDateOfBirth($church, $ussdSession, $ussdString, $msisdn)
    {
        $date = null;
        $formats = ['d-m-Y','d.m.Y','d/m/Y'];
        $detectedFormat = null;
        if ($ussdString) {
            try {
                foreach ($formats as $format) {
                    $dateTime = DateTime::createFromFormat($format, $ussdString);
                    if ($dateTime && $dateTime->format($format) == $ussdString) {
                        $detectedFormat = $format;
                        break;
                    }
                }
                if (!$detectedFormat) {
                    return "CON Invalid date format\nPlease enter a date.\n00. Home";
                }
                $carbonDate = Carbon::createFromFormat($detectedFormat, $ussdString);

                if ($carbonDate->format('d-m-Y') !== $ussdString && $carbonDate->format('d.m.Y') !== $ussdString && $carbonDate->format('d/m/Y') !== $ussdString) {
                    // Invalid date format
                    return "CON Invalid date format.\nPlease enter a valid date.\n00. Home";
                } else {
                    // Valid date format
                    if (Carbon::today()->diffInYears(Carbon::parse($carbonDate)) < 18) {
                        return "CON You should be 18yrs and above\nPlease enter your Date Of Birth\n00. Home";
                    }
                    $date = $carbonDate->format('Y-m-d');
                }
            } catch (Exception $e) {
                // Invalid date
                return "CON Invalid date format\nPlease enter a date.\n00. Home";
            }
        }
        $text = "CON Please select your gender\n1. Male\n2. Female\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'insertDateOfBirth',
                'text' => $text,
                'options' => [
                    '1' => 'insertGender',
                    '2' => 'insertGender',
                    '00' => Redis::get($ussdSession['msisdn'] . 'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        //save first name in cache
        Redis::set($msisdn . 'date_of_birth',$date);

        return $text;

    }

    //insert gender
    public static function insertGender($church, $ussdSession, $ussdString, $msisdn)
    {
        //save other names in cache
        Redis::set($msisdn . 'gender', $ussdString);

        $text = "CON Select your marital status\n";

        $text .= "1. Single \n2. Married \n3. Divorced\n4. Widowed\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'insertGender',
                'text' => $text,
                'options' => [
                    '1' => 'insertMaritalStatus',
                    '2' => 'insertMaritalStatus',
                    '3' => 'insertMaritalStatus',
                    '4' => 'insertMaritalStatus',
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    public static function insertMaritalStatus($church, $ussdSession, $ussdString, $msisdn)
    {
        //save other names in cache
        Redis::set($msisdn . 'marital_status', $ussdString);

        //extract the id number, first name & other names from the database and cache respectively

        $name = Redis::get($msisdn . 'name');
        $other_names = Redis::get($msisdn . 'other_names');
        $physical_address = Redis::get($msisdn . 'physical_address');
        $date_of_birth = Redis::get($msisdn . 'date_of_birth');
        $gender = Redis::get($msisdn . 'gender') == 1 ? 'Male' : 'Female';
        $maritalStatus = Redis::get($msisdn . 'marital_status') == 1 ? 'Single' : ( Redis::get($msisdn . 'marital_status') == 2 ? 'Married' :
            (Redis::get($msisdn . 'marital_status') == 3 ? 'Divorced' :  (Redis::get($msisdn . 'marital_status') == 4 ? 'Widowed' : '')));


        $text = "CON Name: " . $name . "\nDate of Birth: " . $date_of_birth . "\nGender: " . $gender . "\nMarital Status: ".$maritalStatus."\n";

        $text .= "1. Confirm \n2. Change Details\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'insertMaritalStatus',
                'text' => $text,
                'options' => [
                    '1' => 'confirm',
                    '2' => 'showMenu',
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    //Insert pin Functionality
    public static function insertPin($church, $ussdSession, $ussdString, $msisdn)
    {
        if (!$ussdString || !is_numeric($ussdString) && strlen($ussdString) != 4) {
            return "CON Invalid. Enter a valid pin.\nPlease enter 4 digit pin\n0. Cancel\n00. Home";
        }
        //save other names in cache
        Redis::set($msisdn . 'pin', $ussdString);

        //extract the id number, first name & other names from the database and cache respectively
        $first_name = Redis::get($msisdn . 'first_name');
        $other_names = Redis::get($msisdn . 'other_names');
        $physical_address = Redis::get($msisdn . 'physical_address');
        $date_of_birth = Redis::get($msisdn . 'date_of_birth');

        $text = "CON Names: " . $first_name . " " . $other_names . "\nPhysical Address: " . $physical_address . "\nDate of Birth: " . $date_of_birth . "\n";

        $text .= "1. Confirm\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'insertPin',
                'text' => $text,
                'options' => [
                    '1' => 'confirm',
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    public static function confirm($church, $ussdSession, $ussdString, $msisdn)
    {
        //save user details
        $name = Redis::get($msisdn . 'name');
        $physical_address = Redis::get($msisdn . 'physical_address');
        $date_of_birth = Redis::get($msisdn . 'date_of_birth') ?? null;
        $gender = Redis::get($msisdn . 'gender');
        $marital = Redis::get($msisdn . 'marital_status');
        $name_parts = explode(" ", $name);

// Initialize the variables
        $first_name = '';
        $last_name = '';
        $middle_name = '';

// Check the number of parts
        if (count($name_parts) == 1) {
            // If there's only one part, consider it as the first name
            $first_name = $name_parts[0];
        } elseif (count($name_parts) == 2) {
            // If there are two parts, consider the first part as the first name
            // and the second part as the last name
            $first_name = $name_parts[0];
            $last_name = $name_parts[1];
        } elseif (count($name_parts) >= 3) {
            // If there are three or more parts, consider the first part as the first name,
            // the last part as the last name, and the parts in between as the middle name.
            $first_name = $name_parts[0];
            $last_name = end($name_parts);
            $middle_name = implode(" ", array_slice($name_parts, 1, -1));
        }

        $data = [
            'church_id' => $church['id'],
            'first_name' => $first_name,
            'other_names' => $last_name,
            'middle_name' => $middle_name,
            'primary_phone' => $msisdn,
            'gender' => $gender,
            'date_of_birth' => $date_of_birth,
            'marital_status' => $marital,
            'department' => null,
            'type' => 1,
            'status' => 0,
            'physical_address' => $physical_address,
        ];
        RegisterChurchMember::dispatch($data)
            ->onQueue('register_church_member')
            ->onConnection('beanstalkd-worker001');

        self::clearCache($msisdn, $ussdSession);

        return self::showMenuRegistered($church, $ussdSession, $ussdString);

    }

    /**
     * Payment section starts here
     */
    public static function makePayments($church, $ussdSession, $ussdString)
    {
//        $accounts = CollectionAccount::whereHas('paybill', function ($query) use ($church) {
//            $query->where('church_id', $church->id);
//        })->get();
        $accounts = json_decode(Redis::get($church['uuid'] . 'collectionAccounts'), true);
        if (!$accounts){
            $accounts = [];
        }
        $text = "CON Select Payment Account\n";
        $values = [];
        for ($i = 0; $i < count($accounts); $i ++){
            $text .= $i+1 . ". " . $accounts[$i]['account'] . "\n";
            $values[] = $i+1;
        }
//        foreach ($accounts as $account) {
//            if ($account['status'] == 1) {
//                $text .= $account['id'] . ". " . $account['account'] . "\n";
//                $values[] = $account['id'];
//            }
//        }
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'fromDB',
                'name' => 'makePayments',
                'function' => 'selectAccount',
                'text' => $text,
                'values' => $values,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    public static function selectAccount($church, $ussdSession, $ussdString)
    {
        $id = null;
        $accounts = json_decode(Redis::get($church['uuid'] . 'collectionAccounts'), true);
        for ($i = 0; $i < count($accounts); $i ++){
            if ($i+1 == $ussdString){
                $id = $accounts[$i]['id'];
            }
            if ($i+1 == $ussdString && $accounts[$i]['paybill_id'] == null) {
                //send payment instruction
                $text = "CON Payment instructions have been sent to you via sms.\n00. Home";
                $data = [
                    'msisdn' => $ussdSession['msisdn'],
                    'session_id' => $ussdSession['session_id'],
                    'ussd_string' => $ussdString,
                    'action' => json_encode([
                        'type' => 'choice',
                        'name' => 'enterAmount',
                        'text' => $text,
                        'options' => [
                            '00' => Redis::get($ussdSession['msisdn'].'menu')
                        ]
                    ]),
                ];
                Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
                SendPaymentInstructions::dispatch([
                    'phone' => $ussdSession['msisdn'],
                    'account' => $accounts[$i]['id'],
                    'church_id' => $church['id']
                ])->onQueue('send-payment-instruction')
                    ->onConnection('beanstalkd-worker001');
                return $text;
            }
        }
//        foreach ($accounts as $account) {
//            if ($account['id'] == $ussdString && $account['paybill_id'] == null) {
//                //send payment instruction
//                $text = "CON Payment instructions have been sent to you via sms.\n00. Home";
//                $data = [
//                    'msisdn' => $ussdSession['msisdn'],
//                    'session_id' => $ussdSession['session_id'],
//                    'ussd_string' => $ussdString,
//                    'action' => json_encode([
//                        'type' => 'choice',
//                        'name' => 'enterAmount',
//                        'text' => $text,
//                        'options' => [
//                            '00' => Redis::get($ussdSession['msisdn'].'menu')
//                        ]
//                    ]),
//                ];
//                Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
//                SendPaymentInstructions::dispatch([
//                    'phone' => $ussdSession['msisdn'],
//                    'account' => $ussdString,
//                    'church_id' => $church['id']
//                ])->onQueue('send-payment-instruction')
//                    ->onConnection('beanstalkd-worker001');
//                return $text;
//            }
//        }
        $text = "CON Please Enter Amount\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'enterAmount',
                'name' => 'selectAccount',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        //save accont in cache
        Redis::set($ussdSession['msisdn'] . 'account', $id);
        return $text;

    }

    public static function enterAmount($church, $ussdSession, $ussdString)
    {
        if (!$ussdString || !is_numeric($ussdString)) {
            return "CON Invalid. Enter valid amount.\nPlease enter amount\n0. Cancel\n00. Home";
        }
        //save other names in cache
        Redis::set($ussdSession['msisdn'] . 'amount', $ussdString);

        //$account = CollectionAccount::whereId(Redis::get($ussdSession['msisdn'] . 'account'))->first()->account;
        $accounts = json_decode(Redis::get($church['uuid'] . 'collectionAccounts'), true);
        $account = null;

        foreach ($accounts as $element) {
            if ($element['id'] == Redis::get($ussdSession['msisdn'] . 'account')) {
                $account = $element;
                break; // Exit the loop once the item is found
            }
        }
        $amount = Redis::get($ussdSession['msisdn'] . 'amount');

        $text = "CON Account: " . $account['account'] . "\nAmount: " . $amount . "\n";

        $text .= "1. Confirm\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'enterAmount',
                'text' => $text,
                'options' => [
                    '1' => 'paymentOption',
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    public static function paymentOption($church, $ussdSession, $ussdString)
    {
        $text = "CON Select Payment Option\n1. Mpesa From This Number \n2. Mpesa From a different Number\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'paymentOption',
                'text' => $text,
                'options' => [
                    '1' => 'confirmPayment',
                    '2' => 'differentNumber',
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    public static function differentNumber($church, $ussdSession, $ussdString)
    {
        $text = "CON Please Enter the Mpesa Number \n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'confirmPaymentDifferentNumber',
                'name' => 'differentNumber',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        //save phone in cache
        Redis::set($ussdSession['msisdn'] . 'phone', $ussdString);
        return $text;
    }

    public static function confirmPaymentDifferentNumber($church, $ussdSession, $ussdString)
    {
        $phoneFormat = validatePhone($ussdString);

        $formatedPhone = $phoneFormat['phone'];
        if ($phoneFormat['isValid']) {
            Redis::set($ussdSession['msisdn'] . 'phone', $formatedPhone);
        } else {
            return "CON Invalid. Enter a valid Mpesa Number.\nPlease enter Mpesa Number\n0. Cancel\n00. Home";
        }
        $account = Redis::get($ussdSession['msisdn'] . 'account');
        $amount = Redis::get($ussdSession['msisdn'] . 'amount');
        $phone = Redis::get($ussdSession['msisdn'] . 'phone');
        $paymentData = [
            'phone' => $phone,
            'initiated_by' => $ussdSession['msisdn'],
            'amount' => $amount,
            'account' => $account,
            'church_id' => $church['id']
        ];
        InitiateSTKPush::dispatch($paymentData)->onQueue('initiate-stk-payment')->onConnection('beanstalkd-worker001');
        self::clearCache($ussdSession['msisdn'], $ussdSession);

        return 'END A payment request has been initiated';
    }

    public static function confirmPayment($church, $ussdSession)
    {
        $account = Redis::get($ussdSession['msisdn'] . 'account');
        $amount = Redis::get($ussdSession['msisdn'] . 'amount');
        $paymentData = [
            'phone' => $ussdSession['msisdn'],
            'initiated_by' => null,
            'amount' => $amount,
            'account' => $account,
            'church_id' => $church['id']
        ];
        InitiateSTKPush::dispatch($paymentData)
            ->onQueue('initiate-stk-payment')
            ->onConnection('beanstalkd-worker001');
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return 'END A payment request has been initiated.';
    }

    /**
     * This is the contact us section. It returns both contact us and about us
     */
    public static function contactUs($church, $ussdSession)
    {
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return "END Contact Us via:\nEmail: " . $church['email'] . "\nPhone: " . $church['phone']."\nPhysical Address: ".$church['physical_address'];
    }

    /**
     * This section returns the services scheduled for that day
     */
    public static function todayServices($church, $ussdSession)
    {
//        $adhoc = ServiceSchedule::whereHas('adhoc', function ($q) use ($church) {
//            $q->where('church_id', $church->id)->where('date', Carbon::today());
//        });
//        $services = ServiceSchedule::whereHas('permanent', function ($q) use ($church) {
//            $q->where('church_id', $church->id)
//                ->where('day_of_the_week', Carbon::today()->format('l'));
//        })->whereDate('created_at', Carbon::today())->union($adhoc)->get();
        $services = json_decode(Redis::get($church['uuid'] . 'services'), true);
        if (!$services){
            $services = [];
        }
        $message = "END Today's services:\n";
        foreach ($services as $service) {
                $message .= $service['service']['service_name'] . "\nFrom: " . $service['service']['from_time'] . " To: " . $service['service']['to_time'] . "\n";
        }
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return $message;
    }

    //Quit Menu
    public static function cancel($church, $ussdSession)
    {
        
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return "END Thank you for using " . $church['church_name'] . " platform!";
    }

    static function clearCache($msisdn, $ussdSession)
    {
        Redis::del($msisdn . 'menu');
        Redis::del($msisdn . 'first_name');
        Redis::del($msisdn . 'other_names');
        Redis::del($msisdn . 'date_of_birth');
        Redis::del($msisdn . 'physical_address');
        Redis::del($msisdn . 'frequency');
        Redis::del($msisdn . 'frequency_time_value');
        Redis::del($msisdn . 'amount');
        Redis::del($ussdSession['session_id'] . 'ussdSession');
    }

    /**
     * Pledge section starts here
     */
    public static function pledge($church, $ussdSession, $ussdString)
    {
//        $accounts = CollectionAccount::whereHas('paybill', function ($query) use ($church) {
//            $query->where('church_id', $church->id);
//        })->get();
//        $accounts = json_decode(Redis::get($church['uuid'] . 'collectionAccounts'), true);
//        $text = "CON Select Payment Account\n";
//        $values = [];
//        foreach ($accounts as $account) {
//            if ($account['status'] == 1) {
//                $text .= $account['id'] . ". " . $account['account'] . "\n";
//                $values[] = $account['id'];
//            }
//        }
        $accounts = json_decode(Redis::get($church['uuid'] . 'collectionAccounts'), true);
        if (!$accounts){
            $accounts = [];
        }
        $text = "CON Select Payment Account\n";
        $values = [];
        for ($i = 0; $i < count($accounts); $i ++){
            $text .= $i+1 . ". " . $accounts[$i]['account'] . "\n";
            $values[] = $i+1;
        }
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'fromDB',
                'name' => 'pledge',
                'function' => 'selectPledgeAccount',
                'text' => $text,
                'values' => $values,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    public static function selectPledgeAccount($church, $ussdSession, $ussdString)
    {
//        $acc = CollectionAccount::find($ussdString);
//        $accounts = CollectionAccount::whereHas('paybill', function ($query) use ($church) {
//            $query->where('church_id', $church->id);
//        })->get();
//        $text = "CON Select Payment Account\n";
//        foreach ($accounts as $account) {
//            $text .= $account->id . ". " . $account->account . "\n";
//        }
//        if ($acc == null) {
//
//            return $text;
//        } else {
//            $paybill = ChurchPaybill::find($acc->paybill_id);
//            if ($paybill->church_id != $church->id) {
//                return $text;
//            }
//        }
        $text = "CON Please Enter Amount\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'enterPledgeAmount',
                'name' => 'selectAccount',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        $accounts = json_decode(Redis::get($church['uuid'] . 'collectionAccounts'), true);
        $id = null;
        for ($i = 0; $i < count($accounts); $i ++) {
            if ($i+1 == $ussdString) {
                $id = $accounts[$i]['id'];
            }
        }
        Redis::set($ussdSession['msisdn'] . 'account', $id);
        return $text;

    }

    public static function enterPledgeAmount($church, $ussdSession, $ussdString)
    {
        if (!$ussdString || !is_numeric($ussdString)) {
            return "CON Invalid. Enter valid amount.\nPlease enter amount\n00. Home";
        }
        //save amount in cache
        Redis::set($ussdSession['msisdn'] . 'amount', $ussdString);

        // $account = CollectionAccount::whereId(Redis::get($ussdSession['msisdn'] . 'account'))->first()->account;
        $accounts = json_decode(Redis::get($church['uuid'] . 'collectionAccounts'), true);
        $account = null;

        foreach ($accounts as $element) {
            if ($element['id'] == Redis::get($ussdSession['msisdn'] . 'account')) {
                $account = $element;
                break; // Exit the loop once the item is found
            }
        }

        $amount = Redis::get($ussdSession['msisdn'] . 'amount');

        $text = "CON Account: " . $account['account'] . "\nAmount: " . $amount . "\n";

        $text .= "1. Confirm\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'enterAmount',
                'text' => $text,
                'options' => [
                    '1' => 'savePledge',
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

//    public static function confirmPledge($church, $ussdSession, $ussdString)
//    {
//        //save amount in cache
//
//        $text = "CON How would you like to pay?\n1. Once\n2. Daily\n3. Weekly\n4. Monthly\n00. Home";
//
//        $data = [
//            'msisdn' => $ussdSession['msisdn'],
//            'session_id' => $ussdSession['session_id'],
//            'ussd_string' => $ussdString,
//            'action' => json_encode([
//                'type' => 'choice',
//                'name' => 'enterAmount',
//                'text' => $text,
//                'options' => [
//                    '1' => 'endDateOnceDaily',
//                    '2' => 'endDateOnceDaily',
//                    '3' => 'weekly',
//                    '4' => 'monthly',
//                    '00' => Redis::get($ussdSession['msisdn'].'menu')
//                ]
//            ]),
//        ];
//        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
//        return $text;
//    }
//
//    public static function weekly($church, $ussdSession, $ussdString)
//    {
//        Redis::set($ussdSession['msisdn'] . 'frequency', $ussdString);
//        $text = "CON When would you like to pay?\n1. Monday\n2. Tuesday\n3. Wednesday\n4. Thursday\n5. Friday\n6. Saturday\n7. Sunday\n00. Home";
//
//        $data = [
//            'msisdn' => $ussdSession['msisdn'],
//            'session_id' => $ussdSession['session_id'],
//            'ussd_string' => $ussdString,
//            'action' => json_encode([
//                'type' => 'choice',
//                'name' => 'enterAmount',
//                'text' => $text,
//                'options' => [
//                    '1' => 'endDate',
//                    '2' => 'endDate',
//                    '3' => 'endDate',
//                    '4' => 'endDate',
//                    '5' => 'endDate',
//                    '7' => 'endDate',
//                    '6' => 'endDate',
//                    '00' => Redis::get($ussdSession['msisdn'].'menu')
//                ]
//            ]),
//        ];
//        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
//
//        return $text;
//    }
//
//    public static function monthly($church, $ussdSession, $ussdString)
//    {
//        Redis::set($ussdSession['msisdn'] . 'frequency', $ussdString);
//        $text = "CON Please enter day of the month(Date only. e.g 10)\n00. Home";
//        $data = [
//            'msisdn' => $ussdSession['msisdn'],
//            'session_id' => $ussdSession['session_id'],
//            'ussd_string' => $ussdString,
//            'action' => json_encode([
//                'type' => 'input',
//                'function' => 'saveMonth',
//                'name' => 'monthly',
//                'text' => $text,
//                'options' => [
//                    '00' => Redis::get($ussdSession['msisdn'].'menu')
//                ]
//            ]),
//        ];
//        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
//        return $text;
//    }
//
//    public static function saveMonth($church, $ussdSession, $ussdString)
//    {
//        if (!$ussdString || !is_numeric($ussdString) || $ussdString < 1 || $ussdString > 31) {
//            return "CON Invalid. Enter correct day of the month.\nPlease enter day of the month\n0. Cancel\n00. Home";
//        }
//        $text = "CON Please enter your completion date(dd-mm-YYYY)\n00. Home";
//        $data = [
//            'msisdn' => $ussdSession['msisdn'],
//            'session_id' => $ussdSession['session_id'],
//            'ussd_string' => $ussdString,
//            'action' => json_encode([
//                'type' => 'input',
//                'function' => 'savePledge',
//                'name' => 'saveMonth',
//                'text' => $text,
//                'options' => [
//                    '00' => Redis::get($ussdSession['msisdn'].'menu')
//                ]
//            ]),
//        ];
//        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
//
//        Redis::set($ussdSession['msisdn'] . 'frequency_time_value', $ussdString);
//        return $text;
//    }
//
//    public static function endDateOnceDaily($church, $ussdSession, $ussdString)
//    {
//
//        Redis::set($ussdSession['msisdn'] . 'frequency', $ussdString);
//        $text = "CON Please enter your completion date\n00. Home";
//        $data = [
//            'msisdn' => $ussdSession['msisdn'],
//            'session_id' => $ussdSession['session_id'],
//            'ussd_string' => $ussdString,
//            'action' => json_encode([
//                'type' => 'input',
//                'function' => 'savePledge',
//                'name' => 'endDate',
//                'text' => $text,
//                'options' => [
//                    '00' => Redis::get($ussdSession['msisdn'].'menu')
//                ]
//            ]),
//        ];
//        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
//        return $text;
//    }
//
//    public static function endDate($church, $ussdSession, $ussdString)
//    {
//        Redis::set($ussdSession['msisdn'] . 'frequency_time_value', $ussdString);
//        $text = "CON Please enter your completion date\n00. Home";
//        $data = [
//            'msisdn' => $ussdSession['msisdn'],
//            'session_id' => $ussdSession['session_id'],
//            'ussd_string' => $ussdString,
//            'action' => json_encode([
//                'type' => 'input',
//                'function' => 'savePledge',
//                'name' => 'endDate',
//                'text' => $text,
//                'options' => [
//                    '00' => Redis::get($ussdSession['msisdn'].'menu')
//                ]
//            ]),
//        ];
//        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
//        return $text;
//    }

    public static function savePledge($church, $ussdSession, $ussdString)
    {
//        $date = null;
//        $formats = ['d-m-Y','d.m.Y','d/m/Y'];
//        $detectedFormat = null;
//        if ($ussdString) {
//            try {
//                foreach ($formats as $format) {
//                    $dateTime = DateTime::createFromFormat($format, $ussdString);
//                    if ($dateTime && $dateTime->format($format) == $ussdString) {
//                        $detectedFormat = $format;
//                        break;
//                    }
//                }
//                if (!$detectedFormat) {
//                    return "CON Invalid date format\nPlease enter a date.\n00. Home";
//                }
//                $carbonDate = Carbon::createFromFormat($detectedFormat, $ussdString);
//                // Valid date format
//                if (Carbon::today()->greaterThan($carbonDate)) {
//                    return "CON Completion date must be greater than or equal to today\nPlease enter your completion date\n0. Cancel\n00. Home";
//                }

                //save completion date in cache
                //Redis::set($ussdSession['msisdn'] . 'completion_date', $carbonDate->format('Y-m-d'));

                $account = Redis::get($ussdSession['msisdn'] . 'account');
                $amount = Redis::get($ussdSession['msisdn'] . 'amount');
//                $frequency = Redis::get($ussdSession['msisdn'] . 'frequency');//1=once, 2=daily, 3=weekly, 4=monthly
//                $frequencyTimeValue = Redis::get($ussdSession['msisdn'] . 'frequency_time_value');
//                $fr = $frequencyTimeValue;
//                if ($frequency == 3) {
//                    //weekly payment
//                    switch ($frequencyTimeValue) {
//                        case 1:
//                            $fr = 'Monday';
//                            break;
//                        case 2:
//                            $fr = 'Tuesday';
//                            break;
//                        case 3:
//                            $fr = 'Wednesday';
//                            break;
//                        case 4:
//                            $fr = 'Thursday';
//                            break;
//                        case 5:
//                            $fr = 'Friday';
//                            break;
//                        case 6:
//                            $fr = 'Saturday';
//                            break;
//                        case 7:
//                            $fr = 'Sunday';
//                            break;
//                    }
//                }
//                $completionDate = Redis::get($ussdSession['msisdn'] . 'completion_date');

                savePledgeRecord($church['id'], $amount, $ussdSession['msisdn'], $account);
                self::clearCache($ussdSession['msisdn'], $ussdSession);
                return 'END Your pledge is being recorded';

//            } catch (Exception $e) {
//                // Invalid date
//                return "CON Invalid date format\nPlease enter a date in the format.\n00. Home";
//            }
//        }else{
//            return "CON Invalid date format\nPlease enter a valid date.\n00. Home";
//        }

    }

    /**
     * Feedback section begins here
     */
    public static function feedbackCategories($church, $ussdSession, $ussdString)
    {
        $categories = json_decode(Redis::get($church['uuid'] . 'feedbackCategories'), true);
        if (!$categories){
            $categories = [];
        }
        $text = "CON Select Category\n";
        $values = [];
        for ($i = 0; $i < count($categories); $i ++){
            $text .= $i+1 . ". " . $categories[$i]['name'] . "\n";
            $values[] = $i+1;
        }
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'fromDB',
                'name' => 'feedbackCategories',
                'function' => 'feedbackSubCategory',
                'text' => $text,
                'values' => $values,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function feedbackSubCategory($church, $ussdSession, $ussdString)
    {
        $id = null;
        $categories = json_decode(Redis::get($church['uuid'] . 'feedbackCategories'), true);
        for ($i = 0; $i < count($categories); $i ++){
            if ($i+1 == $ussdString){
                $id = $categories[$i]['uuid'];
            }
        }
        Redis::set($ussdSession['msisdn'] . 'feedbackCategory', $id);
        $categoryUUID = Redis::get($ussdSession['msisdn'] . 'feedbackCategory');
        $subs = json_decode(Redis::get($categoryUUID . 'feedbackSubCategories'), true);
        if (!$subs){
            $subs = [];
        }
        $text = "CON Select Sub Category\n";
        $values = [];
        for ($i = 0; $i < count($subs); $i ++){
            $text .= $i+1 . ". " . $subs[$i]['name'] . "\n";
            $values[] = $i+1;
        }
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'fromDB',
                'name' => 'feedbackSubCategory',
                'function' => 'feedback',
                'text' => $text,
                'values' => $values,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function feedback($church, $ussdSession, $ussdString)
    {
        $id = null;
        $categoryUUID = Redis::get($ussdSession['msisdn'] . 'feedbackCategory');
        $subs = json_decode(Redis::get($categoryUUID . 'feedbackSubCategories'), true);
        for ($i = 0; $i < count($subs); $i ++){
            if ($i+1 == $ussdString){
                $id = $subs[$i]['uuid'];
            }
        }
        Redis::set($ussdSession['msisdn'] . 'feedbackSubCategory', $id);
        $text = "CON We value your feedback.\nPlease enter your feedback\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertFeedback',
                'name' => 'sendFeedback',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function insertFeedback($church, $ussdSession, $ussdString, $msisdn)
    {
        if (!$ussdString || is_numeric($ussdString) && $ussdString != 0) {
            return "CON Invalid. Feedback required.\nPlease enter your feedback\n0. Cancel\n00. Home";
        }
        Feedback::create([
            'church_id' => $church['id'],
            'category_id' => FeedbackSubCategory::where('uuid',Redis::get($ussdSession['msisdn'] . 'feedbackSubCategory'))->first()->id,
            'phone' => $msisdn,
            'message' => $ussdString,
            'sent_on' => now()->format('Y-m-d H:i:s')
        ]);
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return "END Your feedback has been recorded. Thank You.";
    }

    /**
     * prayer requests section start
     */
    //if member is not registered, starts with asking for the name
    public static function enterName($church, $ussdSession, $ussdString)
    {
        $text = "CON Please enter your Name\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'prayerRequestNonMember',
                'name' => 'enterName',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function prayerRequestNonMember($church, $ussdSession, $ussdString)
    {
        if (!$ussdString){
            return "CON Please enter your name\n00. Home";
        }
        Redis::set($ussdSession['msisdn'].'name',$ussdString);
        $categories = json_decode(Redis::get($church['uuid'] . 'prayerCategories'), true);
        if (!$categories){
            $categories = [];
        }
        $text = "CON Select Category\n";
        $values = [];
        for ($i = 0; $i < count($categories); $i ++){
            $text .= $i+1 . ". " . $categories[$i]['name'] . "\n";
            $values[] = $i+1;
        }
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'fromDB',
                'name' => 'prayerRequestNonMember',
                'function' => 'subCategory',
                'text' => $text,
                'values' => $values,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }



    public static function prayerRequest($church, $ussdSession, $ussdString)
    {
        $categories = json_decode(Redis::get($church['uuid'] . 'prayerCategories'), true);
        if (!$categories){
            $categories = [];
        }
        $text = "CON Select Category\n";
        $values = [];
        for ($i = 0; $i < count($categories); $i ++){
            $text .= $i+1 . ". " . $categories[$i]['name'] . "\n";
            $values[] = $i+1;
        }
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'fromDB',
                'name' => 'prayerRequest',
                'function' => 'subCategory',
                'text' => $text,
                'values' => $values,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

//    public static function selectCategory($church, $ussdSession, $ussdString)
//    {
//        $id = null;
//        $categories = json_decode(Redis::get($church['uuid'] . 'prayerCategories'), true);
//        for ($i = 0; $i < count($categories); $i ++){
//            if ($i+1 == $ussdString){
//                $id = $categories[$i]['uuid'];
//            }
//        }
//        $text = "CON Select Sub Category\n00. Home";
//
//        $data = [
//            'msisdn' => $ussdSession['msisdn'],
//            'session_id' => $ussdSession['session_id'],
//            'ussd_string' => $ussdString,
//            'action' => json_encode([
//                'type' => 'fromDB',
//                'function' => 'subCategory',
//                'name' => 'selectAccount',
//                'text' => $text,
//                'options' => [
//                    '00' => Redis::get($ussdSession['msisdn'].'menu')
//                ]
//            ]),
//        ];
//        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
//        //save accont in cache
//        Redis::set($ussdSession['msisdn'] . 'category', $id);
//        return $text;
//
//    }

    public static function subCategory($church, $ussdSession, $ussdString)
    {
        $id = null;
        $categories = json_decode(Redis::get($church['uuid'] . 'prayerCategories'), true);
        for ($i = 0; $i < count($categories); $i ++){
            if ($i+1 == $ussdString){
                $id = $categories[$i]['uuid'];
            }
        }
        Redis::set($ussdSession['msisdn'] . 'category', $id);
        $categoryUUID = Redis::get($ussdSession['msisdn'] . 'category');
        $subs = json_decode(Redis::get($categoryUUID . 'prayerSubCategories'), true);
        if (!$subs){
            $subs = [];
        }
        $text = "CON Select Sub Category\n";
        $values = [];
        for ($i = 0; $i < count($subs); $i ++){
            $text .= $i+1 . ". " . $subs[$i]['name'] . "\n";
            $values[] = $i+1;
        }
        $text .= "00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'fromDB',
                'name' => 'subCategory',
                'function' => 'enterMessage',
                'text' => $text,
                'values' => $values,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
//    public static function selectSubCategory($church, $ussdSession, $ussdString)
//    {
//        $id = null;
//        $categoryUUID = Redis::get($ussdSession['msisdn'] . 'category');
//        $subs = json_decode(Redis::get($categoryUUID . 'prayerSubCategories'), true);
//        for ($i = 0; $i < count($subs); $i ++){
//            if ($i+1 == $ussdString){
//                $id = $subs[$i]['uuid'];
//            }
//        }
//        $text = "CON Please Enter Message\n00. Home";
//
//        $data = [
//            'msisdn' => $ussdSession['msisdn'],
//            'session_id' => $ussdSession['session_id'],
//            'ussd_string' => $ussdString,
//            'action' => json_encode([
//                'type' => 'input',
//                'function' => 'enterMessage',
//                'name' => 'selectSubCategory',
//                'text' => $text,
//                'options' => [
//                    '00' => Redis::get($ussdSession['msisdn'].'menu')
//                ]
//            ]),
//        ];
//        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
//        //save accont in cache
//        Redis::set($ussdSession['msisdn'] . 'subcategory', $id);
//        return $text;
//
//    }
    public static function enterMessage($church, $ussdSession, $ussdString)
    {
        $id = null;
        $categoryUUID = Redis::get($ussdSession['msisdn'] . 'category');
        $subs = json_decode(Redis::get($categoryUUID . 'prayerSubCategories'), true);
        for ($i = 0; $i < count($subs); $i ++){
            if ($i+1 == $ussdString){
                $id = $subs[$i]['uuid'];
            }
        }
        Redis::set($ussdSession['msisdn'] . 'subcategory', $id);

        $text = "CON Enter prayer request\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'name' => 'enterMessage',
                'function' => 'sendRequest',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function sendRequest($church, $ussdSession, $ussdString)
    {
        if (!$ussdString) {
            return "CON Invalid.\nPlease enter message\n00. Home";
        }
        Redis::set($ussdSession['msisdn'] . 'message', $ussdString);
        $text = "CON Your prayer request has been sent\n00. Home";

        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'sendRequest',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu'),
                ]
            ]),
        ];
        $name =  Redis::get($ussdSession['msisdn'] . 'name');
        $prayerRequestData = [
            'category' => Redis::get($ussdSession['msisdn'] . 'category'),
            'sub' => Redis::get($ussdSession['msisdn'] . 'subcategory'),
            'message' => $ussdString,
            'phone' => $ussdSession['msisdn'],
            'church' => $church['uuid'],
            'name' => $name
        ];
        SavePrayerRequest::dispatch($prayerRequestData)->onQueue('save-prayer-request')->onConnection('beanstalkd-worker001');
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }


}
