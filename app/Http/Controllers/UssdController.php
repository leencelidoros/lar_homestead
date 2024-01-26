<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use App\Repositories\UssdRepositoryCache;
use Illuminate\Support\Facades\Log;

class UssdController extends Controller
{
    
    function ussd(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phoneNumber' => ['required'],
            'networkCode' => ['required'],
            'serviceCode' => ['nullable', 'string'],
            'sessionId' => ['required', 'string']
        ]);
        $msisdn = $request['phoneNumber'];

        if ($validator->fails()) {
            return "END The application is under maintenance. Try again later";
        }
        $str = $request['text'];
        if (str_contains($str, '*')) {
            $arr = explode("*", $str);
            $len = count($arr);

            $ussdString = $arr[$len - 1];
        } else {
            $ussdString = $str;
        }

        // find last session for user
        $ussdSession = json_decode(Redis::get($request['sessionId'].'ussdSession'),true);
        $userExists = 0;
        $users = json_decode(Redis::get('users'),true);
        if ($users) {
            foreach ($users as $object) {
                if ($object['phone'] === $msisdn) {
                    $userExists = 1;
                    break; // Exit the loop if the phone number is found
                }
            }
        }
        // if no session, start a new one
        if (!isset($ussdSession)) {
            if ($userExists == 1) {
                $text = "CON Welcome to Bitwise.\n1.View Profile\n2.Contacts Us\n3.Send Us Feedback \n4.Quit.";
                $options = [
                    '1' => 'ViewProfile',
                    '2' => 'ContactsUs',
                    '3' => 'SendFeedback',
                    '4' => 'Quit',
                
                ];
            } else {
                $text = "CON Welcome to Bitwise.\n1.Register\n2.Contacts Us\n3.Send Us Feedback \n4.Quit.";
                $options = [
                    '1' => 'Register',
                    '2' => 'ContactsUs',
                    '3' => 'SendFeedback',
                    '4' => 'Quit',
                
                ];
            }
            $data = [
                'msisdn' => $msisdn,
                'session_id' => $request['sessionId'],
                'ussd_string' => $text,
                'action' => json_encode([
                    'type' => 'choice',
                    'name' => 'process',
                    'text' => $text,
                    'options' => $options
                ]),
            ];
            Redis::set($request['sessionId'].'ussdSession',json_encode($data));
            $ussdSession = json_decode(Redis::get($request['sessionId'] . 'ussdSession'),true);
            //return $text;
        }
        return UssdRepositoryCache::process($ussdSession, $ussdString, $msisdn, $userExists);
    }
}
