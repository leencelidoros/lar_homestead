<?php

namespace App\Repositories;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Models\User;


class UssdRepositoryCache
{
    public static function process($ussdSession, $ussdString, $msisdn, $userExists)
    {
        if ($ussdString === null && $userExists == 1) {
            return self::showMenuRegistered($ussdSession, $ussdString);
        } elseif ($ussdString === null && $userExists == 0) {
            return self::showMenu($ussdSession, $ussdString);
        }
        $data = $ussdSession['action'];
        $dataType = json_decode($data, true);
        //check if the type is choice on input
        if (in_array('choice', $dataType)) {
            $options = $dataType['options'];
            if (array_key_exists($ussdString, $options)) {
                return self::{$options[$ussdString]}($ussdSession, $ussdString, $msisdn);
            }
            $modified_text = str_replace("CON ", "", $dataType['text']);
            return "CON Invalid choice\n{$modified_text}";
        }
        //check if the type is choice on input
        if (in_array('fromDB', $dataType)) {
            $values = collect($dataType['values'])->toArray();
            $options = collect($dataType['options'])->toArray();
            if (array_key_exists($ussdString, $options)) {
                return self::{$options[$ussdString]}($ussdSession, $ussdString, $msisdn);
            }
            if (!in_array($ussdString, $values)) {
                $modified_text = str_replace("CON ", "", $dataType['text']);
                return "CON Invalid choice\n{$modified_text}";
            }
        }
        $options = $dataType['options'];
        if (array_key_exists($ussdString, $options)) {
            return self::{$options[$ussdString]}($ussdSession, $ussdString, $msisdn);
        }
        $function = $dataType['function'];
        return self::{$function}($ussdSession, $ussdString, $msisdn);
    }

    //Displays  Menu

    /**
     * This menu is only for non-registered members
     */
    public static function showMenu($ussdSession, $ussdString)
    {
        Redis::set($ussdSession['msisdn'] . 'menu', 'showMenu');
        $text = "CON Welcome to Bitwise.\n1.Register\n2.Contact Us\n3.Send Us Feedback \n0.Quit.";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'showMenu',
                'text' => $text,
                'options' => [
                    '1' => 'Register',
                    '2' => 'ContactUs',
                    '3' => 'SendFeedback', 
                    '0' => 'Quit',
                
                    ]
                ]),
            ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }

    /**
     * This menu is only for registered members
     */
    public static function showMenuRegistered($ussdSession, $ussdString)
    {
        Redis::set($ussdSession['msisdn'] . 'menu', 'showMenuRegistered');
        $text = "CON Welcome to Bitwise.\n1.View Profile\n2.Contact Us\n3.Send Us Feedback \n0.Quit.";
              
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'choice',
                'name' => 'showMenuRegistered',
                'text' => $text,
                'options' => [
                    '1' => 'ViewProfile',
                    '2' => 'ContactUs',
                    '3' => 'SendFeedback',
                    '0' => 'Quit',
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function ViewProfile($msisdn, $userExists)
    {
        if ($userExists) {
            $userProfile = self::getUserProfile($msisdn);

            if ($userProfile) {
                $text = "END Your Profile:\nName: {$userProfile['name']}\nEmail: {$userProfile['email']}\nPhone: {$userProfile['phone']}\nLocation: {$userProfile['location']}\n00. Home";
            } else {
                $text = "END User profile not found.";
            }
        } else {
            $text = "END User not registered. Please register first.";
        }

        return $text;
    }

    public static function getUserProfile($msisdn)
    {
        $user = User::where('phone', $msisdn)->first();
        return $user ? $user->toArray() : null;
    }


    public static function Register($ussdSession, $ussdString)
    {
        $text = "CON Please enter your Full Name\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertName',
                'name' => 'Register',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        return $text;
    }
    public static function InsertName($ussdSession, $ussdString)
    {
        $text = "CON Please enter your Email\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertEmail',
                'name' => 'InsertName',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        Redis::set($ussdSession['session_id'] . 'name',$ussdString );
        return $text;
    }
    public static function InsertEmail($ussdSession, $ussdString)
    {
        $text = "CON Please enter your Location\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertLocation',
                'name' => 'InsertEmail',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'].'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));
        Redis::set($ussdSession['session_id'] . 'email',$ussdString );
        return $text;
    }
    public static function insertLocation($ussdSession, $ussdString, $userResponse)
{
    $sessionID = $ussdSession['session_id'];
    $name = Redis::get($sessionID . 'name');
    $phoneNumber = Redis::get($sessionID . 'phone');
    $email = Redis::get($sessionID . 'email');
    $location = Redis::get($sessionID . 'location');

    
    // echo "Debug - Location: $location, UserResponse: $userResponse\n";

    if (empty($location)) {
        Redis::set($sessionID . 'location', $ussdString);

        $text = "CON Confirm Details\n";
        $text .= "Email: " . $email . "\n";
        $text .= "Phone: " . $ussdSession['msisdn'] . "\n";
        $text .= "Name: " . $name . "\n";
        $text .= "Location: " . $ussdString . "\n";
        $text .= "1. Confirm\n";
        $text .= "2. Cancel";

        // Update USSD session state
        $ussdSession['state'] = 'confirm_details';
        Redis::set($sessionID, json_encode($ussdSession));

        return $text;
    }

$userResponse = (string) $userResponse;

$userInput = $ussdString;

if ($userResponse === '1' || $userInput === '1') {
    $userModel = new User([
        'email' => $email,
        'name' => $name,
        'phone' => $ussdSession['msisdn'],
        'location' => $location,
    ]);

    $userModel->save();
    self::clearCache($ussdSession['msisdn'], $ussdSession);

    return "END Details saved successfully";
} elseif ($userResponse === '2' || $userInput === '2') {
    // Clear the location from the cache
    Redis::del($sessionID . 'location');
    
    $text = "CON Details not saved.\n00. Home" ;

    // Update USSD session state
    $ussdSession['state'] = 'main_menu';
    Redis::set($sessionID, json_encode($ussdSession));

    return $text;
} else {
    $text = "CON Invalid option. Confirm Details\n";
    $text .= "1. Confirm\n";
    $text .= "2. Cancel";
    return $text;
}

}
    public static function contactUs($ussdSession)
    {
        return "END Contact Us via:\nEmail:info@bitwise.co.ke \nPhone:0100000000 \n00 .Home";
    }
    static function clearCache($msisdn, $ussdSession)
    {
        Redis::del($msisdn . 'menu');
        Redis::del($msisdn . 'name');
        Redis::del($msisdn . 'email');
        Redis::del($msisdn . 'location');
        Redis::del($ussdSession['session_id'] . 'ussdSession');
    }
    public static function SendFeedback($ussdSession, $ussdString)
    {
        Redis::set($ussdSession['msisdn'], 'name'); 

        $text = "CON We value your Feedback.Please Enter Your Message\n00. Home";
        $data = [
            'msisdn' => $ussdSession['msisdn'],
            'session_id' => $ussdSession['session_id'],
            'ussd_string' => $ussdString,
            'action' => json_encode([
                'type' => 'input',
                'function' => 'insertFeedback',
                'name' => 'SendFeedback',
                'text' => $text,
                'options' => [
                    '00' => Redis::get($ussdSession['msisdn'] . 'menu')
                ]
            ]),
        ];
        Redis::set($ussdSession['session_id'] . 'ussdSession', json_encode($data));

        return $text;
    }
    public static function insertFeedback($ussdSession, $ussdString)
    {
        $text = "END Your Feedback was received Successfully\n00. Home";
        Redis::set($ussdSession['session_id'] . 'ussdSession',$ussdString );

        return $text;
    
    }
    // Quit Menu
    public static function Quit($ussdSession)
    {
        self::clearCache($ussdSession['msisdn'], $ussdSession);
        return "END Thank you for using Bitwise";
    }   
    
}