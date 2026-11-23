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
use Ginjer\Network\Services\Ops\ServerTools;
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
            \Filament\Actions\ActionGroup::make([
                Action::make('exclude_pregenerate')
                    ->label('Pre-generate the map')
                    ->icon('tabler-world-download')
                    ->modalHeading('Pre-generate the map with Chunky')
                    ->modalDescription(fn () => 'Generates chunks ahead of time, so exploring doesn\'t lag the server. Runs in the background; players can stay online.'
                        . (ServerTools::shardOf($server)[0] ? ' This server is a shard: only the part of the square it owns is generated.' : ''))
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('world')->default('world')->required(),
                        \Filament\Forms\Components\TextInput::make('radius')->label('Radius around 0, 0 (blocks)')->numeric()->integer()->minValue(500)->default(5000)->required(),
                    ])
                    ->action(fn (array $data) => $this->pregenerate((string) $data['world'], (int) $data['radius'])),
                Action::make('exclude_pregen_progress')
                    ->label('Pre-generation progress')
                    ->icon('tabler-progress')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('world')->default('world')->required(),
                        \Filament\Forms\Components\TextInput::make('radius')->label('Radius around 0, 0 (blocks)')->numeric()->integer()->minValue(500)->default(5000)->required(),
                    ])
                    ->action(function (array $data) {
                        [$shard, $regions] = ServerTools::shardOf($this->server());
                        $area = ServerTools::area($regions, $shard, (int) $data['radius']);
                        if ($area === null) {
                            Notification::make()->title('This shard owns none of that area')->warning()->send();

                            return;
                        }
                        try {
                            $folder = trim((string) $data['world'], '/') . '/region';
                            $files = collect(app(\App\Repositories\Daemon\DaemonFileRepository::class)->setServer($this->server())->getDirectory($folder))->pluck('name')->all();
                            $p = \Ginjer\Network\Services\Ops\Hotspots::pregenProgress($files, $area);
                            Notification::make()
                                ->title("About {$p['percent']}% generated")
                                ->body("{$p['have']} of the {$p['need']} region files (512 × 512 blocks each) for that area exist. A region file can be partly generated, so this is an estimate." . ($p['percent'] < 100 ? ' Pre-generate the map to fill in the rest.' : ''))
                                ->color($p['percent'] >= 95 ? 'success' : 'warning')
                                ->persistent()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()->title('Couldn\'t read the world\'s region folder')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('exclude_spark')
                    ->label('Profile with spark')
                    ->icon('tabler-activity-heartbeat')
                    ->requiresConfirmation()
                    ->modalDescription('Records what the server spends its time on for 60 seconds, then sends you a link to the profile. Best while the server is lagging.')
                    ->action(fn () => $this->profile()),
            ])
                ->label('Tools')
                ->icon('tabler-tool')
                ->color('gray')
                ->button()
                ->visible(fn () => user()?->can(SubuserPermission::ControlConsole, $server)),
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

    /** Installs a tool from Modrinth if it's missing. Returns true when it's there and loaded. */
    private function ensureTool(string $name, string $label): bool
    {
        $tools = app(ServerTools::class);
        if ($tools->findPlugin($this->server(), $name) !== null) {
            return true;
        }
        try {
            $file = $tools->installFromModrinth($this->server(), $name);
            Notification::make()->title("Installed $label")->body("Downloaded $file from Modrinth. Restart the server so it loads, then try again.")->success()->persistent()->send();
        } catch (Throwable $exception) {
            Notification::make()->title("Couldn't install $label")->body($exception->getMessage())->danger()->send();
        }

        return false;
    }

    private function pregenerate(string $world, int $radius): void
    {
        if (!$this->ensureTool(ServerTools::CHUNKY, 'Chunky')) {
            return;
        }
        [$shard, $regions] = ServerTools::shardOf($this->server());
        $area = ServerTools::area($regions, $shard, $radius);
        if ($area === null) {
            Notification::make()->title('Nothing to generate here')->body("This shard owns none of the area within $radius blocks of 0, 0.")->warning()->send();

            return;
        }
        try {
            $this->server()->send(ServerTools::chunkyCommands($world, $area));
            Notification::make()
                ->title('Pre-generation started')
                ->body(sprintf('Chunky is generating x %d to %d, z %d to %d. Progress shows in the console; "chunky pause" stops it.', $area['min-x'], $area['max-x'], $area['min-z'], $area['max-z']))
                ->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Couldn\'t start Chunky')->body('The server must be running. ' . $exception->getMessage())->danger()->send();
        }
    }

    private function profile(): void
    {
        if (!$this->ensureTool(ServerTools::SPARK, 'spark')) {
            return;
        }
        try {
            $previous = rescue(fn () => app(ServerTools::class)->sparkLink($this->server()), null, false);
            $this->server()->send('spark profiler start --timeout 60');
            \Ginjer\Network\Jobs\ProfileWithSpark::dispatch($this->server()->id, (int) user()?->id, 60, $previous);
            Notification::make()->title('Profiling for 60 seconds')->body('You\'ll get a notification with the link when spark has uploaded it.')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Couldn\'t start spark')->body('The server must be running. ' . $exception->getMessage())->danger()->send();
        }
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
