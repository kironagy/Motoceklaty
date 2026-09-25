<?php

namespace App\Filament\Pages;

use App\Agent\Context\TokenEstimator;
use App\Agent\Tools\ToolRegistry;
use App\Domain\Settings\AgentInstructions;
use App\Domain\Settings\AgentSettings;
use App\Models\AgentInstructionVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Edits the L0 system instructions. Every save is a new version; the old
 * ones stay and can be restored in one click, so a bad edit is never final.
 */
class BotInstructions extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'البوت الذكي';
    protected static ?string $navigationLabel = 'تعليمات البوت';
    protected static ?string $title = 'تعليمات البوت';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.bot-instructions';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $this->form->fill(['content' => $this->instructions()->current()['text'], 'notes' => null]);
    }

    private function instructions(): AgentInstructions
    {
        return app(AgentInstructions::class);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Textarea::make('content')
                ->label('التعليمات')
                ->required()
                ->rows(30)
                ->autosize()
                ->live(debounce: 1000)
                ->extraInputAttributes(['dir' => 'rtl', 'style' => 'font-size: 14px; line-height: 1.9;'])
                ->helperText(fn ($state) => 'تقريبًا '.number_format(TokenEstimator::estimate((string) $state)).' توكن - التعليمات بتتبعت مع كل رسالة، فكل ما تطول الرد بيبطأ.'),
            TextInput::make('notes')
                ->label('إيه اللي اتغير؟')
                ->placeholder('مثال: خليته ما يسألش عن نوع الشغل قبل ما يدي السعر')
                ->maxLength(255),
        ])->statePath('data');
    }

    public function publish(): void
    {
        $state = $this->form->getState();
        $current = $this->instructions()->current();

        if (trim($state['content']) === $current['text']) {
            Notification::make()->title('مفيش تغيير عن النسخة الحالية')->warning()->send();

            return;
        }

        $version = $this->instructions()->publish($state['content'], $state['notes'] ?? null, auth()->id());
        $this->form->fill(['content' => $version->content, 'notes' => null]);

        Notification::make()
            ->title("اتحفظت النسخة {$version->version}")
            ->body('البوت هيستخدمها من الرسالة الجاية. جرّبها في محاكي المحادثات.')
            ->success()
            ->actions([
                \Filament\Notifications\Actions\Action::make('simulate')
                    ->label('جرّب دلوقتي')
                    ->url(ConversationSimulatorPage::getUrl()),
            ])
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('اعتمد النسخة الحالية')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => config('agent.instructions.approved_version') !== $this->instructions()->current()['version'])
                ->requiresConfirmation()
                ->modalDescription('الاعتماد معناه إنك راجعت النسخة دي وجربتها. agent:readiness بيرفض التشغيل لو النسخة الشغالة مش معتمدة.')
                ->action(function () {
                    $version = $this->instructions()->current()['version'];
                    AgentSettings::save(['instructions.approved_version' => $version], auth()->id());
                    Notification::make()->title("اتعتمدت {$version}")->success()->send();
                }),
            Action::make('tools')
                ->label('أسماء الأدوات')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('gray')
                ->modalHeading('الأدوات اللي البوت يقدر يستخدمها')
                ->modalDescription('لو هتكتب في التعليمات إن البوت يستخدم أداة، اكتب اسمها بالظبط زي هنا.')
                ->modalSubmitAction(false)
                ->modalContent(fn () => new HtmlString(view('filament.pages.partials.tool-list', [
                    'tools' => app(ToolRegistry::class)->declarations(),
                ])->render())),
            Action::make('useFile')
                ->label('ارجع لنسخة السيستم')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn () => $this->instructions()->current()['source'] === 'dashboard')
                ->requiresConfirmation()
                ->modalDescription(fn () => 'البوت هيرجع يستخدم ملف resources/agent/instructions/agent.md ('.$this->instructions()->fromFile()['version'].'). النسخ اللي اتعملت هنا هتفضل محفوظة تحت.')
                ->action(function () {
                    $this->instructions()->useFile();
                    $this->form->fill(['content' => $this->instructions()->current()['text'], 'notes' => null]);
                    Notification::make()->title('رجعنا لنسخة السيستم')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('النسخ السابقة')
            ->query(AgentInstructionVersion::query()->with('createdBy'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('version')->label('النسخة')->weight('bold'),
                Tables\Columns\IconColumn::make('is_active')->label('شغالة')->boolean(),
                Tables\Columns\TextColumn::make('notes')->label('التغيير')->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('createdBy.name')->label('بواسطة')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->since()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('عرض')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (AgentInstructionVersion $record) => "النسخة {$record->version}")
                    ->modalSubmitAction(false)
                    ->modalContent(fn (AgentInstructionVersion $record) => new HtmlString(
                        '<pre dir="rtl" style="white-space: pre-wrap; font-family: inherit; line-height: 1.9;">'.e($record->content).'</pre>'
                    )),
                Tables\Actions\Action::make('edit_from')
                    ->label('عدّل منها')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->action(function (AgentInstructionVersion $record) {
                        $this->form->fill(['content' => $record->content, 'notes' => "رجوع لـ {$record->version}"]);
                        Notification::make()->title("اتحملت {$record->version} في المحرر - راجعها واحفظ")->send();
                    }),
                Tables\Actions\Action::make('activate')
                    ->label('شغّلها')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->hidden(fn (AgentInstructionVersion $record) => $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (AgentInstructionVersion $record) {
                        $this->instructions()->activate($record);
                        $this->form->fill(['content' => $record->content, 'notes' => null]);
                        Notification::make()->title("البوت شغال دلوقتي بـ {$record->version}")->success()->send();
                    }),
            ])
            ->emptyStateHeading('لسه مفيش نسخ متعدلة من هنا')
            ->emptyStateDescription('البوت شغال بملف السيستم. أول ما تحفظ تعديل هيظهر هنا.');
    }

    public function getCurrent(): array
    {
        return $this->instructions()->current() + [
            'approved' => config('agent.instructions.approved_version'),
        ];
    }
}
