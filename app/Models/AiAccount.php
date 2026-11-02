<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiAccount extends Model
{
    public const REQUEST_MODE_SERIAL = 'serial';

    public const REQUEST_MODE_PARALLEL = 'parallel';

    protected $fillable = [
        'name',
        'provider',
        'base_url',
        'api_key',
        'default_model',
        'extra_headers',
        'is_active',
        'request_mode',
    ];

    protected $hidden = [
        'api_key',
        'extra_headers',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'extra_headers' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class)->orderBy('model_id');
    }

    public function getHasApiKeyAttribute(): bool
    {
        return filled($this->api_key);
    }

    public function isParallel(): bool
    {
        return $this->request_mode === self::REQUEST_MODE_PARALLEL;
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'base_url' => $this->base_url,
            'default_model' => $this->default_model,
            'is_active' => (bool) $this->is_active,
            'request_mode' => $this->request_mode ?? self::REQUEST_MODE_SERIAL,
            'has_api_key' => $this->has_api_key,
            'models' => $this->relationLoaded('models')
                ? $this->models->map(fn (AiModel $model) => [
                    'id' => $model->id,
                    'model_id' => $model->model_id,
                    'label' => $model->label,
                    'context_length' => $model->context_length,
                ])->values()->all()
                : [],
        ];
    }
}
