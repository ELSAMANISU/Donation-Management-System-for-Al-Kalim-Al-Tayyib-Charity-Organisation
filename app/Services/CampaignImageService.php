<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class CampaignImageService
{
    private const DISK = 'campaign_images';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function upload(User $actor, Campaign $campaign, UploadedFile $image, string $altAr, string $altEn, Request $request): Campaign
    {
        $this->assertTopLevelTransaction();
        $extension = $this->detectedExtension($image);
        $filename = Str::uuid()->toString().'.'.$extension;
        $directory = 'campaigns/'.$campaign->getKey();
        $newPath = $directory.'/'.$filename;
        $storageAttempted = false;

        try {
            [$updatedCampaign, $oldPath] = DB::transaction(function () use ($actor, $campaign, $image, $altAr, $altEn, $request, $directory, $filename, $newPath, &$storageAttempted): array {
                $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
                $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->getKey());
                $this->authorizeMutation($lockedActor, $lockedCampaign);

                if (! Campaign::isManagedImagePath($newPath, $lockedCampaign->getKey())) {
                    throw new LogicException('Generated Campaign image path is invalid.');
                }

                $storageAttempted = true;
                $storedPath = Storage::disk(self::DISK)->putFileAs($directory, $image, $filename);
                if ($storedPath !== $newPath) {
                    throw ValidationException::withMessages(['image' => 'The Campaign image could not be stored. Please try again. / تعذر حفظ صورة الحملة. حاول مرة أخرى.']);
                }

                $oldPath = $lockedCampaign->image_path;
                $hadImage = is_string($oldPath) && $oldPath !== '';
                $lockedCampaign->image_path = $newPath;
                $lockedCampaign->image_alt_ar = $altAr;
                $lockedCampaign->image_alt_en = $altEn;
                $lockedCampaign->updated_by = $lockedActor->getKey();
                $lockedCampaign->save();

                $this->auditLogger->log(
                    action: $hadImage ? 'campaign.image_replaced' : 'campaign.image_uploaded',
                    actor: $lockedActor,
                    subject: $lockedCampaign,
                    oldValues: ['had_image' => $hadImage, 'has_image' => $hadImage],
                    newValues: ['had_image' => $hadImage, 'has_image' => true],
                    request: $request,
                );

                return [$lockedCampaign, $oldPath];
            });
        } catch (Throwable $exception) {
            if ($storageAttempted) {
                $isCommittedCurrentImage = $this->isCommittedCurrentImage($campaign->getKey(), $newPath);
                if ($isCommittedCurrentImage === false) {
                    $this->deleteManagedFileBestEffort($newPath, $campaign->getKey());
                } else {
                    $this->warnCleanupFailure($campaign->getKey());
                }
            }

            throw $exception;
        }

        if ($oldPath !== $newPath) {
            $this->deleteManagedFileBestEffort($oldPath, $updatedCampaign->getKey());
        }

        return $updatedCampaign;
    }

    public function remove(User $actor, Campaign $campaign, Request $request): Campaign
    {
        $this->assertTopLevelTransaction();

        [$updatedCampaign, $oldPath] = DB::transaction(function () use ($actor, $campaign, $request): array {
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->getKey());
            $this->authorizeMutation($lockedActor, $lockedCampaign);
            $oldPath = $lockedCampaign->image_path;

            if ($oldPath === null || $oldPath === '') {
                return [$lockedCampaign, null];
            }

            $lockedCampaign->image_path = null;
            $lockedCampaign->image_alt_ar = null;
            $lockedCampaign->image_alt_en = null;
            $lockedCampaign->updated_by = $lockedActor->getKey();
            $lockedCampaign->save();
            $this->auditLogger->log(
                action: 'campaign.image_removed',
                actor: $lockedActor,
                subject: $lockedCampaign,
                oldValues: ['had_image' => true, 'has_image' => true],
                newValues: ['had_image' => true, 'has_image' => false],
                request: $request,
            );

            return [$lockedCampaign, $oldPath];
        });

        $this->deleteManagedFileBestEffort($oldPath, $updatedCampaign->getKey());

        return $updatedCampaign;
    }

    /** @return array{content:string,mime:string} */
    public function preview(Campaign $campaign): array
    {
        $path = $campaign->image_path;
        if (! Campaign::isManagedImagePath($path, $campaign->getKey())) {
            abort(404);
        }

        try {
            $disk = Storage::disk(self::DISK);
            if (! $disk->exists($path)) {
                abort(404);
            }
            $content = $disk->get($path);
        } catch (Throwable) {
            abort(404);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$extension] ?? null;
        if ($mime === null) {
            abort(404);
        }

        return ['content' => $content, 'mime' => $mime];
    }

    private function authorizeMutation(User $actor, Campaign $campaign): void
    {
        Gate::forUser($actor)->authorize('update', $campaign);
        if ($campaign->status !== CampaignStatus::Draft) {
            abort(403);
        }
        if ($campaign->raised_amount !== '0.00') {
            throw ValidationException::withMessages(['image' => 'This draft has an unexpected raised balance and its image cannot be changed. / تحتوي هذه المسودة على رصيد مرفوع غير متوقع ولا يمكن تغيير صورتها.']);
        }
    }

    private function detectedExtension(UploadedFile $image): string
    {
        return match ($image->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['image' => 'The uploaded file must be a valid JPEG, PNG, or WebP image. / يجب أن يكون الملف المرفوع صورة JPEG أو PNG أو WebP صالحة.']),
        };
    }

    private function assertTopLevelTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Campaign image mutations require a top-level database transaction.');
        }
    }

    private function deleteManagedFileBestEffort(?string $path, int $campaignId): void
    {
        if (! Campaign::isManagedImagePath($path, $campaignId)) {
            return;
        }

        try {
            if (! Storage::disk(self::DISK)->delete($path)) {
                $this->warnCleanupFailure($campaignId);
            }
        } catch (Throwable) {
            $this->warnCleanupFailure($campaignId);
        }
    }

    private function isCommittedCurrentImage(int $campaignId, string $newPath): ?bool
    {
        if (DB::transactionLevel() !== 0) {
            return null;
        }

        try {
            return Campaign::withTrashed()->find($campaignId)?->image_path === $newPath;
        } catch (Throwable) {
            return null;
        }
    }

    private function warnCleanupFailure(int $campaignId): void
    {
        try {
            Log::warning('Managed Campaign image cleanup failed after a storage operation.', ['campaign_id' => $campaignId]);
        } catch (Throwable) {
            // Cleanup diagnostics must never change the mutation outcome.
        }
    }
}
