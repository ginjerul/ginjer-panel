<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Ops\ConfigSync;
use Illuminate\Support\HtmlString;

/**
 * Config sync: plugin configs edited once (on the source shard) and copied to every other shard.
 *
 * @property Network $record
 */
class NetworkConfigSyncPage extends Page implements HasTable
{
    use \Ginjer\Network\Filament\Admin\Resources\Networks\Pages\Concerns\ChecksNetworkAccess;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = NetworkResource::class;

    protected function networkAbility(): string
    {
        return 'update';
    }

    /** @var list<array{path: string, same: list<string>, different: list<string>, missing: list<string>}> */
    public array $rows = [];

    /** @var list<string> */
    public array $skipped = [];

    public ?string $source = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->checkNetworkAccess();
        $this->load();
    }

    public function getTitle(): string
    {
        return $this->record->name . ': config sync';
    }

    public function load(): void
    {
        $result = app(ConfigSync::class)->compare($this->record->refresh());
        $this->rows = $result['files'];
        $this->skipped = $result['skipped'];
        $this->source = $result['source'];
        $this->resetTable();
    }

    public function getSubheading(): string|HtmlString|null
    {
        if ($this->source === null) {
            return 'This network has no shards yet.';
        }
        $text = e("Edit these files on {$this->source} (the source shard), then Sync now copies them to every other shard. "
            . 'A shard\'s old copy is kept next to it as <file>' . ConfigSync::OLD . '. Most plugins read their config at start, so restart the shards after a sync.');
        if (ConfigSync::paths($this->record) === []) {
            $text .= '<br><b>' . e('No files chosen yet: use "Choose files".') . '</b>';
        }
        foreach ($this->skipped as $line) {
            $text .= '<br><span style="color: rgb(217 119 6)">' . e("Not synced: $line") . '</span>';
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
            Action::make('exclude_choose')
                ->label('Choose files')
                ->icon('tabler-file-settings')
                ->color('gray')
                ->fillForm(fn () => ['sync_source_server_id' => $this->record->sync_source_server_id, 'synced_files' => $this->record->synced_files])
                ->schema([
                    Select::make('sync_source_server_id')
                        ->label('Source shard')
                        ->helperText('Edit configs here; the others get copies. Empty: the Nodes leader.')
                        ->options(fn () => $this->record->membersWithRole(MemberRole::Shard)->mapWithKeys(fn ($m) => [$m->server_id => $m->shard_name ?: $m->server->name])->all())
                        ->placeholder('The Nodes leader'),
                    Textarea::make('synced_files')
                        ->label('Files and folders')
                        ->rows(8)
                        ->placeholder("plugins/LuckPerms/config.yml\nplugins/Essentials/config.yml\nplugins/Essentials/messages\nbukkit.yml\nconfig/paper-world-defaults.yml")
                        ->helperText(new HtmlString(e('One path per line, from the server folder. A folder syncs every file in it (up to ' . ConfigSync::MAX_FILES . ' files of up to 2 MB). Never synced: reef.yml, server.properties, plugins/Nodes, worlds, logs and jars.'))),
                ])
                ->action(function (array $data) {
                    $this->record->update($data);
                    \Ginjer\Network\Services\Activity\NetworkLog::record($this->record, 'sync', 'Synced files changed', (string) $data['synced_files']);
                    $this->load();
                }),
            Action::make('exclude_refresh')
                ->label('Compare')
                ->icon('tabler-refresh')
                ->color('gray')
                ->action(fn () => $this->load()),
            Action::make('exclude_sync')
                ->label('Sync now')
                ->icon('tabler-copy')
                ->disabled(fn () => $this->rows === [])
                ->requiresConfirmation()
                ->modalDescription(fn () => "Copies these files from {$this->source} to every other shard where they differ. The old copies are kept as " . ConfigSync::OLD . ' files.')
                ->action(function () {
                    $result = app(ConfigSync::class)->sync($this->record);
                    \Ginjer\Network\Services\Activity\NetworkLog::record($this->record, 'sync', "Config sync copied {$result['copied']} file(s)", $result['failed'] === [] ? null : implode("\n", $result['failed']), $result['failed'] === [] ? 'info' : 'warning');
                    Notification::make()
                        ->title($result['failed'] === [] ? "Copied {$result['copied']} file(s)" : "Copied {$result['copied']} file(s), " . count($result['failed']) . ' failed')
                        ->body($result['failed'] === [] ? ($result['copied'] > 0 ? 'Restart the shards (Power → Rolling restart) to load them.' : 'Every shard already had the same files.') : implode('; ', $result['failed']))
                        ->color($result['failed'] === [] ? 'success' : 'warning')
                        ->persistent($result['failed'] !== [])
                        ->send();
                    $this->load();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect($this->rows)->keyBy('path')->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('path')->label('File'),
                TextColumn::make('state')
                    ->label('Other shards')
                    ->badge()
                    ->state(fn (array $record) => match (true) {
                        $record['different'] === [] && $record['missing'] === [] => 'Same everywhere',
                        default => implode(', ', array_filter([
                            $record['different'] === [] ? null : count($record['different']) . ' different',
                            $record['missing'] === [] ? null : count($record['missing']) . ' missing',
                        ])),
                    })
                    ->color(fn (array $record) => $record['different'] === [] && $record['missing'] === [] ? 'success' : 'warning')
                    ->tooltip(fn (array $record) => implode("\n", array_filter([
                        $record['different'] === [] ? null : 'Different on ' . implode(', ', $record['different']),
                        $record['missing'] === [] ? null : 'Missing on ' . implode(', ', $record['missing']),
                    ])) ?: null),
            ])
            ->emptyStateIcon('tabler-file-settings')
            ->emptyStateHeading('No files to sync')
            ->emptyStateDescription('Choose the plugin configs every shard should share.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
