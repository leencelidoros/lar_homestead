<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory()
        //     ->count(10) // Change the count to the number of users you want to create.
        //     ->create();
        // Post::factory()
        //     ->count(10) // Change the count to the number of users you want to create.
        //     ->create();
        // Comment::factory()
        //     ->count(10) // Change the count to the number of users you want to create.
        //     ->create();
     $this->call([CustomerSeeder::class]);  

    }

}
