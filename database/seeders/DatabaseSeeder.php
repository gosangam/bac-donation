<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // is_admin is not mass-assignable by design, so it is set explicitly
        // rather than leaning on Seeder::unguard() doing it invisibly.
        User::updateOrCreate(
            ['email' => 'admin@brajanimalcare.com'],
            [
                'name' => 'BAC Admin',
                'password' => 'password',
                'phone' => '+91 89237 37924',
                'country' => 'IN',
            ]
        )->promoteToAdmin();

        User::updateOrCreate(
            ['email' => 'donor@example.com'],
            [
                'name' => 'Radha Sharma',
                'password' => 'password',
                'phone' => '+91 98765 43210',
                'address_line1' => '21 Parikrama Marg',
                'city' => 'Vrindavan',
                'state' => 'Uttar Pradesh',
                'postal_code' => '281121',
                'country' => 'IN',
            ]
        );

        // Amounts in paise. Gateway ids are intentionally blank — each has to be
        // created in that gateway's own dashboard and pasted in under Admin → Plans.
        $plans = [
            ['monthly-500', 'Monthly Seva', 50000, 'Feeds one cow for a month.', 1],
            ['monthly-1000', 'Gaushala Supporter', 100000, 'Feed and basic veterinary care.', 2],
            ['monthly-2500', 'Guardian', 250000, 'Supports rescue and emergency treatment.', 3],
            ['yearly-12000', 'Annual Patron', 1200000, 'One year of support, paid annually.', 4],
        ];

        foreach ($plans as [$slug, $name, $amount, $description, $sort]) {
            Plan::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'description' => $description,
                'amount' => $amount,
                'currency' => 'INR',
                'interval' => str_starts_with($slug, 'yearly') ? 'yearly' : 'monthly',
                'interval_count' => 1,
                'is_active' => true,
                'sort_order' => $sort,
            ]);
        }
    }
}
