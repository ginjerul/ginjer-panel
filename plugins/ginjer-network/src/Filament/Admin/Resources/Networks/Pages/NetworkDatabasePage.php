<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Ops\NetworkDatabase;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The network's shared MySQL database: create it, see how to connect, point LuckPerms at it.
 *
 * @property Network $record
 */
class NetworkDatabasePage extends Page
{
    use \Ginjer\Network\Filament\Admin\Resources\Networks\Pages\Concerns\ChecksNetworkAccess;
    use InteractsWithRecord;

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
        return $this->record->name . ': database';
    }

    public function getSubheading(): ?string
    {
        return 'One MySQL database every shard shares, for plugins that keep their own data: LuckPerms ranks, economies, stats. '
            . 'Plugins that save to files see different data on each shard; point them here instead.';
    }

    protected function getHeaderActions(): array
    {
        $has = fn () => NetworkDatabase::database($this->record) !== null;

        return [
            Action::make('exclude_back')
                ->label('Back to the network')
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->record])),
            Action::make('exclude_create')
                ->label('Create database')
                ->icon('tabler-database-plus')
                ->hidden($has)
                ->schema([
                    Select::make('host')
                        ->label('Database host')
                        ->options(fn () => NetworkDatabase::hostOptions($this->record))
                        ->default(fn () => array_key_first(NetworkDatabase::hostOptions($this->record)))
                        ->required()
                        ->helperText('Add one under Admin → Database Hosts if the list is empty. Every shard\'s node must be able to reach it.'),
                ])
                ->action(function (array $data) {
                    try {
                        app(NetworkDatabase::class)->create($this->record, (int) $data['host']);
                        \Ginjer\Network\Services\Activity\NetworkLog::record($this->record, 'database', 'Network database created');
                        Notification::make()->title('Database created')->body('Now set up LuckPerms, or give the details below to other plugins.')->success()->send();
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->title('Couldn\'t create the database')->body($exception->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('exclude_luckperms')
                ->label('Set up LuckPerms')
                ->icon('tabler-key')
                ->visible($has)
                ->requiresConfirmation()
                ->modalDescription('Points LuckPerms on every shard (and the proxy, if it has LuckPerms) at this database, with ' . (filled($this->record->redis_host) ? 'Redis' : 'the database') . ' sending changes between them right away. The old config is kept as config.yml.ginjer-old. Ranks already saved in a shard\'s local file aren\'t copied: export them first with "/lp export" and "/lp import" after.')
                ->action(function () {
                    $results = collect(app(NetworkDatabase::class)->setUpLuckPerms($this->record));
                    $failed = $results->where('ok', false)->count();
                    \Ginjer\Network\Services\Activity\NetworkLog::record($this->record, 'database', 'LuckPerms pointed at the network database', $results->map(fn ($r) => "{$r['server']}: {$r['message']}")->implode("\n"), $failed === 0 ? 'info' : 'warning');
                    Notification::make()
                        ->title($failed === 0 ? 'LuckPerms set up' : "LuckPerms set up, $failed server(s) not")
                        ->body(new HtmlString('<ul>' . $results->map(fn ($r) => '<li><b>' . e($r['server']) . ':</b> ' . e($r['message']) . '</li>')->implode('') . '</ul>' . ($failed === 0 ? 'Restart the network (Power → Rolling restart) to use it.' : '')))
                        ->color($failed === 0 ? 'success' : 'warning')
                        ->persistent()
                        ->send();
                }),
            Action::make('exclude_rotate')
                ->label('New password')
                ->icon('tabler-refresh')
                ->color('gray')
                ->visible($has)
                ->requiresConfirmation()
                ->modalDescription('Plugins using the old password lose the connection. Run "Set up LuckPerms" again afterwards, and update any other plugin by hand.')
                ->action(function () {
                    app(NetworkDatabase::class)->rotatePassword($this->record);
                    \Ginjer\Network\Services\Activity\NetworkLog::record($this->record, 'database', 'Network database password changed');
                    Notification::make()->title('Password changed')->success()->send();
                }),
            Action::make('exclude_delete')
                ->label('Delete database')
                ->icon('tabler-trash')
                ->color('danger')
                ->visible($has)
                ->requiresConfirmation()
                ->modalDescription('Drops the database and all the data in it: ranks, balances, everything plugins stored there. Take a backup first.')
                ->action(function () {
                    app(NetworkDatabase::class)->remove($this->record);
                    \Ginjer\Network\Services\Activity\NetworkLog::record($this->record, 'database', 'Network database deleted', null, 'warning');
                    Notification::make()->title('Database deleted')->success()->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $database = NetworkDatabase::database($this->record);
        if ($database === null) {
            return $schema->components([
                Section::make('No database yet')->schema([
                    Text::make('Create one: it\'s a normal Pelican database on the leader shard\'s server (it shows on its Databases tab), reachable from every shard.'),
                ]),
            ]);
        }
        $d = NetworkDatabase::details($database);

        return $schema->components([
            Section::make('Connection')
                ->description('For plugins that ask for MySQL (or MariaDB) settings. Use the same on every shard.')
                ->columns(2)
                ->schema([
                    TextEntry::make('host')->state($d['host'])->copyable(),
                    TextEntry::make('port')->state($d['port'])->copyable(),
                    TextEntry::make('database')->state($d['database'])->copyable(),
                    TextEntry::make('username')->state($d['username'])->copyable(),
                    TextEntry::make('password')->state($d['password'])->copyable()->fontFamily('mono'),
                    TextEntry::make('jdbc')->label('JDBC URL')->state($d['jdbc'])->copyable(),
                    TextEntry::make('owner')->label('Owned by')->state($database->server?->name),
                ]),
        ]);
    }
}
