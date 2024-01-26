<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use App\Repositories\UssdRepositoryCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UssdController extends Controller
{
    
    function ussd(Request $request)
    {

            $validator = Validator::make($request->all(), [
                'phoneNumber' => [
                    'required',
                    'regex:/^(?:\+?254|0)\d{9}$/',
                    Rule::exists('users', 'phone'),
                ],
                'networkCode' => ['required'],
                'serviceCode' => ['nullable', 'string'],
                'sessionId' => ['required', 'string'],
            ]);

            $msisdn = $request->input('phoneNumber');

            if ($validator->fails()) {

                $userExists = 0;
            } else {
                // Phone number exists in the database
                $userExists = 1;
            }

            $str = $request->input('text');
            if (str_contains($str, '*')) {
                $arr = explode("*", $str);
                $ussdString = end($arr);
            } else {
                $ussdString = $str;
            }

            // find last session for user
            $ussdSession = json_decode(Redis::get($request->input('sessionId') . 'ussdSession'), true);

            // if no session, start a new one
            if (!isset($ussdSession)) {
                $text = "CON Welcome to Bitwise.\n";
                
                if ($userExists == 1) {
                    // Menu for registered users
                    $text .= "1.View Profile\n2.Contacts Us\n3.Send Us Feedback \n4.Quit.";
                    $options = [
                        '1' => 'ViewProfile',
                        '2' => 'ContactsUs',
                        '3' => 'SendFeedback',
                        '4' => 'Quit',
                    ];
                } else {
                    // Menu for new users
                    $text .= "1.Register\n2.Contacts Us\n3.Send Us Feedback \n4.Quit.";
                    $options = [
                        '1' => 'Register',
                        '2' => 'ContactsUs',
                        '3' => 'SendFeedback',
                        '4' => 'Quit',
                    ];
                }
                
                $data = [
                    'msisdn' => $msisdn,
                    'session_id' => $request->input('sessionId'),
                    'ussd_string' => $text,
                    'action' => json_encode([
                        'type' => 'choice',
                        'name' => 'process',
                        'text' => $text,
                        'options' => $options
                    ]),
                ];

                Redis::set($request->input('sessionId') . 'ussdSession', json_encode($data));
                $ussdSession = json_decode(Redis::get($request->input('sessionId') . 'ussdSession'), true);
            }

            return UssdRepositoryCache::process($ussdSession, $ussdString, $msisdn, $userExists);
                }
            }
