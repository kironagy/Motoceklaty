<?php

namespace App\Filament\Support;

use App\Models\PermissionTemplate;
use App\Support\Access;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;

/**
 * The tabs x actions grid used by roles and by a staff member's custom permissions, with saved
 * templates that tick several tabs at once. State is {TabKey: [abilities]}, matching App\Support\Access.
 */
class PermissionMatrix
{
    public static function field(string $statePath = 'permissions'): Forms\Components\ViewField
    {
        return Forms\Components\ViewField::make($statePath)
            ->label('التابات والصلاحيات')
            ->view('filament.forms.permission-matrix')
            ->viewData([
                'groups' => static::groups(),
                'abilities' => Access::COLUMNS,
            ])
            ->hintActions([
                static::applyTemplateAction(),
                static::saveTemplateAction(),
                static::deleteTemplateAction(),
            ])
            ->columnSpanFull();
    }

    /** @return list<array{name: string, tabs: list<array{key: string, label: string, group: string, abilities: list<string>}>}> */
    public static function groups(): array
    {
        return collect(Access::tabs())
            ->map(fn (array $tab, string $key) => [
                'key' => $key,
                'label' => trim((string) $tab['label']),
                'group' => $tab['group'],
                'abilities' => array_keys($tab['abilities']),
            ])
            ->groupBy('group')
            ->map(fn ($tabs, $name) => ['name' => $name, 'tabs' => $tabs->values()->all()])
            ->values()
            ->all();
    }

    /** Drops tabs with nothing ticked and unknown tabs/abilities so the stored JSON stays short. */
    public static function clean(?array $permissions): array
    {
        $tabs = Access::tabs();

        return collect($permissions ?? [])
            ->filter(fn ($abilities, $key) => isset($tabs[$key]))
            ->map(fn ($abilities, $key) => array_values(array_intersect(array_keys($tabs[$key]['abilities']), (array) $abilities)))
            ->filter()
            ->all();
    }

    /** Ticks one or more saved templates into the grid, added to what's ticked or replacing it. */
    protected static function applyTemplateAction(): Action
    {
        return Action::make('applyPermissionTemplate')
            ->label('تطبيق تمبلت')
            ->icon('heroicon-m-sparkles')
            ->color('primary')
            ->modalHeading('تطبيق تمبلت صلاحيات')
            ->modalDescription('اختار تمبلت أو أكتر، وصلاحياتهم كلها هتتعلّم مع بعض.')
            ->modalSubmitActionLabel('تطبيق')
            ->modalWidth('lg')
            ->form([
                Forms\Components\CheckboxList::make('templates')
                    ->label('التمبلتس')
                    ->options(fn () => PermissionTemplate::orderBy('name')->pluck('name', 'id'))
                    ->descriptions(fn () => PermissionTemplate::orderBy('name')->get()
                        ->mapWithKeys(fn (PermissionTemplate $t) => [$t->id => count($t->permissions ?? []).' تاب'])
                        ->all())
                    ->searchable()
                    ->bulkToggleable()
                    ->required()
                    ->validationMessages(['required' => 'اختار تمبلت واحد على الأقل.']),
                Forms\Components\Radio::make('mode')
                    ->label('الطريقة')
                    ->options([
                        'merge' => 'ضيفهم على اللي متعلّم دلوقتي',
                        'replace' => 'امسح اللي متعلّم وحط التمبلتس بس',
                    ])
                    ->default('merge')
                    ->required(),
            ])
            ->hidden(fn () => ! PermissionTemplate::exists())
            ->action(function (array $data, Forms\Components\ViewField $component) {
                $result = $data['mode'] === 'replace' ? [] : (array) $component->getState();

                foreach (PermissionTemplate::whereIn('id', $data['templates'])->get() as $template) {
                    foreach ((array) $template->permissions as $key => $abilities) {
                        $result[$key] = array_values(array_unique(array_merge((array) ($result[$key] ?? []), (array) $abilities)));
                    }
                }

                $component->state(static::clean($result));

                Notification::make()->success()->title('اتطبّق '.count($data['templates']).' تمبلت')->send();
            });
    }

    /** Saves what's ticked now as a template; an existing name is updated. */
    protected static function saveTemplateAction(): Action
    {
        return Action::make('savePermissionTemplate')
            ->label('حفظ كتمبلت')
            ->icon('heroicon-m-bookmark')
            ->color('gray')
            ->modalHeading('حفظ الاختيار كتمبلت')
            ->modalDescription('اللي متعلّم دلوقتي في الجدول هيتحفظ كتمبلت تقدر تطبّقه على أي دور أو موظف بعد كده.')
            ->modalSubmitActionLabel('حفظ')
            ->modalWidth('md')
            ->form([
                Forms\Components\TextInput::make('name')
                    ->label('اسم التمبلت')
                    ->placeholder('مثلًا: مشاهدة الطلبات')
                    ->datalist(fn () => PermissionTemplate::orderBy('name')->pluck('name')->all())
                    ->helperText('لو الاسم موجود قبل كده، التمبلت ده هيتحدّث بالاختيار الجديد.')
                    ->required()
                    ->maxLength(100),
            ])
            ->action(function (array $data, Forms\Components\ViewField $component) {
                $permissions = static::clean((array) $component->getState());

                if (! $permissions) {
                    Notification::make()->warning()->title('علّم على تاب واحد على الأقل الأول')->send();

                    return;
                }

                $template = PermissionTemplate::updateOrCreate(['name' => trim($data['name'])], ['permissions' => $permissions]);

                Notification::make()->success()
                    ->title($template->wasRecentlyCreated ? 'اتحفظ التمبلت' : 'اتحدّث التمبلت')
                    ->body($template->name.' — '.count($permissions).' تاب')
                    ->send();
            });
    }

    protected static function deleteTemplateAction(): Action
    {
        return Action::make('deletePermissionTemplate')
            ->label('حذف تمبلت')
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->modalHeading('حذف تمبلتس')
            ->modalDescription('الأدوار والموظفين اللي اتطبّق عليهم التمبلت مش هيتغيروا.')
            ->modalSubmitActionLabel('حذف')
            ->modalWidth('md')
            ->form([
                Forms\Components\CheckboxList::make('templates')
                    ->label('التمبلتس')
                    ->options(fn () => PermissionTemplate::orderBy('name')->pluck('name', 'id'))
                    ->bulkToggleable()
                    ->required(),
            ])
            ->hidden(fn () => ! PermissionTemplate::exists())
            ->action(function (array $data) {
                $count = PermissionTemplate::whereIn('id', $data['templates'])->delete();

                Notification::make()->success()->title("اتحذف {$count} تمبلت")->send();
            });
    }
}
