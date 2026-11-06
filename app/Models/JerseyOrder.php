<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JerseyOrder extends Model
{

    private const MANUFACTURER_PERSONALIZATION_KEYS = [
        'Custom Name',
        'Player Name',
        'Name',
        'Name on Back',
        'Jersey Name',
        'First Name',
        'Last Name',
        'Firstname',
        'Lastname',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shopify_order_gid',
        'shopify_order_number',
        'shopify_line_item_gid',
        'shopify_product_gid',
        'shopify_variant_gid',
        'ordered_at',
        'product_title',
        'product_handle',
        'variant_title',
        'style',
        'color',
        'size',
        'display_name',
        'quantity',
        'image_url',
        'custom_fields',
        'selected_options',
        'product_tags',
        'shopify_fulfillment_status',
        'shopify_financial_status',
        'shopify_cancelled_at',
        'shopify_closed_at',
        'tracking_number',
        'tracking_updated_at',
        'tracking_updated_by',
        'remark',
        'remark_updated_at',
        'remark_updated_by',
        'remark_processed_at',
        'remark_processed_by',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'custom_fields' => 'array',
            'selected_options' => 'array',
            'product_tags' => 'array',
            'shopify_cancelled_at' => 'datetime',
            'shopify_closed_at' => 'datetime',
            'tracking_updated_at' => 'datetime',
            'remark_updated_at' => 'datetime',
            'remark_processed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function trackingUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tracking_updated_by');
    }

    public function remarkUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remark_updated_by');
    }

    public function remarkProcessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remark_processed_by');
    }

    /**
     * @return list<array{key:string,value:string}>
     */
    public function manufacturerPersonalizationFields(): array
    {
        return collect($this->custom_fields ?? [])
            ->filter(fn (mixed $field): bool => is_array($field))
            ->map(fn (array $field): array => [
                'key' => trim((string) ($field['key'] ?? '')),
                'value' => trim((string) ($field['value'] ?? '')),
            ])
            ->filter(function (array $field): bool {
                if ($field['key'] === '' || str_starts_with($field['key'], '_')) {
                    return false;
                }

                if (! in_array($field['key'], self::MANUFACTURER_PERSONALIZATION_KEYS, true)) {
                    return false;
                }

                return $field['value'] !== '' || in_array($field['key'], ['First Name', 'Last Name'], true);
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{key:string,value:string}>
     */
    public function visibleCustomFields(): array
    {
        return $this->customFields(internal: false);
    }

    /**
     * @return list<array{key:string,value:string}>
     */
    public function technicalCustomFields(): array
    {
        return $this->customFields(internal: true);
    }

    public function scopeEligibleForProduction(Builder $query): Builder
    {
        return $query->whereNull('shopify_cancelled_at')
            ->where(fn (Builder $query) => $query->whereNull('shopify_financial_status')->orWhereRaw('UPPER(shopify_financial_status) <> ?', ['REFUNDED']));
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        $like = '%'.$search.'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->where('shopify_order_number', 'like', $like)
                ->orWhere('product_title', 'like', $like)
                ->orWhere('variant_title', 'like', $like)
                ->orWhere('display_name', 'like', $like)
                ->orWhere('tracking_number', 'like', $like)
                ->orWhere('color', 'like', $like)
                ->orWhere('size', 'like', $like)
                ->orWhere('style', 'like', $like);
        });
    }

    public function scopeForProduct(Builder $query, ?string $product): Builder
    {
        return $this->whereIfFilled($query, 'product_title', $product);
    }

    public function scopeForVariant(Builder $query, ?string $variant): Builder
    {
        return $this->whereIfFilled($query, 'variant_title', $variant);
    }

    public function scopeForColor(Builder $query, ?string $color): Builder
    {
        return $this->whereIfFilled($query, 'color', $color);
    }

    public function scopeForSize(Builder $query, ?string $size): Builder
    {
        return $this->whereIfFilled($query, 'size', $size);
    }

    public function scopeForFulfillmentStatus(Builder $query, ?string $status): Builder
    {
        return $this->whereIfFilled($query, 'shopify_fulfillment_status', $status);
    }

    private function whereIfFilled(Builder $query, string $column, ?string $value): Builder
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $query;
        }

        return $query->where($column, $value);
    }

    /**
     * @return list<array{key:string,value:string}>
     */
    private function customFields(bool $internal): array
    {
        return collect($this->custom_fields ?? [])
            ->filter(fn (mixed $field): bool => is_array($field))
            ->map(fn (array $field): array => [
                'key' => trim((string) ($field['key'] ?? '')),
                'value' => trim((string) ($field['value'] ?? '')),
            ])
            ->filter(fn (array $field): bool => $field['key'] !== '' && str_starts_with($field['key'], '_') === $internal)
            ->values()
            ->all();
    }
}
