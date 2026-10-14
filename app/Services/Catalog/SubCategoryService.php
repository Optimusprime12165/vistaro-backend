<?php

namespace App\Services\Catalog;

use App\Abstracts\AbstractCrudService;
use App\Contracts\ImageStorageInterface;
use App\Exceptions\ConflictException;
use App\Models\SubCategory;
use App\Services\Catalog\Concerns\HandlesCatalogRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class SubCategoryService extends AbstractCrudService
{
    use HandlesCatalogRecords;

    private ?string $imageToDelete = null;

    public function __construct(private readonly ImageStorageInterface $storage)
    {
    }

    protected function modelClass(): string
    {
        return SubCategory::class;
    }

    protected function searchable(): array
    {
        return ['name', 'slug'];
    }

    protected function searchContains(): bool
    {
        return true;
    }

    protected function sortable(): array
    {
        return ['id' => 'id', 'name' => 'name', 'sort_order' => 'sort_order', 'created_at' => 'created_at'];
    }

    protected function defaultSort(): array
    {
        return ['sort_order', 'asc'];
    }

    protected function filterable(): array
    {
        return [
            'is_active' => fn (Builder $q, $v) => $q->where('is_active', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
            'category_id' => 'category_id',
        ];
    }

    protected function with(): array
    {
        return ['category:id,name,slug'];
    }

    protected function newQuery(): Builder
    {
        return parent::newQuery()->withCount('products');
    }

    protected function beforeStore(array $data): array
    {
        $data['slug'] = ! empty($data['slug']) ? $data['slug'] : $this->uniqueSlug($data['name'], SubCategory::class);
        [$data] = $this->applyImage($data, null, $this->storage, 'sub-categories');

        return $data;
    }

    protected function beforeUpdate(Model $model, array $data): array
    {
        if (array_key_exists('slug', $data) && empty($data['slug'])) {
            unset($data['slug']);
        }
        [$data, $this->imageToDelete] = $this->applyImage($data, $model->image_path, $this->storage, 'sub-categories');

        return $data;
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        $this->deleteStoredImage($this->imageToDelete, $this->storage);
    }

    protected function beforeDestroy(Model $model): void
    {
        if ($model->products()->exists()) {
            throw new ConflictException('This sub-category still has products. Move or archive them first.');
        }
    }

    protected function afterDestroy(Model $model): void
    {
        $this->deleteStoredImage($model->image_path, $this->storage);
    }
}
