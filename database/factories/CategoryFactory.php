<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $englishName = fake()->unique()->words(2, true);

        return [
            'name_ar' => fake()->randomElement(['الغذاء', 'التعليم', 'الصحة']).' '.fake()->unique()->numberBetween(1, 1000000),
            'name_en' => Str::title($englishName),
            'slug' => Str::slug($englishName).'-'.fake()->unique()->numberBetween(1, 1000000),
            'description_ar' => 'وصف تجريبي للفئة الخيرية.',
            'description_en' => 'A test description for the charity category.',
            'icon' => null,
            'image_path' => null,
            'display_order' => 0,
            'is_active' => true,
            'created_by' => null,
            'updated_by' => null,
            'deleted_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => ['deleted_at' => now()]);
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn (array $attributes) => ['display_order' => $position]);
    }
}
