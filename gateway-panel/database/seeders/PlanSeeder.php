<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'             => 'trial',
                'label'            => 'Trial',
                'price_usd'        => 0,
                'max_shield_sites' => 1,
                'is_active'        => true,
            ],
            [
                'name'             => 'start',
                'label'            => 'Start',
                'price_usd'        => 29,
                'max_shield_sites' => 1,
                'is_active'        => true,
            ],
            [
                'name'             => 'pro',
                'label'            => 'Pro',
                'price_usd'        => 99,
                'max_shield_sites' => 5,
                'is_active'        => true,
            ],
            [
                'name'             => 'enterprise',
                'label'            => 'Enterprise',
                'price_usd'        => 0,
                'max_shield_sites' => 999,
                'is_active'        => true,
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('plans')->updateOrInsert(
                ['name' => $plan['name']],
                array_merge($plan, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
