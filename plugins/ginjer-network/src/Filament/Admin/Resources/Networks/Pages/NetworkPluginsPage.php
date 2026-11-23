<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\NetworkPlugins;
use Ginjer\Network\Services\PluginSwitch;
use Illuminate\Support\HtmlString;

/**
 * Plugin switches for every shard of a network at once.
 *
 * @property Network $record
 */
class NetworkPluginsPage extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = NetworkResource::class;

    /** @var list<array<string, mixed>> */
    public array $plugins = [];

    /** @var list<string> */
    public array $errors = [];

    public int $shards = 0;

    public int $pendingChanges = 0;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->load();
    }

    public function getTitle(): string
    {
        return $this->record->name . ': plugins';
    }

    public function load(): void
    {
        $result = app(NetworkPlugins::class)->list($this->record);
        $this->plugins = $result['plugins'];
        $this->errors = $result['errors'];
        $this->shards = $result['shards'];
        $this->resetTable();
    }

    public function getSubheading(): string|HtmlString|null
    {
        $text = e("Switching a plugin here switches it on all {$this->shards} shard(s); shards should run the same plugins. Nothing is deleted.");
        if ($this->pendingChanges > 0) {
            $text .= ' <b>' . e("{$this->pendingChanges} change(s) take effect when the shards restart (Power → Restart network on the network page).") . '</b>';
        }
        foreach (PluginSwitch::warnings(array_map(fn ($p) => ['name' => $p['name'], 'enabled' => $p['enabled'], 'file' => $p['file']], $this->plugins)) as $warning) {
            $text .= '<br><span style="color: rgb(217 119 6)">' . e($warning) . '</span>';
        }
        foreach ($this->errors as $error) {
            $text .= '<br><span style="color: rgb(220 38 38)">' . e("Couldn't read $error") . '</span>';
        }

        return new HtmlString($text);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exclude_back')
                ->label('Back to the network')
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->record])),
            Action::make('exclude_refresh')
                ->label('Refresh')
                ->icon('tabler-refresh')
                ->color('gray')
                ->action(fn () => $this->load()),
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
                        $result = app(NetworkPlugins::class)->set($this->record, $record['file'], $state);
                        $this->pendingChanges += $result['changed'];
                        Notification::make()
                            ->title($record['name'] . ($state ? ' switched on' : ' switched off') . " on {$result['changed']} shard(s)")
                            ->body($result['failed'] === [] ? 'Restart the shards to apply it.' : 'Failed on ' . implode('; ', $result['failed']))
                            ->color($result['failed'] === [] ? 'success' : 'warning')
                            ->send();
                        $this->load();

                        return $state;
                    }),
                TextColumn::make('name')
                    ->label('Plugin')
                    ->description(fn (array $record) => $record['file']),
                TextColumn::make('on')
                    ->label('Shards')
                    ->state(fn (array $record) => "{$record['on']} on, {$record['off']} off" . ($record['missing'] > 0 ? ", missing on {$record['missing']}" : ''))
                    ->badge()
                    ->color(fn (array $record) => $record['mixed'] || $record['missing'] > 0 ? 'warning' : ($record['enabled'] ? 'success' : 'gray'))
                    ->tooltip(fn (array $record) => $record['mixed'] ? 'Shards disagree: switching sets all of them.' : ($record['missing'] > 0 ? 'Not every shard has this jar; copy it to the others (one-click updates do this for Reef, Nodes and GinjerMap).' : null)),
            ])
            ->emptyStateHeading('No plugins found on the shards');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
