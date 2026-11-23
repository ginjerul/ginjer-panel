<?php

namespace Ginjer\Network\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Ginjer\Network\Services\PluginSwitch;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Throwable;

/**
 * Every plugin jar in plugins/ with an on/off switch. Off renames the jar to .jar.disabled so the
 * server skips it; on renames it back. Nothing is deleted and the plugin's data folder is kept.
 */
class Plugins extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-plug';

    protected static ?string $slug = 'plugin-switches';

    protected static ?int $navigationSort = 32;

    /** @var list<array<string, mixed>> */
    public array $plugins = [];

    public ?string $error = null;

    /** Plugins switched since the page opened: they change on the next restart. */
    public int $pendingChanges = 0;

    public static function getNavigationLabel(): string
    {
        return 'Plugins';
    }

    public function getTitle(): string
    {
        return 'Plugins';
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

    public function load(): void
    {
        try {
            $this->plugins = app(PluginSwitch::class)->list($this->server());
            $this->error = null;
        } catch (Throwable $exception) {
            $this->plugins = [];
            $this->error = 'Couldn\'t read the plugins folder: ' . $exception->getMessage();
        }
        $this->resetTable();
    }

    public function getSubheading(): string|HtmlString|null
    {
        if ($this->error) {
            return $this->error;
        }
        $on = collect($this->plugins)->where('enabled', true)->count();
        $text = e("$on of " . count($this->plugins) . ' plugins on. Switching one off keeps the jar and its data; it just isn\'t loaded.');
        if ($this->pendingChanges > 0) {
            $text .= ' <b>' . e($this->pendingChanges . ' change(s) take effect when the server restarts.') . '</b>';
        }
        foreach (PluginSwitch::warnings($this->plugins) as $warning) {
            $text .= '<br><span style="color: rgb(217 119 6)">' . e($warning) . '</span>';
        }

        return new HtmlString($text);
    }

    protected function getHeaderActions(): array
    {
        $server = $this->server();

        return [
            Action::make('exclude_refresh')
                ->label('Refresh')
                ->icon('tabler-refresh')
                ->color('gray')
                ->action(fn () => $this->load()),
            Action::make('exclude_restart')
                ->label('Restart')
                ->icon('tabler-reload')
                ->color('warning')
                ->visible(fn () => user()?->can(SubuserPermission::ControlRestart, $server))
                ->requiresConfirmation()
                ->action(function (DaemonServerRepository $daemon) use ($server) {
                    try {
                        $daemon->setServer($server)->power('restart');
                        $this->pendingChanges = 0;
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
            ->records(fn () => collect($this->plugins)->keyBy('file')->all())
            ->paginated(false)
            ->columns([
                ToggleColumn::make('enabled')
                    ->label('On')
                    ->updateStateUsing(function (array $record, bool $state) {
                        $this->switch($record['file'], $state);

                        return $state;
                    }),
                TextColumn::make('name')
                    ->label('Plugin')
                    ->description(fn (array $record) => $record['file'])
                    ->searchable(false),
                TextColumn::make('size')
                    ->label('Size')
                    ->formatStateUsing(fn ($state) => Number::fileSize((int) $state, precision: 1)),
            ])
            ->emptyStateIcon('tabler-plug-off')
            ->emptyStateHeading($this->error ? 'Couldn\'t read the plugins folder' : 'No plugins')
            ->emptyStateDescription($this->error ?? 'There are no .jar files in plugins/.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    private function switch(string $file, bool $enabled): void
    {
        try {
            $newName = app(PluginSwitch::class)->set($this->server(), $file, $enabled);
            $this->pendingChanges++;
            Notification::make()
                ->title(preg_replace('/\.jar(\.disabled)?$/i', '', $newName) . ($enabled ? ' switched on' : ' switched off'))
                ->body('Restart the server to apply it.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Couldn\'t switch ' . $file)->body($exception->getMessage())->danger()->send();
        }
        $this->load();
    }
}
