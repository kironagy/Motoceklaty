<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\AttendancePoints;
use App\Filament\Support\NavigationLayout;
use App\Models\DashboardSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * Owner settings of the dashboard itself:
 * - attendance points: value of a red / black, how many greens cancel each, the late limits;
 * - the sidebar: group names and order, which tabs sit in each group, their names and order.
 */
class DashboardSettings extends Page implements HasForms
{
    use \App\Filament\Concerns\RoleGatedPage;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-8-tooth';
    protected static ?string $navigationGroup = 'إدارة الموظفين';
    protected static ?string $navigationLabel = 'إعدادات الداشبورد';
    protected static ?string $title = 'إعدادات الداشبورد';
    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.dashboard-settings';

    public ?array $data = [];

    /** Without a role only the owner opens it (RoleGatedPage falls back here). */
    public static function canAccess(): bool
    {
        return \App\Support\Access::allows(static::class, 'view');
    }

    public function mount(): void
    {
        $points = [];

        foreach (AttendancePoints::EDITABLE as $key) {
            $points[$key] = AttendancePoints::setting($key);
        }
        $points['off_days'] = array_map('strval', AttendancePoints::offDays());

        $this->form->fill([
            'points' => $points,
            'groups' => NavigationLayout::current()['groups'],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Tabs::make('settings')->tabs([
                    Tabs\Tab::make('نقط الحضور')
                        ->icon('heroicon-o-star')
                        ->schema([
                            Section::make('قيمة النقط')
                                ->description('القيمة بتتسجل على النقطة وقت ما تتحسب، فتغييرها مش بيغيّر نقط اتحسبت قبل كده.')
                                ->schema([
                                    TextInput::make('points.red_amount')->label('🔴 خصم النقطة الحمرا')->numeric()->prefix('ج')->minValue(0)->required(),
                                    TextInput::make('points.black_amount')->label('⚫ خصم النقطة السودا')->numeric()->prefix('ج')->minValue(0)->required(),
                                ])
                                ->columns(2),
                            Section::make('الاستبدال')
                                ->description('عدد النقط الخضرا اللي الموظف بيجمعها بعد النقطة عشان يلغيها ويتشال خصمها.')
                                ->schema([
                                    TextInput::make('points.greens_for_red')->label('🟢 كام خضرا تلغي الحمرا')->integer()->minValue(1)->maxValue(60)->required(),
                                    TextInput::make('points.greens_for_black')->label('🟢 كام خضرا تلغي السودا')->integer()->minValue(1)->maxValue(60)->required(),
                                ])
                                ->columns(2),
                            Section::make('الإجازة الأسبوعية')
                                ->description('الأيام دي مش بتتحسب غياب في كاردات الحضور.')
                                ->schema([
                                    \Filament\Forms\Components\CheckboxList::make('points.off_days')
                                        ->hiddenLabel()
                                        ->options(array_map(fn ($d) => $d, AttendancePoints::WEEK_DAYS))
                                        ->columns(7)
                                        ->gridDirection('row'),
                                ]),
                            Section::make('التأخير')
                                ->schema([
                                    TextInput::make('points.grace_minutes')->label('سماح قبل الحمرا')->helperText('0 = أي تأخير بنقطة حمرا.')->integer()->minValue(0)->suffix('دقيقة')->required(),
                                    TextInput::make('points.black_after_minutes')->label('السودا من تأخير')->helperText('180 = 3 ساعات.')->integer()->minValue(1)->suffix('دقيقة')->required(),
                                ])
                                ->columns(2),
                        ]),
                    Tabs\Tab::make('القايمة الجانبية')
                        ->icon('heroicon-o-bars-3')
                        ->schema([
                            Placeholder::make('sidebar_help')
                                ->hiddenLabel()
                                ->content('اسحب التاب من ⠿ عشان ترتبه أو تنقله لجروب تاني، واسحب الجروب من راسه. دوس ✎ (أو دبل كليك) عشان تغيّر اسم التاب. الصلاحيات زي ما هي، والتغيير بيظهر بعد الحفظ.'),
                            ViewField::make('groups')
                                ->hiddenLabel()
                                ->view('filament.forms.sidebar-builder')
                                ->viewData(['entries' => NavigationLayout::builderEntries()]),
                        ]),
                ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $points = [];
        foreach (AttendancePoints::EDITABLE as $key) {
            $points[$key] = in_array($key, ['red_amount', 'black_amount'], true) ? (float) $data['points'][$key] : (int) $data['points'][$key];
        }

        $points['off_days'] = array_values(array_map('intval', (array) ($data['points']['off_days'] ?? [])));

        if ($points['black_after_minutes'] <= $points['grace_minutes']) {
            throw ValidationException::withMessages(['data.points.black_after_minutes' => 'لازم تكون أكبر من دقايق السماح.']);
        }

        $groups = [];
        $seen = [];
        $labels = [];

        foreach ($data['groups'] ?? [] as $g) {
            $label = trim((string) ($g['label'] ?? ''));

            if (isset($labels[$label])) {
                throw ValidationException::withMessages(['data.groups' => $label === '' ? 'فيه أكتر من جروب من غير اسم - خليهم جروب واحد.' : "اسم الجروب «{$label}» متكرر."]);
            }
            $labels[$label] = true;

            $items = [];
            foreach ($g['items'] ?? [] as $item) {
                if (isset($seen[$item['key']])) {
                    $name = NavigationLayout::entries()[$item['key']]['label'] ?? $item['key'];
                    throw ValidationException::withMessages(['data.groups' => "التاب «{$name}» موجود في أكتر من جروب."]);
                }
                $seen[$item['key']] = true;
                $items[] = ['key' => $item['key'], 'label' => filled($item['label'] ?? null) ? trim($item['label']) : null];
            }

            $groups[] = ['label' => $label, 'items' => $items];
        }

        DashboardSetting::put(AttendancePoints::SETTINGS_KEY, $points, auth()->id());
        DashboardSetting::put(NavigationLayout::KEY, ['groups' => $groups], auth()->id());

        Notification::make()->title('اتحفظت الإعدادات')->body('القايمة الجانبية هتتحدث مع أول تحميل للصفحة.')->success()->send();

        $this->redirect(static::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset_sidebar')
                ->label('رجّع القايمة لأصلها')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('الأسماء والترتيب والجروبات هترجع زي ما كانت. الصلاحيات مش هتتأثر.')
                ->action(function () {
                    DashboardSetting::put(NavigationLayout::KEY, null, auth()->id());
                    $this->redirect(static::getUrl());
                }),
        ];
    }
}
