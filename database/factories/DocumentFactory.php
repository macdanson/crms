<?php

namespace Database\Factories;

use App\Enums\DocumentCategory;
use App\Enums\ScanStatus;
use App\Models\CriminalCase;
use App\Models\Document;
use App\Models\User;
use App\Services\DataProtection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public const CONTENTS = 'Synthetic document contents.';

    /**
     * Define the model's default state. The file really exists, encrypted, on
     * the (usually faked) documents disk.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $storageKey = 'test/'.Str::random(48);

        Storage::disk('documents')->put($storageKey, app(DataProtection::class)->encrypt(self::CONTENTS));

        return [
            'case_id' => CriminalCase::factory(),
            'category' => DocumentCategory::Report,
            'original_name' => 'synthetic.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => strlen(self::CONTENTS),
            'sha256' => hash('sha256', self::CONTENTS),
            'storage_key' => $storageKey,
            'scan_status' => ScanStatus::Clean,
            'uploaded_by' => User::factory(),
        ];
    }
}
