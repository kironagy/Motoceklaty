<?php

namespace App\Filament\Resources\DailyTaskResource\Pages;

use App\Filament\Resources\DailyTaskResource;
use App\Models\DailyTask;
use App\Models\DailyTaskFile;
use App\Support\StaffNotifier;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDailyTask extends CreateRecord
{
    protected static string $resource = DailyTaskResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(in_array((int) $data['team_id'], DailyTaskResource::managedTeamIds(), true), 403);

        $files = (array) ($data['new_attachments'] ?? []);
        $names = (array) ($data['new_attachment_names'] ?? []);
        unset($data['new_attachments'], $data['new_attachment_names']);

        $task = DailyTask::create([...$data, 'created_by' => auth()->id(), 'status' => DailyTask::TODO]);
        DailyTaskResource::saveFiles($task, $files, $names, DailyTaskFile::ATTACHMENT);

        if ((int) $task->assigned_to !== (int) auth()->id()) {
            StaffNotifier::send(
                (int) $task->assigned_to,
                'مهمة جديدة',
                auth()->user()->name.': '.$task->title,
                DailyTaskResource::getUrl('index'),
                'daily-task-'.$task->id,
            );
        }

        return $task;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
