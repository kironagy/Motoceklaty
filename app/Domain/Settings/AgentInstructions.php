<?php

namespace App\Domain\Settings;

use App\Models\AgentInstructionVersion;
use Illuminate\Support\Facades\DB;

/**
 * The L0 system instructions. The active dashboard version wins; with no
 * dashboard version the shipped file (resources/agent/instructions/agent.md)
 * is used, so a fresh install behaves exactly as before.
 */
class AgentInstructions
{
    public const FILE_PATH = 'agent/instructions/agent.md';

    /** @return array{version: string, text: string, source: string, id: ?int} */
    public function current(): array
    {
        $active = $this->activeVersion();

        if ($active) {
            return ['version' => $active->version, 'text' => trim($active->content), 'source' => 'dashboard', 'id' => $active->id];
        }

        return $this->fromFile() + ['source' => 'file', 'id' => null];
    }

    /** @return array{version: string, text: string} */
    public function fromFile(): array
    {
        $raw = trim((string) file_get_contents(resource_path(self::FILE_PATH)));
        $lines = explode("\n", $raw, 2);

        return ['version' => trim($lines[0]), 'text' => trim($lines[1] ?? '')];
    }

    public function activeVersion(): ?AgentInstructionVersion
    {
        try {
            return AgentInstructionVersion::where('is_active', true)->latest('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Saves the text as a new active version with the next patch number. */
    public function publish(string $content, ?string $notes = null, ?int $staffId = null): AgentInstructionVersion
    {
        $version = $this->nextVersion($this->current()['version']);

        return DB::transaction(function () use ($version, $content, $notes, $staffId) {
            AgentInstructionVersion::where('is_active', true)->update(['is_active' => false]);

            return AgentInstructionVersion::create([
                'version' => $version,
                'content' => trim($content),
                'notes' => $notes,
                'is_active' => true,
                'created_by' => $staffId,
            ]);
        });
    }

    public function activate(AgentInstructionVersion $version): void
    {
        // query updates, not $version->update(): the in-memory model may
        // still say is_active=true, so Eloquent would see no change to save
        DB::transaction(function () use ($version) {
            AgentInstructionVersion::where('is_active', true)->update(['is_active' => false]);
            AgentInstructionVersion::whereKey($version->id)->update(['is_active' => true]);
        });

        $version->refresh();
    }

    /** Goes back to the shipped file. */
    public function useFile(): void
    {
        AgentInstructionVersion::where('is_active', true)->update(['is_active' => false]);
    }

    public function nextVersion(string $current): string
    {
        if (preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', trim($current), $m)) {
            $highest = [(int) $m[1], (int) $m[2], (int) $m[3]];

            // never reuse a number an older dashboard version already took
            foreach (AgentInstructionVersion::pluck('version') as $existing) {
                if (preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', $existing, $e)) {
                    $candidate = [(int) $e[1], (int) $e[2], (int) $e[3]];

                    if ($candidate > $highest) {
                        $highest = $candidate;
                    }
                }
            }

            return sprintf('v%d.%d.%d', $highest[0], $highest[1], $highest[2] + 1);
        }

        return trim($current).'.1';
    }
}
