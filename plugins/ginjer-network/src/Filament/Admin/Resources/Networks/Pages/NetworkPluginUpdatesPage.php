<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Jobs\CheckPluginUpdates;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Activity\NetworkLog;
use Ginjer\Network\Services\Ops\PluginUpdates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Newer versions of the network's plugins, and plugins whose copies differ between shards.
 *
 * @property Network $record
 */
class NetworkPluginUpdatesPage extends Page implements HasTable
{
    use Concerns\ChecksNetworkAccess;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = NetworkResource::class;

    protected function networkAbility(): string
    {
        return 'update';
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->checkNetworkAccess();
    }

    public function getTitle(): string
    {
        return $this->record->name . ': plugin updates';
    }

    /** @return array{checked: ?string, rows: list<array<string, mixed>>} */
    private function result(): array
    {
        return Cache::get(CheckPluginUpdates::key($this->record->id), ['checked' => null, 'rows' => []]);
    }

    private function running(): bool
    {
        return Cache::has(CheckPluginUpdates::key($this->record->id) . ':running');
    }

    public function getSubheading(): ?string
    {
        $checked = $this->result()['checked'];

        return ($this->running() ? 'Checking now… ' : '') . ($checked ? 'Checked ' . Carbon::parse($checked)->diffForHumans() . '. ' : 'Not checked yet. ')
            . 'Modrinth plugins are matched by their exact file; Hangar and SpigotMC ones by name. Updates install on every shard that has the plugin and load at the next restart. Reef, Nodes and GinjerMap are updated with Update shards.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exclude_back')
                ->label('Back to the network')
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->record])),
            Action::make('exclude_check')
                ->label('Check now')
                ->icon('tabler-refresh')
                ->disabled(fn () => $this->running())
                ->action(function () {
                    CheckPluginUpdates::dispatch($this->record->id, (int) auth()->id());
                    Notification::make()->title('Checking the plugins')->body('It reads every plugin on the shards, so it takes a minute. The result goes to your notifications.')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect($this->result()['rows'])
                ->sortBy(fn ($r) => [$r['status'] === 'update' ? 0 : ($r['same'] ? 2 : 1), strtolower($r['name'])])
                ->keyBy('file')->all())
            ->paginated(false)
            ->poll(fn () => $this->running() ? '5s' : null)
            ->columns([
                TextColumn::make('name')
                    ->label('Plugin')
                    ->description(fn (array $record) => $record['file'] . ($record['enabled'] ? '' : ' (switched off)')),
                TextColumn::make('version')->label('Installed')->placeholder('?'),
                TextColumn::make('latest')
                    ->label('Newest')
                    ->placeholder('not found')
                    ->badge()
                    ->color(fn (array $record) => ['update' => 'warning', 'current' => 'success'][$record['status']] ?? 'gray')
                    ->description(fn (array $record) => $record['source'] ? 'on ' . $record['source'] . ($record['source'] === 'Modrinth' ? '' : ' (by name)') : null)
                    ->url(fn (array $record) => $record['url'], true),
                TextColumn::make('same')
                    ->label('Shards')
                    ->badge()
                    ->state(fn (array $record) => $record['same'] ? count($record['shards']) . ' shard(s), same file' : 'Different on some shards')
                    ->color(fn (array $record) => $record['same'] ? 'gray' : 'danger')
                    ->tooltip(fn (array $record) => $record['same'] ? null : collect($record['shards'])->map(fn ($size, $shard) => "$shard: " . number_format($size / 1024) . ' KB')->implode(', ')),
            ])
            ->recordActions([
                Action::make('exclude_install')
                    ->label(fn (array $record) => 'Install ' . $record['latest'])
                    ->icon('tabler-download')
                    ->visible(fn (array $record) => $record['status'] === 'update' && filled($record['download']))
                    ->requiresConfirmation()
                    ->modalDescription(fn (array $record) => "Downloads {$record['name']} {$record['latest']} and puts it on " . count($record['shards']) . ' shard(s) in place of ' . $record['file'] . ' (kept as .ginjer-old). It loads at the next restart.')
                    ->action(function (array $record) {
                        try {
                            $result = app(PluginUpdates::class)->install($this->record, $record, auth()->user());
                            NetworkLog::record($this->record, 'plugins', "Updated {$record['name']} to {$record['latest']} on " . count($result['done']) . ' shard(s)', $result['failed'] === [] ? null : implode("\n", $result['failed']), $result['failed'] === [] ? 'info' : 'warning');
                            Notification::make()
                                ->title("{$record['name']} {$record['latest']} installed on " . count($result['done']) . ' shard(s)')
                                ->body($result['failed'] === [] ? 'Restart the shards (Power → Rolling restart) to load it.' : 'Failed: ' . implode('; ', $result['failed']))
                                ->color($result['failed'] === [] ? 'success' : 'warning')
                                ->send();
                            CheckPluginUpdates::dispatch($this->record->id);
                        } catch (Throwable $exception) {
                            Notification::make()->title('Couldn\'t install it')->body($exception->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->emptyStateIcon('tabler-package')
            ->emptyStateHeading(fn () => $this->running() ? 'Checking…' : 'Not checked yet')
            ->emptyStateDescription('Check now reads the plugins on every shard.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
