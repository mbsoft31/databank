<?php
// database/factories/UserFactory.php
namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        $roles = ['admin', 'author', 'reviewer', 'editor', 'viewer'];
        $arabicNames = [
            'أحمد محمد', 'فاطمة علي', 'عمر حسن', 'عائشة إبراهيم', 'محمود أحمد',
            'خديجة محمد', 'يوسف علي', 'مريم حسن', 'إبراهيم يوسف', 'زينب محمود',
            'عبدالله أحمد', 'آمنة علي', 'سعد محمد', 'هدى إبراهيم', 'خالد عمر',
        ];

        $role = fake()->randomElement($roles);

        return [
            'name' => fake()->randomElement($arabicNames),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => fake()->boolean(80) ? now() : null,
            'password' => static::$password ??= Hash::make('password'),
            'role' => $role,
            'is_admin' => $role === 'admin' || fake()->boolean(5), // 5% chance for non-admin to be admin
            'locale' => fake()->randomElement(['ar', 'en']),
            'preferences' => [
                'theme' => fake()->randomElement(['light', 'dark', 'auto']),
                'language' => fake()->randomElement(['ar', 'en']),
                'notifications' => [
                    'email' => fake()->boolean(70),
                    'browser' => fake()->boolean(80),
                    'reviews' => fake()->boolean(90),
                    'exports' => fake()->boolean(60),
                ],
                'ui' => [
                    'items_per_page' => fake()->randomElement([10, 15, 25, 50]),
                    'show_latex_preview' => fake()->boolean(80),
                    'auto_save_drafts' => fake()->boolean(90),
                ],
            ],
            'last_active_at' => fake()->boolean(60)
                ? fake()->dateTimeBetween('-7 days', 'now')
                : null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
            'is_admin' => true,
        ]);
    }

    /**
     * Indicate that the user is an author.
     */
    public function author(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'author',
            'is_admin' => false,
        ]);
    }

    /**
     * Indicate that the user is a reviewer.
     */
    public function reviewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'reviewer',
            'is_admin' => false,
        ]);
    }

    /**
     * Indicate that the user is recently active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_active_at' => fake()->dateTimeBetween('-2 hours', 'now'),
        ]);
    }

    /**
     * Indicate that the user is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_active_at' => fake()->dateTimeBetween('-6 months', '-1 month'),
        ]);
    }

    /**
     * Create a user with specific preferences.
     */
    public function withPreferences(array $preferences): static
    {
        return $this->state(fn (array $attributes) => [
            'preferences' => array_merge($attributes['preferences'] ?? [], $preferences),
        ]);
    }
}
