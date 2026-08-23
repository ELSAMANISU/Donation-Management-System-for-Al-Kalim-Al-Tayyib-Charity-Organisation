<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CategoryImageService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function upload(User $actor, Category $category, UploadedFile $image, Request $request): Category
    {
        $path = $this->storeUploadedImage($image, $category->getKey());

        try {
            [$updatedCategory, $oldPath] = DB::transaction(function () use ($actor, $category, $path, $request): array {
                $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
                $lockedCategory = Category::query()->lockForUpdate()->findOrFail($category->getKey());

                Gate::forUser($lockedActor)->authorize('update', $lockedCategory);

                if (! $lockedActor->is_active) {
                    abort(403);
                }

                $oldPath = $lockedCategory->image_path;
                $hadImage = is_string($oldPath) && $oldPath !== '';

                $lockedCategory->image_path = $path;
                $lockedCategory->updated_by = $lockedActor->getKey();
                $lockedCategory->save();

                $this->auditLogger->log(
                    action: $hadImage ? 'category.image_replaced' : 'category.image_uploaded',
                    actor: $lockedActor,
                    subject: $lockedCategory,
                    oldValues: ['had_image' => $hadImage, 'has_image' => $hadImage],
                    newValues: ['had_image' => $hadImage, 'has_image' => true],
                    request: $request,
                );

                return [$lockedCategory, $oldPath];
            });
        } catch (Throwable $exception) {
            $this->deleteManagedFileBestEffort($path, $category->getKey());

            throw $exception;
        }

        if ($oldPath !== $path) {
            $this->deleteManagedFileBestEffort($oldPath, $updatedCategory->getKey());
        }

        return $updatedCategory;
    }

    public function remove(User $actor, Category $category, Request $request): Category
    {
        [$updatedCategory, $oldPath] = DB::transaction(function () use ($actor, $category, $request): array {
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            $lockedCategory = Category::query()->lockForUpdate()->findOrFail($category->getKey());

            Gate::forUser($lockedActor)->authorize('update', $lockedCategory);

            if (! $lockedActor->is_active) {
                abort(403);
            }

            $oldPath = $lockedCategory->image_path;

            if ($oldPath === null || $oldPath === '') {
                return [$lockedCategory, null];
            }

            $lockedCategory->image_path = null;
            $lockedCategory->updated_by = $lockedActor->getKey();
            $lockedCategory->save();

            $this->auditLogger->log(
                action: 'category.image_removed',
                actor: $lockedActor,
                subject: $lockedCategory,
                oldValues: ['had_image' => true, 'has_image' => true],
                newValues: ['had_image' => true, 'has_image' => false],
                request: $request,
            );

            return [$lockedCategory, $oldPath];
        });

        $this->deleteManagedFileBestEffort($oldPath, $updatedCategory->getKey());

        return $updatedCategory;
    }

    private function storeUploadedImage(UploadedFile $image, int $categoryId): string
    {
        $extension = match ($image->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if ($extension === null) {
            throw ValidationException::withMessages([
                'image' => 'The uploaded file must be a valid JPEG, PNG, or WebP image.',
            ]);
        }

        $generatedFilename = Str::uuid()->toString().'.'.$extension;
        $expectedPath = 'categories/'.$generatedFilename;
        $storedPath = Storage::disk('public')->putFileAs(
            'categories',
            $image,
            $generatedFilename,
        );

        if ($storedPath !== $expectedPath || ! Category::isManagedImagePath($expectedPath)) {
            $this->deleteManagedFileBestEffort($expectedPath, $categoryId);

            throw ValidationException::withMessages([
                'image' => 'The category image could not be stored. Please try again.',
            ]);
        }

        return $expectedPath;
    }

    private function deleteManagedFileBestEffort(?string $path, int $categoryId): void
    {
        if (! Category::isManagedImagePath($path)) {
            return;
        }

        try {
            if (! Storage::disk('public')->delete($path)) {
                Log::warning('Managed category image cleanup failed after a storage operation.', [
                    'category_id' => $categoryId,
                ]);
            }
        } catch (Throwable) {
            Log::warning('Managed category image cleanup failed after a storage operation.', [
                'category_id' => $categoryId,
            ]);
        }
    }
}
