<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\Quotation;
use App\Models\QuotationAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StoreQuotationAttachments
{
    /** @param list<UploadedFile> $files */
    public function handle(Quotation $quotation, array $files, User $actor, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        if ($files === []) {
            return;
        }
        if (! $actor->mayViewAllLocations() && ! $actor->locations()->whereKey($quotation->location_id)->exists()) {
            abort(403);
        }

        $storedPaths = [];
        try {
            DB::transaction(function () use ($quotation, $files, $actor, $ipAddress, $userAgent, &$storedPaths): void {
                $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
                $metadata = [];
                foreach ($files as $file) {
                    $filename = Str::uuid().'.'.($file->guessExtension() ?: 'bin');
                    $directory = "quotations/{$quotation->id}/revisions/{$quotation->revision}";
                    $path = $file->storeAs($directory, $filename, 'local');
                    if (! is_string($path) || $path === '') {
                        throw new RuntimeException('The quotation attachment could not be stored.');
                    }
                    $storedPaths[] = $path;
                    $originalName = preg_replace('/[\r\n\x00-\x1F]/', '', basename($file->getClientOriginalName())) ?: $filename;
                    $attachment = QuotationAttachment::query()->create([
                        'quotation_id' => $quotation->id,
                        'uploaded_by' => $actor->id,
                        'revision' => $quotation->revision,
                        'original_name' => Str::limit($originalName, 180, ''),
                        'disk' => 'local',
                        'path' => $path,
                        'mime_type' => (string) $file->getMimeType(),
                        'size_bytes' => (int) $file->getSize(),
                    ]);
                    $metadata[] = ['id' => $attachment->id, 'name' => $attachment->original_name, 'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes];
                }

                AuditLog::query()->create([
                    'actor_id' => $actor->id,
                    'location_id' => $quotation->location_id,
                    'action' => 'quotation.attachments_uploaded',
                    'subject_type' => Quotation::class,
                    'subject_id' => $quotation->id,
                    'after_state' => ['revision' => $quotation->revision, 'attachments' => $metadata],
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'reason' => 'Quotation supporting documents were uploaded.',
                    'occurred_at' => now(),
                ]);
            }, attempts: 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }
    }
}
