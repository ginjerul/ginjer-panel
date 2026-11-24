<?php

namespace Ginjer\Network\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Ginjer\Network\Services\Ops\Hotspots as HotspotReport;
use Ginjer\Network\Services\Ops\ServerLifecycle;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Where the lag is: the chunks with the most entities and block entities (from Reef's entity
 * report), with the Nodes town that owns each, and buttons to clear ground items, arrows and XP.
 */
class Hotspots extends Page implements HasTable
{
    use InteractsWithTable;

    public const REPORT = 'reef-entities.json';

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-flame';

    protected static ?string $slug = 'lag-hotspots';

    protected static ?int $navigationSort = 33;

    /** @var list<array<string, mixed>> */
    public array $chunks = [];

    /** @var array<string, int> */
    public array $totals = [];

    public ?int $generated = null;

    public ?string $error = null;

    public static function getNavigationLabel(): string
    {
        return 'Lag hotspots';
    }

    public function getTitle(): string
    {
        return 'Lag hotspots';
    }

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return user()?->can(SubuserPermission::FileRead, $server) && user()?->can(SubuserPermission::ControlConsole, $server) && parent::canAccess();
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    public function mount(): void
    {
        $this->load();
    }

    /** Reads the last report (if any). */
    public function load(): void
    {
        $files = app(DaemonFileRepository::class)->setServer($this->server());
        try {
            $read = function (string $path) use ($files) {
                try {
                    return (string) $files->getContent($path);
                } catch (FileNotFoundException) {
                    return null;
                }
            };
            if ($read('reef.yml') === null) {
                $this->error = 'This server isn\'t running Reef, which writes the entity report. Switch it to the Reef jar to use this page.';

                return;
            }
            $report = $read(self::REPORT);
            if ($report === null) {
                $this->error = null;
                $this->chunks = [];

                return;
            }
            $parsed = HotspotReport::parse($report, HotspotReport::owners($read('plugins/nodes/world.json'), $read('plugins/nodes/towns.json')));
            $this->chunks = $parsed['chunks'];
            $this->totals = $parsed['totals'];
            $this->generated = $parsed['generated'];
            $this->error = null;
        } catch (Throwable $exception) {
            $this->error = 'Couldn\'t read the report: ' . $exception->getMessage();
        }
        $this->resetTable();
    }

    public function getSubheading(): string|HtmlString|null
    {
        if ($this->error) {
            return $this->error;
        }
        if ($this->generated === null) {
            return 'No scan yet. Scan counts every entity and block entity (hoppers, furnaces, ...) in the loaded chunks and lists the worst chunks, with the town that owns them.';
        }
        $when = \Illuminate\Support\Carbon::createFromTimestampMs($this->generated)->diffForHumans();

        return new HtmlString(e("Scanned $when. Most common: " . HotspotReport::summary($this->totals, 6) . '.')
            . '<br>' . e('Mob farms, item piles and hopper chains are the usual causes. Clearing items, arrows and XP is safe; mobs are left to their owners.'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exclude_scan')
                ->label('Scan now')
                ->icon('tabler-radar')
                ->disabled(fn () => $this->error !== null && str_contains($this->error, 'isn\'t running Reef'))
                ->action(fn () => $this->scan()),
            ActionGroup::make([
                $this->clearAction('items', 'Ground items'),
                $this->clearAction('arrows', 'Arrows stuck in the ground'),
                $this->clearAction('xp', 'Experience orbs'),
                $this->clearAction('all', 'All of them'),
            ])
                ->label('Clear')
                ->icon('tabler-trash')
                ->color('warning')
                ->button(),
        ];
    }

    private function clearAction(string $what, string $label): Action
    {
        return Action::make("exclude_clear_$what")
            ->label($label)
            ->requiresConfirmation()
            ->modalDescription('Players get a 30-second warning in chat, then Reef removes them. Named items are kept.')
            ->action(function () use ($what) {
                try {
                    \Ginjer\Network\Services\Ops\Console::run($this->server(), "reef clear $what 30");
                    Notification::make()->title('Clearing in 30 seconds')->body('Players were warned. The console shows how many were removed.')->success()->send();
                } catch (Throwable $exception) {
                    Notification::make()->title('Couldn\'t send the command')->body('The server must be running. ' . $exception->getMessage())->danger()->send();
                }
            });
    }

    private function scan(): void
    {
        $before = $this->generated;
        try {
            \Ginjer\Network\Services\Ops\Console::run($this->server(), 'reef entities');
        } catch (Throwable $exception) {
            Notification::make()->title('Couldn\'t start a scan')->body('The server must be running. ' . $exception->getMessage())->danger()->send();

            return;
        }
        for ($i = 0; $i < 8; $i++) {
            ServerLifecycle::sleep(1);
            $this->load();
            if ($this->generated !== null && $this->generated !== $before) {
                Notification::make()->title('Scan done')->success()->send();

                return;
            }
        }
        Notification::make()->title('No new report yet')->body('Reef didn\'t write one within a few seconds. Check the console, then press Scan again.')->warning()->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect($this->chunks)->keyBy('key')->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('where')
                    ->label('Chunk')
                    ->state(fn (array $r) => "x {$r['block_x']}, z {$r['block_z']}")
                    ->description(fn (array $r) => "{$r['world']}, chunk {$r['x']}, {$r['z']}")
                    ->copyable()
                    ->copyableState(fn (array $r) => "/tp {$r['block_x']} ~ {$r['block_z']}")
                    ->tooltip('Click to copy a /tp command'),
                TextColumn::make('town')
                    ->label('Town')
                    ->placeholder('Wilderness')
                    ->description(fn (array $r) => $r['nation']),
                TextColumn::make('entities')
                    ->label('Entities')
                    ->description(fn (array $r) => HotspotReport::summary($r['types']))
                    ->color(fn (array $r) => $r['entities'] >= 200 ? 'danger' : ($r['entities'] >= 80 ? 'warning' : null))
                    ->wrap(),
                TextColumn::make('block_entities')
                    ->label('Block entities')
                    ->description(fn (array $r) => HotspotReport::summary($r['block_types']))
                    ->color(fn (array $r) => $r['block_entities'] >= 200 ? 'danger' : ($r['block_entities'] >= 80 ? 'warning' : null))
                    ->wrap(),
            ])
            ->emptyStateIcon('tabler-flame-off')
            ->emptyStateHeading($this->error ? 'Unavailable' : 'No scan yet')
            ->emptyStateDescription($this->error ?? 'Press "Scan now" while the server is running.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
