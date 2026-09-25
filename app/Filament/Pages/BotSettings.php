<?php

namespace App\Filament\Pages;

use App\Domain\Settings\AgentInstructions;
use App\Domain\Settings\AgentSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Every AGENT_* value that used to need an .env edit and a worker restart.
 * An empty field means "use the .env value" - the placeholder shows it.
 */
class BotSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationGroup = 'البوت الذكي';
    protected static ?string $navigationLabel = 'إعدادات البوت';
    protected static ?string $title = 'إعدادات البوت';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.bot-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $overrides = AgentSettings::overrides();
        $state = [];

        foreach (AgentSettings::definitions() as $key => [, , $type]) {
            $value = $overrides[$key] ?? null;
            $state[$this->field($key)] = match (true) {
                $value === null => null,
                $type === 'bool' => $value ? '1' : '0',
                default => (string) $value,
            };
        }

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        $tabs = [];

        foreach (AgentSettings::groups() as $group => $label) {
            $fields = [];

            foreach (AgentSettings::definitions() as $key => [$fieldGroup, $fieldLabel, $type, $help]) {
                if ($fieldGroup === $group) {
                    $fields[] = $this->component($key, $fieldLabel, $type, $help);
                }
            }

            $tabs[] = Tabs\Tab::make($label)->schema($fields)->columns(2);
        }

        return $form->schema([Tabs::make('settings')->tabs($tabs)->persistTabInQueryString()])->statePath('data');
    }

    private function component(string $key, string $label, string $type, ?string $help): Component
    {
        $default = AgentSettings::envDefault($key);
        $defaultText = match (true) {
            $default === null || $default === '' => 'مش متحدد',
            is_bool($default) => $default ? 'شغال' : 'متوقف',
            default => (string) $default,
        };
        $hint = 'فاضي = قيمة ملف .env ('.$defaultText.')';

        $component = match ($type) {
            'bool' => Select::make($this->field($key))
                ->options(['1' => 'شغال', '0' => 'متوقف'])
                ->placeholder('زي ملف .env ('.$defaultText.')'),
            'int' => TextInput::make($this->field($key))->numeric()->integer()->minValue(0)->placeholder($defaultText),
            'float' => TextInput::make($this->field($key))->numeric()->minValue(0)->maxValue(1)->step(0.01)->placeholder($defaultText),
            'text' => Textarea::make($this->field($key))->rows(2)->placeholder($defaultText)->columnSpanFull(),
            default => TextInput::make($this->field($key))->placeholder($defaultText),
        };

        return $component
            ->label($label)
            ->helperText(trim(($help ? $help.' ' : '').$hint));
    }

    /** Dots would nest the form state, so config paths are stored flattened. */
    private function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $values = [];

        foreach (array_keys(AgentSettings::definitions()) as $key) {
            if ($key === 'instructions.approved_version') {
                continue;
            }

            $values[$key] = $state[$this->field($key)] ?? null;
        }

        AgentSettings::save($values, auth()->id());

        Notification::make()
            ->title('اتحفظت الإعدادات')
            ->body('البوت هيشتغل بيها من الرسالة الجاية، من غير ريستارت.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('instructions')
                ->label('تعليمات البوت')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(BotInstructions::getUrl()),
            Action::make('simulator')
                ->label('جرّب البوت')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->url(ConversationSimulatorPage::getUrl()),
        ];
    }

    /** Effective values, for the summary cards on top of the page. */
    public function getStatus(): array
    {
        $instructions = app(AgentInstructions::class)->current();

        return [
            'enabled' => (bool) config('agent.enabled'),
            'model' => (string) config('agent.model'),
            'instructions_version' => $instructions['version'],
            'instructions_source' => $instructions['source'],
            'approved_version' => config('agent.instructions.approved_version'),
            'overrides' => count(AgentSettings::overrides()),
        ];
    }
}
