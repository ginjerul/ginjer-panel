<?php

namespace Ginjer\Network\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Ginjer\Network\Services\Optimizer\Optimizer;
use Ginjer\Network\Services\Optimizer\Rule;
use Ginjer\Network\Services\Optimizer\ServerOptimizer;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Recommends Paper, Spigot, Bukkit, Reef, Nodes and Java settings for this server, shows the
 * current and recommended value of each with the reason, applies the ones picked (backing up the
 * files first) and reverts the last apply.
 */
class Performance extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-gauge';

    protected static ?string $slug = 'performance';

    protected static ?int $navigationSort = 31;

    public string $profile = Rule::BALANCED;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public ?string $error = null;

    public static function getNavigationLabel(): string
    {
        return 'Performance';
    }

    public function getTitle(): string
    {
        return 'Performance';
    }

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return user()?->can(SubuserPermission::FileUpdate, $server) && parent::canAccess();
    }

    public function mount(): void
    {
        $this->load();
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function canChangeStartup(): bool
    {
        return (bool) user()?->can(SubuserPermission::StartupUpdate, $this->server());
    }

    public function load(): void
    {
        try {
            $this->rows = app(ServerOptimizer::class)->analyze($this->server(), $this->profile, $this->canChangeStartup());
            $this->error = null;
        } catch (Throwable $exception) {
            $this->rows = [];
            $this->error = 'Couldn\'t read this server\'s files: ' . $exception->getMessage();
        }
        $this->resetTable();
    }

    public function getSubheading(): string|HtmlString|null
    {
        if ($this->error) {
            return $this->error;
        }
        $rows = collect($this->rows);
        $todo = $rows->where('status', Optimizer::CHANGE);

        if ($todo->isEmpty()) {
            return 'Everything in the ' . $this->profile . ' profile is already set.'
                . ($this->profile === Rule::BALANCED ? ' "Performance" goes further, trading some gameplay for speed.' : '');
        }

        return $todo->count() . ' recommended change(s), ' . $todo->where('gameplay', true)->count() . ' of them noticeable in game. '
            . 'Pick the ones you want and apply them; the files are backed up first. Changes take effect after a restart.';
    }

    protected function getHeaderActions(): array
    {
        $server = $this->server();

        return [
            Action::make('exclude_profile_balanced')
                ->label('Balanced')
                ->tooltip('Changes players barely notice')
                ->color(fn () => $this->profile === Rule::BALANCED ? 'primary' : 'gray')
                ->action(function () {
                    $this->profile = Rule::BALANCED;
                    $this->load();
                }),
            Action::make('exclude_profile_performance')
                ->label('Performance')
                ->tooltip('Also trades some gameplay for speed')
                ->color(fn () => $this->profile === Rule::PERFORMANCE ? 'primary' : 'gray')
                ->action(function () {
                    $this->profile = Rule::PERFORMANCE;
                    $this->load();
                }),
            Action::make('exclude_apply_all')
                ->label('Apply all')
                ->icon('tabler-wand')
                ->disabled(fn () => collect($this->rows)->where('status', Optimizer::CHANGE)->isEmpty())
                ->requiresConfirmation()
                ->modalHeading('Apply every recommended change?')
                ->modalDescription(fn () => $this->confirmText(collect($this->rows)->where('status', Optimizer::CHANGE)))
                ->action(fn () => $this->apply(collect($this->rows)->where('status', Optimizer::CHANGE)->pluck('id')->all())),
            Action::make('exclude_revert')
                ->label('Undo last apply')
                ->icon('tabler-arrow-back-up')
                ->color('gray')
                ->visible(fn () => app(ServerOptimizer::class)->lastBackup($server) !== null)
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Puts back the files as they were before the last apply (' . app(ServerOptimizer::class)->lastBackup($server)?->created_at?->diffForHumans() . '). Restart the server afterwards.')
                ->action(function () use ($server) {
                    try {
                        $count = app(ServerOptimizer::class)->revert($server);
                        Notification::make()->title("Restored $count file(s)")->body('Restart the server to use the old settings.')->success()->send();
                    } catch (Throwable $exception) {
                        Notification::make()->title('Couldn\'t restore the files')->body($exception->getMessage())->danger()->send();
                    }
                    $this->load();
                }),
            Action::make('exclude_restart')
                ->label('Restart')
                ->icon('tabler-reload')
                ->color('warning')
                ->visible(fn () => user()?->can(SubuserPermission::ControlRestart, $server))
                ->requiresConfirmation()
                ->action(function (DaemonServerRepository $daemon) use ($server) {
                    try {
                        $daemon->setServer($server)->power('restart');
                        Notification::make()->title('Restarting')->success()->send();
                    } catch (Throwable $exception) {
                        Notification::make()->title('Couldn\'t restart')->body($exception->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect($this->rows)
                ->sortBy(fn ($r) => [array_search($r['status'], [Optimizer::CHANGE, Optimizer::OK, Optimizer::SKIP, Optimizer::MISSING], true), $r['file']])
                ->keyBy('id')
                ->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('title')
                    ->label('Setting')
                    ->description(fn (array $record) => $record['reason'])
                    ->wrap()
                    ->width('42%'),
                TextColumn::make('file')
                    ->label('Where')
                    ->state(fn (array $record) => $record['file'] === 'startup' ? 'Startup command' : $record['file'])
                    ->description(fn (array $record) => $record['file'] === 'startup' ? null : $record['key'])
                    ->size('sm')
                    ->wrap()
                    ->width('18%'),
                TextColumn::make('current')
                    ->label('Now')
                    ->placeholder('-')
                    ->fontFamily('mono')
                    ->limit(18)
                    ->wrap()
                    ->width('11%')
                    ->tooltip(fn (array $record) => strlen((string) $record['current']) > 18 ? $record['current'] : null),
                TextColumn::make('recommended')
                    ->label('Recommended')
                    ->placeholder('-')
                    ->fontFamily('mono')
                    ->limit(18)
                    ->wrap()
                    ->width('11%')
                    ->tooltip(fn (array $record) => strlen((string) $record['recommended']) > 18 ? $record['recommended'] : null),
                IconColumn::make('gameplay')
                    ->label('In game')
                    ->boolean()
                    ->trueIcon('tabler-device-gamepad-2')
                    ->falseIcon('tabler-minus')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (array $record) => $record['gameplay'] ? 'Players may notice this' : 'Players won\'t notice'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Optimizer::CHANGE => 'Recommended',
                        Optimizer::OK => 'Done',
                        Optimizer::SKIP => 'Not for this server',
                        default => 'Not found',
                    })
                    ->color(fn (string $state) => match ($state) {
                        Optimizer::CHANGE => 'warning',
                        Optimizer::OK => 'success',
                        default => 'gray',
                    })
                    ->tooltip(fn (array $record) => $record['note']),
            ])
            ->checkIfRecordIsSelectableUsing(fn (array $record) => $record['status'] === Optimizer::CHANGE)
            ->toolbarActions([
                BulkAction::make('exclude_apply_selected')
                    ->label('Apply selected')
                    ->icon('tabler-check')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Collection $records) => $this->confirmText($records))
                    ->action(fn (Collection $records) => $this->apply($records->pluck('id')->all())),
            ])
            ->emptyStateHeading($this->error ? 'Couldn\'t read the server' : 'Nothing to show')
            ->emptyStateDescription($this->error);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /** @param  Collection<int|string, array<string, mixed>>  $rows */
    private function confirmText(Collection $rows): string
    {
        $gameplay = $rows->where('gameplay', true)->pluck('title');

        return $rows->count() . ' setting(s) will change. The files are backed up first, so you can undo it.'
            . ($gameplay->isEmpty() ? '' : ' Players may notice: ' . $gameplay->implode(', ') . '.')
            . ' Changes take effect after a restart.';
    }

    /** @param  list<string>  $ids */
    private function apply(array $ids): void
    {
        try {
            $applied = app(ServerOptimizer::class)->apply($this->server(), $this->profile, $ids, $this->canChangeStartup(), user()?->id);
            Notification::make()
                ->title($applied === [] ? 'Nothing needed changing' : 'Applied ' . count($applied) . ' change(s)')
                ->body($applied === [] ? null : 'Restart the server to use them. "Undo last apply" puts the old files back.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Couldn\'t apply the changes')->body($exception->getMessage())->danger()->send();
        }
        $this->load();
    }
}
