<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkSchedule;
use Ginjer\Network\Services\Activity\NetworkLog;
use Ginjer\Network\Services\Ops\Console;
use Ginjer\Network\Services\Ops\ScheduleRules;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Commands on a schedule: announcements, events, anything the console can do.
 *
 * @property Network $record
 */
class NetworkSchedulesPage extends Page implements HasTable
{
    use Concerns\ChecksNetworkAccess;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = NetworkResource::class;

    protected function networkAbility(): string
    {
        return 'control';
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->checkNetworkAccess();
    }

    public function getTitle(): string
    {
        return $this->record->name . ': schedules';
    }

    public function getSubheading(): ?string
    {
        return 'Console commands at set times, for announcements and events. Times are the panel\'s time (' . now()->format('H:i T') . ' now). Servers that are offline skip it.';
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private function scheduleForm(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(100)->placeholder('War warning'),
            Textarea::make('commands')
                ->required()
                ->rows(4)
                ->placeholder("say The war starts in 1 hour!\ntitle @a title {\"text\":\"War at 19:00\"}")
                ->helperText('One per line, without the slash. They run as the console.'),
            Grid::make(3)->schema([
                Select::make('targets')
                    ->label('On')
                    ->options(['shards' => 'Every shard', 'proxy' => 'The proxy', 'all' => 'Shards and proxy'])
                    ->default('shards')
                    ->selectablePlaceholder(false),
                Select::make('mode')
                    ->label('When')
                    ->options(['time' => 'At a time of day', 'every' => 'Every few minutes'])
                    ->default('time')
                    ->selectablePlaceholder(false)
                    ->live(),
                TextInput::make('at_time')
                    ->label('Time')
                    ->placeholder('18:00')
                    ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                    ->validationMessages(['regex' => 'Like 18:00.'])
                    ->visible(fn (Get $get) => $get('mode') !== 'every')
                    ->required(fn (Get $get) => $get('mode') !== 'every'),
                TextInput::make('every_minutes')
                    ->label('Every')
                    ->numeric()->integer()->minValue(1)->maxValue(10080)
                    ->suffix('minutes')
                    ->visible(fn (Get $get) => $get('mode') === 'every')
                    ->required(fn (Get $get) => $get('mode') === 'every'),
            ]),
            CheckboxList::make('days')
                ->label('On these days (none ticked: every day)')
                ->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])
                ->columns(7),
            Toggle::make('enabled')->default(true),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function toColumns(array $data): array
    {
        $every = ($data['mode'] ?? 'time') === 'every';

        return [
            'name' => $data['name'],
            'commands' => $data['commands'],
            'targets' => $data['targets'] ?? 'shards',
            'at_time' => $every ? null : $data['at_time'],
            'every_minutes' => $every ? (int) $data['every_minutes'] : null,
            'days' => ($data['days'] ?? []) === [] ? null : implode(',', array_map('intval', $data['days'])),
            'enabled' => (bool) ($data['enabled'] ?? true),
        ];
    }

    private function formData(NetworkSchedule $schedule): array
    {
        return $schedule->only(['name', 'commands', 'targets', 'at_time', 'every_minutes', 'enabled']) + [
            'mode' => $schedule->every_minutes ? 'every' : 'time',
            'days' => array_map('strval', $schedule->dayList()),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exclude_back')
                ->label('Back to the network')
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->record])),
            Action::make('exclude_add')
                ->label('Add a schedule')
                ->icon('tabler-plus')
                ->schema($this->scheduleForm())
                ->action(function (array $data) {
                    $schedule = NetworkSchedule::create(['network_id' => $this->record->id] + $this->toColumns($data));
                    NetworkLog::record($this->record, 'schedule', "Schedule \"{$schedule->name}\" added", ScheduleRules::describe($schedule->at_time, $schedule->every_minutes, $schedule->dayList()));
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => NetworkSchedule::query()->where('network_id', $this->record->id)->orderBy('name'))
            ->paginated(false)
            ->columns([
                ToggleColumn::make('enabled')->label('On'),
                TextColumn::make('name')->description(fn (NetworkSchedule $s) => mb_strimwidth(implode(' / ', $s->commandLines()), 0, 90, '…')),
                TextColumn::make('when')
                    ->label('When')
                    ->state(fn (NetworkSchedule $s) => ScheduleRules::describe($s->at_time, $s->every_minutes, $s->dayList())),
                TextColumn::make('targets')->label('On')->formatStateUsing(fn ($state) => ['shards' => 'Every shard', 'proxy' => 'The proxy', 'all' => 'Shards and proxy'][$state] ?? $state),
                TextColumn::make('last_run_at')->label('Last ran')->since()->placeholder('Not yet'),
            ])
            ->recordActions([
                Action::make('exclude_run')
                    ->label('Run now')
                    ->icon('tabler-player-play')
                    ->color('gray')
                    ->action(function (NetworkSchedule $s) {
                        $roles = ['shards' => [MemberRole::Shard], 'proxy' => [MemberRole::Proxy], 'all' => [MemberRole::Shard, MemberRole::Proxy]][$s->targets] ?? [MemberRole::Shard];
                        $sent = 0;
                        foreach ($this->record->load('members.server')->members->filter(fn ($m) => in_array($m->role, $roles, true)) as $member) {
                            try {
                                Console::run($member->server, $s->commandLines());
                                $sent++;
                            } catch (Throwable) {
                                // offline
                            }
                        }
                        Notification::make()->title("Ran on $sent server(s)")->color($sent > 0 ? 'success' : 'warning')->send();
                    }),
                Action::make('exclude_edit')
                    ->label('Edit')
                    ->icon('tabler-pencil')
                    ->color('gray')
                    ->fillForm(fn (NetworkSchedule $s) => $this->formData($s))
                    ->schema($this->scheduleForm())
                    ->action(fn (NetworkSchedule $s, array $data) => $s->update($this->toColumns($data))),
                Action::make('exclude_delete')
                    ->label('Delete')
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (NetworkSchedule $s) {
                        NetworkLog::record($this->record, 'schedule', "Schedule \"{$s->name}\" deleted");
                        $s->delete();
                    }),
            ])
            ->emptyStateIcon('tabler-calendar-time')
            ->emptyStateHeading('No schedules yet')
            ->emptyStateDescription('For example: "say The war starts in 1 hour!" every Saturday at 18:00.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
