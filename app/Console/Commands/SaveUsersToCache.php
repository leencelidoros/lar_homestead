<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;

class SaveUsersToCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:save-users-to-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $users =User::all();
        // User::find(1)->update(['phone'=>'254745460260']);
        // Redis::del('users');
        $user = User::create([
            'name'=>'Leence Lidonde',
            'phone' => '254745460260',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
        ]);
        Redis::set('users',json_encode($users));
    }
}
