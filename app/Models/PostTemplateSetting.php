<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PostTemplateSetting extends Model
{
    protected $fillable = [
        'logo_path', 'font_path', 'brand_name', 'banner_color', 'text_color',
        'show_logo', 'logo_position',
        'card_margin_x', 'card_margin_bottom', 'card_height', 'headline_font_size',
        'line_spacing', 'show_social_row', 'social_platforms', 'social_usernames',
        'icon_style', 'icon_color', 'icon_bg_color',
        'social_icon_size', 'social_username_font_size',
    ];

    protected function casts(): array
    {
        return [
            'show_logo' => 'boolean',
            'show_social_row' => 'boolean',
            'social_platforms' => 'array',
            'social_usernames' => 'array',
            'card_margin_x' => 'float',
            'card_margin_bottom' => 'float',
            'card_height' => 'float',
            'headline_font_size' => 'integer',
            'line_spacing' => 'float',
            'social_icon_size' => 'integer',
            'social_username_font_size' => 'integer',
        ];
    }

    protected $attributes = [
        'brand_name' => '',
        'banner_color' => '#0B5E34',
        'text_color' => '#FFFFFF',
        'show_logo' => true,
        'logo_position' => 'top-left',
        'card_margin_x' => 4.50,
        'card_margin_bottom' => 3.00,
        'card_height' => 26.00,
        'headline_font_size' => 40,
        'line_spacing' => 1.30,
        'show_social_row' => true,
        'icon_style' => 'brand',
        'icon_color' => '#FFFFFF',
        'icon_bg_color' => '#FFFFFF',
        'social_icon_size' => 29,
        'social_username_font_size' => 9,
    ];

    /** The single settings row (created on first access). */
    public static function current(): self
    {
        $settings = static::query()->firstOrCreate([]);

        if ($settings->social_platforms === null) {
            $settings->social_platforms = ['facebook', 'twitter', 'instagram'];
        }

        if ($settings->social_usernames === null) {
            $settings->social_usernames = [];
        }

        return $settings;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function logoAbsolutePath(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->path($this->logo_path) : null;
    }

    public function fontAbsolutePath(): ?string
    {
        return $this->font_path ? Storage::disk('public')->path($this->font_path) : null;
    }

    public function hasLogo(): bool
    {
        return $this->logo_path && Storage::disk('public')->exists($this->logo_path);
    }

    public function hasFont(): bool
    {
        return $this->font_path && Storage::disk('public')->exists($this->font_path);
    }
}
